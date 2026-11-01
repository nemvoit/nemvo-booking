<?php
/**
 * Plugin Name:       Nemvo Booking
 * Plugin URI:        https://github.com/fpoller/nemvo-booking
 * Description:       Meeting booking with Google Calendar availability, Google Meet links and calendar invitations.
 * Version:           1.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nemvo
 * Author URI:        https://nemvo.eu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       nemvo-booking
 */

defined( 'ABSPATH' ) || exit;

define( 'NEMVO_BOOKING_VERSION', '1.2.1' );
define( 'NEMVO_BOOKING_FILE', __FILE__ );
define( 'NEMVO_BOOKING_DIR', plugin_dir_path( __FILE__ ) );
define( 'NEMVO_BOOKING_URL', plugin_dir_url( __FILE__ ) );

require_once NEMVO_BOOKING_DIR . 'includes/class-plugin.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-install.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-settings.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-crypto.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-log.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-google.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-employees.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-pages.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-holidays.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-availability.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-bookings.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-mailer.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-protection.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-privacy.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-rest.php';
require_once NEMVO_BOOKING_DIR . 'includes/class-frontend.php';

if ( is_admin() ) {
	require_once NEMVO_BOOKING_DIR . 'includes/admin/class-admin.php';
	require_once NEMVO_BOOKING_DIR . 'includes/admin/class-settings-page.php';
	require_once NEMVO_BOOKING_DIR . 'includes/admin/class-employees-page.php';
	require_once NEMVO_BOOKING_DIR . 'includes/admin/class-page-editor.php';
	require_once NEMVO_BOOKING_DIR . 'includes/admin/class-bookings-page.php';
	require_once NEMVO_BOOKING_DIR . 'includes/admin/class-log-page.php';
}

register_activation_hook( __FILE__, array( 'NemvoBooking\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NemvoBooking\\Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'NemvoBooking\\Plugin', 'init' ) );
