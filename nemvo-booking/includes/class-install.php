<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

class Install {

	const DB_VERSION = '3';

	public static function activate() {
		self::create_tables();
		if ( ! wp_next_scheduled( 'nemvo_booking_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nemvo_booking_daily' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'nemvo_booking_daily' );
	}

	public static function maybe_upgrade() {
		$installed = get_option( 'nemvo_booking_db_version' );
		if ( $installed === self::DB_VERSION ) {
			return;
		}
		self::create_tables();

		// Database version 1 had no rescheduling: give saved confirmation emails the new link.
		if ( '1' === $installed ) {
			$saved = get_option( Settings::OPTION );
			$body  = $saved['email_booker_confirm_body'] ?? '';
			if ( $body && false === strpos( $body, '{reschedule_link}' ) && false !== strpos( $body, 'Need to cancel? {cancel_link}' ) ) {
				$saved['email_booker_confirm_body'] = str_replace( 'Need to cancel? {cancel_link}', "Need to change something?\nPick a new time: {reschedule_link}\nCancel: {cancel_link}", $body );
				update_option( Settings::OPTION, $saved );
			}
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$employees = Plugin::table( 'employees' );
		$bookings  = Plugin::table( 'bookings' );
		$log       = Plugin::table( 'log' );

		dbDelta(
			"CREATE TABLE {$employees} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			photo_id bigint(20) unsigned NOT NULL DEFAULT 0,
			google_email varchar(191) NOT NULL DEFAULT '',
			refresh_token text NULL,
			access_token text NULL,
			token_expires bigint(20) NOT NULL DEFAULT 0,
			calendars longtext NULL,
			busy_calendars longtext NULL,
			target_calendar varchar(255) NOT NULL DEFAULT '',
			connect_token varchar(64) NOT NULL DEFAULT '',
			connect_expires bigint(20) NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$bookings} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			start_utc datetime NOT NULL,
			end_utc datetime NOT NULL,
			duration int(11) NOT NULL DEFAULT 0,
			timezone varchar(64) NOT NULL DEFAULT '',
			organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			employee_ids varchar(255) NOT NULL DEFAULT '',
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			guests text NULL,
			answers longtext NULL,
			event_id varchar(255) NOT NULL DEFAULT '',
			calendar_id varchar(255) NOT NULL DEFAULT '',
			ical_uid varchar(255) NOT NULL DEFAULT '',
			meet_link varchar(255) NOT NULL DEFAULT '',
			token varchar(64) NOT NULL DEFAULT '',
			sequence int(11) NOT NULL DEFAULT 0,
			consent_at datetime NULL,
			cancel_reason text NULL,
			cancelled_by varchar(20) NOT NULL DEFAULT '',
			cancelled_at datetime NULL,
			ip varchar(64) NOT NULL DEFAULT '',
			page_url text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY page_id (page_id),
			KEY start_utc (start_utc),
			KEY status (status),
			KEY token (token)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(10) NOT NULL DEFAULT 'info',
			event varchar(64) NOT NULL DEFAULT '',
			message text NULL,
			booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
			employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			context longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY booking_id (booking_id)
			) {$charset};"
		);

		update_option( 'nemvo_booking_db_version', self::DB_VERSION );
	}
}
