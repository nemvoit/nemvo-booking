<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

class Plugin {

	public static function init() {
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

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'nemvo_booking_' . $name;
	}

	/** Current UTC time as a MySQL datetime string. */
	public static function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function to_mysql( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	public static function from_mysql( string $datetime ): int {
		return (int) strtotime( $datetime . ' UTC' );
	}

	public static function timezone( string $name ): \DateTimeZone {
		try {
			return new \DateTimeZone( $name ?: wp_timezone_string() );
		} catch ( \Exception $e ) {
			return wp_timezone();
		}
	}

	public static function is_valid_timezone( string $name ): bool {
		return '' !== $name && in_array( $name, timezone_identifiers_list(), true );
	}

	public static function time_format(): string {
		return '12' === Settings::get( 'time_format' ) ? 'g:i A' : 'H:i';
	}

	public static function date_format(): string {
		return 'l, j F Y';
	}

	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'nemvo_booking_client_ip', $ip );
	}
}
