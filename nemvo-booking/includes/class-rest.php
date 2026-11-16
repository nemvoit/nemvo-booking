<?php
/**
 * REST API for the booking widget.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Public REST API used by the booking widget. No nonces, so it keeps working behind page caches.
 */
class Rest {

	const NS = 'nemvo-booking/v1';

	/**
	 * Registers the routes on rest_api_init.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the widget's routes. All of them are public on purpose: anonymous visitors use
	 * them to see free times, book, and cancel or reschedule with the secret token from their
	 * email. There are no admin routes (wp-admin uses admin-post and admin-ajax with nonces and
	 * capability checks). No route accepts a nonce, so cached pages keep working; each parameter
	 * is validated and sanitized below, and the callbacks check the booking page, the token and
	 * the spam protection.
	 */
	public static function routes() {
		$page_id = array(
			'id' => array(
				'description'       => 'Booking page ID.',
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'is_id' ),
				'sanitize_callback' => 'absint',
			),
		);
		$token   = array(
			'description'       => 'Secret token from the booker\'s cancel or reschedule link.',
			'required'          => true,
			'validate_callback' => array( __CLASS__, 'is_text' ),
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::NS,
			'/pages/(?P<id>\d+)/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'slots' ),
				// Public: the widget shows free times to anonymous visitors. Read-only, published pages only.
				'permission_callback' => '__return_true',
				'args'                => $page_id + array(
					'from'     => self::date_arg( 'First day (Y-m-d) in the time zone tz.' ),
					'to'       => self::date_arg( 'Last day (Y-m-d) in the time zone tz.' ),
					'duration' => self::number_arg( 'Meeting length in minutes.' ),
					'tz'       => self::text_arg( 'The visitor\'s time zone.' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/pages/(?P<id>\d+)/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				// Public: anonymous visitors book here. Protection::check_booking() applies the honeypot,
				// signed time-to-submit token, blocklist, headless check, per-IP rate limit and Cap.
				'permission_callback' => '__return_true',
				'args'                => $page_id + array(
					'start'      => self::number_arg( 'Start time (Unix timestamp).', true ),
					'duration'   => self::number_arg( 'Meeting length in minutes.' ),
					'tz'         => self::text_arg( 'The visitor\'s time zone.' ),
					'fields'     => array(
						'description'       => 'Form answers keyed by field key.',
						'validate_callback' => array( __CLASS__, 'is_list' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_answers' ),
					),
					'guests'     => array(
						'description'       => 'Guest emails, as a list or separated by commas.',
						'validate_callback' => array( __CLASS__, 'is_text_or_list' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_guests' ),
					),
					'consent'    => array(
						'description'       => 'Whether the privacy consent box was ticked.',
						'validate_callback' => 'rest_is_boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					'website'    => self::text_arg( 'Honeypot field; people leave it empty.' ),
					'form_token' => self::text_arg( 'Signed time-to-submit token from the slots response.' ),
					'cap_token'  => self::text_arg( 'Token from the Cap widget.' ),
					'client'     => array(
						'description'       => 'Automation signals collected in the browser.',
						'validate_callback' => array( __CLASS__, 'is_list' ),
						'sanitize_callback' => array( __CLASS__, 'sanitize_client' ),
					),
					'page_url'   => array(
						'description'       => 'Address of the page the booking was made on.',
						'validate_callback' => array( __CLASS__, 'is_text' ),
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/reschedule',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'reschedule_info' ),
					// Public: the booker isn't logged in; the secret token from their email is the credential.
					'permission_callback' => '__return_true',
					'args'                => array( 'token' => $token ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'reschedule' ),
					// Public: as above; acts only on the booking that matches the token, rate-limited per IP.
					'permission_callback' => '__return_true',
					'args'                => array(
						'token' => $token,
						'start' => self::number_arg( 'New start time (Unix timestamp).', true ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/cancel',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'cancel_info' ),
					// Public: the booker isn't logged in; the secret token from their email is the credential.
					'permission_callback' => '__return_true',
					'args'                => array( 'token' => $token ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'cancel' ),
					// Public: as above; acts only on the booking that matches the token, rate-limited per IP.
					'permission_callback' => '__return_true',
					'args'                => array(
						'token'  => $token,
						'reason' => array(
							'description'       => 'Reason for cancelling (optional).',
							'validate_callback' => array( __CLASS__, 'is_text' ),
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);
		Protection::register_routes();
	}

	/**
	 * Argument definition for a date in Y-m-d format.
	 *
	 * @param string $description What the date is.
	 * @return array Argument definition.
	 */
	private static function date_arg( string $description ): array {
		return array(
			'description'       => $description,
			'required'          => true,
			'validate_callback' => array( __CLASS__, 'is_date' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Argument definition for a whole number.
	 *
	 * @param string $description What the number is.
	 * @param bool   $required    Whether the argument is required.
	 * @return array Argument definition.
	 */
	private static function number_arg( string $description, bool $required = false ): array {
		return array(
			'description'       => $description,
			'required'          => $required,
			'validate_callback' => array( __CLASS__, 'is_id' ),
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Argument definition for a short text.
	 *
	 * @param string $description What the text is.
	 * @return array Argument definition.
	 */
	private static function text_arg( string $description ): array {
		return array(
			'description'       => $description,
			'validate_callback' => array( __CLASS__, 'is_text' ),
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Validates a non-negative whole number (an ID, a timestamp or minutes).
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function is_id( $value ): bool {
		return is_int( $value ) ? $value >= 0 : ( is_string( $value ) && 1 === preg_match( '/^\d{1,12}$/', $value ) );
	}

	/**
	 * Validates a date in Y-m-d format.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function is_date( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	/**
	 * Validates a text parameter of reasonable length.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function is_text( $value ): bool {
		return ( is_string( $value ) || is_numeric( $value ) ) && strlen( (string) $value ) <= 10000;
	}

	/**
	 * Validates a JSON object or array.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function is_list( $value ): bool {
		return is_array( $value );
	}

	/**
	 * Validates guests, sent as a list of emails or as one text.
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public static function is_text_or_list( $value ): bool {
		return is_array( $value ) ? count( $value ) <= 100 : self::is_text( $value );
	}

	/**
	 * Keeps text answers only; validate_fields() then sanitizes each by its field type.
	 *
	 * @param array $value Answers keyed by field key.
	 * @return array Answers as strings, keyed by sanitized field key.
	 */
	public static function sanitize_answers( $value ): array {
		$answers = array();
		foreach ( (array) $value as $key => $answer ) {
			if ( is_scalar( $answer ) ) {
				$answers[ sanitize_key( (string) $key ) ] = (string) $answer;
			}
		}
		return $answers;
	}

	/**
	 * Sanitizes guests to a list of texts, or one text.
	 *
	 * @param mixed $value Guests.
	 * @return array|string
	 */
	public static function sanitize_guests( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', array_filter( $value, 'is_scalar' ) );
		}
		return sanitize_textarea_field( (string) $value );
	}

	/**
	 * Keeps only the list of automation signal names.
	 *
	 * @param array $value Browser checks.
	 * @return array Array with the key "signals".
	 */
	public static function sanitize_client( $value ): array {
		$signals = is_array( $value ) && isset( $value['signals'] ) && is_array( $value['signals'] ) ? $value['signals'] : array();
		return array( 'signals' => array_values( array_filter( array_map( 'sanitize_key', array_filter( $signals, 'is_scalar' ) ) ) ) );
	}

	/**
	 * The published booking page named in the route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Configuration, or a 404 error.
	 */
	private static function page( \WP_REST_Request $request ) {
		$id = Pages::find( (int) $request['id'] );
		if ( ! $id ) {
			return new \WP_Error( 'not_found', __( 'Booking page not found.', 'nemvo-booking' ), array( 'status' => 404 ) );
		}
		return Pages::config( $id );
	}

	/**
	 * The requested meeting length if the page offers it, else the page's default.
	 *
	 * @param array $cfg   Booking page configuration.
	 * @param mixed $value Requested length in minutes.
	 * @return int Minutes.
	 */
	private static function duration( array $cfg, $value ): int {
		$value = (int) $value;
		return in_array( $value, $cfg['durations'], true ) ? $value : (int) $cfg['default_duration'];
	}

	/**
	 * The requested time zone if valid, else the booking page's.
	 *
	 * @param array $cfg   Booking page configuration.
	 * @param mixed $value Requested time zone.
	 * @return string
	 */
	private static function tz( array $cfg, $value ): string {
		$value = (string) $value;
		return Plugin::is_valid_timezone( $value ) ? $value : $cfg['timezone'];
	}

	/**
	 * GET /pages/<id>/slots: free start times per day for a date range, plus a fresh form token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function slots( \WP_REST_Request $request ) {
		$cfg = self::page( $request );
		if ( is_wp_error( $cfg ) ) {
			return $cfg;
		}

		$duration = self::duration( $cfg, $request['duration'] );
		$tz_name  = self::tz( $cfg, $request['tz'] );
		$tz       = Plugin::timezone( $tz_name );
		$from     = (string) $request['from'];
		$to       = (string) $request['to'];
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
			return new \WP_Error( 'bad_request', 'Invalid date range.', array( 'status' => 400 ) );
		}

		$from_ts = ( new \DateTimeImmutable( $from . ' 00:00', $tz ) )->getTimestamp();
		$to_ts   = ( new \DateTimeImmutable( $to . ' 00:00', $tz ) )->modify( '+1 day' )->getTimestamp();
		$to_ts   = min( $to_ts, $from_ts + 45 * DAY_IN_SECONDS );

		$days = array();
		foreach ( Availability::slots( $cfg, $duration, $from_ts, $to_ts ) as $start ) {
			$days[ wp_date( 'Y-m-d', $start, $tz ) ][] = array(
				'start' => $start,
				'label' => wp_date( Plugin::time_format(), $start, $tz ),
			);
		}

		list( $earliest, $latest ) = Availability::window( $cfg );
		$next                      = null;
		if ( ! $days ) {
			$next_ts = Availability::next_available( $cfg, $duration, max( $to_ts, $earliest ) );
			$next    = $next_ts ? wp_date( 'Y-m-d', $next_ts, $tz ) : null;
		}

		$response = rest_ensure_response(
			array(
				'days'          => (object) $days,
				'nextAvailable' => $next,
				'minDate'       => wp_date( 'Y-m-d', $earliest, $tz ),
				'maxDate'       => wp_date( 'Y-m-d', $latest - 1, $tz ),
				'bookable'      => Pages::is_bookable( $cfg ),
				'formToken'     => Protection::form_token(),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * POST /pages/<id>/bookings: validates the form, runs the spam checks and creates the booking.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error Summary, thank-you message and redirect URL.
	 */
	public static function book( \WP_REST_Request $request ) {
		$cfg = self::page( $request );
		if ( is_wp_error( $cfg ) ) {
			return $cfg;
		}

		$start = (int) $request['start'];
		if ( $start <= time() ) {
			return new \WP_Error( 'invalid_time', __( 'Please choose a time.', 'nemvo-booking' ), array( 'status' => 400 ) );
		}

		$answers = self::validate_fields( $cfg, (array) $request['fields'] );
		if ( is_wp_error( $answers ) ) {
			return $answers;
		}
		if ( '' !== Privacy::consent_label() && empty( $request['consent'] ) ) {
			return new \WP_Error(
				'invalid_fields',
				__( 'Please check the highlighted fields.', 'nemvo-booking' ),
				array(
					'status' => 400,
					'fields' => array( 'consent' => __( 'Please accept to continue.', 'nemvo-booking' ) ),
				)
			);
		}
		$allowed = Protection::check_booking( $request, $answers['email'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$guests = self::validate_guests( $cfg, $request['guests'], $answers['email'] );
		if ( is_wp_error( $guests ) ) {
			return $guests;
		}

		$booking = Bookings::create(
			$cfg,
			array(
				'start'    => $start,
				'duration' => self::duration( $cfg, $request['duration'] ),
				'timezone' => self::tz( $cfg, $request['tz'] ),
				'answers'  => $answers,
				'guests'   => $guests,
				'page_url' => self::page_url( (string) $request['page_url'] ),
				'ip'       => Plugin::client_ip(),
				'consent'  => ! empty( $request['consent'] ),
			)
		);
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}

		$vars = Bookings::vars( $booking, $cfg, $booking['timezone'] );
		return array(
			'booking'  => Bookings::summary( $booking, $cfg ),
			'message'  => Bookings::render( $cfg['thank_you'], $vars, true ),
			'redirect' => $cfg['redirect_url'],
		);
	}

	/**
	 * Validates and sanitizes the form answers by field type.
	 *
	 * @param array $cfg   Booking page configuration.
	 * @param array $input Submitted answers.
	 * @return array|\WP_Error Answers keyed by field key, or an error listing the invalid fields.
	 */
	private static function validate_fields( array $cfg, array $input ) {
		$answers = array();
		$errors  = array();

		foreach ( $cfg['fields'] as $field ) {
			$key = $field['key'];
			$raw = $input[ $key ] ?? '';
			$raw = is_scalar( $raw ) ? (string) $raw : '';

			switch ( $field['type'] ) {
				case 'checkbox':
					$value = ( '' !== $raw && '0' !== $raw && 'false' !== $raw ) ? '1' : '';
					break;
				case 'textarea':
					$value = mb_substr( sanitize_textarea_field( $raw ), 0, 5000 );
					break;
				case 'email':
					$value = sanitize_email( $raw );
					if ( '' !== trim( $raw ) && ! is_email( $value ) ) {
						$errors[ $key ] = __( 'Please enter a valid email address.', 'nemvo-booking' );
					}
					break;
				case 'url':
					$value = esc_url_raw( $raw );
					break;
				default:
					$value = mb_substr( sanitize_text_field( $raw ), 0, 500 );
			}

			if ( 'select' === $field['type'] && '' !== $value ) {
				$options = array_map( 'trim', preg_split( '/\R/', $field['options'] ) );
				if ( ! in_array( $value, $options, true ) ) {
					$errors[ $key ] = __( 'Please choose one of the options.', 'nemvo-booking' );
				}
			}
			if ( $field['required'] && '' === $value && ! isset( $errors[ $key ] ) ) {
				$errors[ $key ] = __( 'This field is required.', 'nemvo-booking' );
			}
			$answers[ $key ] = $value;
		}

		if ( $errors ) {
			return new \WP_Error(
				'invalid_fields',
				__( 'Please check the highlighted fields.', 'nemvo-booking' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}
		return $answers;
	}

	/**
	 * Validates guest emails: valid addresses, the booker left out, at most the page's maximum.
	 *
	 * @param array        $cfg          Booking page configuration.
	 * @param array|string $input        Guests as a list or separated by commas, spaces or semicolons.
	 * @param string       $booker_email Booker's email address.
	 * @return array|\WP_Error Unique lowercase emails.
	 */
	private static function validate_guests( array $cfg, $input, string $booker_email ) {
		if ( ! $cfg['allow_guests'] || empty( $input ) ) {
			return array();
		}
		$list   = is_array( $input ) ? $input : preg_split( '/[\s,;]+/', (string) $input );
		$guests = array();
		foreach ( $list as $email ) {
			$email = strtolower( trim( (string) $email ) );
			if ( '' === $email ) {
				continue;
			}
			if ( ! is_email( $email ) ) {
				return new \WP_Error(
					'invalid_fields',
					/* translators: %s: email address */
					sprintf( __( '"%s" is not a valid email address.', 'nemvo-booking' ), $email ),
					array(
						'status' => 400,
						'fields' => array( 'guests' => __( 'Please enter valid email addresses.', 'nemvo-booking' ) ),
					)
				);
			}
			if ( strtolower( $booker_email ) !== $email ) {
				$guests[ $email ] = $email;
			}
		}
		if ( count( $guests ) > $cfg['max_guests'] ) {
			return new \WP_Error(
				'invalid_fields',
				/* translators: %d: maximum number of guests */
				sprintf( __( 'You can add at most %d guests.', 'nemvo-booking' ), $cfg['max_guests'] ),
				array(
					'status' => 400,
					/* translators: %d: maximum number of guests */
					'fields' => array( 'guests' => sprintf( __( 'You can add at most %d guests.', 'nemvo-booking' ), $cfg['max_guests'] ) ),
				)
			);
		}
		return array_values( $guests );
	}

	/**
	 * Only keeps URLs on this site, without any booker link parameters; they are used in cancel links.
	 *
	 * @param string $url Submitted page address.
	 * @return string URL or ''.
	 */
	private static function page_url( string $url ): string {
		$url = esc_url_raw( strtok( $url, '#' ) );
		if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return '';
		}
		return remove_query_arg( array( Bookings::CANCEL_PARAM, Bookings::RESCHEDULE_PARAM, Bookings::LEGACY_CANCEL_PARAM, Bookings::LEGACY_RESCHEDULE_PARAM ), $url );
	}

	/**
	 * The booking that matches the token in the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error The booking, or a 404 error (also for failed or pending bookings).
	 */
	private static function booking_from_token( \WP_REST_Request $request ) {
		$booking = Bookings::by_token( sanitize_text_field( (string) $request['token'] ) );
		if ( ! $booking || 'failed' === $booking['status'] || 'pending' === $booking['status'] ) {
			return new \WP_Error( 'not_found', __( 'We could not find this booking.', 'nemvo-booking' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

	/**
	 * GET /reschedule: the booking and its page's widget configuration, to pick a new time.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function reschedule_info( \WP_REST_Request $request ) {
		if ( Protection::rate_limited( 'cancel', 60 ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'nemvo-booking' ), array( 'status' => 429 ) );
		}
		$booking = self::booking_from_token( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$cfg = Pages::config( $booking['page_id'] );
		return array(
			'booking' => Bookings::summary( $booking, $cfg ),
			'config'  => Frontend::client_config( $cfg ),
		);
	}

	/**
	 * POST /reschedule: moves the booking to a new start time.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function reschedule( \WP_REST_Request $request ) {
		if ( Protection::rate_limited( 'cancel', 60 ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'nemvo-booking' ), array( 'status' => 429 ) );
		}
		$booking = self::booking_from_token( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$result = Bookings::reschedule( $booking, (int) $request['start'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'booking' => Bookings::summary( $result, Pages::config( $result['page_id'] ) ),
			'message' => '<h3>' . esc_html__( 'Your meeting has been moved', 'nemvo-booking' ) . '</h3><p>' . esc_html__( 'Everyone has been notified and the calendar invitation has been updated. The Google Meet link stays the same.', 'nemvo-booking' ) . '</p>',
		);
	}

	/**
	 * GET /cancel: the booking, for the cancel screen.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function cancel_info( \WP_REST_Request $request ) {
		if ( Protection::rate_limited( 'cancel', 60 ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'nemvo-booking' ), array( 'status' => 429 ) );
		}
		$booking = self::booking_from_token( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		return array( 'booking' => Bookings::summary( $booking, Pages::config( $booking['page_id'] ) ) );
	}

	/**
	 * POST /cancel: cancels the booking.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function cancel( \WP_REST_Request $request ) {
		if ( Protection::rate_limited( 'cancel', 60 ) ) {
			return new \WP_Error( 'rate_limited', __( 'Too many requests. Please try again later.', 'nemvo-booking' ), array( 'status' => 429 ) );
		}
		$booking = self::booking_from_token( $request );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$result = Bookings::cancel( $booking, mb_substr( sanitize_textarea_field( (string) $request['reason'] ), 0, 1000 ), 'booker' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$booking = Bookings::get( $booking['id'] );
		return array( 'booking' => Bookings::summary( $booking, Pages::config( $booking['page_id'] ) ) );
	}
}
