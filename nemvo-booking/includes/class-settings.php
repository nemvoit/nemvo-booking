<?php
/**
 * Plugin settings.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's settings, stored as one array in the nemvo_booking_settings option.
 */
class Settings {

	const OPTION = 'nemvo_booking_settings';

	/** Settings stored encrypted with Crypto (the key derives from the site's salts). */
	const SECRETS = array( 'google_client_secret', 'cap_secret' );

	/**
	 * Encrypts secrets whenever the option is saved, whichever code saves it.
	 */
	public static function init() {
		add_filter( 'pre_update_option_' . self::OPTION, array( __CLASS__, 'encrypt_secrets' ) );
	}

	/**
	 * Email types: key => [label, description].
	 *
	 * @return array[]
	 */
	public static function email_types(): array {
		return array(
			'booker_confirm'      => array( __( 'Booking confirmation (to booker)', 'nemvo-booking' ), __( 'Sent to the person who booked.', 'nemvo-booking' ) ),
			'guest_invite'        => array( __( 'Guest invitation (to guests)', 'nemvo-booking' ), __( 'Sent to each guest added by the booker.', 'nemvo-booking' ) ),
			'employee_notify'     => array( __( 'New booking (to hosts)', 'nemvo-booking' ), __( 'Sent to the employees hosting the meeting.', 'nemvo-booking' ) ),
			'booker_reschedule'   => array( __( 'Rescheduled (to booker)', 'nemvo-booking' ), __( 'Sent to the booker when the meeting is moved to a new time.', 'nemvo-booking' ) ),
			'guest_reschedule'    => array( __( 'Rescheduled (to guests)', 'nemvo-booking' ), __( 'Sent to guests when the meeting is moved.', 'nemvo-booking' ) ),
			'employee_reschedule' => array( __( 'Rescheduled (to hosts)', 'nemvo-booking' ), __( 'Sent to the hosts when the meeting is moved.', 'nemvo-booking' ) ),
			'booker_cancel'       => array( __( 'Cancellation (to booker)', 'nemvo-booking' ), __( 'Sent to the booker when a meeting is cancelled.', 'nemvo-booking' ) ),
			'guest_cancel'        => array( __( 'Cancellation (to guests)', 'nemvo-booking' ), __( 'Sent to guests when a meeting is cancelled.', 'nemvo-booking' ) ),
			'employee_cancel'     => array( __( 'Cancellation (to hosts)', 'nemvo-booking' ), __( 'Sent to the hosts when a meeting is cancelled.', 'nemvo-booking' ) ),
		);
	}

	/**
	 * Placeholders available in email texts, the invitation text and the thank-you message.
	 *
	 * @return string[] Placeholder => description.
	 */
	public static function placeholders(): array {
		return array(
			'{name}'            => __( 'Booker name', 'nemvo-booking' ),
			'{email}'           => __( 'Booker email', 'nemvo-booking' ),
			'{recipient_name}'  => __( 'Name of the person receiving the email', 'nemvo-booking' ),
			'{page_title}'      => __( 'Booking page title', 'nemvo-booking' ),
			'{date}'            => __( 'Meeting date (in the recipient\'s timezone)', 'nemvo-booking' ),
			'{time}'            => __( 'Start time', 'nemvo-booking' ),
			'{end_time}'        => __( 'End time', 'nemvo-booking' ),
			'{timezone}'        => __( 'Timezone', 'nemvo-booking' ),
			'{duration}'        => __( 'Duration in minutes', 'nemvo-booking' ),
			'{host}'            => __( 'Host name(s)', 'nemvo-booking' ),
			'{meet_link}'       => __( 'Google Meet link', 'nemvo-booking' ),
			'{cancel_link}'     => __( 'Link where the booker can cancel', 'nemvo-booking' ),
			'{reschedule_link}' => __( 'Link where the booker can pick a new time', 'nemvo-booking' ),
			'{old_date}'        => __( 'Previous date (rescheduling emails)', 'nemvo-booking' ),
			'{old_time}'        => __( 'Previous start time (rescheduling emails)', 'nemvo-booking' ),
			'{booking_url}'     => __( 'Booking page URL (to book a new time)', 'nemvo-booking' ),
			'{guests}'          => __( 'Guest emails', 'nemvo-booking' ),
			'{answers}'         => __( 'All form answers', 'nemvo-booking' ),
			'{cancel_reason}'   => __( 'Reason given when cancelling', 'nemvo-booking' ),
			'{site_name}'       => __( 'Site name', 'nemvo-booking' ),
			'{field_key}'       => __( 'Any form field by its key, e.g. {company} or {phone}', 'nemvo-booking' ),
		);
	}

