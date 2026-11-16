<?php
/**
 * History log.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

// Bookings, employees and the log live in the plugin's own tables, which have no WordPress API.
// Queries are prepared; results that are read repeatedly in a request are cached in the class.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * History log stored in its own table and shown under Bookings → Log.
 */
class Log {

	/**
	 * Adds an info entry.
	 *
	 * @param string $event   Event name, e.g. booking.created.
	 * @param string $message Text shown in the log.
	 * @param array  $args    Optional booking_id, employee_id and context (array).
	 */
	public static function info( string $event, string $message, array $args = array() ) {
		self::add( 'info', $event, $message, $args );
	}

	/**
	 * Adds a warning entry.
	 *
	 * @param string $event   Event name.
	 * @param string $message Text shown in the log.
	 * @param array  $args    Optional booking_id, employee_id and context (array).
	 */
	public static function warning( string $event, string $message, array $args = array() ) {
		self::add( 'warning', $event, $message, $args );
	}

	/**
	 * Adds an error entry.
	 *
	 * @param string $event   Event name.
	 * @param string $message Text shown in the log.
	 * @param array  $args    Optional booking_id, employee_id and context (array).
	 */
	public static function error( string $event, string $message, array $args = array() ) {
		self::add( 'error', $event, $message, $args );
	}

	/**
	 * Adds an entry to the history log.
	 *
	 * @param string $level   info, warning or error.
	 * @param string $event   Event name.
	 * @param string $message Text shown in the log.
	 * @param array  $args    Optional booking_id, employee_id and context (array).
	 */
	public static function add( string $level, string $event, string $message, array $args = array() ) {
		global $wpdb;
		$wpdb->insert(
			Plugin::table( 'log' ),
			array(
				'created_at'  => Plugin::now_mysql(),
				'level'       => $level,
				'event'       => $event,
				'message'     => $message,
				'booking_id'  => (int) ( $args['booking_id'] ?? 0 ),
				'employee_id' => (int) ( $args['employee_id'] ?? 0 ),
				'user_id'     => get_current_user_id(),
				'context'     => empty( $args['context'] ) ? null : wp_json_encode( $args['context'] ),
			)
		);
	}

	/**
	 * Log entries for the History log screen, newest first.
	 *
	 * @param array $args Optional level, booking_id, search, per_page and page.
	 * @return array{0: array, 1: int} Rows and total count.
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$table = Plugin::table( 'log' );
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['level'] ) ) {
			$where[] = 'level = %s';
			$vals[]  = $args['level'];
		}
		if ( ! empty( $args['booking_id'] ) ) {
			$where[] = 'booking_id = %d';
			$vals[]  = (int) $args['booking_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(message LIKE %s OR event LIKE %s)';
			$vals[]  = $like;
			$vals[]  = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = (int) ( $args['per_page'] ?? 50 );
		$offset    = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );

		// $where_sql is built above from fixed strings; every value is a placeholder.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", array_merge( array( $table ), $vals ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( array( $table ), $vals, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array( $rows ? $rows : array(), $total );
	}

	/**
	 * Deletes entries older than the "Keep history log for" setting (daily job).
	 */
	public static function cleanup() {
		global $wpdb;
		$days = (int) Settings::get( 'log_retention_days' );
		if ( $days > 0 ) {
			$table = Plugin::table( 'log' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, Plugin::to_mysql( time() - $days * DAY_IN_SECONDS ) ) );
		}
	}
}
