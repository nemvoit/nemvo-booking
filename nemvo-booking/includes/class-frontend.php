<?php
/**
 * Booking widget on the site: shortcode, block and standalone cancel page.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [nemvo_booking id="123"], the "Booking page" block and the cancel page.
 */
class Frontend {

	const BLOCK = 'nemvo-booking/booking-page';

	/**
	 * Hooks in the shortcode, the block, the assets and the standalone cancel page.
	 */
	public static function init() {
		add_shortcode( 'nemvo_booking', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'template_redirect', array( __CLASS__, 'standalone_cancel_page' ) );
	}

	/**
	 * Registers the widget's stylesheet and script, plus the Accent color setting as inline CSS.
	 */
	public static function register_assets() {
		if ( wp_style_is( 'nemvo-booking', 'registered' ) ) {
			return;
		}
		wp_register_style( 'nemvo-booking', NEMVO_BOOKING_URL . 'assets/css/frontend.css', array(), NEMVO_BOOKING_VERSION );
		wp_register_script( 'nemvo-booking', NEMVO_BOOKING_URL . 'assets/js/frontend.js', array(), NEMVO_BOOKING_VERSION, true );
		$accent = self::accent_css();
		if ( $accent ) {
			wp_add_inline_style( 'nemvo-booking', $accent );
		}
	}

	/**
	 * The Accent color setting as a zero-specificity token default, so a theme's `.nb-booking` rule
	 * still wins. Empty when the setting matches the stylesheet's own default.
	 */
	private static function accent_css(): string {
		$accent = strtolower( (string) sanitize_hex_color( (string) Settings::get( 'accent_color' ) ) );
		if ( '' === $accent || Settings::defaults()['accent_color'] === $accent ) {
			return '';
		}
		return ':where(.nb-booking){--nb-accent:' . $accent . '}';
	}

