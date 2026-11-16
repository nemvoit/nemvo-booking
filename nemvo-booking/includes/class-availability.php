<?php
/**
 * Availability calculation.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

// Bookings, employees and the log live in the plugin's own tables, which have no WordPress API.
// Queries are prepared; results that are read repeatedly in a request are cached in the class.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Computes bookable slots from weekly hours, holidays, Google busy times and existing bookings.
 */
class Availability {

	/**
	 * Confirmed bookings per time range, cached for the current request.
	 *
	 * @var array
	 */
	private static $local = array();

	/**
	 * The time range in which a meeting may start: from the minimum notice to the last bookable day.
	 *
	 * @param array $cfg Booking page configuration.
	 * @return int[] [earliest, latest) as Unix timestamps.
	 */
	public static function window( array $cfg ): array {
		$tz       = Plugin::timezone( $cfg['timezone'] );
		$earliest = time() + (int) $cfg['min_notice'] * HOUR_IN_SECONDS;
		$latest   = ( new \DateTimeImmutable( 'today', $tz ) )->modify( '+' . ( (int) $cfg['max_days'] + 1 ) . ' days' )->getTimestamp();
		return array( $earliest, $latest );
	}

	/**
	 * Bookable start times in a range: inside the weekly hours, not on a closed day or holiday,
	 * and free for every host including buffers.
	 *
	 * @param array $cfg      Booking page configuration.
	 * @param int   $duration Meeting length in minutes.
	 * @param int   $from     Range start (Unix timestamp).
	 * @param int   $to       Range end (Unix timestamp, exclusive).
	 * @param bool  $fresh    True to ask Google again instead of using the short free/busy cache.
	 * @param int   $exclude  Booking ID to ignore (the booking being rescheduled).
	 * @return int[] Bookable start times, sorted.
	 */
	public static function slots( array $cfg, int $duration, int $from, int $to, bool $fresh = false, int $exclude = 0 ): array {
		list( $earliest, $latest ) = self::window( $cfg );
		$from                      = max( $from, $earliest );
		$to                        = min( $to, $latest );
		if ( $from >= $to || ! Pages::is_bookable( $cfg ) ) {
			return array();
		}

		$len    = $duration * MINUTE_IN_SECONDS;
		$before = (int) $cfg['buffer_before'] * MINUTE_IN_SECONDS;
		$after  = (int) $cfg['buffer_after'] * MINUTE_IN_SECONDS;

		// Every host attends, so a time is only offered when all of them are free.
		$busy = array();
		foreach ( Pages::hosts( $cfg ) as $host ) {
			$intervals = self::busy( $host, $from - $before, $to + $len + $after, $fresh, $exclude );
			if ( null === $intervals ) {
				return array();
			}
			$busy[ $host['id'] ] = $intervals;
		}

		$tz   = Plugin::timezone( $cfg['timezone'] );
		$day  = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( $tz )->setTime( 0, 0 );
		$last = ( new \DateTimeImmutable( '@' . $to ) )->setTimezone( $tz )->format( 'Y-m-d' );
		$skip = Holidays::blocked_dates();
		if ( $cfg['exclude_holidays'] ) {
			$skip += Holidays::public_holidays( $day->format( 'Y-m-d' ), $last );
		}

		$slots = array();
		$end   = ( new \DateTimeImmutable( $last . ' 00:00', $tz ) )->modify( '+1 day' );
		foreach ( new \DatePeriod( $day, new \DateInterval( 'P1D' ), $end ) as $day ) {
			if ( isset( $skip[ $day->format( 'Y-m-d' ) ] ) ) {
				continue;
			}
			$weekday = strtolower( $day->format( 'D' ) );
			foreach ( Pages::parse_ranges( $cfg['availability'][ $weekday ] ?? '' ) as $range ) {
				for ( $m = $range[0]; $m + $duration <= $range[1]; $m += (int) $cfg['interval'] ) {
					$start = $day->setTime( intdiv( $m, 60 ), $m % 60 )->getTimestamp();
					if ( $start < $from || $start >= $to || isset( $slots[ $start ] ) ) {
						continue;
					}
					foreach ( $busy as $intervals ) {
						if ( ! self::is_free( $intervals, $start - $before, $start + $len + $after ) ) {
							continue 2;
						}
					}
					$slots[ $start ] = $start;
				}
			}
		}

		ksort( $slots );
		return array_values( $slots );
	}

	/**
	 * Whether an exact start time can be booked. Always checks Google live.
	 *
	 * @param array $cfg      Booking page configuration.
	 * @param int   $duration Meeting length in minutes.
	 * @param int   $start    Start time (Unix timestamp).
	 * @param int   $exclude  Booking ID to ignore (the booking being rescheduled).
	 * @return bool
	 */
	public static function is_available( array $cfg, int $duration, int $start, int $exclude = 0 ): bool {
		return in_array( $start, self::slots( $cfg, $duration, $start, $start + 1, true, $exclude ), true );
	}

