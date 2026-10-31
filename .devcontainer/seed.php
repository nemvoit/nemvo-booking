<?php
/**
 * Demo data for local development with the fake Google API. Run with: wp eval-file seed.php
 */

use NemvoBooking\Crypto;
use NemvoBooking\Employees;
use NemvoBooking\Pages;
use NemvoBooking\Settings;

/** Gives the demo employees the illustrated portraits in avatars/ (anna.png, erik.png). */
function nb_dev_seed_photos( array $ids ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( $ids as $id ) {
		$employee = Employees::get( $id );
		$file     = __DIR__ . '/avatars/' . strtolower( strtok( $employee['name'], ' ' ) ) . '.png';
		if ( $employee['photo_id'] || ! file_exists( $file ) ) {
			continue;
		}
		$tmp = wp_tempnam( basename( $file ) );
		copy( $file, $tmp );
		$photo = media_handle_sideload( array( 'name' => basename( $file ), 'tmp_name' => $tmp ), 0, $employee['name'] );
		if ( ! is_wp_error( $photo ) ) {
			Employees::update( $id, array( 'photo_id' => $photo ) );
		}
	}
}

if ( Employees::all() ) {
	nb_dev_seed_photos( wp_list_pluck( Employees::all(), 'id' ) );
	WP_CLI::log( 'Demo data already exists.' );
	return;
}

Settings::update(
	array(
		'google_client_id'     => 'mock-client-id.apps.googleusercontent.com',
		'google_client_secret' => 'mock-secret',
	)
);

$ids = array();
foreach ( array( 'Anna Andersson' => 'anna@example.com', 'Erik Eriksson' => 'erik@example.com' ) as $name => $email ) {
	$id = Employees::create( $name, $email );
	Employees::update( $id, array( 'refresh_token' => Crypto::encrypt( 'mock:' . $email ) ) );
	Employees::refresh_calendars( Employees::get( $id ) );
	$ids[] = $id;
}
nb_dev_seed_photos( $ids );

// One page per person and one where both attend.
$pages = array(
	'Meet Anna'         => array( 'book-anna', array( $ids[0] ) ),
	'Meet Erik'         => array( 'book-erik', array( $ids[1] ) ),
	'Meet Anna and Erik' => array( 'book', $ids ),
);
foreach ( $pages as $title => list( $slug, $hosts ) ) {
	$page_id            = wp_insert_post(
		array(
			'post_type'   => Pages::CPT,
			'post_status' => 'publish',
			'post_title'  => $title,
		)
	);
	$cfg                = Pages::defaults();
	$cfg['employees']   = $hosts;
	$cfg['durations']   = array( 30, 60 );
	$cfg['description'] = 'A short call to get to know each other and see how we can help.';
	update_post_meta( $page_id, Pages::META, Pages::sanitize( $cfg ) );

	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => '<!-- wp:nemvo-booking/booking-page {"pageId":' . $page_id . ',"align":"wide"} /-->',
		)
	);
}

// Create a Cap site key through Cap's API and enable the CAPTCHA.
$cap   = 'http://cap:3000';
$login = json_decode( wp_remote_retrieve_body( wp_remote_post( $cap . '/auth/login', array( 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'admin_key' => getenv( 'CAP_ADMIN_KEY' ) ) ) ) ) ), true );
if ( ! empty( $login['session_token'] ) ) {
	$bearer = base64_encode( wp_json_encode( array( 'token' => $login['session_token'], 'hash' => $login['hashed_token'] ) ) );
	$key    = json_decode(
		wp_remote_retrieve_body(
			wp_remote_post(
				$cap . '/server/keys',
				array(
					'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $bearer ),
					'body'    => wp_json_encode( array( 'name' => 'WordPress dev', 'instrumentation' => true ) ),
				)
			)
		),
		true
	);
	if ( ! empty( $key['siteKey'] ) ) {
		Settings::update(
			array(
				'cap_enabled'      => 1,
				'cap_url'          => 'http://localhost:3000',
				'cap_internal_url' => $cap,
				'cap_site_key'     => $key['siteKey'],
				'cap_secret'       => $key['secretKey'],
			)
		);
		WP_CLI::log( 'Cap enabled with site key ' . $key['siteKey'] );
	}
} else {
	WP_CLI::warning( 'Could not log in to Cap; CAPTCHA not enabled.' );
}

WP_CLI::success( 'Demo data created: http://localhost:8080/book-anna/, /book-erik/ and /book/ (both)' );
