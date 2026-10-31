<?php
/**
 * Removes all plugin data on uninstall, but only when enabled in the settings.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$nemvo_booking_settings = get_option( 'nemvo_booking_settings', array() );
if ( empty( $nemvo_booking_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'employees', 'bookings', 'log' ) as $nemvo_booking_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'nemvo_booking_' . $nemvo_booking_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall.
}

foreach ( get_posts( array( 'post_type' => 'nb_booking_page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $nemvo_booking_post ) {
	wp_delete_post( $nemvo_booking_post, true );
}

delete_option( 'nemvo_booking_settings' );
delete_option( 'nemvo_booking_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%nemvo\\_booking\\_%'" );
wp_clear_scheduled_hook( 'nemvo_booking_daily' );