	/**
	 * First bookable start at or after a time, searched in four-week chunks.
	 *
	 * @param array $cfg      Booking page configuration.
	 * @param int   $duration Meeting length in minutes.
	 * @param int   $from     Unix timestamp to search from.
	 * @return int|null Start time, or null when nothing is free within the booking window.
	 */
	public static function next_available( array $cfg, int $duration, int $from ): ?int {
		list( , $latest ) = self::window( $cfg );
		for ( $chunk = $from; $chunk < $latest; $chunk += 28 * DAY_IN_SECONDS ) {
			$slots = self::slots( $cfg, $duration, $chunk, $chunk + 28 * DAY_IN_SECONDS );
			if ( $slots ) {
				return $slots[0];
			}
		}
		return null;
	}

	/**
	 * Whether a time range overlaps none of the busy intervals.
	 *
	 * @param array $intervals Busy intervals as [start, end] timestamps.
	 * @param int   $start     Range start.
	 * @param int   $end       Range end.
	 * @return bool
	 */
	private static function is_free( array $intervals, int $start, int $end ): bool {
		foreach ( $intervals as $b ) {
			if ( $b[0] < $end && $b[1] > $start ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Busy intervals for a host: Google free/busy (cached for two minutes) plus confirmed bookings.
	 *
	 * @param array $host    Employee.
	 * @param int   $from    Range start (Unix timestamp).
	 * @param int   $to      Range end (Unix timestamp).
	 * @param bool  $fresh   True to skip the cache.
	 * @param int   $exclude Booking ID to ignore.
	 * @return array|null Intervals as [start, end], or null when the calendar could not be read; the host is then treated as unavailable.
	 */
	private static function busy( array $host, int $from, int $to, bool $fresh, int $exclude ): ?array {
		// Round to whole hours so the cache is reused between requests.
		$from = $from - ( $from % HOUR_IN_SECONDS );
		$to   = $to + ( HOUR_IN_SECONDS - $to % HOUR_IN_SECONDS );
		$key  = 'nemvo_booking_fb_' . md5( $host['id'] . '|' . $from . '|' . $to . '|' . implode( ',', Employees::busy_calendars( $host ) ) );

		$google = $fresh ? false : get_transient( $key );
		if ( ! is_array( $google ) ) {
			$google = Google::free_busy( $host, $from, $to );
			if ( is_wp_error( $google ) ) {
				if ( ! get_transient( 'nemvo_booking_fb_err_' . $host['id'] ) ) {
					set_transient( 'nemvo_booking_fb_err_' . $host['id'], 1, 15 * MINUTE_IN_SECONDS );
					Log::error(
						'google.freebusy_failed',
						sprintf( 'Could not read calendar of %s: %s', $host['name'], $google->get_error_message() ),
						array( 'employee_id' => $host['id'] )
					);
				}
				return null;
			}
			set_transient( $key, $google, 2 * MINUTE_IN_SECONDS );
		}

		return array_merge( $google, self::local_busy( $host['id'], $from, $to, $exclude ) );
	}

	/**
	 * Confirmed bookings of a host that overlap a range.
	 *
	 * @param int $employee_id Employee ID.
	 * @param int $from        Range start (Unix timestamp).
	 * @param int $to          Range end (Unix timestamp).
	 * @param int $exclude     Booking ID to ignore.
	 * @return array Intervals as [start, end].
	 */
	private static function local_busy( int $employee_id, int $from, int $to, int $exclude ): array {
		global $wpdb;
		$cache_key = "{$from}|{$to}";
		if ( ! isset( self::$local[ $cache_key ] ) ) {
			$table                     = Plugin::table( 'bookings' );
			$rows                      = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, start_utc, end_utc, employee_ids FROM %i WHERE status = 'confirmed' AND start_utc < %s AND end_utc > %s",
					$table,
					Plugin::to_mysql( $to ),
					Plugin::to_mysql( $from )
				),
				ARRAY_A
			);
			self::$local[ $cache_key ] = is_array( $rows ) ? $rows : array();
		}

		$busy = array();
		foreach ( self::$local[ $cache_key ] as $row ) {
			if ( (int) $row['id'] !== $exclude && in_array( $employee_id, array_map( 'intval', explode( ',', $row['employee_ids'] ) ), true ) ) {
				$busy[] = array( Plugin::from_mysql( $row['start_utc'] ), Plugin::from_mysql( $row['end_utc'] ) );
			}
		}
		return $busy;
	}

	/**
	 * Forgets the bookings cached during this request (after a booking was created or moved).
	 */
	public static function flush_local_cache() {
		self::$local = array();
	}
}
