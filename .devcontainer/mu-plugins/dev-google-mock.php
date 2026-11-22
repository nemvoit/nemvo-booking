<?php
/**
 * Plugin Name: Dev – fake Google API
 * Description: Local development only. Answers Google OAuth and Calendar API calls with fake data so
 *              the booking flow can be tested without Google credentials. Enabled by NEMVO_BOOKING_GOOGLE_MOCK=1.
 *
 * Fake calendars (Europe/Stockholm): everyone is busy 12:00–13:00 every day; Anna is also busy 09:00–10:00
 * and Erik 15:00–16:00, so a page with both only offers times when both are free.
 * 11 November is a fake public holiday (in both the Swedish and Norwegian mock calendars); 18 November only in Norway. A refresh token ending in "|revoked" simulates revoked access.
 * Adding another Google account (no login hint) "signs in" as the address in the option
 * nemvo_booking_mock_next_account (default private@example.com); addresses starting with
 * "private" are also busy 14:00–15:00. The last consent request is kept in nemvo_booking_mock_last_auth.
 */

if ( ! getenv( 'NEMVO_BOOKING_GOOGLE_MOCK' ) ) {
	return;
}

// Skip Google's consent screen: go straight back to the plugin's callback.
add_filter(
	'nemvo_booking_google_auth_url',
	function ( $url, $params ) {
		update_option( 'nemvo_booking_mock_last_auth', $params, false );
		$who = $params['login_hint'] ?? get_option( 'nemvo_booking_mock_next_account', 'private@example.com' );
		return $params['redirect_uri'] . '&state=' . rawurlencode( $params['state'] ) . '&code=' . rawurlencode( 'mock:' . $who );
	},
	10,
	2
);

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'oauth2.googleapis.com', 'www.googleapis.com' ), true ) ) {
			return $pre;
		}

		$path   = wp_parse_url( $url, PHP_URL_PATH );
		$method = strtoupper( $args['method'] ?? 'GET' );
		$auth   = $args['headers']['Authorization'] ?? '';
		$email  = preg_match( '/^Bearer mock:(.+)$/', $auth, $m ) ? $m[1] : 'someone@example.com';
		$json   = function ( $data, $code = 200 ) {
			return array(
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $data ),
				'response' => array( 'code' => $code, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		if ( '/token' === $path ) {
			$body  = is_array( $args['body'] ) ? $args['body'] : array();
			if ( '|revoked' === substr( $body['refresh_token'] ?? '', -8 ) ) {
				return $json( array( 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ), 400 );
			}
			$who   = isset( $body['code'] ) ? substr( $body['code'], 5 ) : substr( $body['refresh_token'] ?? 'mock:someone@example.com', 5 );
			return $json(
				array(
					'access_token'  => 'mock:' . $who,
					'refresh_token' => 'mock:' . $who,
					'expires_in'    => 3600,
				)
			);
		}
		if ( '/revoke' === $path ) {
			return $json( array() );
		}

		if ( '/calendar/v3/users/me/calendarList' === $path ) {
			return $json(
				array(
					'items' => array(
						array( 'id' => $email, 'summary' => $email, 'primary' => true, 'accessRole' => 'owner' ),
						array( 'id' => 'team-' . md5( $email ) . '@group.calendar.google.com', 'summary' => 'Team calendar', 'accessRole' => 'owner' ),
						array( 'id' => 'en.swedish#holiday@group.v.calendar.google.com', 'summary' => 'Holidays in Sweden', 'accessRole' => 'reader' ),
					),
				)
			);
		}

		if ( '/calendar/v3/freeBusy' === $path ) {
			$req  = json_decode( $args['body'], true );
			$tz   = new DateTimeZone( 'Europe/Stockholm' );
			$from = new DateTimeImmutable( $req['timeMin'] );
			$to   = new DateTimeImmutable( $req['timeMax'] );
			$cals = array();
			foreach ( $req['items'] as $item ) {
				$busy = array();
				$hours = array( array( 12, 13 ) );
				if ( 0 === strpos( $email, 'anna@' ) ) {
					$hours[] = array( 9, 10 );
				}
				if ( 0 === strpos( $email, 'erik@' ) ) {
					$hours[] = array( 15, 16 );
				}
				if ( 0 === strpos( $email, 'private' ) ) {
					$hours = array( array( 14, 15 ) );
				}
				for ( $d = $from->setTimezone( $tz )->setTime( 0, 0 ); $d < $to; $d = $d->modify( '+1 day' ) ) {
					foreach ( $hours as $h ) {
						$busy[] = array(
							'start' => $d->setTime( $h[0], 0 )->format( DATE_RFC3339 ),
							'end'   => $d->setTime( $h[1], 0 )->format( DATE_RFC3339 ),
						);
					}
				}
				$cals[ $item['id'] ] = array( 'busy' => $busy );
			}
			return $json( array( 'calendars' => $cals ) );
		}

		if ( preg_match( '#^/calendar/v3/calendars/([^/]+)/events$#', $path, $m ) ) {
			$calendar = rawurldecode( $m[1] );
			if ( 'POST' === $method ) {
				$event = json_decode( $args['body'], true );
				$id    = 'mock' . wp_generate_password( 16, false, false );
				$code  = strtolower( wp_generate_password( 3, false, false ) . '-' . wp_generate_password( 4, false, false ) . '-' . wp_generate_password( 3, false, false ) );
				update_option( 'nemvo_booking_mock_last_event', array( 'calendar' => $calendar, 'url' => $url, 'event' => $event ), false );
				return $json(
					$event + array(
						'id'          => $id,
						'iCalUID'     => $id . '@google.com',
						'hangoutLink' => 'https://meet.google.com/' . $code,
						'htmlLink'    => 'https://calendar.google.com/event?eid=' . $id,
					)
				);
			}
			// Public holiday calendars.
			if ( false !== strpos( $calendar, 'norwegian' ) ) {
				return $json(
					array(
						'items' => array(
							array( 'summary' => 'Mock Norwegian Holiday', 'description' => 'Public holiday', 'start' => array( 'date' => '2026-11-18' ), 'end' => array( 'date' => '2026-11-19' ) ),
							array( 'summary' => 'Shared Mock Holiday', 'description' => 'Public holiday', 'start' => array( 'date' => '2026-11-11' ), 'end' => array( 'date' => '2026-11-12' ) ),
						),
					)
				);
			}
			return $json(
				array(
					'items' => array(
						array( 'summary' => 'Mock Holiday', 'description' => 'Public holiday', 'start' => array( 'date' => '2026-11-11' ), 'end' => array( 'date' => '2026-11-12' ) ),
						array( 'summary' => 'Christmas Eve', 'description' => 'Observance', 'start' => array( 'date' => '2026-12-24' ), 'end' => array( 'date' => '2026-12-25' ) ),
						array( 'summary' => 'Christmas Day', 'description' => 'Public holiday', 'start' => array( 'date' => '2026-12-25' ), 'end' => array( 'date' => '2026-12-26' ) ),
						array( 'summary' => 'Boxing Day', 'description' => 'Public holiday', 'start' => array( 'date' => '2026-12-26' ), 'end' => array( 'date' => '2026-12-27' ) ),
					),
				)
			);
		}

		if ( preg_match( '#^/calendar/v3/calendars/[^/]+/events/[^/]+$#', $path ) ) {
			return 'DELETE' === $method ? $json( null, 204 ) : $json( array( 'id' => basename( $path ), 'hangoutLink' => 'https://meet.google.com/abc-defg-hij' ) );
		}

		return $json( array( 'error' => array( 'message' => 'Not mocked: ' . $method . ' ' . $path ) ), 404 );
	},
	10,
	3
);