	/**
	 * Default value of every setting.
	 *
	 * @return array Setting name => default.
	 */
	public static function defaults(): array {
		$defaults = array(
			'google_client_id'     => '',
			'google_client_secret' => '',
			'invite_mode'          => 'google',
			'event_description'    => __( "Booked via {site_name}.\n\n{answers}\n\nNeed to change something?\nPick a new time: {reschedule_link}\nCancel: {cancel_link}", 'nemvo-booking' ),
			'holiday_calendars'    => array( Holidays::default_calendar() ),
			'holiday_custom'       => '',
			'include_observances'  => 0,
			'blocked_dates'        => '',
			'from_name'            => get_bloginfo( 'name' ),
			'from_email'           => '',
			'accent_color'         => '#4f46e5',
			'time_format'          => preg_match( '/[aA]/', (string) get_option( 'time_format' ) ) ? '12' : '24',
			'week_numbers'         => 1,
			'log_retention_days'   => 365,
			'delete_on_uninstall'  => 0,
			'honeypot_enabled'     => 1,
			'min_submit_seconds'   => 5,
			'headless_check'       => 1,
			'rate_limit'           => 10,
			'blocklist'            => '',
			'cap_enabled'          => 0,
			'cap_url'              => '',
			'cap_public_url'       => '',
			'cap_site_key'         => '',
			'cap_secret'           => '',
			'cap_mode'             => 'visible',
			'alert_email'          => '',
			'alert_employee'       => 1,
			'privacy_consent'      => 1,
			'privacy_text'         => __( 'I agree that {site_name} stores my details to arrange this meeting, as described in the {privacy_policy}.', 'nemvo-booking' ),
			'retention_days'       => 365,
		);

		$emails = array(
			'booker_confirm'      => array(
				'subject' => __( 'Confirmed: {page_title} on {date} at {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi {name},\n\nYour meeting is booked. Here are the details:\n\n<strong>{page_title}</strong>\n{date}, {time} – {end_time} ({timezone})\nWith: {host}\nGoogle Meet: {meet_link}\n\n{answers}\n\nNeed to change something?\nPick a new time: {reschedule_link}\nCancel: {cancel_link}\n\nBest regards,\n{site_name}",
					'nemvo-booking'
				),
			),
			'guest_invite'        => array(
				'subject' => __( 'Invitation: {page_title} on {date} at {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi,\n\n{name} has invited you to a meeting.\n\n<strong>{page_title}</strong>\n{date}, {time} – {end_time} ({timezone})\nWith: {host}, {name}\nGoogle Meet: {meet_link}\n\nBest regards,\n{site_name}",
					'nemvo-booking'
				),
			),
			'employee_notify'     => array(
				'subject' => __( 'New booking: {name} – {date} {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi {recipient_name},\n\nYou have a new booking for <strong>{page_title}</strong>.\n\nWhen: {date}, {time} – {end_time} ({timezone})\nGoogle Meet: {meet_link}\nGuests: {guests}\n\n{answers}",
					'nemvo-booking'
				),
			),
			'booker_reschedule'   => array(
				'subject' => __( 'Rescheduled: {page_title} is now on {date} at {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi {name},\n\nYour meeting <strong>{page_title}</strong> has been moved.\n\nNew time: {date}, {time} – {end_time} ({timezone})\nPrevious time: {old_date}, {old_time}\nWith: {host}\nGoogle Meet: {meet_link}\n\nNeed to change something?\nPick a new time: {reschedule_link}\nCancel: {cancel_link}\n\nBest regards,\n{site_name}",
					'nemvo-booking'
				),
			),
			'guest_reschedule'    => array(
				'subject' => __( 'Rescheduled: {page_title} is now on {date} at {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi,\n\nThe meeting <strong>{page_title}</strong> with {name} has been moved.\n\nNew time: {date}, {time} – {end_time} ({timezone})\nPrevious time: {old_date}, {old_time}\nGoogle Meet: {meet_link}\n\nBest regards,\n{site_name}",
					'nemvo-booking'
				),
			),
			'employee_reschedule' => array(
				'subject' => __( 'Rescheduled: {name} – now {date} {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi {recipient_name},\n\n{name} ({email}) has moved <strong>{page_title}</strong>.\n\nNew time: {date}, {time} – {end_time} ({timezone})\nPrevious time: {old_date}, {old_time}\nGoogle Meet: {meet_link}",
					'nemvo-booking'
				),
			),
			'booker_cancel'       => array(
				'subject' => __( 'Cancelled: {page_title} on {date} at {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi {name},\n\nYour meeting <strong>{page_title}</strong> on {date} at {time} ({timezone}) has been cancelled.\n\nReason: {cancel_reason}\n\nYou are welcome to book a new time: {booking_url}\n\nBest regards,\n{site_name}",
					'nemvo-booking'
				),
			),
			'guest_cancel'        => array(
				'subject' => __( 'Cancelled: {page_title} on {date} at {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi,\n\nThe meeting <strong>{page_title}</strong> with {name} on {date} at {time} ({timezone}) has been cancelled.\n\nBest regards,\n{site_name}",
					'nemvo-booking'
				),
			),
			'employee_cancel'     => array(
				'subject' => __( 'Cancelled: {name} – {date} {time}', 'nemvo-booking' ),
				'body'    => __(
					"Hi {recipient_name},\n\nThe meeting <strong>{page_title}</strong> with {name} ({email}) on {date} at {time} ({timezone}) has been cancelled.\n\nReason: {cancel_reason}",
					'nemvo-booking'
				),
			),
		);

		foreach ( $emails as $key => $email ) {
			$defaults[ "email_{$key}_enabled" ] = 1;
			$defaults[ "email_{$key}_subject" ] = $email['subject'];
			$defaults[ "email_{$key}_body" ]    = $email['body'];
		}

		return $defaults;
	}

