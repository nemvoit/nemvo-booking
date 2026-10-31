<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Google OAuth 2.0 + Calendar API client (no external dependencies).
 */
class Google {

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
	const API        = 'https://www.googleapis.com/calendar/v3';
	const SCOPES     = 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events';

	public static function init() {
		add_action( 'admin_post_nemvo_booking_oauth', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_nopriv_nemvo_booking_oauth', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_nemvo_booking_connect', array( __CLASS__, 'handle_connect_link' ) );
		add_action( 'admin_post_nopriv_nemvo_booking_connect', array( __CLASS__, 'handle_connect_link' ) );
	}

	public static function is_configured(): bool {
		return '' !== Settings::get( 'google_client_id' ) && '' !== Settings::get( 'google_client_secret' );
	}

	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=nemvo_booking_oauth' );
	}

	/**
	 * @param string $mode 'admin' (started from wp-admin) or 'link' (employee used a connect link).
	 */
	public static function auth_url( array $employee, string $mode, string $link_token = '' ): string {
		$state = Crypto::sign(
			array(
				'e'   => (int) $employee['id'],
				'm'   => $mode,
				't'   => $link_token,
				'exp' => time() + 30 * MINUTE_IN_SECONDS,
			)
		);

		$params = array(
			'client_id'              => Settings::get( 'google_client_id' ),
			'redirect_uri'           => self::redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => self::SCOPES,
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
		);
		if ( ! empty( $employee['email'] ) ) {
			$params['login_hint'] = $employee['email'];
		}

		$url = self::AUTH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		return (string) apply_filters( 'nemvo_booking_google_auth_url', $url, $params );
	}

	/** Entry point for links sent to employees so they can connect without a WordPress login. */
	public static function handle_connect_link() {
		$id       = isset( $_GET['e'] ) ? absint( $_GET['e'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$token    = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$employee = Employees::get( $id );

		if ( ! $employee || ! self::is_configured() || ! Employees::connect_token_valid( $employee, $token ) ) {
			self::message_page( __( 'This link has expired', 'nemvo-booking' ), __( 'Please ask your administrator for a new link to connect your Google Calendar.', 'nemvo-booking' ), 403 );
		}

		wp_redirect( self::auth_url( $employee, 'link', $token ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public static function handle_callback() {
		// phpcs:disable WordPress.Security.NonceVerification -- OAuth state is HMAC-signed.
		$state    = Crypto::verify( isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '' );
		$employee = $state ? Employees::get( (int) $state['e'] ) : null;

		if ( ! $state || ! $employee ) {
			self::message_page( __( 'Connection failed', 'nemvo-booking' ), __( 'The request was invalid or has expired. Please try again.', 'nemvo-booking' ), 400 );
		}

		$is_admin = 'admin' === $state['m'];
		if ( $is_admin && ! current_user_can( 'manage_options' ) ) {
			self::message_page( __( 'Connection failed', 'nemvo-booking' ), __( 'You are not allowed to do this.', 'nemvo-booking' ), 403 );
		}
		if ( ! $is_admin && ! Employees::connect_token_valid( $employee, (string) $state['t'] ) ) {
			self::message_page( __( 'This link has expired', 'nemvo-booking' ), __( 'Please ask your administrator for a new link to connect your Google Calendar.', 'nemvo-booking' ), 403 );
		}

		$error = '';
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
		} else {
			$result = self::exchange_code( isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '', $employee );
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			}
		}
		// phpcs:enable

		if ( $error ) {
			Log::error( 'google.connect_failed', sprintf( 'Google connection failed for %s: %s', $employee['name'], $error ), array( 'employee_id' => $employee['id'] ) );
		}

		if ( $is_admin ) {
			$url = admin_url( 'admin.php?page=nemvo-booking-employees&edit=' . $employee['id'] );
			$url = add_query_arg( $error ? array( 'nb_error' => rawurlencode( $error ) ) : array( 'nb_notice' => 'connected' ), $url );
			wp_safe_redirect( $url );
			exit;
		}

		if ( $error ) {
			/* translators: %s: error message */
			self::message_page( __( 'Connection failed', 'nemvo-booking' ), sprintf( __( 'Google returned an error: %s', 'nemvo-booking' ), $error ), 400 );
		}
		self::message_page( __( 'Google Calendar connected', 'nemvo-booking' ), __( 'Thank you! Your calendar is now connected and you can close this window.', 'nemvo-booking' ), 200 );
	}

	/** @return true|\WP_Error */
	private static function exchange_code( string $code, array $employee ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => Settings::get( 'google_client_id' ),
					'client_secret' => Settings::get( 'google_client_secret' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		$data = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new \WP_Error( 'no_refresh_token', __( 'Google did not return a refresh token. Remove the app under myaccount.google.com/permissions and connect again.', 'nemvo-booking' ) );
		}

		Employees::update(
			(int) $employee['id'],
			array(
				'refresh_token'   => Crypto::encrypt( $data['refresh_token'] ),
				'access_token'    => Crypto::encrypt( $data['access_token'] ?? '' ),
				'token_expires'   => time() + (int) ( $data['expires_in'] ?? 3600 ) - 60,
				'last_error'      => '',
				'connect_token'   => '',
				'connect_expires' => 0,
			)
		);

		$employee  = Employees::get( (int) $employee['id'] );
		$refreshed = Employees::refresh_calendars( $employee );
		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}
		$employee = Employees::get( (int) $employee['id'] );

		Log::info(
			'google.connected',
			/* translators: 1: employee name, 2: Google account */
			sprintf( 'Google Calendar connected for %1$s (%2$s).', $employee['name'], $employee['google_email'] ),
			array( 'employee_id' => $employee['id'] )
		);
		return true;
	}

	/** @return string|\WP_Error */
	public static function access_token( array $employee ) {
		if ( (int) $employee['token_expires'] > time() ) {
			$token = Crypto::decrypt( $employee['access_token'] );
			if ( $token ) {
				return $token;
			}
		}

		$refresh = Crypto::decrypt( $employee['refresh_token'] );
		if ( ! $refresh ) {
			return new \WP_Error( 'not_connected', __( 'Google Calendar is not connected.', 'nemvo-booking' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'refresh_token' => $refresh,
					'client_id'     => Settings::get( 'google_client_id' ),
					'client_secret' => Settings::get( 'google_client_secret' ),
					'grant_type'    => 'refresh_token',
				),
			)
		);
		$data = self::decode( $response );
		if ( is_wp_error( $data ) ) {
			Employees::report_problem( $employee, $data );
			return $data;
		}

		Employees::update(
			(int) $employee['id'],
			array(
				'access_token'  => Crypto::encrypt( $data['access_token'] ),
				'token_expires' => time() + (int) ( $data['expires_in'] ?? 3600 ) - 60,
			)
		);
		return $data['access_token'];
	}

	/** @return array|\WP_Error */
	public static function request( array $employee, string $method, string $path, array $query = array(), ?array $body = null ) {
		$token = self::access_token( $employee );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::API . $path;
		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$result = self::decode( wp_remote_request( $url, $args ) );
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			// A missing event (e.g. deleted by hand) says nothing about the connection.
			if ( ! ( 'DELETE' === $method && in_array( $status, array( 404, 410 ), true ) ) ) {
				Employees::report_problem( $employee, $result );
			}
		} else {
			Employees::report_ok( $employee );
		}
		return $result;
	}

	/** @return array|\WP_Error */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$message = $data['error']['message'] ?? ( $data['error_description'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : "HTTP {$code}" ) );
			return new \WP_Error( 'google_api', $message, array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function cal_path( string $calendar_id ): string {
		return '/calendars/' . rawurlencode( $calendar_id );
	}

	public static function rfc3339( int $ts ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	/** @return array|\WP_Error List of calendars. */
	public static function calendar_list( array $employee ) {
		$data = self::request( $employee, 'GET', '/users/me/calendarList', array( 'maxResults' => 250 ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$calendars = array();
		foreach ( $data['items'] ?? array() as $item ) {
			$calendars[] = array(
				'id'      => $item['id'],
				'summary' => $item['summaryOverride'] ?? ( $item['summary'] ?? $item['id'] ),
				'primary' => ! empty( $item['primary'] ),
				'role'    => $item['accessRole'] ?? 'reader',
			);
		}
		return $calendars;
	}

	/**
	 * Busy intervals across the employee's conflict calendars. Google's free/busy
	 * only reports events marked "Busy", so free all-day events don't block.
	 *
	 * @return array|\WP_Error List of [start_ts, end_ts].
	 */
	public static function free_busy( array $employee, int $from, int $to ) {
		$calendars = Employees::busy_calendars( $employee );
		$data      = self::request(
			$employee,
			'POST',
			'/freeBusy',
			array(),
			array(
				'timeMin' => self::rfc3339( $from ),
				'timeMax' => self::rfc3339( $to ),
				'items'   => array_map(
					function ( $id ) {
						return array( 'id' => $id );
					},
					$calendars
				),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$busy   = array();
		$failed = array();
		foreach ( $data['calendars'] ?? array() as $id => $cal ) {
			if ( ! empty( $cal['errors'] ) ) {
				$failed[] = $id;
				continue;
			}
			foreach ( $cal['busy'] ?? array() as $b ) {
				$busy[] = array( (int) strtotime( $b['start'] ), (int) strtotime( $b['end'] ) );
			}
		}
		if ( $failed ) {
			$error = new \WP_Error( 'freebusy', 'Cannot read calendar(s): ' . implode( ', ', $failed ) . '. Check the calendars selected under Employees.', array( 'status' => 404 ) );
			Employees::report_problem( $employee, $error );
			if ( count( $failed ) >= count( $calendars ) ) {
				return $error;
			}
		}
		return $busy;
	}

	/** @return array|\WP_Error Created event. */
	public static function create_event( array $employee, string $calendar_id, array $event, bool $send_updates ) {
		$created = self::request(
			$employee,
			'POST',
			self::cal_path( $calendar_id ) . '/events',
			array(
				'conferenceDataVersion' => 1,
				'sendUpdates'           => $send_updates ? 'all' : 'none',
			),
			$event
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		// Meet links are normally ready immediately, but can be pending briefly.
		for ( $i = 0; $i < 3 && ! self::meet_link( $created ) && ! empty( $created['id'] ); $i++ ) {
			sleep( 1 );
			$fetched = self::request( $employee, 'GET', self::cal_path( $calendar_id ) . '/events/' . rawurlencode( $created['id'] ) );
			if ( ! is_wp_error( $fetched ) ) {
				$created = $fetched;
			}
		}
		return $created;
	}

	/** @return array|\WP_Error Updated event. */
	public static function patch_event( array $employee, string $calendar_id, string $event_id, array $changes, bool $send_updates ) {
		return self::request(
			$employee,
			'PATCH',
			self::cal_path( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			array(
				'conferenceDataVersion' => 1,
				'sendUpdates'           => $send_updates ? 'all' : 'none',
			),
			$changes
		);
	}

	public static function meet_link( array $event ): string {
		if ( ! empty( $event['hangoutLink'] ) ) {
			return $event['hangoutLink'];
		}
		foreach ( $event['conferenceData']['entryPoints'] ?? array() as $entry ) {
			if ( 'video' === ( $entry['entryPointType'] ?? '' ) ) {
				return $entry['uri'];
			}
		}
		return '';
	}

	/** @return true|\WP_Error */
	public static function delete_event( array $employee, string $calendar_id, string $event_id, bool $send_updates ) {
		$result = self::request(
			$employee,
			'DELETE',
			self::cal_path( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			array( 'sendUpdates' => $send_updates ? 'all' : 'none' )
		);
		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			return in_array( $status, array( 404, 410 ), true ) ? true : $result;
		}
		return true;
	}

	/** @return array|\WP_Error Events of a (public) calendar in a time range. */
	public static function list_events( array $employee, string $calendar_id, int $from, int $to ) {
		$data = self::request(
			$employee,
			'GET',
			self::cal_path( $calendar_id ) . '/events',
			array(
				'timeMin'      => self::rfc3339( $from ),
				'timeMax'      => self::rfc3339( $to ),
				'singleEvents' => 'true',
				'maxResults'   => 250,
			)
		);
		return is_wp_error( $data ) ? $data : ( $data['items'] ?? array() );
	}

	public static function revoke( array $employee ) {
		$refresh = Crypto::decrypt( $employee['refresh_token'] );
		if ( $refresh ) {
			wp_remote_post( self::REVOKE_URL, array( 'timeout' => 10, 'body' => array( 'token' => $refresh ) ) );
		}
	}

	private static function message_page( string $title, string $message, int $status ) {
		wp_die( '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p>', esc_html( $title ), array( 'response' => (int) $status ) );
	}
}
