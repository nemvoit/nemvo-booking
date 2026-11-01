<?php
namespace NemvoBooking\Admin;

use NemvoBooking\Employees;
use NemvoBooking\Google;
use NemvoBooking\Pages;

defined( 'ABSPATH' ) || exit;

class Admin {

	const SLUG = 'nemvo-booking';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'submenus' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		SettingsPage::init();
		EmployeesPage::init();
		PageEditor::init();
		BookingsPage::init();
	}

	public static function menu() {
		add_menu_page( __( 'Bookings', 'nemvo-booking' ), __( 'Bookings', 'nemvo-booking' ), self::CAP, self::SLUG, array( BookingsPage::class, 'render' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( self::SLUG, __( 'Bookings', 'nemvo-booking' ), __( 'Bookings', 'nemvo-booking' ), self::CAP, self::SLUG, array( BookingsPage::class, 'render' ) );
	}

	public static function submenus() {
		add_submenu_page( self::SLUG, __( 'Employees', 'nemvo-booking' ), __( 'Employees', 'nemvo-booking' ), self::CAP, self::SLUG . '-employees', array( EmployeesPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'History log', 'nemvo-booking' ), __( 'History log', 'nemvo-booking' ), self::CAP, self::SLUG . '-log', array( LogPage::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Booking settings', 'nemvo-booking' ), __( 'Settings', 'nemvo-booking' ), self::CAP, self::SLUG . '-settings', array( SettingsPage::class, 'render' ) );
	}

	private static function is_our_screen(): bool {
		$screen = get_current_screen();
		return $screen && ( false !== strpos( $screen->id, self::SLUG ) || Pages::CPT === $screen->post_type );
	}

	public static function assets() {
		if ( ! self::is_our_screen() ) {
			return;
		}
		if ( false !== strpos( get_current_screen()->id, self::SLUG . '-employees' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'nemvo-booking-admin', NEMVO_BOOKING_URL . 'assets/css/admin.css', array(), NEMVO_BOOKING_VERSION );
		wp_enqueue_script( 'nemvo-booking-admin', NEMVO_BOOKING_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), NEMVO_BOOKING_VERSION, true );
		wp_localize_script(
			'nemvo-booking-admin',
			'nemvoBookingAdmin',
			array(
				'copied'        => __( 'Copied!', 'nemvo-booking' ),
				'confirmDelete' => __( 'Are you sure?', 'nemvo-booking' ),
				'confirmReset'  => __( 'Replace this email with the default text?', 'nemvo-booking' ),
				'choosePhoto'   => __( 'Choose a photo', 'nemvo-booking' ),
				'usePhoto'      => __( 'Use this photo', 'nemvo-booking' ),
				'testing'       => __( 'Testing…', 'nemvo-booking' ),
				'testFailed'    => __( 'The test could not be run. Reload the page and try again.', 'nemvo-booking' ),
			)
		);
	}

	public static function notices() {
		self::broken_connection_notice();
		if ( ! self::is_our_screen() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( ! Google::is_configured() && self::SLUG . '-settings' !== $page ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Google is not connected yet, so nothing can be booked.', 'nemvo-booking' ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
				esc_html__( 'Follow the setup guide in Settings →', 'nemvo-booking' )
			);
		}

		$messages = array(
			'saved'        => __( 'Saved.', 'nemvo-booking' ),
			'created'      => __( 'Employee added. Now connect their Google Calendar below.', 'nemvo-booking' ),
			'deleted'      => __( 'Deleted.', 'nemvo-booking' ),
			'connected'    => __( 'Google Calendar connected.', 'nemvo-booking' ),
			'disconnected' => __( 'Google Calendar disconnected.', 'nemvo-booking' ),
			'refreshed'    => __( 'Calendar list refreshed.', 'nemvo-booking' ),
			'link'         => __( 'A new connect link was created. Copy it and send it to the employee.', 'nemvo-booking' ),
			'cancelled'    => __( 'The booking was cancelled and everyone has been notified.', 'nemvo-booking' ),
		);
		if ( isset( $_GET['nb_notice'], $messages[ $_GET['nb_notice'] ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ sanitize_key( $_GET['nb_notice'] ) ] ) );
		}
		if ( isset( $_GET['nb_error'] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['nb_error'] ) ) ) );
		}
		// phpcs:enable
	}

	/** Shown on every admin screen while a host's Google connection is broken, since bookings stop. */
	private static function broken_connection_notice() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		foreach ( Employees::all() as $employee ) {
			if ( ! $employee['last_error'] || ! Employees::is_connected( $employee ) ) {
				continue;
			}
			$pages = Employees::page_titles( $employee );
			if ( ! $pages ) {
				continue;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html(
					/* translators: %s: employee name */
					sprintf( __( 'Bookings: the Google Calendar connection for %s is not working.', 'nemvo-booking' ), $employee['name'] )
				),
				esc_html(
					/* translators: %s: booking page titles */
					sprintf( __( 'These booking pages show no available times: %s.', 'nemvo-booking' ), implode( ', ', $pages ) )
				),
				esc_url( admin_url( 'admin.php?page=nemvo-booking-employees&edit=' . $employee['id'] ) ),
				esc_html__( 'Reconnect →', 'nemvo-booking' )
			);
		}
	}

	/** Verifies capability and nonce for admin-post handlers. */
	public static function check( string $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nemvo-booking' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function redirect( string $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	public static function copy_field( string $value, string $class = 'regular-text' ): string {
		return sprintf(
			'<span class="nb-copy"><input type="text" readonly class="%1$s code" value="%2$s" onfocus="this.select()"><button type="button" class="button" data-copy>%3$s</button></span>',
			esc_attr( $class ),
			esc_attr( $value ),
			esc_html__( 'Copy', 'nemvo-booking' )
		);
	}

	public static function status_badge( string $status ): string {
		$labels = array(
			'confirmed' => __( 'Confirmed', 'nemvo-booking' ),
			'cancelled' => __( 'Cancelled', 'nemvo-booking' ),
			'failed'    => __( 'Failed', 'nemvo-booking' ),
			'pending'   => __( 'Pending', 'nemvo-booking' ),
			'info'      => __( 'Info', 'nemvo-booking' ),
			'warning'   => __( 'Warning', 'nemvo-booking' ),
			'error'     => __( 'Error', 'nemvo-booking' ),
		);
		return sprintf( '<span class="nb-badge nb-badge--%1$s">%2$s</span>', esc_attr( $status ), esc_html( $labels[ $status ] ?? $status ) );
	}

	public static function pagination( int $total, int $per_page, int $current ) {
		$pages = (int) ceil( $total / $per_page );
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $current,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}
}
