<?php
/**
 * Extra Google accounts of an employee, checked for conflicts only.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

// The accounts live in the plugin's own table, which has no WordPress API.
// Queries are prepared; an employee's accounts are cached in the class for the request.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * An employee can connect more Google accounts than their main one, for example a personal Gmail
 * next to a work account. Busy times in those accounts' calendars block bookings; meetings are
 * always created with the main account. These accounts are only granted free/busy and the
 * calendar list. Account arrays have an employee_id, which employee rows don't, so the Google
 * client can tell the two apart.
 */
class Accounts {

	/**
	 * Scopes asked for: when the account is busy, and which calendars it has.
	 */
	const SCOPES = 'https://www.googleapis.com/auth/calendar.freebusy https://www.googleapis.com/auth/calendar.calendarlist.readonly';

	/**
	 * Accounts per employee ID, read in this request.
	 *
	 * @var array[]
	 */
	private static $cache = array();

	/**
	 * An employee's extra accounts, oldest first.
	 *
	 * @param int $employee_id Employee ID.
	 * @return array[]
	 */
	public static function for_employee( int $employee_id ): array {
		global $wpdb;
		if ( ! isset( self::$cache[ $employee_id ] ) ) {
			$rows                        = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE employee_id = %d ORDER BY id ASC', Plugin::table( 'accounts' ), $employee_id ), ARRAY_A );
			self::$cache[ $employee_id ] = array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
		}
		return self::$cache[ $employee_id ];
	}

