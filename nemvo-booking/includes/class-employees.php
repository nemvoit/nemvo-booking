<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Employees (hosts) and their Google Calendar connection.
 */
class Employees {

	public static function all(): array {
		global $wpdb;
		$table = Plugin::table( 'employees' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', $table ), ARRAY_A );
		return array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Plugin::table( 'employees' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array[] Employees for the given IDs, in the given order, skipping missing ones. */
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

	private static function hydrate( array $row ): array {
		$row['id']             = (int) $row['id'];
		$row['photo_id']       = (int) ( $row['photo_id'] ?? 0 );
		$row['calendars']      = json_decode( (string) $row['calendars'], true ) ?: array();
		$row['busy_calendars'] = json_decode( (string) $row['busy_calendars'], true ) ?: array();
		return $row;
	}

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

	public static function update( int $id, array $data ) {
		global $wpdb;
		foreach ( array( 'calendars', 'busy_calendars' ) as $json ) {
			if ( isset( $data[ $json ] ) && is_array( $data[ $json ] ) ) {
				$data[ $json ] = wp_json_encode( array_values( $data[ $json ] ) );
			}
		}
		$wpdb->update( Plugin::table( 'employees' ), $data, array( 'id' => $id ) );
	}

	public static function delete( int $id ) {
		global $wpdb;
		$wpdb->delete( Plugin::table( 'employees' ), array( 'id' => $id ) );
	}

	public static function is_connected( array $employee ): bool {
		return '' !== (string) $employee['refresh_token'];
	}

	public static function disconnect( array $employee ) {
		Google::revoke( $employee );
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

	/** Calendar IDs checked for conflicts. Defaults to the primary calendar. */
	public static function busy_calendars( array $employee ): array {
		return $employee['busy_calendars'] ?: array( 'primary' );
	}

	/** Calendar where new meetings are created. */
	public static function target_calendar( array $employee ): string {
		return $employee['target_calendar'] ?: 'primary';
	}

	/** Email used as organizer/attendee address for this employee. */
	public static function calendar_email( array $employee ): string {
		return $employee['google_email'] ?: $employee['email'];
	}

	/** @return true|\WP_Error */
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
		$ids = wp_list_pluck( $calendars, 'id' );
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

	/** Creates a link the employee can open to connect their own Google account. Valid for 7 days. */
	public static function connect_link( array $employee ): string {
		$token = wp_generate_password( 32, false );
		self::update(
			$employee['id'],
			array(
				'connect_token'   => $token,
				'connect_expires' => time() + 7 * DAY_IN_SECONDS,
			)
		);
		return add_query_arg(
			array(
				'action' => 'nemvo_booking_connect',
				'e'      => $employee['id'],
				't'      => $token,
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function connect_token_valid( array $employee, string $token ): bool {
		return '' !== $token
			&& '' !== $employee['connect_token']
			&& (int) $employee['connect_expires'] > time()
			&& hash_equals( $employee['connect_token'], $token );
	}

	/** Booking page titles (published) that use this employee. */
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

	/** Clears a recorded problem after a successful Google request. */
	public static function report_ok( array $employee ) {
		if ( '' === (string) ( $employee['last_error'] ?? '' ) ) {
			return;
		}
		self::update( (int) $employee['id'], array( 'last_error' => '' ) );
		Log::info( 'google.connection_restored', sprintf( 'Google Calendar connection for %s works again.', $employee['name'] ), array( 'employee_id' => $employee['id'] ) );
	}

	/** Tells the admin (and the employee) how to fix a broken connection. */
	private static function send_alert( array $employee, string $error ) {
		$pages   = self::page_titles( $employee );
		$link    = self::connect_link( $employee );
		$subject = sprintf(
			/* translators: 1: site name, 2: employee name */
			__( '[%1$s] Google Calendar connection for %2$s stopped working', 'nemvo-booking' ),
			get_bloginfo( 'name' ),
			$employee['name']
		);
		$html  = '<p>' . sprintf(
			/* translators: %s: employee name */
			esc_html__( 'The Google Calendar connection for %s has stopped working.', 'nemvo-booking' ),
			'<strong>' . esc_html( $employee['name'] ) . '</strong>'
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

	/** Daily check, so a broken connection is noticed even when nobody visits the booking pages. */
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

	/** Picture for wp-admin: the chosen photo, else the WordPress avatar with a placeholder. */
	public static function avatar( array $employee ): string {
		return self::chosen_photo( $employee ) ?: self::gravatar( $employee, 'mp' );
	}

	/**
	 * Photo shown on booking pages: the one chosen under Employees, else the WordPress avatar
	 * (Gravatar) when avatars are switched on under Settings → Discussion. Gravatar's "404"
	 * default lets the widget drop hosts that have no picture.
	 */
	public static function photo( array $employee ): string {
		$photo = self::chosen_photo( $employee );
		if ( ! $photo && get_option( 'show_avatars' ) ) {
			$photo = self::gravatar( $employee, '404' );
		}
		return $photo;
	}

	private static function chosen_photo( array $employee ): string {
		return $employee['photo_id'] ? (string) wp_get_attachment_image_url( $employee['photo_id'], 'thumbnail' ) : '';
	}

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
