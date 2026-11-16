<?php
/**
 * Plugin bootstrap and shared helpers.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap and small helpers shared by the other classes.
 */
class Plugin {

	/**
	 * Starts the plugin on plugins_loaded: upgrades the database if needed and hooks in every part.
	 */
	public static function init() {
		Settings::init();
		Install::maybe_upgrade();
		Pages::init();
		Google::init();
		Rest::init();
		Frontend::init();
		Privacy::init();

		add_action( 'nemvo_booking_daily', array( Log::class, 'cleanup' ) );
		add_action( 'nemvo_booking_daily', array( Employees::class, 'health_check' ) );

		if ( is_admin() ) {
			Admin\Admin::init();
		}
	}

	/**
	 * Full name of one of the plugin's tables.
	 *
	 * @param string $name Short name: employees, bookings or log.
	 * @return string Table name with the site's prefix.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'nemvo_booking_' . $name;
	}

	/**
	 * Current UTC time as a MySQL datetime string.
	 *
	 * @return string Y-m-d H:i:s in UTC.
	 */
	public static function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * A Unix timestamp as a MySQL datetime string in UTC.
	 *
	 * @param int $ts Unix timestamp.
	 * @return string Y-m-d H:i:s in UTC.
	 */
	public static function to_mysql( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * A MySQL datetime string in UTC as a Unix timestamp.
	 *
	 * @param string $datetime Y-m-d H:i:s in UTC.
	 * @return int Unix timestamp.
	 */
	public static function from_mysql( string $datetime ): int {
		return (int) strtotime( $datetime . ' UTC' );
	}

	/**
	 * A time zone object, falling back to the site's time zone for empty or unknown names.
	 *
	 * @param string $name IANA time zone name, e.g. Europe/Stockholm.
	 * @return \DateTimeZone
	 */
	public static function timezone( string $name ): \DateTimeZone {
		try {
			return new \DateTimeZone( $name ? $name : wp_timezone_string() );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	/**
	 * Whether a name is a known IANA time zone.
	 *
	 * @param string $name Time zone name.
	 * @return bool
	 */
	public static function is_valid_timezone( string $name ): bool {
		return '' !== $name && in_array( $name, timezone_identifiers_list(), true );
	}

	/**
	 * PHP time format for times shown to visitors, from the Time format setting.
	 *
	 * @return string "g:i A" or "H:i".
	 */
	public static function time_format(): string {
		return '12' === Settings::get( 'time_format' ) ? 'g:i A' : 'H:i';
	}

	/**
	 * PHP date format for dates in emails and summaries.
	 *
	 * @return string Date format.
	 */
	public static function date_format(): string {
		return 'l, j F Y';
	}

	/**
	 * The value, or the fallback when the value is empty (what PHP's short ternary does).
	 *
	 * @param mixed $value    Value to use when it isn't empty.
	 * @param mixed $fallback Value to use otherwise.
	 * @return mixed
	 */
	public static function fallback( $value, $fallback ) {
		return $value ? $value : $fallback;
	}

	/**
	 * The visitor's IP address: REMOTE_ADDR, which a site behind a proxy can replace with the
	 * nemvo_booking_client_ip filter. Anything that isn't an IP address becomes ''.
	 *
	 * @return string IPv4 or IPv6 address, or an empty string.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = trim( (string) apply_filters( 'nemvo_booking_client_ip', $ip ) );
		return false !== rest_is_ip_address( $ip ) ? $ip : '';
	}

	/**
	 * Capability needed to manage bookings, employees, booking pages and settings.
	 * Defaults to manage_options; the nemvo_booking_capability filter can change it.
	 *
	 * @return string Capability name.
	 */
	public static function capability(): string {
		$capability = apply_filters( 'nemvo_booking_capability', 'manage_options' );
		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}
}
