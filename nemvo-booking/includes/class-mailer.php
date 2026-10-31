<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the customizable booking emails via wp_mail(). When invites are sent by
 * WordPress (instead of Google), emails also carry an iCalendar invitation.
 */
class Mailer {

	/** @var string Invitation attached to the email currently being sent. */
	private static $ics = '';

	public static function booking_created( array $booking, array $cfg ) {
		self::notify( $booking, $cfg, 'REQUEST', 'booker_confirm', 'guest_invite', 'employee_notify' );
	}

	public static function booking_rescheduled( array $booking, array $cfg ) {
		self::notify( $booking, $cfg, 'REQUEST', 'booker_reschedule', 'guest_reschedule', 'employee_reschedule' );
	}

	public static function booking_cancelled( array $booking, array $cfg ) {
		self::notify( $booking, $cfg, 'CANCEL', 'booker_cancel', 'guest_cancel', 'employee_cancel' );
	}

	private static function notify( array $booking, array $cfg, string $method, string $booker_type, string $guest_type, string $host_type ) {
		$wp_invites = 'wordpress' === Settings::get( 'invite_mode' );
		$hosts      = Employees::many( $booking['employee_ids'] );
		$organizer  = Employees::get( $booking['organizer_id'] ) ?: ( $hosts[0] ?? null );
		$org_email  = $organizer ? $organizer['email'] : '';

		/*
		 * Everyone gets exactly one email per change: the one with the calendar invitation.
		 * With Google invites, Google emails the booker, guests and other hosts itself, so WordPress
		 * only notifies the organizer (Google never emails the organizer about their own event).
		 */
		foreach ( $hosts as $host ) {
			$is_organizer = $organizer && $host['id'] === $organizer['id'];
			if ( $wp_invites || $is_organizer ) {
				// The organizer already has the event in their calendar, so no invitation is attached.
				$ics = $wp_invites && ! $is_organizer ? self::ics( $booking, $cfg, $method ) : '';
				self::send( $host_type, $host['email'], $booking, $cfg, $cfg['timezone'], $host['name'], $booking['email'], $ics );
			}
		}
		if ( ! $wp_invites ) {
			return;
		}

		$ics = self::ics( $booking, $cfg, $method );
		self::send( $booker_type, $booking['email'], $booking, $cfg, $booking['timezone'], $booking['name'], $org_email, $ics );
		foreach ( $booking['guests'] as $guest ) {
			self::send( $guest_type, $guest, $booking, $cfg, $booking['timezone'], '', $org_email, $ics );
		}
	}

	private static function send( string $type, string $to, array $booking, array $cfg, string $timezone, string $recipient_name, string $reply_to, string $ics ) {
		if ( ! Settings::get( "email_{$type}_enabled" ) || ! is_email( $to ) ) {
			return;
		}

		$vars    = Bookings::vars( $booking, $cfg, $timezone, $recipient_name );
		$subject = Bookings::render( (string) Settings::get( "email_{$type}_subject" ), $vars, false );
		$body    = self::layout( Bookings::render( (string) Settings::get( "email_{$type}_body" ), $vars, true ) );
		self::deliver( $to, $subject, $body, $reply_to, $ics, (int) $booking['id'] );
	}

