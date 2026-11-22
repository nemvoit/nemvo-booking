<?php
/**
 * The wp-admin menu and shared admin helpers.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking\Admin;

use NemvoBooking\Accounts;
use NemvoBooking\Employees;
use NemvoBooking\Google;
use NemvoBooking\Pages;
use NemvoBooking\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The Bookings menu in wp-admin and helpers shared by its screens.
 */
class Admin {

	const SLUG = 'nemvo-booking';

	/**
	 * Hooks in the menu, assets and notices, and starts the admin screens.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'submenus' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'load-edit.php', array( __CLASS__, 'redirect_legacy_post_type' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'redirect_legacy_post_type' ) );

		Settings_Page::init();
		Employees_Page::init();
		Page_Editor::init();
		Bookings_Page::init();
	}

	/**
	 * Bookmarks to the booking page list from before 1.2.2 use the old post type name: send them
	 * to the same screen under the new name.
	 */
	public static function redirect_legacy_post_type() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compared, then redirected to a fixed screen.
		if ( isset( $_GET['post_type'] ) && Pages::LEGACY_CPT === $_GET['post_type'] ) {
			global $pagenow;
			wp_safe_redirect( admin_url( $pagenow . '?post_type=' . Pages::CPT ) );
			exit;
		}
	}

	/**
	 * Adds the Bookings menu.
	 */
	public static function menu() {
		add_menu_page( __( 'Bookings', 'nemvo-booking' ), __( 'Bookings', 'nemvo-booking' ), Plugin::capability(), self::SLUG, array( Bookings_Page::class, 'render' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( self::SLUG, __( 'Bookings', 'nemvo-booking' ), __( 'Bookings', 'nemvo-booking' ), Plugin::capability(), self::SLUG, array( Bookings_Page::class, 'render' ) );
	}

	/**
	 * Adds Employees, History log and Settings below the booking pages.
	 */
	public static function submenus() {
		add_submenu_page( self::SLUG, __( 'Employees', 'nemvo-booking' ), __( 'Employees', 'nemvo-booking' ), Plugin::capability(), self::SLUG . '-employees', array( Employees_Page::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'History log', 'nemvo-booking' ), __( 'History log', 'nemvo-booking' ), Plugin::capability(), self::SLUG . '-log', array( Log_Page::class, 'render' ) );
		add_submenu_page( self::SLUG, __( 'Booking settings', 'nemvo-booking' ), __( 'Settings', 'nemvo-booking' ), Plugin::capability(), self::SLUG . '-settings', array( Settings_Page::class, 'render' ) );
	}

	/**
	 * Whether the current screen belongs to the plugin.
	 *
	 * @return bool
	 */
	private static function is_our_screen(): bool {
		$screen = get_current_screen();
		return $screen && ( false !== strpos( $screen->id, self::SLUG ) || Pages::CPT === $screen->post_type );
	}

	/**
	 * Loads the admin stylesheet and script on the plugin's screens.
	 */
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

	/**
	 * Shows the broken-connection warning everywhere, and the plugin's own notices on its screens.
	 */
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
			'saved'           => __( 'Saved.', 'nemvo-booking' ),
			'created'         => __( 'Employee added. Now connect their Google Calendar below.', 'nemvo-booking' ),
			'deleted'         => __( 'Deleted.', 'nemvo-booking' ),
			'connected'       => __( 'Google Calendar connected.', 'nemvo-booking' ),
			'disconnected'    => __( 'Google Calendar disconnected.', 'nemvo-booking' ),
			'refreshed'       => __( 'Calendar list refreshed.', 'nemvo-booking' ),
			'link'            => __( 'A new connect link was created. Copy it and send it to the employee.', 'nemvo-booking' ),
			'account_added'   => __( 'Google account added. Busy times in its checked calendars now block bookings.', 'nemvo-booking' ),
			'account_removed' => __( 'Google account removed.', 'nemvo-booking' ),
			'cancelled'       => __( 'The booking was cancelled and everyone has been notified.', 'nemvo-booking' ),
		);
		if ( isset( $_GET['nemvo_booking_notice'], $messages[ $_GET['nemvo_booking_notice'] ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $messages[ sanitize_key( $_GET['nemvo_booking_notice'] ) ] ) );
		}
		if ( isset( $_GET['nemvo_booking_error'] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['nemvo_booking_error'] ) ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Shown on every admin screen while a host's Google connection is broken, since bookings stop.
	 */
	private static function broken_connection_notice() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		foreach ( Employees::all() as $employee ) {
			if ( ! Employees::is_connected( $employee ) ) {
				continue;
			}
			$broken = $employee['last_error'] ? array( $employee['name'] ) : array();
			foreach ( Accounts::for_employee( $employee['id'] ) as $account ) {
				if ( $account['last_error'] ) {
					$broken[] = Accounts::label( $account, $employee );
				}
			}
			$pages = $broken ? Employees::page_titles( $employee ) : array();
			if ( ! $pages ) {
				continue;
			}
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html(
					/* translators: %s: employee name */
					sprintf( __( 'Bookings: the Google Calendar connection for %s is not working.', 'nemvo-booking' ), implode( ', ', $broken ) )
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

	/**
	 * Verifies capability and nonce for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	public static function check( string $action ) {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'nemvo-booking' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects to one of the plugin's screens and ends the request.
	 *
	 * @param string $page Admin page slug.
	 * @param array  $args Query arguments.
	 */
	public static function redirect( string $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	/**
	 * A read-only text field with a Copy button.
	 *
	 * @param string $value     Text to copy.
	 * @param string $css_class Class of the input.
	 * @return string HTML.
	 */
	public static function copy_field( string $value, string $css_class = 'regular-text' ): string {
		return sprintf(
			'<span class="nb-copy"><input type="text" readonly class="%1$s code" value="%2$s"><button type="button" class="button" data-copy>%3$s</button></span>',
			esc_attr( $css_class ),
			esc_attr( $value ),
			esc_html__( 'Copy', 'nemvo-booking' )
		);
	}

	/**
	 * A colored status label.
	 *
	 * @param string $status Booking status or log level.
	 * @return string HTML.
	 */
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

	/**
	 * Prints page links below a table.
	 *
	 * @param int $total    Number of rows.
	 * @param int $per_page Rows per page.
	 * @param int $current  Current page.
	 */
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
