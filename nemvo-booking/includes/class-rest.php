<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Public REST API used by the booking widget. No nonces, so it keeps working behind page caches.
 */
class Rest {

	const NS = 'nemvo-booking/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/pages/(?P<id>\d+)/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'slots' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/pages/(?P<id>\d+)/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/reschedule',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'reschedule_info' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'reschedule' ),
					'permission_callback' => '__return_true',
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
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'cancel' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/** @return array|\WP_Error */
	private static function page( \WP_REST_Request $request ) {
		$id = Pages::find( (int) $request['id'] );
		if ( ! $id ) {
			return new \WP_Error( 'not_found', __( 'Booking page not found.', 'nemvo-booking' ), array( 'status' => 404 ) );
		}
		return Pages::config( $id );
	}

	private static function duration( array $cfg, $value ): int {
		$value = (int) $value;
		return in_array( $value, $cfg['durations'], true ) ? $value : (int) $cfg['default_duration'];
	}

	private static function tz( array $cfg, $value ): string {
		$value = (string) $value;
		return Plugin::is_valid_timezone( $value ) ? $value : $cfg['timezone'];
	}

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

	/** @return array|\WP_Error Answers keyed by field key. */
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

	/** @return array|\WP_Error */
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
					'fields' => array( 'guests' => sprintf( __( 'You can add at most %d guests.', 'nemvo-booking' ), $cfg['max_guests'] ) ), // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
				)
			);
		}
		return array_values( $guests );
	}

	/** Only keep URLs on this site; they are used in cancel links. */
	private static function page_url( string $url ): string {
		$url = esc_url_raw( strtok( $url, '#' ) );
		if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return '';
		}
		return remove_query_arg( 'nb_cancel', $url );
	}

	/** @return array|\WP_Error */
	private static function booking_from_token( \WP_REST_Request $request ) {
		$booking = Bookings::by_token( sanitize_text_field( (string) $request['token'] ) );
		if ( ! $booking || 'failed' === $booking['status'] || 'pending' === $booking['status'] ) {
			return new \WP_Error( 'not_found', __( 'We could not find this booking.', 'nemvo-booking' ), array( 'status' => 404 ) );
		}
		return $booking;
	}

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
