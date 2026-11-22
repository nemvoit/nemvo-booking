<?php
/**
 * Installation and database upgrades.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin's tables and moves stored data forward when the plugin is updated.
 */
class Install {

	/**
	 * Database version. 4 renamed the booking page post type from nb_booking_page to
	 * nemvo_booking_page and encrypted the stored secrets. 5 added the accounts table (extra
	 * Google accounts checked for conflicts); dbDelta() creates it, nothing else moves.
	 */
	const DB_VERSION = 5;

	/**
	 * Activation: creates or upgrades the tables and schedules the daily job.
	 */
	public static function activate() {
		self::maybe_upgrade();
		if ( ! wp_next_scheduled( 'nemvo_booking_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nemvo_booking_daily' );
		}
	}

	/**
	 * Deactivation: stops the daily job. Data stays until the plugin is deleted.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'nemvo_booking_daily' );
	}

	/**
	 * Runs on every request (plugins_loaded) and does nothing once the stored database version
	 * matches. Updates that skip versions run every step they missed.
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'nemvo_booking_db_version' );
		if ( false !== $installed && self::DB_VERSION === (int) $installed ) {
			return;
		}
		self::create_tables();
		$installed = (int) $installed;

		// Database version 1 had no rescheduling: give saved confirmation emails the new link.
		if ( 1 === $installed ) {
			$saved = get_option( Settings::OPTION );
			$body  = $saved['email_booker_confirm_body'] ?? '';
			if ( $body && false === strpos( $body, '{reschedule_link}' ) && false !== strpos( $body, 'Need to cancel? {cancel_link}' ) ) {
				$saved['email_booker_confirm_body'] = str_replace( 'Need to cancel? {cancel_link}', "Need to change something?\nPick a new time: {reschedule_link}\nCancel: {cancel_link}", $body );
				update_option( Settings::OPTION, $saved );
			}
		}

		if ( $installed > 0 && $installed < 4 ) {
			self::rename_post_type();
			self::secure_settings();
		}

		update_option( 'nemvo_booking_db_version', self::DB_VERSION );
	}

	/**
	 * Version 4: booking pages move from the post type nb_booking_page to nemvo_booking_page.
	 * Post IDs stay the same, so blocks and shortcodes on existing pages keep working. Screen
	 * options saved per user (closed boxes, box order, hidden columns) are carried over too.
	 */
	private static function rename_post_type() {
		global $wpdb;
		$old = Pages::LEGACY_CPT;
		$new = Pages::CPT;

		// One-time migration of the plugin's own posts; WordPress has no API to change a post type in bulk.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $ids ) {
			$wpdb->update( $wpdb->posts, array( 'post_type' => $new ), array( 'post_type' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $ids as $id ) {
				clean_post_cache( (int) $id );
			}
		}

		// Meta box IDs and list columns were renamed in the same version.
		$renames = array(
			'nb-hosts'        => 'nemvo-booking-hosts',
			'nb-scheduling'   => 'nemvo-booking-scheduling',
			'nb-availability' => 'nemvo-booking-availability',
			'nb-form'         => 'nemvo-booking-form',
			'nb-after'        => 'nemvo-booking-after',
			'nb-embed'        => 'nemvo-booking-embed',
			'nb_hosts'        => 'nemvo_booking_hosts',
			'nb_duration'     => 'nemvo_booking_duration',
			'nb_upcoming'     => 'nemvo_booking_upcoming',
			'nb_shown_on'     => 'nemvo_booking_shown_on',
			'nb_code'         => 'nemvo_booking_code',
		);
		$keys    = array(
			"closedpostboxes_{$old}"         => "closedpostboxes_{$new}",
			"metaboxhidden_{$old}"           => "metaboxhidden_{$new}",
			"meta-box-order_{$old}"          => "meta-box-order_{$new}",
			"screen_layout_{$old}"           => "screen_layout_{$new}",
			"manageedit-{$old}columnshidden" => "manageedit-{$new}columnshidden",
			"edit_{$old}_per_page"           => "edit_{$new}_per_page",
		);
		foreach ( $keys as $old_key => $new_key ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $old_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $rows as $row ) {
				$value = maybe_unserialize( $row->meta_value );
				if ( is_array( $value ) ) {
					foreach ( $value as $index => $item ) {
						$value[ $index ] = is_string( $item ) ? strtr( $item, $renames ) : $item;
					}
				}
				update_user_meta( (int) $row->user_id, $new_key, $value );
				delete_user_meta( (int) $row->user_id, $old_key );
			}
		}
	}

	/**
	 * Version 4: the Google client secret and the Cap secret key are stored encrypted (see
	 * Settings::encrypt_secrets()), and the removed "Widget script URL" setting is dropped.
	 * Saving the option once encrypts values that were stored in plain text.
	 */
	private static function secure_settings() {
		$saved = get_option( Settings::OPTION );
		if ( ! is_array( $saved ) ) {
			return;
		}
		unset( $saved['cap_script_url'] );
		update_option( Settings::OPTION, Settings::encrypt_secrets( $saved ) );
	}

	/**
	 * Creates or updates the plugin's tables with dbDelta().
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset   = $wpdb->get_charset_collate();
		$employees = Plugin::table( 'employees' );
		$bookings  = Plugin::table( 'bookings' );
		$log       = Plugin::table( 'log' );
		$accounts  = Plugin::table( 'accounts' );

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

		dbDelta(
			"CREATE TABLE {$accounts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			employee_id bigint(20) unsigned NOT NULL DEFAULT 0,
			google_email varchar(191) NOT NULL DEFAULT '',
			refresh_token text NULL,
			access_token text NULL,
			token_expires bigint(20) NOT NULL DEFAULT 0,
			calendars longtext NULL,
			busy_calendars longtext NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY employee_id (employee_id)
			) {$charset};"
		);
	}
}
