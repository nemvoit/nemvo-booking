<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * GDPR: consent, automatic deletion of old personal data, and WordPress's
 * "Export/Erase Personal Data" tools (Tools menu).
 */
class Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_action( 'nemvo_booking_daily', array( __CLASS__, 'apply_retention' ) );
	}

	/** Consent checkbox label as HTML, or '' when consent isn't required. */
	public static function consent_label(): string {
		if ( ! Settings::get( 'privacy_consent' ) ) {
			return '';
		}
		$url  = get_privacy_policy_url();
		$link = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'nemvo-booking' ) . '</a>' : esc_html__( 'privacy policy', 'nemvo-booking' );
		return strtr(
			esc_html( (string) Settings::get( 'privacy_text' ) ),
			array(
				'{privacy_policy}' => $link,
				'{site_name}'      => esc_html( get_bloginfo( 'name' ) ),
			)
		);
	}

	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$days = (int) Settings::get( 'retention_days' );
		$text = '<p>' . esc_html__( 'When you book a meeting, we store the details you enter in the booking form (such as name, email, company, phone and message), the email addresses of any guests you add, the chosen time, your time zone and your IP address. We use them to arrange the meeting, send confirmations and invitations, and protect the booking form against abuse.', 'nemvo-booking' ) . '</p>';
		$text .= '<p>' . esc_html__( 'The meeting is created in our Google Calendar with a Google Meet link, so your name, email address and answers are also processed by Google as our service provider.', 'nemvo-booking' ) . '</p>';
		if ( Protection::cap_enabled() ) {
			$text .= '<p>' . esc_html__( 'To keep out spam bots, your browser solves a small puzzle with our own verification server (Cap) before the booking is sent. No cookies are used for this.', 'nemvo-booking' ) . '</p>';
		}
		if ( get_option( 'show_avatars' ) ) {
			$text .= '<p>' . esc_html__( 'Photos of the people you can book may be loaded from Gravatar, a service run by Automattic, which then receives your IP address.', 'nemvo-booking' ) . '</p>';
		}
		$text .= '<p>' . ( $days > 0
			/* translators: %d: number of days */
			? esc_html( sprintf( __( 'Booking details are deleted %d days after the meeting.', 'nemvo-booking' ), $days ) )
			: esc_html__( 'Booking details are kept until you ask us to delete them.', 'nemvo-booking' ) ) . '</p>';
		wp_add_privacy_policy_content( __( 'Nemvo Booking', 'nemvo-booking' ), wp_kses_post( $text ) );
	}

	/** Anonymizes bookings whose meeting ended more than the configured number of days ago. */
	public static function apply_retention() {
		global $wpdb;
		$days = (int) Settings::get( 'retention_days' );
		if ( $days <= 0 ) {
			return;
		}
		$table = Plugin::table( 'bookings' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE email <> '' AND end_utc < %s LIMIT 500", $table, Plugin::to_mysql( time() - $days * DAY_IN_SECONDS ) ) );
		foreach ( $ids as $id ) {
			Bookings::anonymize( Bookings::get( (int) $id ) );
		}
		if ( $ids ) {
			/* translators: 1: number of bookings, 2: days */
			Log::info( 'privacy.retention', sprintf( 'Deleted personal data from %1$d booking(s) older than %2$d days.', count( $ids ), $days ) );
		}
	}

	/** @return array[] Bookings where the email is the booker or a guest. */
	private static function find( string $email ): array {
		global $wpdb;
		$table = Plugin::table( 'bookings' );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE email = %s OR guests LIKE %s ORDER BY id',
				$table,
				$email,
				'%' . $wpdb->esc_like( '"' . $email . '"' ) . '%'
			)
		);
		return array_filter( array_map( array( Bookings::class, 'get' ), array_map( 'intval', $ids ) ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['nemvo-booking'] = array(
			'exporter_friendly_name' => __( 'Bookings', 'nemvo-booking' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['nemvo-booking'] = array(
			'eraser_friendly_name' => __( 'Bookings', 'nemvo-booking' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( $email, $page = 1 ) {
		$items = array();
		foreach ( self::find( strtolower( $email ) ) as $booking ) {
			$cfg  = Pages::config( $booking['page_id'] );
			$data = array(
				array( 'name' => __( 'Booking page', 'nemvo-booking' ), 'value' => $cfg['title'] ),
				array( 'name' => __( 'Time', 'nemvo-booking' ), 'value' => wp_date( 'Y-m-d H:i', $booking['start'] ) . ' ' . wp_timezone_string() ),
				array( 'name' => __( 'Status', 'nemvo-booking' ), 'value' => $booking['status'] ),
				array( 'name' => __( 'Hosts', 'nemvo-booking' ), 'value' => implode( ', ', wp_list_pluck( Employees::many( $booking['employee_ids'] ), 'name' ) ) ),
			);
			if ( strtolower( $booking['email'] ) === strtolower( $email ) ) {
				foreach ( Bookings::answer_rows( $booking, $cfg ) as $row ) {
					$data[] = array( 'name' => $row[0], 'value' => $row[1] );
				}
				$data[] = array( 'name' => __( 'Guests', 'nemvo-booking' ), 'value' => implode( ', ', $booking['guests'] ) );
				$data[] = array( 'name' => __( 'Time zone', 'nemvo-booking' ), 'value' => $booking['timezone'] );
				$data[] = array( 'name' => __( 'IP address', 'nemvo-booking' ), 'value' => $booking['ip'] );
				$data[] = array( 'name' => __( 'Consent given', 'nemvo-booking' ), 'value' => $booking['consent_at'] ? $booking['consent_at'] . ' UTC' : '–' );
			} else {
				$data[] = array( 'name' => __( 'Invited as guest by', 'nemvo-booking' ), 'value' => $booking['name'] );
			}
			$items[] = array(
				'group_id'    => 'nemvo-booking',
				'group_label' => __( 'Bookings', 'nemvo-booking' ),
				'item_id'     => 'booking-' . $booking['id'],
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$email   = strtolower( $email );
		$removed = 0;
		foreach ( self::find( $email ) as $booking ) {
			if ( strtolower( $booking['email'] ) === $email ) {
				Bookings::anonymize( $booking );
			} else {
				$guests = array_values( array_diff( $booking['guests'], array( $email ) ) );
				Bookings::update( $booking['id'], array( 'guests' => wp_json_encode( $guests ) ) );
			}
			++$removed;
		}
		// Log entries not tied to a booking (e.g. blocked spam attempts) may mention the address too.
		$removed += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE message LIKE %s', Plugin::table( 'log' ), '%' . $wpdb->esc_like( $email ) . '%' ) );

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'Booking data was anonymized. Events already in Google Calendar must be removed there if needed.', 'nemvo-booking' ) ) : array(),
			'done'           => true,
		);
	}
}
