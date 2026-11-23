<?php
/**
 * Employees.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

// Bookings, employees and the log live in the plugin's own tables, which have no WordPress API.
// Queries are prepared; results that are read repeatedly in a request are cached in the class.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Employees (hosts) and their Google Calendar connection.
 */
class Employees {

	/**
	 * All employees, by name.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		global $wpdb;
		$table = Plugin::table( 'employees' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', $table ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * An employee by ID.
	 *
	 * @param int $id Employee ID.
	 * @return array|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Plugin::table( 'employees' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Employees for the given IDs, in the given order, skipping missing ones.
	 *
	 * @param int[] $ids Employee IDs.
	 * @return array[]
	 */
	public static function many( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$employee = self::get( (int) $id );
			if ( $employee ) {
				$out[] = $employee;
			}
		}
		return $out;
	}

	/**
	 * Converts a database row: numbers and decoded calendar lists.
	 *
	 * @param array $row Database row.
	 * @return array Employee.
	 */
	private static function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['photo_id']       = (int) ( $row['photo_id'] ?? 0 );
		$row['calendars']      = Plugin::fallback( json_decode( (string) $row['calendars'], true ), array() );
		$row['busy_calendars'] = Plugin::fallback( json_decode( (string) $row['busy_calendars'], true ), array() );
		return $row;
	}

	/**
	 * Adds an employee.
	 *
	 * @param string $name  Name shown to visitors.
	 * @param string $email Email for notifications.
	 * @return int New employee ID.
	 */
	public static function create( string $name, string $email ): int {
		global $wpdb;
		$wpdb->insert(
			Plugin::table( 'employees' ),
			array(
				'name'       => $name,
				'email'      => $email,
				'created_at' => Plugin::now_mysql(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates columns of an employee. Calendar lists may be given as arrays.
	 *
	 * @param int   $id   Employee ID.
	 * @param array $data Column => value.
	 */
	public static function update( int $id, array $data ) {
		global $wpdb;
		foreach ( array( 'calendars', 'busy_calendars' ) as $json ) {
			if ( isset( $data[ $json ] ) && is_array( $data[ $json ] ) ) {
				$data[ $json ] = wp_json_encode( array_values( $data[ $json ] ) );
			}
		}
		$wpdb->update( Plugin::table( 'employees' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Deletes an employee.
	 *
	 * @param int $id Employee ID.
	 */
	public static function delete( int $id ) {
		global $wpdb;
		$wpdb->delete( Plugin::table( 'employees' ), array( 'id' => $id ) );
	}

	/**
	 * Whether the employee has connected a Google Calendar.
	 *
	 * @param array $employee Employee.
	 * @return bool
	 */
	public static function is_connected( array $employee ): bool {
		return '' !== (string) $employee['refresh_token'];
	}

	/**
	 * Revokes the Google access (unless the same Google account is still connected elsewhere on
	 * the site) and forgets the tokens. The employee's extra accounts stay.
	 *
	 * @param array $employee Employee.
	 */
	public static function disconnect( array $employee ) {
		if ( ! Accounts::used_elsewhere( (string) $employee['google_email'], (int) $employee['id'] ) ) {
			Google::revoke( $employee );
		}
		self::update(
			$employee['id'],
			array(
				'refresh_token' => '',
				'access_token'  => '',
				'token_expires' => 0,
				'google_email'  => '',
				'last_error'    => '',
			)
		);
	}

	/**
	 * Calendar IDs checked for conflicts. Defaults to the primary calendar. Calendars Google's
	 * free/busy can't read (such as public holidays) are left out.
	 *
	 * @param array $employee Employee.
	 * @return string[]
	 */
	public static function busy_calendars( array $employee ): array {
		$ids = array_values( array_filter( (array) $employee['busy_calendars'], array( Google::class, 'has_busy_times' ) ) );
		return $ids ? $ids : array( 'primary' );
	}

	/**
	 * Calendar where new meetings are created.
	 *
	 * @param array $employee Employee.
	 * @return string Calendar ID.
	 */
	public static function target_calendar( array $employee ): string {
		return $employee['target_calendar'] ? $employee['target_calendar'] : 'primary';
	}

	/**
	 * Email used as organizer/attendee address for this employee.
	 *
	 * @param array $employee Employee.
	 * @return string
	 */
	public static function calendar_email( array $employee ): string {
		return $employee['google_email'] ? $employee['google_email'] : $employee['email'];
	}

	/**
	 * Reads the employee's calendar list from Google and keeps the conflict and target calendars valid.
	 * On the first connection every calendar the employee owns is checked for conflicts.
	 *
	 * @param array $employee Employee.
	 * @return true|\WP_Error
	 */
	public static function refresh_calendars( array $employee ) {
		$calendars = Google::calendar_list( $employee );
		if ( is_wp_error( $calendars ) ) {
			return $calendars;
		}

		$data = array( 'calendars' => $calendars );
		foreach ( $calendars as $cal ) {
			if ( $cal['primary'] ) {
				$data['google_email'] = $cal['id'];
			}
		}

		// First connection: check all calendars the employee owns for conflicts.
		$ids  = wp_list_pluck( $calendars, 'id' );
		$busy = array_values( array_intersect( $employee['busy_calendars'], $ids ) );
		if ( ! $busy ) {
			foreach ( $calendars as $cal ) {
				if ( 'owner' === $cal['role'] ) {
					$busy[] = $cal['id'];
				}
			}
		}
		$data['busy_calendars'] = $busy;

		if ( $employee['target_calendar'] && ! in_array( $employee['target_calendar'], $ids, true ) ) {
			$data['target_calendar'] = '';
		}

		self::update( $employee['id'], $data );
		return true;
	}

	/**
	 * Creates a link the employee can open to connect their own Google account. Valid for 7 days.
	 *
	 * @param array $employee Employee.
	 * @param bool  $extra    True for a link that adds another Google account, checked for conflicts only.
	 * @param bool  $fresh    False to reuse a current link that is valid for at least another day
	 *                        (alerts do, so one alert doesn't break the link in another).
	 * @return string URL.
	 */
	public static function connect_link( array $employee, bool $extra = false, bool $fresh = true ): string {
		$token = (string) $employee['connect_token'];
		if ( $fresh || '' === $token || (int) $employee['connect_expires'] < time() + DAY_IN_SECONDS ) {
			$token = wp_generate_password( 32, false );
			self::update(
				$employee['id'],
				array(
					'connect_token'   => $token,
					'connect_expires' => time() + 7 * DAY_IN_SECONDS,
				)
			);
		}
		return self::link_url( $employee, $token, $extra );
	}

	/**
	 * The address of a connect link.
	 *
	 * @param array  $employee Employee.
	 * @param string $token    Connect token.
	 * @param bool   $extra    True for a link that adds another Google account.
	 * @return string URL.
	 */
	public static function link_url( array $employee, string $token, bool $extra = false ): string {
		return add_query_arg(
			array_filter(
				array(
					'action' => 'nemvo_booking_connect',
					'e'      => $employee['id'],
					't'      => $token,
					'add'    => $extra ? 1 : 0,
				)
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Whether a connect link token is the employee's current one and hasn't expired.
	 *
	 * @param array  $employee Employee.
	 * @param string $token Token from the link.
	 * @return bool
	 */
	public static function connect_token_valid( array $employee, string $token ): bool {
		return '' !== $token
			&& '' !== $employee['connect_token']
			&& (int) $employee['connect_expires'] > time()
			&& hash_equals( $employee['connect_token'], $token );
	}

	/**
	 * Booking page titles (published) that use this employee.
	 *
	 * @param array $employee Employee.
	 * @return string[]
	 */
	public static function page_titles( array $employee ): array {
		$titles = array();
		foreach ( Pages::published() as $id => $title ) {
			if ( in_array( (int) $employee['id'], Pages::config( $id )['employees'], true ) ) {
				$titles[] = $title;
			}
		}
		return $titles;
	}

	/**
	 * Records a Google problem. Network hiccups and Google outages fix themselves, so only
	 * authorization and configuration errors count, and people are alerted once when a
	 * working connection breaks.
	 *
	 * @param array     $employee Employee.
	 * @param \WP_Error $error Error from Google.
	 */
	public static function report_problem( array $employee, \WP_Error $error ) {
		$status = (int) ( $error->get_error_data()['status'] ?? 0 );
		if ( ! in_array( $status, array( 400, 401, 403, 404 ), true ) ) {
			return;
		}
		$current = self::get( (int) $employee['id'] );
		if ( ! $current || ! self::is_connected( $current ) ) {
			return;
		}
		$message = $error->get_error_message();
		self::update( $current['id'], array( 'last_error' => $message ) );
		if ( '' !== (string) $current['last_error'] ) {
			return; // Already reported.
		}
		Log::error(
			'google.connection_broken',
			sprintf( 'Google Calendar connection for %1$s stopped working: %2$s', $current['name'], $message ),
			array( 'employee_id' => $current['id'] )
		);
		self::send_alert( $current, $message );
	}

	/**
	 * Clears a recorded problem after a successful Google request.
	 *
	 * @param array $employee Employee.
	 */
	public static function report_ok( array $employee ) {
		if ( '' === (string) ( $employee['last_error'] ?? '' ) ) {
			return;
		}
		self::update( (int) $employee['id'], array( 'last_error' => '' ) );
		Log::info( 'google.connection_restored', sprintf( 'Google Calendar connection for %s works again.', $employee['name'] ), array( 'employee_id' => $employee['id'] ) );
	}

	/**
	 * Tells the admin (and the employee) how to fix a broken connection.
	 *
	 * @param array      $employee Employee.
	 * @param string     $error    Error message from Google.
	 * @param array|null $account  The extra account that broke, or null for the main connection.
	 */
	public static function send_alert( array $employee, string $error, ?array $account = null ) {
		$pages   = self::page_titles( $employee );
		$link    = self::connect_link( $employee, null !== $account, false );
		$who     = $account ? Accounts::label( $account, $employee ) : $employee['name'];
		$subject = sprintf(
			/* translators: 1: site name, 2: employee name */
			__( '[%1$s] Google Calendar connection for %2$s stopped working', 'nemvo-booking' ),
			get_bloginfo( 'name' ),
			$who
		);
		$html = '<p>' . sprintf(
			/* translators: %s: employee name */
			esc_html__( 'The Google Calendar connection for %s has stopped working.', 'nemvo-booking' ),
			'<strong>' . esc_html( $who ) . '</strong>'
		) . '</p>';
		if ( $pages ) {
			$html .= '<p>' . esc_html__( 'These booking pages show no available times until it is fixed:', 'nemvo-booking' ) . ' <strong>' . esc_html( implode( ', ', $pages ) ) . '</strong></p>';
		}
		/* translators: %s: error message from Google */
		$html .= '<p>' . esc_html( sprintf( __( 'Google said: %s', 'nemvo-booking' ), $error ) ) . '</p>';
		$html .= '<p><strong>' . esc_html__( 'How to fix it', 'nemvo-booking' ) . '</strong><br>';
		/* translators: %s: employee name */
		$html .= esc_html( sprintf( __( '%s opens this link, signs in with Google and clicks Allow. The link works for 7 days:', 'nemvo-booking' ), $employee['name'] ) ) . '<br>';
		$html .= '<a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a></p>';
		$html .= '<p>' . esc_html__( 'An administrator can also reconnect in wp-admin:', 'nemvo-booking' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=nemvo-booking-employees&edit=' . $employee['id'] ) ) . '">' . esc_html__( 'Bookings → Employees', 'nemvo-booking' ) . '</a></p>';
		$html .= '<p style="color:#6b7280;font-size:13px">' . esc_html__( 'Common causes: the Google password was changed, access was removed in the Google account settings, the Google Cloud app is still in "Testing" mode (access expires after 7 days), or a selected calendar was deleted or is no longer shared.', 'nemvo-booking' ) . '</p>';

		$recipients = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'alert_email' ) ) ) );
		if ( ! $recipients ) {
			$recipients = array( get_option( 'admin_email' ) );
		}
		if ( Settings::get( 'alert_employee' ) ) {
			$recipients[] = $employee['email'];
		}
		foreach ( array_unique( array_filter( $recipients, 'is_email' ) ) as $to ) {
			Mailer::deliver( $to, $subject, $html );
		}
	}

	/**
	 * Daily check, so a broken connection is noticed even when nobody visits the booking pages.
	 */
	public static function health_check() {
		if ( ! Google::is_configured() ) {
			return;
		}
		foreach ( self::all() as $employee ) {
			if ( self::is_connected( $employee ) && self::page_titles( $employee ) ) {
				Google::free_busy( $employee, time(), time() + HOUR_IN_SECONDS );
			}
		}
	}

	/**
	 * Picture for wp-admin: the chosen photo, else the WordPress avatar (Gravatar) when avatars are
	 * switched on under Settings → Discussion, else a placeholder that ships with the plugin.
	 *
	 * @param array $employee Employee.
	 * @return string Image URL.
	 */
	public static function avatar( array $employee ): string {
		return Plugin::fallback( self::chosen_photo( $employee ), self::placeholder( $employee ) );
	}

	/**
	 * Picture shown for an employee without a chosen photo in wp-admin.
	 *
	 * @param array $employee Employee.
	 * @return string Image URL: Gravatar with its "mystery person" default when avatars are on,
	 *                otherwise the plugin's own placeholder, so nothing is loaded from Gravatar.
	 */
	public static function placeholder( array $employee ): string {
		return get_option( 'show_avatars' ) ? self::gravatar( $employee, 'mp' ) : NEMVO_BOOKING_URL . 'assets/images/person.svg';
	}

	/**
	 * Photo shown on booking pages: the one chosen under Employees, else the WordPress avatar
	 * (Gravatar) when avatars are switched on under Settings → Discussion. Gravatar's "404"
	 * default lets the widget drop hosts that have no picture.
	 *
	 * @param array $employee Employee.
	 * @return string Image URL, or '' for none.
	 */
	public static function photo( array $employee ): string {
		$photo = self::chosen_photo( $employee );
		if ( ! $photo && get_option( 'show_avatars' ) ) {
			$photo = self::gravatar( $employee, '404' );
		}
		return $photo;
	}

	/**
	 * The photo chosen under Bookings → Employees.
	 *
	 * @param array $employee Employee.
	 * @return string Thumbnail URL, or ''.
	 */
	private static function chosen_photo( array $employee ): string {
		return $employee['photo_id'] ? (string) wp_get_attachment_image_url( $employee['photo_id'], 'thumbnail' ) : '';
	}

	/**
	 * The employee's avatar URL (Gravatar unless a plugin changes it).
	 *
	 * @param array  $employee Employee.
	 * @param string $fallback Gravatar default image, e.g. mp or 404.
	 * @return string
	 */
	private static function gravatar( array $employee, string $fallback ): string {
		return (string) get_avatar_url(
			$employee['email'],
			array(
				'size'    => 112,
				'default' => $fallback,
			)
		);
	}
}
