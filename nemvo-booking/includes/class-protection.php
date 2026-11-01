<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Spam and abuse protection for bookings: honeypot, time-to-submit, rate limiting,
 * blocklist, headless browser detection and Cap (self-hosted proof-of-work CAPTCHA).
 */
class Protection {

	/** Version of the Cap widget bundled in assets/vendor/cap. */
	const CAP_VERSION = '0.1.58';

	public static function cap_enabled(): bool {
		$s = Settings::all();
		return $s['cap_enabled'] && $s['cap_url'] && $s['cap_site_key'] && $s['cap_secret'];
	}

	/**
	 * Endpoint the visitor's browser talks to: the public Cap address if one is set, otherwise the
	 * relay below, so Cap can stay on an internal network and visitors only talk to this site.
	 */
	public static function cap_endpoint(): string {
		$s = Settings::all();
		if ( $s['cap_public_url'] ) {
			return trailingslashit( $s['cap_public_url'] ) . rawurlencode( (string) $s['cap_site_key'] ) . '/';
		}
		return trailingslashit( rest_url( Rest::NS . '/cap' ) );
	}

	/** Endpoint WordPress uses for a Cap request (the server address as WordPress reaches it). */
	private static function cap_server_url( array $s, string $action ): string {
		return trailingslashit( $s['cap_url'] ) . rawurlencode( (string) $s['cap_site_key'] ) . '/' . $action;
	}