	/**
	 * All settings: saved values over the defaults, with secrets decrypted and settings from older
	 * versions converted.
	 *
	 * @return array Setting name => value.
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		// Older settings stored a single holiday calendar ('custom' meant the custom ID field).
		if ( isset( $saved['holiday_calendar'] ) && ! isset( $saved['holiday_calendars'] ) ) {
			$old                        = (string) $saved['holiday_calendar'];
			$saved['holiday_calendars'] = ( '' === $old || 'custom' === $old ) ? array() : array( $old );
			if ( 'custom' !== $old ) {
				$saved['holiday_custom'] = '';
			}
		}

		unset( $saved['cap_script_url'] ); // Removed in 1.2.2; the bundled widget is always used.

		// Secrets are stored encrypted since 1.2.2; older plain-text values are read as they are.
		foreach ( self::SECRETS as $key ) {
			if ( isset( $saved[ $key ] ) ) {
				$saved[ $key ] = self::reveal( (string) $saved[ $key ] );
			}
		}

		// Up to 1.2.0 the Cap URL was the public address and the internal one was optional.
		// Now the Cap URL is the address WordPress uses and the public one is optional.
		if ( array_key_exists( 'cap_internal_url', $saved ) && ! isset( $saved['cap_public_url'] ) ) {
			$saved['cap_public_url'] = (string) ( $saved['cap_url'] ?? '' );
			$saved['cap_url']        = $saved['cap_internal_url'] ? $saved['cap_internal_url'] : $saved['cap_public_url'];
			unset( $saved['cap_internal_url'] );
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed The value, or null for an unknown name.
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Saves changed settings, keeping the others.
	 *
	 * @param array $values Settings to change, keyed by name, with secrets in plain text.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, self::encrypt_secrets( array_merge( self::all(), $values ) ) );
	}

	/**
	 * Encrypts the secret settings before the option is written (pre_update_option filter).
	 * Values that are already encrypted are left alone.
	 *
	 * @param mixed $value Settings array about to be saved.
	 * @return mixed The settings with encrypted secrets.
	 */
	public static function encrypt_secrets( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( self::SECRETS as $key ) {
			if ( isset( $value[ $key ] ) && '' !== (string) $value[ $key ] && ! Crypto::is_encrypted( (string) $value[ $key ] ) ) {
				$value[ $key ] = Crypto::encrypt( (string) $value[ $key ] );
			}
		}
		return $value;
	}

	/**
	 * Plain text of a stored secret: decrypted when encrypted, as it is when stored by a version
	 * before 1.2.2. Decrypted values are remembered for the rest of the request.
	 *
	 * @param string $stored Value from the database.
	 * @return string The secret, or '' if it can't be decrypted (e.g. the salts changed).
	 */
	private static function reveal( string $stored ): string {
		static $plain = array();
		if ( ! Crypto::is_encrypted( $stored ) ) {
			return $stored;
		}
		if ( ! isset( $plain[ $stored ] ) ) {
			$plain[ $stored ] = Crypto::decrypt( $stored );
		}
		return $plain[ $stored ];
	}
}
