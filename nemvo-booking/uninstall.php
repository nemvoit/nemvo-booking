<?php
/**
 * Removes all plugin data when the plugin is deleted, but only on sites where "Delete all
 * bookings, employees, booking pages, log and settings" is ticked under Bookings → Settings.
 * On a multisite network every site is checked.
 *
 * @package NemvoBooking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-crypto.php';

/**
 * Deletes the current site's data if its settings ask for it. Connected Google accounts are
 * disconnected first, so the site's access to their calendars ends with the plugin.
 */
function nemvo_booking_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'nemvo_booking_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	// Employees' main accounts and their extra accounts (since 1.2.4).
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own tables, read once while uninstalling.
	foreach ( array( 'employees', 'accounts' ) as $nemvo_booking_table ) {
		$nemvo_booking_table = $wpdb->prefix . 'nemvo_booking_' . $nemvo_booking_table;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $nemvo_booking_table ) ) ) !== $nemvo_booking_table ) {
			continue;
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT refresh_token FROM %i WHERE refresh_token <> ''", $nemvo_booking_table ) ) as $nemvo_booking_token ) {
			$nemvo_booking_token = NemvoBooking\Crypto::decrypt( $nemvo_booking_token );
			if ( '' !== $nemvo_booking_token ) {
				wp_remote_post(
					'https://oauth2.googleapis.com/revoke',
					array(
						'timeout' => 5,
						'body'    => array( 'token' => $nemvo_booking_token ),
					)
				);
			}
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( array( 'employees', 'accounts', 'bookings', 'log' ) as $nemvo_booking_table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'nemvo_booking_' . $nemvo_booking_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing the plugin's own tables on uninstall.
	}

	// Booking pages, including any left under the post type name used before 1.2.2.
	$nemvo_booking_posts = get_posts(
		array(
			'post_type'   => array( 'nemvo_booking_page', 'nb_booking_page' ),
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $nemvo_booking_posts as $nemvo_booking_post ) {
		wp_delete_post( $nemvo_booking_post, true );
	}

	delete_option( 'nemvo_booking_settings' );
	delete_option( 'nemvo_booking_db_version' );
	// Transients (rate limits, holiday cache) have generated names, so they are found by prefix.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup, no API deletes transients by prefix.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_nemvo_booking_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_nemvo_booking_' ) . '%'
		)
	);
	wp_clear_scheduled_hook( 'nemvo_booking_daily' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'number' => 0,
			'fields' => 'ids',
		)
	) as $nemvo_booking_site ) {
		switch_to_blog( $nemvo_booking_site );
		nemvo_booking_uninstall_site();
		restore_current_blog();
	}
} else {
	nemvo_booking_uninstall_site();
}