	/**
	 * An account by ID.
	 *
	 * @param int $id Account ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Plugin::table( 'accounts' ), $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Converts a database row: numbers and decoded calendar lists.
	 *
	 * @param array $row Database row.
	 * @return array Account.
	 */
	private static function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['employee_id']    = (int) $row['employee_id'];
		$row['calendars']      = Plugin::fallback( json_decode( (string) $row['calendars'], true ), array() );
		$row['busy_calendars'] = Plugin::fallback( json_decode( (string) $row['busy_calendars'], true ), array() );
		return $row;
	}

	/**
	 * Whether a connection array is an extra account (and not an employee).
	 *
	 * @param array $connection Employee or account.
	 * @return bool
	 */
	public static function is_account( array $connection ): bool {
		return isset( $connection['employee_id'] );
	}

	/**
	 * Adds an account.
	 *
	 * @param int   $employee_id Employee ID.
	 * @param array $data        Column => value.
	 * @return int New account ID.
	 */
	public static function create( int $employee_id, array $data ): int {
		global $wpdb;
		$wpdb->insert(
			Plugin::table( 'accounts' ),
			array(
				'employee_id' => $employee_id,
				'created_at'  => Plugin::now_mysql(),
			) + $data
		);
		self::$cache = array();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates columns of an account. Calendar lists may be given as arrays.
	 *
	 * @param int   $id   Account ID.
	 * @param array $data Column => value.
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;
		foreach ( array( 'calendars', 'busy_calendars' ) as $json ) {
			if ( isset( $data[ $json ] ) && is_array( $data[ $json ] ) ) {
				$data[ $json ] = wp_json_encode( array_values( $data[ $json ] ) );
			}
		}
		$wpdb->update( Plugin::table( 'accounts' ), $data, array( 'id' => $id ) );
		self::$cache = array();
	}

	/**
	 * Deletes an account row without contacting Google.
	 *
	 * @param int $id Account ID.
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$wpdb->delete( Plugin::table( 'accounts' ), array( 'id' => $id ) );
		self::$cache = array();
	}

	/**
	 * Revokes the site's access to the account (unless the same Google account is still
	 * connected elsewhere on the site) and forgets it.
	 *
	 * @param array $account Account.
	 */
	public static function remove( array $account ) {
		if ( ! self::used_elsewhere( (string) $account['google_email'], 0, (int) $account['id'] ) ) {
			Google::revoke( $account );
		}
		self::delete( (int) $account['id'] );
	}

	/**
	 * Removes all extra accounts of an employee.
	 *
	 * @param int $employee_id Employee ID.
	 */
	public static function remove_all( int $employee_id ) {
		foreach ( self::for_employee( $employee_id ) as $account ) {
			self::remove( $account );
		}
	}

	/**
	 * Whether a Google account is connected anywhere else on the site. Google revokes the whole
	 * grant for the account and this app, so revoking it would end those connections too.
	 *
	 * @param string $email            Google account email.
	 * @param int    $except_employee  Employee whose main connection to ignore.
	 * @param int    $except_account   Extra account to ignore.
	 * @return bool
	 */
	public static function used_elsewhere( string $email, int $except_employee = 0, int $except_account = 0 ): bool {
		global $wpdb;
		if ( '' === $email ) {
			return false;
		}
		$employees = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE google_email = %s AND refresh_token <> '' AND id <> %d", Plugin::table( 'employees' ), $email, $except_employee ) );
		$accounts  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE google_email = %s AND refresh_token <> '' AND id <> %d", Plugin::table( 'accounts' ), $email, $except_account ) );
		return $employees + $accounts > 0;
	}

	/**
	 * Calendar IDs checked for conflicts. Defaults to the primary calendar. Calendars Google's
	 * free/busy can't read (such as public holidays) are left out.
	 *
	 * @param array $account Account.
	 * @return string[]
	 */
	public static function busy_calendars( array $account ): array {
		$ids = array_values( array_filter( (array) $account['busy_calendars'], array( Google::class, 'has_busy_times' ) ) );
		return $ids ? $ids : array( 'primary' );
	}

	/**
	 * Reads the account's calendar list from Google and keeps the conflict calendars valid.
	 * The first time, every calendar the account owns is checked.
	 *
	 * @param array $account Account.
	 * @return true|\WP_Error
	 */
	public static function refresh_calendars( array $account ) {
		$calendars = Google::calendar_list( $account );
		if ( is_wp_error( $calendars ) ) {
			return $calendars;
		}

		$data = array( 'calendars' => $calendars );
		foreach ( $calendars as $cal ) {
			if ( $cal['primary'] ) {
				$data['google_email'] = $cal['id'];
			}
		}

		$ids  = wp_list_pluck( $calendars, 'id' );
		$busy = array_values( array_intersect( $account['busy_calendars'], $ids ) );
		if ( ! $busy ) {
			foreach ( $calendars as $cal ) {
				if ( 'owner' === $cal['role'] ) {
					$busy[] = $cal['id'];
				}
			}
		}
		$data['busy_calendars'] = $busy;

		self::update( (int) $account['id'], $data );
		return true;
	}

	/**
	 * A string that changes whenever the employee's extra accounts or their checked calendars
	 * change, for cache keys.
	 *
	 * @param int $employee_id Employee ID.
	 * @return string
	 */
	public static function signature( int $employee_id ): string {
		$parts = array();
		foreach ( self::for_employee( $employee_id ) as $account ) {
			$parts[] = $account['id'] . ':' . implode( ',', self::busy_calendars( $account ) );
		}
		return implode( ';', $parts );
	}

	/**
	 * Name for messages: the employee's name and the account's address.
	 *
	 * @param array $account  Account.
	 * @param array $employee Employee.
	 * @return string
	 */
	public static function label( array $account, array $employee ): string {
		return sprintf( '%1$s (%2$s)', $employee['name'], Plugin::fallback( $account['google_email'], '?' ) );
	}

	/**
	 * Records a Google problem with an extra account, alerting once when a working account breaks.
	 * Like a broken main connection, it leaves the employee without free times until it is fixed.
	 *
	 * @param array     $account Account.
	 * @param \WP_Error $error   Error from Google.
	 */
	public static function report_problem( array $account, \WP_Error $error ) {
		$status = (int) ( $error->get_error_data()['status'] ?? 0 );
		if ( ! in_array( $status, array( 400, 401, 403, 404 ), true ) ) {
			return;
		}
		$current  = self::get( (int) $account['id'] );
		$employee = $current ? Employees::get( $current['employee_id'] ) : null;
		if ( ! $current || ! $employee || '' === (string) $current['refresh_token'] ) {
			return;
		}
		$message = $error->get_error_message();
		self::update( $current['id'], array( 'last_error' => $message ) );
		if ( '' !== (string) $current['last_error'] ) {
			return; // Already reported.
		}
		Log::error(
			'google.account_broken',
			sprintf( 'Google account %1$s of %2$s stopped working: %3$s', $current['google_email'], $employee['name'], $message ),
			array( 'employee_id' => $employee['id'] )
		);
		Employees::send_alert( $employee, $message, $current );
	}

	/**
	 * Clears a recorded problem after a successful Google request.
	 *
	 * @param array $account Account.
	 */
	public static function report_ok( array $account ) {
		if ( '' === (string) ( $account['last_error'] ?? '' ) ) {
			return;
		}
		self::update( (int) $account['id'], array( 'last_error' => '' ) );
		Log::info( 'google.account_restored', sprintf( 'Google account %s works again.', $account['google_email'] ), array( 'employee_id' => $account['employee_id'] ) );
	}
}
