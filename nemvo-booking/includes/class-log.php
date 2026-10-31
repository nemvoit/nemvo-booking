<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * History log stored in its own table and shown under Bookings → Log.
 */
class Log {

	public static function info( string $event, string $message, array $args = array() ) {
		self::add( 'info', $event, $message, $args );
	}

	public static function warning( string $event, string $message, array $args = array() ) {
		self::add( 'warning', $event, $message, $args );
	}

	public static function error( string $event, string $message, array $args = array() ) {
		self::add( 'error', $event, $message, $args );
	}

	/**
	 * @param array $args booking_id, employee_id, context (array).
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", array_merge( array( $table ), $vals ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( array( $table ), $vals, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array( $rows ?: array(), $total );
	}

	public static function cleanup() {
		global $wpdb;
		$days = (int) Settings::get( 'log_retention_days' );
		if ( $days > 0 ) {
			$table = Plugin::table( 'log' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, Plugin::to_mysql( time() - $days * DAY_IN_SECONDS ) ) );
		}
	}
}