	/**
	 * Registers the "Booking page" block, its editor script and the data the editor needs.
	 */
	public static function register_block() {
		wp_register_script(
			'nemvo-booking-block',
			NEMVO_BOOKING_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			NEMVO_BOOKING_VERSION,
			true
		);
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 2,
				'editor_script'   => 'nemvo-booking-block',
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'pageId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'align'  => array(
						'type' => 'string',
					),
				),
				'supports'        => array(
					'align' => array( 'wide', 'full' ),
					'html'  => false,
				),
			)
		);
		wp_set_script_translations( 'nemvo-booking-block', 'nemvo-booking' );
		add_action(
			'enqueue_block_editor_assets',
			function () {
				$pages = array();
				foreach ( Pages::published() as $id => $title ) {
					$pages[] = array(
						'id'    => $id,
						'title' => $title,
					);
				}
				wp_localize_script(
					'nemvo-booking-block',
					'nemvoBookingBlock',
					array(
						'pages'  => $pages,
						'newUrl' => admin_url( 'post-new.php?post_type=' . Pages::CPT ),
					)
				);
			}
		);
	}

	/**
	 * Renders the block on the site.
	 *
	 * @param array $attributes Block attributes (pageId, align).
	 * @return string HTML.
	 */
	public static function render_block( $attributes ): string {
		$html = self::shortcode( array( 'id' => (int) ( $attributes['pageId'] ?? 0 ) ) );
		return $html ? '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>' : '';
	}

	/**
	 * [nemvo_booking id="123"] (or page="slug"): the booking widget for one booking page.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML; an explanation for admins when the page isn't found, empty for visitors.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => '',
				'page' => '',
			),
			$atts,
			'nemvo_booking'
		);
		$id   = Pages::find( '' !== $atts['id'] ? $atts['id'] : $atts['page'] );
		if ( ! $id ) {
			return current_user_can( Plugin::capability() )
				? '<p><em>' . esc_html__( 'Booking page not found or not published.', 'nemvo-booking' ) . '</em></p>'
				: '';
		}

		self::register_assets();
		wp_enqueue_style( 'nemvo-booking' );
		wp_enqueue_script( 'nemvo-booking' );
		Protection::enqueue_cap();

		return self::markup( self::client_config( Pages::config( $id ) ) );
	}

	/**
	 * The widget's container; frontend.js renders the rest from the configuration.
	 *
	 * @param array $config Client configuration from client_config().
	 * @return string HTML.
	 */
	private static function markup( array $config ): string {
		return sprintf(
			'<div class="nb-booking" data-config="%1$s"><div class="nb-loading">%2$s</div><noscript>%3$s</noscript></div>',
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'Loading…', 'nemvo-booking' ),
			esc_html__( 'Please enable JavaScript to book a meeting.', 'nemvo-booking' )
		);
	}

	/**
	 * Token from a cancel link (?nemvo_booking_cancel=…, or ?nb_cancel=… in links sent before 1.2.2).
	 *
	 * @return string The token, or '' when the page wasn't opened from a cancel link.
	 */
	private static function cancel_token(): string {
		return self::link_token( Bookings::CANCEL_PARAM, Bookings::LEGACY_CANCEL_PARAM );
	}

	/**
	 * Token from a reschedule link (?nemvo_booking_reschedule=…, or ?nb_reschedule=… before 1.2.2).
	 *
	 * @return string The token, or '' when the page wasn't opened from a reschedule link.
	 */
	private static function reschedule_token(): string {
		return self::link_token( Bookings::RESCHEDULE_PARAM, Bookings::LEGACY_RESCHEDULE_PARAM );
	}

	/**
	 * Reads a booker link token from the query string. The token itself is the credential (a
	 * random 40-character secret), so there is no nonce; the REST API checks it.
	 *
	 * @param string $param  Current parameter name.
	 * @param string $legacy Parameter name used in emails sent before 1.2.2, still accepted.
	 * @return string Sanitized token or ''.
	 */
	private static function link_token( string $param, string $legacy ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( array( $param, $legacy ) as $name ) {
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				return sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		// phpcs:enable
		return '';
	}

	/**
	 * Configuration for the widget script.
	 *
	 * @param array|null $cfg Booking page configuration, or null on the standalone cancel page.
	 * @return array
	 */
	public static function client_config( ?array $cfg ): array {
		$config = array(
			'rest'            => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'locale'          => str_replace( '_', '-', determine_locale() ),
			'hour12'          => '12' === Settings::get( 'time_format' ),
			'weekNumbers'     => (bool) Settings::get( 'week_numbers' ),
			'cancelToken'     => self::cancel_token(),
			'rescheduleToken' => self::reschedule_token(),
			'consent'         => Privacy::consent_label(),
			'i18n'            => self::strings(),
			'pageId'          => 0,
			'protection'      => Protection::client_config(),
		);
		if ( ! $cfg ) {
			return $config;
		}

		$fields = array();
		foreach ( $cfg['fields'] as $field ) {
			$field['options']  = 'select' === $field['type'] ? array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $field['options'] ) ) ) ) : array();
			$field['required'] = (bool) $field['required'];
			$fields[]          = $field;
		}

		return array_merge(
			$config,
			array(
				'pageId'          => $cfg['id'],
				'title'           => $cfg['title'],
				'hosts'           => $cfg['show_photos'] ? self::host_photos( $cfg ) : array(),
				'description'     => wpautop( wp_kses_post( $cfg['description'] ) ),
				'durations'       => $cfg['durations'],
				'defaultDuration' => $cfg['default_duration'],
				'timezone'        => $cfg['timezone'],
				'fields'          => $fields,
				'guests'          => array(
					'allowed' => (bool) $cfg['allow_guests'],
					'max'     => $cfg['max_guests'],
				),
				'bookable'        => Pages::is_bookable( $cfg ),
			)
		);
	}

	/**
	 * Names and photos of the hosts that have a photo, organizer first.
	 *
	 * @param array $cfg Booking page configuration.
	 * @return array[] Each with name and photo URL.
	 */
	private static function host_photos( array $cfg ): array {
		$hosts = array();
		foreach ( Pages::hosts( $cfg ) as $employee ) {
			$photo = Employees::photo( $employee );
			if ( $photo ) {
				$hosts[] = array(
					'name'  => $employee['name'],
					'photo' => $photo,
				);
			}
		}
		return $hosts;
	}

	/**
	 * Translated texts for the widget script.
	 *
	 * @return string[]
	 */
	private static function strings(): array {
		return array(
			'selectDate'        => __( 'Select a date', 'nemvo-booking' ),
			'selectTime'        => __( 'Select a time', 'nemvo-booking' ),
			'noTimes'           => __( 'No available times on this day.', 'nemvo-booking' ),
			'noTimesMonth'      => __( 'No available times this month.', 'nemvo-booking' ),
			'jumpNext'          => __( 'Go to next available date', 'nemvo-booking' ),
			'noAvailability'    => __( 'There are no available times at the moment. Please check back later.', 'nemvo-booking' ),
			'notBookable'       => __( 'Booking is not available at the moment.', 'nemvo-booking' ),
			'prevMonth'         => __( 'Previous month', 'nemvo-booking' ),
			'nextMonth'         => __( 'Next month', 'nemvo-booking' ),
			'week'              => _x( 'W', 'week number column heading', 'nemvo-booking' ),
			/* translators: %d: minutes */
			'minutes'           => __( '%d min', 'nemvo-booking' ),
			'duration'          => __( 'Duration', 'nemvo-booking' ),
			'timezone'          => __( 'Time zone', 'nemvo-booking' ),
			'googleMeet'        => __( 'Google Meet', 'nemvo-booking' ),
			'with'              => __( 'With', 'nemvo-booking' ),
			'back'              => __( 'Back', 'nemvo-booking' ),
			'yourDetails'       => __( 'Your details', 'nemvo-booking' ),
			'confirm'           => __( 'Confirm booking', 'nemvo-booking' ),
			'booking'           => __( 'Booking…', 'nemvo-booking' ),
			'addGuests'         => __( '+ Add guests', 'nemvo-booking' ),
			'guests'            => __( 'Guest emails', 'nemvo-booking' ),
			'guestsHelp'        => __( 'Separate multiple emails with commas. Guests also receive the invitation.', 'nemvo-booking' ),
			'optional'          => __( 'optional', 'nemvo-booking' ),
			'required'          => __( 'This field is required.', 'nemvo-booking' ),
			'invalidEmail'      => __( 'Please enter a valid email address.', 'nemvo-booking' ),
			'error'             => __( 'Something went wrong. Please try again.', 'nemvo-booking' ),
			'loading'           => __( 'Loading…', 'nemvo-booking' ),
			'joinMeet'          => __( 'Join with Google Meet', 'nemvo-booking' ),
			'cancelTitle'       => __( 'Cancel your meeting', 'nemvo-booking' ),
			'cancelReason'      => __( 'Reason for cancelling (optional)', 'nemvo-booking' ),
			'cancelButton'      => __( 'Cancel meeting', 'nemvo-booking' ),
			'cancelling'        => __( 'Cancelling…', 'nemvo-booking' ),
			'cancelled'         => __( 'Your meeting has been cancelled. Everyone has been notified.', 'nemvo-booking' ),
			'alreadyCancelled'  => __( 'This meeting has already been cancelled.', 'nemvo-booking' ),
			'cannotCancel'      => __( 'This meeting has already taken place and can no longer be cancelled.', 'nemvo-booking' ),
			'bookNew'           => __( 'Book a new time', 'nemvo-booking' ),
			'rescheduleTitle'   => __( 'Pick a new time', 'nemvo-booking' ),
			'currentTime'       => __( 'Current time', 'nemvo-booking' ),
			'newTime'           => __( 'New time', 'nemvo-booking' ),
			'confirmMove'       => __( 'Move meeting', 'nemvo-booking' ),
			'moving'            => __( 'Moving…', 'nemvo-booking' ),
			'rescheduleInstead' => __( 'Pick a new time instead', 'nemvo-booking' ),
			'cannotReschedule'  => __( 'This meeting can no longer be rescheduled.', 'nemvo-booking' ),
			'consentRequired'   => __( 'Please accept to continue.', 'nemvo-booking' ),
			'verifying'         => __( 'Verifying you are human…', 'nemvo-booking' ),
			'capRequired'       => __( 'Please complete the verification above.', 'nemvo-booking' ),
			'capFailed'         => __( 'The verification could not be completed. Please try again.', 'nemvo-booking' ),
		);
	}

	/**
	 * Cancel links normally point to the page the booking was made on. If that page no longer
	 * contains the booking widget, render a minimal standalone cancel page instead.
	 */
	public static function standalone_cancel_page() {
		if ( '' === self::cancel_token() && '' === self::reschedule_token() ) {
			return;
		}
		$post = get_queried_object();
		if ( $post instanceof \WP_Post && ( has_shortcode( $post->post_content, 'nemvo_booking' ) || has_block( self::BLOCK, $post ) ) ) {
			return;
		}

		$config = self::client_config( null );
		self::register_assets();
		// Page frame for the standalone page. The .nb-booking rule needs class specificity to beat the
		// stylesheet's own margin; anything printed by the nemvo_booking_standalone_head hook comes later and wins.
		wp_add_inline_style( 'nemvo-booking', ':where(body){margin:0;padding:48px 16px;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}.nb-booking{max-width:1100px;margin:0 auto}' );
		nocache_headers();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( ( self::reschedule_token() ? $config['i18n']['rescheduleTitle'] : $config['i18n']['cancelTitle'] ) . ' – ' . get_bloginfo( 'name' ) ); ?></title>
		<?php wp_print_styles( 'nemvo-booking' ); ?>
		<?php do_action( 'nemvo_booking_standalone_head' ); ?>
</head>
<body>
		<?php echo self::markup( $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php wp_print_scripts( 'nemvo-booking' ); ?>
</body>
</html>
		<?php
		exit;
	}
}
