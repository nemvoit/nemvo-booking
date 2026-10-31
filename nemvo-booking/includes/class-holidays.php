<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Public holidays (from Google's public holiday calendars) and manually blocked dates.
 */
class Holidays {

	public static function calendars(): array {
		$suffix = '#holiday@group.v.calendar.google.com';
		$list   = array(
			'en.swedish'    => __( 'Sweden', 'nemvo-booking' ),
			'en.norwegian'  => __( 'Norway', 'nemvo-booking' ),
			'en.danish'     => __( 'Denmark', 'nemvo-booking' ),
			'en.finnish'    => __( 'Finland', 'nemvo-booking' ),
			'en.german'     => __( 'Germany', 'nemvo-booking' ),
			'en.dutch'      => __( 'Netherlands', 'nemvo-booking' ),
			'en.uk'         => __( 'United Kingdom', 'nemvo-booking' ),
			'en.irish'      => __( 'Ireland', 'nemvo-booking' ),
			'en.french'     => __( 'France', 'nemvo-booking' ),
			'en.spain'      => __( 'Spain', 'nemvo-booking' ),
			'en.italian'    => __( 'Italy', 'nemvo-booking' ),
			'en.polish'     => __( 'Poland', 'nemvo-booking' ),
			'en.austrian'   => __( 'Austria', 'nemvo-booking' ),
			'en.usa'        => __( 'United States', 'nemvo-booking' ),
			'en.canadian'   => __( 'Canada', 'nemvo-booking' ),
			'en.australian' => __( 'Australia', 'nemvo-booking' ),
		);
		$out    = array();
		foreach ( $list as $id => $label ) {
			$out[ $id . $suffix ] = $label;
		}
		return $out;
	}

	public static function default_calendar(): string {
		$map    = array(
			'sv' => 'en.swedish',
			'nb' => 'en.norwegian',
			'nn' => 'en.norwegian',
			'da' => 'en.danish',
			'fi' => 'en.finnish',
			'de' => 'en.german',
			'nl' => 'en.dutch',
			'fr' => 'en.french',
			'es' => 'en.spain',
			'it' => 'en.italian',
			'pl' => 'en.polish',
		);
		$locale = get_locale();
		$id     = $map[ substr( $locale, 0, 2 ) ] ?? ( 'en_GB' === $locale ? 'en.uk' : 'en.swedish' );
		return $id . '#holiday@group.v.calendar.google.com';
	}

	/** All selected holiday calendars: chosen countries plus any extra calendar IDs (one per line). */
	public static function calendar_ids(): array {
		$ids = (array) Settings::get( 'holiday_calendars' );
		foreach ( preg_split( '/[\s,]+/', (string) Settings::get( 'holiday_custom' ) ) as $id ) {
			$ids[] = $id;
		}
		return array_values( array_unique( array_filter( array_map( 'trim', $ids ) ) ) );
	}

	/** Manually blocked dates from settings, one YYYY-MM-DD (or YYYY-MM-DD - YYYY-MM-DD) per line. */
	public static function blocked_dates(): array {
		$dates = array();
		foreach ( preg_split( '/\R/', (string) Settings::get( 'blocked_dates' ) ) as $line ) {
			if ( ! preg_match( '/^\s*(\d{4}-\d{2}-\d{2})(?:\s*(?:-|–|to)\s*(\d{4}-\d{2}-\d{2}))?/', $line, $m ) ) {
				continue;
			}
			$from = strtotime( $m[1] . ' 00:00 UTC' );
			$to   = isset( $m[2] ) ? strtotime( $m[2] . ' 00:00 UTC' ) : $from;
			for ( $t = $from, $n = 0; $t <= $to && $n < 366; $t += DAY_IN_SECONDS, $n++ ) {
				$dates[ gmdate( 'Y-m-d', $t ) ] = __( 'Blocked', 'nemvo-booking' );
			}
		}
		return $dates;
	}

	/**
	 * Public holidays between two dates (inclusive), as [Y-m-d => name].
	 */
	public static function public_holidays( string $from, string $to ): array {
		$out = array();
		foreach ( self::calendar_ids() as $calendar ) {
			for ( $year = (int) substr( $from, 0, 4 ); $year <= (int) substr( $to, 0, 4 ); $year++ ) {
				foreach ( self::year( $calendar, $year ) as $date => $name ) {
					if ( $date < $from || $date > $to ) {
						continue;
					}
					// The same day can be a holiday in several countries.
					$out[ $date ] = isset( $out[ $date ] ) && false === strpos( $out[ $date ], $name ) ? $out[ $date ] . ' / ' . $name : $name;
				}
			}
		}
		return $out;
	}

	private static function year( string $calendar, int $year ): array {
		$include_observances = (bool) Settings::get( 'include_observances' );
		$key                 = 'nemvo_booking_hol_' . md5( $calendar . $year . (int) $include_observances );
		$cached              = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$employee = null;
		foreach ( Employees::all() as $candidate ) {
			if ( Employees::is_connected( $candidate ) ) {
				$employee = $candidate;
				break;
			}
		}
		if ( ! $employee ) {
			return array();
		}

		$events = Google::list_events( $employee, $calendar, (int) strtotime( "{$year}-01-01 00:00 UTC" ), (int) strtotime( ( $year + 1 ) . '-01-01 00:00 UTC' ) );
		if ( is_wp_error( $events ) ) {
			Log::warning( 'holidays.error', sprintf( 'Could not load holidays from %s: %s', $calendar, $events->get_error_message() ) );
			set_transient( $key, array(), HOUR_IN_SECONDS );
			return array();
		}

		$dates = array();
		foreach ( $events as $event ) {
			$description = (string) ( $event['description'] ?? '' );
			if ( ! $include_observances && 0 === stripos( $description, 'Observance' ) ) {
				continue;
			}
			if ( empty( $event['start']['date'] ) ) {
				continue;
			}
			// All-day event end dates are exclusive.
			$first = (int) strtotime( $event['start']['date'] . ' UTC' );
			$last  = max( $first, (int) strtotime( ( $event['end']['date'] ?? $event['start']['date'] ) . ' UTC' ) - DAY_IN_SECONDS );
			for ( $t = $first; $t <= $last; $t += DAY_IN_SECONDS ) {
				$dates[ gmdate( 'Y-m-d', $t ) ] = (string) ( $event['summary'] ?? '' );
			}
		}
		set_transient( $key, $dates, DAY_IN_SECONDS );
		return $dates;
	}
}