	/**
	 * Sends an HTML email through wp_mail(), so whatever WordPress is configured to send with
	 * (e.g. WP Mail SMTP) is used. Optionally attaches an iCalendar invitation.
	 */
	public static function deliver( string $to, string $subject, string $html, string $reply_to = '', string $ics = '', int $booking_id = 0 ): bool {
		$body    = false === strpos( $html, '<html' ) ? self::layout( $html ) : $html;
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$from_name  = (string) Settings::get( 'from_name' );
		$from_email = (string) Settings::get( 'from_email' );
		$set_name   = function () use ( $from_name ) {
			return $from_name;
		};
		$set_email  = function () use ( $from_email ) {
			return $from_email;
		};
		if ( $from_name ) {
			add_filter( 'wp_mail_from_name', $set_name, 99 );
		}
		if ( is_email( $from_email ) ) {
			add_filter( 'wp_mail_from', $set_email, 99 );
		}
		if ( $ics ) {
			self::$ics = $ics;
			add_action( 'phpmailer_init', array( __CLASS__, 'attach_ics' ) );
		}

		$sent = wp_mail( $to, $subject, $body, $headers );

		remove_filter( 'wp_mail_from_name', $set_name, 99 );
		remove_filter( 'wp_mail_from', $set_email, 99 );
		if ( $ics ) {
			remove_action( 'phpmailer_init', array( __CLASS__, 'attach_ics' ) );
			self::$ics = '';
			global $phpmailer;
			if ( is_object( $phpmailer ) ) {
				$phpmailer->Ical = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		$args = array( 'booking_id' => $booking_id );
		if ( $sent ) {
			Log::info( 'email.sent', sprintf( 'Sent "%1$s" to %2$s.', $subject, $to ), $args );
		} else {
			Log::error( 'email.failed', sprintf( 'Could not send "%1$s" to %2$s. Check the WordPress mail configuration.', $subject, $to ), $args );
		}
		return $sent;
	}

	/** @param \PHPMailer\PHPMailer\PHPMailer $phpmailer */
	public static function attach_ics( $phpmailer ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->AltBody = wp_strip_all_tags( str_replace( array( '<br />', '<br>', '</p>' ), "\n", $phpmailer->Body ) );
		$phpmailer->Ical    = self::$ics;
		// phpcs:enable
		$phpmailer->addStringAttachment( self::$ics, 'invite.ics', 'base64', 'application/ics' );
	}

	private static function layout( string $content ): string {
		$accent = sanitize_hex_color( (string) Settings::get( 'accent_color' ) ) ?: '#4f46e5';
		return '<!doctype html><html><body style="margin:0;padding:24px;background:#f5f5f7;">'
			. '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px 32px;border-top:4px solid ' . esc_attr( $accent ) . ';'
			. 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#1f2937;">'
			. $content
			. '</div></body></html>';
	}

	public static function ics( array $booking, array $cfg, string $method ): string {
		$organizer = Employees::get( $booking['organizer_id'] );
		$hosts     = Employees::many( $booking['employee_ids'] );
		$vars      = Bookings::vars( $booking, $cfg, $cfg['timezone'] );
		$cancel    = 'CANCEL' === $method;
		$uid       = $booking['ical_uid'] ?: 'nemvo-booking-' . $booking['id'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

		$lines = array(
			'BEGIN:VCALENDAR',
			'PRODID:-//Nemvo Booking//EN',
			'VERSION:2.0',
			'CALSCALE:GREGORIAN',
			'METHOD:' . $method,
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'SEQUENCE:' . ( (int) ( $booking['sequence'] ?? 0 ) + ( $cancel ? 1 : 0 ) ),
			'STATUS:' . ( $cancel ? 'CANCELLED' : 'CONFIRMED' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', $booking['start'] ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', $booking['end'] ),
			'SUMMARY:' . self::text( Bookings::render( $cfg['meeting_title'], $vars, false ) ),
			'DESCRIPTION:' . self::text( trim( ( $booking['meet_link'] ? __( 'Join with Google Meet', 'nemvo-booking' ) . ': ' . $booking['meet_link'] . "\n\n" : '' ) . Bookings::event_description( $booking, $cfg ) ) ),
		);
		if ( $booking['meet_link'] ) {
			$lines[] = 'LOCATION:' . self::text( $booking['meet_link'] );
			$lines[] = 'URL:' . $booking['meet_link'];
		}
		if ( $organizer ) {
			$lines[] = 'ORGANIZER;CN=' . self::param( $organizer['name'] ) . ':mailto:' . Employees::calendar_email( $organizer );
		}

		$attendees = array( array( $booking['name'], $booking['email'] ) );
		foreach ( $booking['guests'] as $guest ) {
			$attendees[] = array( '', $guest );
		}
		foreach ( $hosts as $host ) {
			if ( ! $organizer || $host['id'] !== $organizer['id'] ) {
				$attendees[] = array( $host['name'], Employees::calendar_email( $host ) );
			}
		}
		foreach ( $attendees as $a ) {
			$lines[] = 'ATTENDEE;' . ( $a[0] ? 'CN=' . self::param( $a[0] ) . ';' : '' ) . 'ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $a[1];
		}

		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	private static function text( string $value ): string {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $value );
	}

	private static function param( string $value ): string {
		return '"' . str_replace( array( '"', "\r", "\n" ), '', $value ) . '"';
	}

	/** Folds lines longer than 75 octets (RFC 5545), without splitting UTF-8 characters. */
	private static function fold( string $line ): string {
		$out   = '';
		$limit = 75;
		while ( strlen( $line ) > $limit ) {
			$chunk = mb_strcut( $line, 0, $limit, 'UTF-8' );
			$out  .= $chunk . "\r\n ";
			$line  = substr( $line, strlen( $chunk ) );
			$limit = 74; // Continuation lines start with a space.
		}
		return $out . $line;
	}
}
