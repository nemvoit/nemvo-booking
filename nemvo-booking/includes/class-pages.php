<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

/**
 * Booking pages: a private post type whose settings live in a single meta array.
 */
class Pages {

	const CPT  = 'nb_booking_page';
	const META = '_nemvo_booking';

	const FIELD_TYPES = array( 'text', 'email', 'tel', 'url', 'textarea', 'select', 'checkbox' );
	const DAYS        = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		$cap = 'manage_options';
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Booking pages', 'nemvo-booking' ),
					'singular_name' => __( 'Booking page', 'nemvo-booking' ),
					'add_new'       => __( 'Add booking page', 'nemvo-booking' ),
					'add_new_item'  => __( 'Add booking page', 'nemvo-booking' ),
					'edit_item'     => __( 'Edit booking page', 'nemvo-booking' ),
					'all_items'     => __( 'Booking pages', 'nemvo-booking' ),
					'not_found'     => __( 'No booking pages yet.', 'nemvo-booking' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'nemvo-booking',
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'edit_published_posts'   => $cap,
				),
			)
		);
	}

	public static function default_fields(): array {
		return array(
			array( 'key' => 'name', 'label' => __( 'Name', 'nemvo-booking' ), 'type' => 'text', 'required' => 1, 'placeholder' => '', 'options' => '' ),
			array( 'key' => 'email', 'label' => __( 'Email', 'nemvo-booking' ), 'type' => 'email', 'required' => 1, 'placeholder' => '', 'options' => '' ),
			array( 'key' => 'company', 'label' => __( 'Company', 'nemvo-booking' ), 'type' => 'text', 'required' => 0, 'placeholder' => '', 'options' => '' ),
			array( 'key' => 'title', 'label' => __( 'Job title', 'nemvo-booking' ), 'type' => 'text', 'required' => 0, 'placeholder' => '', 'options' => '' ),
			array( 'key' => 'phone', 'label' => __( 'Phone', 'nemvo-booking' ), 'type' => 'tel', 'required' => 0, 'placeholder' => '', 'options' => '' ),
			array( 'key' => 'description', 'label' => __( 'What would you like to discuss?', 'nemvo-booking' ), 'type' => 'textarea', 'required' => 0, 'placeholder' => __( 'Share anything that will help us prepare for the meeting.', 'nemvo-booking' ), 'options' => '' ),
		);
	}

	public static function defaults(): array {
		return array(
			'description'      => '',
			'show_photos'      => 1,
			'employees'        => array(),
			'organizer'        => 0,
			'durations'        => array( 30 ),
			'default_duration' => 30,
			'interval'         => 30,
			'min_notice'       => 24,
			'max_days'         => 60,
			'buffer_before'    => 0,
			'buffer_after'     => 0,
			'timezone'         => wp_timezone_string(),
			'availability'     => array(
				'mon' => '09:00-17:00',
				'tue' => '09:00-17:00',
				'wed' => '09:00-17:00',
				'thu' => '09:00-17:00',
				'fri' => '09:00-17:00',
				'sat' => '',
				'sun' => '',
			),
			'exclude_holidays' => 1,
			'allow_reschedule' => 1,
			'fields'           => self::default_fields(),
			'allow_guests'     => 1,
			'max_guests'       => 5,
			'meeting_title'    => '{page_title}: {name}',
			'thank_you'        => __( "<h3>You're booked, {name}!</h3>\n<p>A calendar invitation with the Google Meet link is on its way to {email}.</p>\n<p>Looking forward to talking to you.</p>", 'nemvo-booking' ),
			'redirect_url'     => '',
		);
	}

	public static function config( int $post_id ): array {
		$saved = get_post_meta( $post_id, self::META, true );
		$cfg   = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		// Hydrated values used everywhere.
		$cfg['id']    = $post_id;
		$cfg['title'] = get_the_title( $post_id );
		if ( ! Plugin::is_valid_timezone( $cfg['timezone'] ) ) {
			$cfg['timezone'] = wp_timezone_string();
		}
		return $cfg;
	}

	/** Resolves an ID or slug to a published booking page ID. */
	public static function find( $id_or_slug ): int {
		if ( is_numeric( $id_or_slug ) ) {
			$post = get_post( (int) $id_or_slug );
		} else {
			$post = get_page_by_path( sanitize_title( (string) $id_or_slug ), OBJECT, self::CPT );
		}
		return ( $post && self::CPT === $post->post_type && 'publish' === $post->post_status ) ? (int) $post->ID : 0;
	}

	/** @return \WP_Post[] Published posts/pages that show this booking page (block or shortcode). */
	public static function embedded_on( int $id ): array {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type NOT IN ('revision', %s) AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)",
				self::CPT,
				'%' . $wpdb->esc_like( '"pageId":' . $id . '}' ) . '%',
				'%' . $wpdb->esc_like( '"pageId":' . $id . ',' ) . '%',
				'%' . $wpdb->esc_like( 'nemvo_booking id="' . $id . '"' ) . '%'
			)
		);
		return array_filter( array_map( 'get_post', $ids ) );
	}

	public static function published(): array {
		$posts = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$out[ $post->ID ] = $post->post_title;
		}
		return $out;
	}

	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$cfg = array();

		$cfg['description'] = wp_kses_post( $in['description'] ?? '' );
		$cfg['show_photos'] = empty( $in['show_photos'] ) ? 0 : 1;
		$cfg['employees']   = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['employees'] ?? array() ) ) ) ) );
		// The organizer is stored first; the meeting is created in their calendar.
		$organizer        = absint( $in['organizer'] ?? 0 );
		$cfg['organizer'] = in_array( $organizer, $cfg['employees'], true ) ? $organizer : ( $cfg['employees'][0] ?? 0 );
		if ( $cfg['organizer'] ) {
			$cfg['employees'] = array_values( array_unique( array_merge( array( $cfg['organizer'] ), $cfg['employees'] ) ) );
		}

		$durations = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['durations'] ?? array() ) ) ) ) );
		sort( $durations );
		$cfg['durations']        = $durations ?: $d['durations'];
		$default_duration        = absint( $in['default_duration'] ?? 0 );
		$cfg['default_duration'] = in_array( $default_duration, $cfg['durations'], true ) ? $default_duration : $cfg['durations'][0];

		$cfg['interval']      = max( 5, min( 240, absint( $in['interval'] ?? $d['interval'] ) ) );
		$cfg['min_notice']    = max( 0, min( 24 * 365, absint( $in['min_notice'] ?? $d['min_notice'] ) ) );
		$cfg['max_days']      = max( 1, min( 730, absint( $in['max_days'] ?? $d['max_days'] ) ) );
		$cfg['buffer_before'] = max( 0, min( 240, absint( $in['buffer_before'] ?? 0 ) ) );
		$cfg['buffer_after']  = max( 0, min( 240, absint( $in['buffer_after'] ?? 0 ) ) );
		$tz                   = sanitize_text_field( $in['timezone'] ?? '' );
		$cfg['timezone']      = Plugin::is_valid_timezone( $tz ) ? $tz : wp_timezone_string();

		$cfg['availability'] = array();
		foreach ( self::DAYS as $day ) {
			$ranges                      = self::parse_ranges( (string) ( $in['availability'][ $day ] ?? '' ) );
			$cfg['availability'][ $day ] = implode(
				', ',
				array_map(
					function ( $r ) {
						return sprintf( '%02d:%02d-%02d:%02d', intdiv( $r[0], 60 ), $r[0] % 60, intdiv( $r[1], 60 ), $r[1] % 60 );
					},
					$ranges
				)
			);
		}

		$cfg['exclude_holidays'] = empty( $in['exclude_holidays'] ) ? 0 : 1;
		$cfg['allow_reschedule'] = empty( $in['allow_reschedule'] ) ? 0 : 1;
		$cfg['fields']           = self::sanitize_fields( (array) ( $in['fields'] ?? array() ) );
		$cfg['allow_guests']     = empty( $in['allow_guests'] ) ? 0 : 1;
		$cfg['max_guests']       = max( 1, min( 50, absint( $in['max_guests'] ?? $d['max_guests'] ) ) );
		$cfg['meeting_title']    = sanitize_text_field( $in['meeting_title'] ?? '' ) ?: $d['meeting_title'];
		$cfg['thank_you']        = wp_kses_post( $in['thank_you'] ?? '' );
		$cfg['redirect_url']     = esc_url_raw( $in['redirect_url'] ?? '' );

		return $cfg;
	}

	private static function sanitize_fields( array $fields ): array {
		$out  = array();
		$seen = array();
		foreach ( $fields as $f ) {
			$label = sanitize_text_field( $f['label'] ?? '' );
			$key   = sanitize_key( $f['key'] ?? '' ) ?: sanitize_key( str_replace( ' ', '_', $label ) );
			if ( '' === $label || '' === $key || isset( $seen[ $key ] ) || in_array( $key, array( 'guests', 'website' ), true ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$type         = in_array( $f['type'] ?? '', self::FIELD_TYPES, true ) ? $f['type'] : 'text';
			$out[]        = array(
				'key'         => $key,
				'label'       => $label,
				'type'        => $type,
				'required'    => empty( $f['required'] ) ? 0 : 1,
				'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
				'options'     => sanitize_textarea_field( $f['options'] ?? '' ),
			);
		}

		// Name and email are always present and required.
		$core = array(
			'email' => array( 'key' => 'email', 'label' => __( 'Email', 'nemvo-booking' ), 'type' => 'email', 'required' => 1, 'placeholder' => '', 'options' => '' ),
			'name'  => array( 'key' => 'name', 'label' => __( 'Name', 'nemvo-booking' ), 'type' => 'text', 'required' => 1, 'placeholder' => '', 'options' => '' ),
		);
		foreach ( $core as $key => $field ) {
			$index = array_search( $key, array_column( $out, 'key' ), true );
			if ( false === $index ) {
				array_unshift( $out, $field );
			} else {
				$out[ $index ]['required'] = 1;
				$out[ $index ]['type']     = $field['type'];
			}
		}
		return $out;
	}

	/**
	 * Parses "09:00-12:00, 13:00-17:00" into [[540, 720], [780, 1020]] (minutes from midnight).
	 */
	public static function parse_ranges( string $value ): array {
		$ranges = array();
		foreach ( preg_split( '/[,;\n]+/', $value ) as $part ) {
			if ( ! preg_match( '/^\s*(\d{1,2})[:.]?(\d{2})?\s*-\s*(\d{1,2})[:.]?(\d{2})?\s*$/', $part, $m ) ) {
				continue;
			}
			$start = (int) $m[1] * 60 + (int) ( $m[2] ?? 0 );
			$end   = (int) $m[3] * 60 + (int) ( $m[4] ?? 0 );
			if ( $start < $end && $end <= 24 * 60 ) {
				$ranges[] = array( $start, $end );
			}
		}
		usort(
			$ranges,
			function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		return $ranges;
	}

	/** Hosts of this page, organizer first. All of them attend every meeting. */
	public static function hosts( array $cfg ): array {
		return Employees::many( $cfg['employees'] );
	}

	/** Bookable when Google is set up and every host has connected their calendar. */
	public static function is_bookable( array $cfg ): bool {
		$hosts = self::hosts( $cfg );
		if ( ! $hosts || ! Google::is_configured() ) {
			return false;
		}
		return count( $hosts ) === count( array_filter( $hosts, array( Employees::class, 'is_connected' ) ) );
	}
}
