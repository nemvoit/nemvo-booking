<?php
/**
 * Spam and abuse protection.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Spam and abuse protection for bookings: honeypot, time-to-submit, rate limiting,
 * blocklist, headless browser detection and Cap (self-hosted proof-of-work CAPTCHA).
 */
class Protection {

	/** Version of the Cap widget bundled in assets/vendor/cap. */
	const CAP_VERSION = '0.1.63';

	/** Cap relay requests per visitor IP and hour (each solve takes two: challenge and redeem). */
	const CAP_RELAY_LIMIT = 60;

	/**
	 * Whether Cap is switched on and fully configured.
	 *
	 * @return bool
	 */
	public static function cap_enabled(): bool {
		$s = Settings::all();
		return $s['cap_enabled'] && $s['cap_url'] && $s['cap_site_key'] && $s['cap_secret'];
	}

	/**
	 * Endpoint the visitor's browser talks to: the public Cap address if one is set, otherwise the
	 * relay below, so Cap can stay on an internal network and visitors only talk to this site.
	 *
	 * @return string URL ending in a slash.
	 */
	public static function cap_endpoint(): string {
		$s = Settings::all();
		if ( $s['cap_public_url'] ) {
			return trailingslashit( $s['cap_public_url'] ) . rawurlencode( (string) $s['cap_site_key'] ) . '/';
		}
		return trailingslashit( rest_url( Rest::NS . '/cap' ) );
	}

	/**
	 * Endpoint WordPress uses for a Cap request (the server address as WordPress reaches it).
	 *
	 * @param array  $s      Settings with cap_url and cap_site_key.
	 * @param string $action challenge, redeem or siteverify.
	 * @return string URL.
	 */
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
					// Public: anonymous visitors' browsers fetch and redeem Cap puzzles here before they
					// book. Only answers when Cap is enabled, takes no parameters except the widget's
					// optional ?wasm=0 (the JSON body is passed on to the site owner's Cap server as it
					// is, up to 64 KB) and is rate-limited per IP.
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/**
	 * Forwards a challenge or redeem request from the widget to the Cap server.
	 *
	 * @param \WP_REST_Request $request Request from the widget.
	 * @return \WP_REST_Response Cap's answer, or an error.
	 */
	public static function relay_cap( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! self::cap_enabled() ) {
			return new \WP_REST_Response( array( 'error' => 'Cap is not enabled.' ), 404 );
		}
		if ( self::rate_limited( 'cap', self::CAP_RELAY_LIMIT ) ) {
			return new \WP_REST_Response( array( 'error' => 'Too many requests.' ), 429 );
		}
		$action = substr( $request->get_route(), strrpos( $request->get_route(), '/' ) + 1 );
		$body   = (string) $request->get_body();
		if ( strlen( $body ) > 65536 ) {
			return new \WP_REST_Response( array( 'error' => 'Too large.' ), 413 );
		}
		$url = self::cap_server_url( Settings::all(), $action );
		// Browsers without WebAssembly ask for challenges they can solve in JavaScript.
		$query = $request->get_query_params();
		if ( 'challenge' === $action && isset( $query['wasm'] ) && '0' === $query['wasm'] ) {
			$url = add_query_arg( 'wasm', '0', $url );
		}
		$response = wp_remote_post(
			$url,
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

	/**
	 * Spam protection settings for the widget script.
	 *
	 * @return array
	 */
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
	 * Loads the bundled Cap widget. The globals point the widget at the bundled WASM solvers and
	 * pako, so its built-in jsDelivr fallback URLs are never used, stop it from adding the page
	 * address and referrer to its "Cap" credit link, and keep the instrumentation frame isolated:
	 * the script from the Cap server runs with an opaque origin and can't read or change the page,
	 * its cookies or storage (CAP_ISOLATE; widget 0.1.58 always did this).
	 */
	public static function enqueue_cap() {
		if ( ! self::cap_enabled() ) {
			return;
		}
		$bundled = NEMVO_BOOKING_URL . 'assets/vendor/cap/';
		wp_enqueue_script( 'nemvo-booking-cap', $bundled . 'cap.min.js', array(), self::CAP_VERSION, true );
		wp_add_inline_script(
			'nemvo-booking-cap',
			sprintf(
				'window.CAP_CUSTOM_WASM_URL=%1$s;window.CAP_CUSTOM_HASHWX_URL=%2$s;window.CAP_PAKO_URL=%3$s;window.CAP_DISABLE_WIDGET_REF=true;window.CAP_ISOLATE=true;',
				wp_json_encode( $bundled . 'cap_wasm_bg.wasm' ),
				wp_json_encode( $bundled . 'hashwx.wasm' ),
				wp_json_encode( $bundled . 'pako_inflate.min.js' )
			),
			'before'
		);
	}

	/**
	 * Signed timestamp handed to the widget when the calendar loads; used for the time-to-submit check.
	 *
	 * @return string
	 */
	public static function form_token(): string {
		return Crypto::sign(
			array(
				't'   => time(),
				'exp' => time() + DAY_IN_SECONDS,
			)
		);
	}

	/**
	 * Runs all checks before a booking is created. Blocked attempts are logged as spam.blocked.
	 *
	 * @param \WP_REST_Request $request Booking request.
	 * @param string           $email   Booker's email address.
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

	/**
	 * Logs a blocked booking attempt and returns the error for the visitor.
	 *
	 * @param string $check   Name of the check.
	 * @param string $detail  Why it was blocked (for the log).
	 * @param string $message Message for the visitor.
	 * @param string $email   Booker's email address.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private static function block( string $check, string $detail, string $message, string $email, int $status = 400 ): \WP_Error {
		Log::warning(
			'spam.blocked',
			sprintf( 'Booking attempt blocked (%1$s): %2$s Email: %3$s, IP: %4$s', $check, $detail, $email ? $email : '–', Plugin::client_ip() )
		);
		return new \WP_Error( 'blocked', $message, array( 'status' => $status ) );
	}

	/**
	 * Counts a request from the visitor's IP address and tells whether the hourly limit is reached.
	 *
	 * @param string $bucket What is limited, e.g. book, cancel or cap.
	 * @param int    $max    Requests allowed per hour.
	 * @return bool True when the limit is reached (the request is then not counted).
	 */
	public static function rate_limited( string $bucket, int $max ): bool {
		$key   = 'nemvo_booking_rl_' . $bucket . '_' . md5( Plugin::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Whether the email, its domain (or a parent domain) or the IP address is on the blocklist.
	 *
	 * @param string $email Email address.
	 * @param string $ip    IP address.
	 * @return bool
	 */
	private static function is_blocklisted( string $email, string $ip ): bool {
		$email  = strtolower( $email );
		$domain = substr( Plugin::fallback( strrchr( $email, '@' ), '' ), 1 );
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

	/**
	 * Looks for signs of an automated browser or HTTP library.
	 *
	 * @param \WP_REST_Request $request Booking request.
	 * @return string Reason when the request looks automated, empty otherwise.
	 */
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

	/**
	 * Checks a token from the Cap widget with the Cap server. Fails closed when Cap can't be reached.
	 *
	 * @param string $token Token from the widget.
	 * @return true|\WP_Error
	 */
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
	 * @param array $settings cap_url, cap_site_key and cap_secret.
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