	/** Relay routes for the widget's challenge and redeem requests. */
	public static function register_routes() {
		foreach ( array( 'challenge', 'redeem' ) as $action ) {
			register_rest_route(
				Rest::NS,
				'/cap/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'relay_cap' ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/** Forwards a challenge or redeem request from the widget to the Cap server. */
	public static function relay_cap( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::cap_enabled() ) {
			return new \WP_REST_Response( array( 'error' => 'Cap is not enabled.' ), 404 );
		}
		$action = substr( $request->get_route(), strrpos( $request->get_route(), '/' ) + 1 );
		$body   = (string) $request->get_body();
		if ( strlen( $body ) > 65536 ) {
			return new \WP_REST_Response( array( 'error' => 'Too large.' ), 413 );
		}
		$response = wp_remote_post(
			self::cap_server_url( Settings::all(), $action ),
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'    => 'application/json',
					// Cap rate-limits per visitor IP.
					'X-Forwarded-For' => Plugin::client_ip(),
				),
				'body'    => '' === $body ? '{}' : $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			Log::error( 'cap.unreachable', 'Could not reach the Cap server: ' . $response->get_error_message() );
			return new \WP_REST_Response( array( 'error' => 'Cap is not reachable.' ), 502 );
		}
		$out = new \WP_REST_Response( json_decode( wp_remote_retrieve_body( $response ), true ), (int) wp_remote_retrieve_response_code( $response ) );
		$out->header( 'Cache-Control', 'no-store' );
		return $out;
	}

	public static function client_config(): array {
		$s = Settings::all();
		return array(
			'honeypot' => (bool) $s['honeypot_enabled'],
			'headless' => (bool) $s['headless_check'],
			'cap'      => self::cap_enabled() ? array(
				'endpoint' => self::cap_endpoint(),
				'mode'     => $s['cap_mode'],
			) : null,
		);
	}

	/**
	 * Loads the bundled Cap widget, or the one from the "Widget script URL" setting. The widget
	 * fetches its WASM solvers from the folder its script is in, so nothing comes from a CDN.
	 */
	public static function enqueue_cap() {
		if ( ! self::cap_enabled() ) {
			return;
		}
		$bundled = NEMVO_BOOKING_URL . 'assets/vendor/cap/';
		$custom  = (string) Settings::get( 'cap_script_url' );
		if ( $custom ) {
			wp_enqueue_script( 'nemvo-booking-cap', $custom, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the URL is the site owner's own.
			$wasm = trailingslashit( dirname( $custom ) );
		} else {
			wp_enqueue_script( 'nemvo-booking-cap', $bundled . 'cap.min.js', array(), self::CAP_VERSION, true );
			$wasm = $bundled;
		}
		wp_add_inline_script(
			'nemvo-booking-cap',
			sprintf(
				'window.CAP_CUSTOM_WASM_URL=%1$s;window.CAP_CUSTOM_HASHWX_URL=%2$s;window.CAP_PAKO_URL=%3$s;',
				wp_json_encode( $wasm . 'cap_wasm_bg.wasm' ),
				wp_json_encode( $wasm . 'hashwx.wasm' ),
				wp_json_encode( $bundled . 'pako_inflate.min.js' )
			),
			'before'
		);
	}

	/** Signed timestamp handed to the widget when the calendar loads; used for the time-to-submit check. */
	public static function form_token(): string {
		return Crypto::sign(
			array(
				't'   => time(),
				'exp' => time() + DAY_IN_SECONDS,
			)
		);
	}

	/**
	 * Runs all checks before a booking is created.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_booking( \WP_REST_Request $request, string $email ) {
		$s       = Settings::all();
		$generic = __( 'We could not accept this booking. Please try again or contact us.', 'nemvo-booking' );
		$ip      = Plugin::client_ip();

		if ( $s['honeypot_enabled'] && '' !== (string) $request['website'] ) {
			return self::block( 'honeypot', 'Honeypot field was filled in.', $generic, $email );
		}

		$min = (int) $s['min_submit_seconds'];
		if ( $min > 0 ) {
			$token = Crypto::verify( (string) $request['form_token'] );
			if ( ! $token ) {
				return self::block( 'time_check', 'Missing or expired form token.', __( 'Your session has expired. Please reload the page and try again.', 'nemvo-booking' ), $email );
			}
			$elapsed = time() - (int) $token['t'];
			if ( $elapsed < $min ) {
				return self::block( 'time_check', sprintf( 'Form submitted after %1$d seconds (minimum %2$d).', $elapsed, $min ), $generic, $email );
			}
		}

		if ( self::is_blocklisted( $email, $ip ) ) {
			return self::block( 'blocklist', 'Email, domain or IP is on the blocklist.', $generic, $email );
		}

		if ( $s['headless_check'] ) {
			$reason = self::headless_reason( $request );
			if ( $reason ) {
				return self::block( 'headless', 'Automated browser detected: ' . $reason, $generic, $email );
			}
		}

		$limit = (int) $s['rate_limit'];
		if ( $limit > 0 && self::rate_limited( 'book', $limit ) ) {
			return self::block( 'rate_limit', sprintf( 'More than %d booking attempts per hour from this IP.', $limit ), __( 'Too many booking attempts. Please try again later.', 'nemvo-booking' ), $email, 429 );
		}

		if ( self::cap_enabled() ) {
			$verified = self::verify_cap( (string) $request['cap_token'] );
			if ( is_wp_error( $verified ) ) {
				return self::block( 'captcha', $verified->get_error_message(), __( 'The spam check failed. Please try again.', 'nemvo-booking' ), $email );
			}
		}

		return true;
	}

	private static function block( string $check, string $detail, string $message, string $email, int $status = 400 ): \WP_Error {
		Log::warning(
			'spam.blocked',
			sprintf( 'Booking attempt blocked (%1$s): %2$s Email: %3$s, IP: %4$s', $check, $detail, $email ?: '–', Plugin::client_ip() )
		);
		return new \WP_Error( 'blocked', $message, array( 'status' => $status ) );
	}

	public static function rate_limited( string $bucket, int $max ): bool {
		$key   = 'nemvo_booking_rl_' . $bucket . '_' . md5( Plugin::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	private static function is_blocklisted( string $email, string $ip ): bool {
		$email  = strtolower( $email );
		$domain = substr( strrchr( $email, '@' ) ?: '', 1 );
		foreach ( preg_split( '/\R/', strtolower( (string) Settings::get( 'blocklist' ) ) ) as $entry ) {
			$entry = trim( preg_replace( '/\s.*$/', '', trim( $entry ) ) );
			if ( '' === $entry || '#' === $entry[0] ) {
				continue;
			}
			$entry = ltrim( $entry, '@' );
			if ( $entry === $email || $entry === $ip || ( $domain && ( $entry === $domain || substr( $domain, -strlen( '.' . $entry ) ) === '.' . $entry ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string Reason when the request looks automated, empty otherwise. */
	private static function headless_reason( \WP_REST_Request $request ): string {
		$ua = (string) $request->get_header( 'user_agent' );
		if ( '' === $ua ) {
			return 'empty user agent';
		}
		if ( preg_match( '/HeadlessChrome|PhantomJS|Puppeteer|Playwright|Selenium|webdriver|python-requests|python-urllib|aiohttp|curl\/|wget\/|Go-http-client|node-fetch|axios\/|okhttp|libwww-perl|Java\//i', $ua, $m ) ) {
			return 'user agent contains ' . $m[0];
		}
		$client = $request['client'];
		if ( ! is_array( $client ) ) {
			return 'browser checks missing';
		}
		$signals = array_filter( array_map( 'sanitize_key', (array) ( $client['signals'] ?? array() ) ) );
		return $signals ? implode( ', ', $signals ) : '';
	}

	/** @return true|\WP_Error */
	public static function verify_cap( string $token ) {
		if ( '' === $token ) {
			return new \WP_Error( 'cap_missing', 'No CAPTCHA token was submitted.' );
		}
		$response = wp_remote_post(
			self::cap_server_url( Settings::all(), 'siteverify' ),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'secret'   => Settings::get( 'cap_secret' ),
						'response' => $token,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			Log::error( 'cap.unreachable', 'Could not reach the Cap server: ' . $response->get_error_message() );
			// Fail closed: without verification we can't tell people from bots.
			return new \WP_Error( 'cap_unreachable', 'Cap server unreachable.' );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['success'] ) ) {
			return new \WP_Error( 'cap_invalid', 'CAPTCHA token was rejected by Cap.' );
		}
		return true;
	}

	/**
	 * Checks that the Cap server answers with the given settings. The made-up token has the shape of a
	 * real one (site key first), because Cap only checks the keys after the token's shape; a valid key
	 * pair then gets "Token not found".
	 *
	 * @return true|\WP_Error
	 */
	public static function test_cap( array $settings ) {
		$response = wp_remote_post(
			self::cap_server_url( $settings, 'siteverify' ),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'secret'   => $settings['cap_secret'],
						'response' => $settings['cap_site_key'] . ':connection-test:0',
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! array_key_exists( 'success', $data ) ) {
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'cap_test', sprintf( __( 'The Cap server answered with HTTP %d but not with a verification result. Check the URL and site key.', 'nemvo-booking' ), $code ) );
		}
		if ( ! empty( $data['error'] ) && false !== stripos( (string) $data['error'], 'secret' ) ) {
			return new \WP_Error( 'cap_test', __( 'The Cap server rejected the site key or the secret key.', 'nemvo-booking' ) );
		}
		return true;
	}
}
