<?php
namespace NemvoBooking;

defined( 'ABSPATH' ) || exit;

class Bookings {

	public static function get( int $id ): ?array {
		global $wpdb;
		$table = Plugin::table( 'bookings' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function by_token( string $token ): ?array {
		global $wpdb;
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$table = Plugin::table( 'bookings' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token = %s', $table, $token ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( array $row ): array {
		$row['id']           = (int) $row['id'];
		$row['page_id']      = (int) $row['page_id'];
		$row['organizer_id'] = (int) $row['organizer_id'];
		$row['employee_ids'] = array_values( array_filter( array_map( 'intval', explode( ',', $row['employee_ids'] ) ) ) );
		$row['guests']       = json_decode( (string) $row['guests'], true ) ?: array();
		$row['answers']      = json_decode( (string) $row['answers'], true ) ?: array();
		$row['start']        = Plugin::from_mysql( $row['start_utc'] );
		$row['end']          = Plugin::from_mysql( $row['end_utc'] );
		$row['sequence']     = (int) $row['sequence'];
		return $row;
	}

	public static function update( int $id, array $data ) {
		global $wpdb;
		$wpdb->update( Plugin::table( 'bookings' ), $data, array( 'id' => $id ) );
	}

	/**
	 * @param array $input start (ts), duration, timezone, answers (key => value), guests (emails), page_url, ip, consent (bool).
	 * @return array|\WP_Error The confirmed booking.
	 */
	public static function create( array $cfg, array $input ) {
		global $wpdb;

		$start    = (int) $input['start'];
		$duration = (int) $input['duration'];
		$lock     = 'nemvo_booking_' . get_current_blog_id();

		// Serialize bookings so two visitors can't grab the same host at the same time.
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', $lock ) );
		try {
			Availability::flush_local_cache();
			if ( ! Availability::is_available( $cfg, $duration, $start ) ) {
				return new \WP_Error( 'slot_unavailable', __( 'Sorry, that time is no longer available. Please choose another time.', 'nemvo-booking' ), array( 'status' => 409 ) );
			}

			$team      = Pages::hosts( $cfg );
			$organizer = $team[0];

			$wpdb->insert(
				Plugin::table( 'bookings' ),
				array(
					'page_id'      => $cfg['id'],
					'status'       => 'pending',
					'start_utc'    => Plugin::to_mysql( $start ),
					'end_utc'      => Plugin::to_mysql( $start + $duration * MINUTE_IN_SECONDS ),
					'duration'     => $duration,
					'timezone'     => $input['timezone'],
					'organizer_id' => $organizer['id'],
					'employee_ids' => implode( ',', wp_list_pluck( $team, 'id' ) ),
					'name'         => $input['answers']['name'],
					'email'        => $input['answers']['email'],
					'guests'       => wp_json_encode( array_values( $input['guests'] ) ),
					'answers'      => wp_json_encode( $input['answers'] ),
					'token'        => wp_generate_password( 40, false ),
					'ip'           => $input['ip'],
					'page_url'     => $input['page_url'],
					'consent_at'   => empty( $input['consent'] ) ? null : Plugin::now_mysql(),
					'created_at'   => Plugin::now_mysql(),
				)
			);
			$booking = self::get( (int) $wpdb->insert_id );

			$calendar = Employees::target_calendar( $organizer );
			$event    = Google::create_event( $organizer, $calendar, self::event_body( $booking, $cfg, $team ), 'google' === Settings::get( 'invite_mode' ) );

			if ( is_wp_error( $event ) ) {
				self::update( $booking['id'], array( 'status' => 'failed' ) );
				Log::error(
					'booking.failed',
					sprintf( 'Could not create Google Calendar event for %s (%s): %s', $booking['name'], $booking['email'], $event->get_error_message() ),
					array(
						'booking_id'  => $booking['id'],
						'employee_id' => $organizer['id'],
					)
				);
				return new \WP_Error( 'google_failed', __( 'We could not create the meeting right now. Please try again in a moment.', 'nemvo-booking' ), array( 'status' => 502 ) );
			}

			self::update(
				$booking['id'],
				array(
					'status'      => 'confirmed',
					'event_id'    => $event['id'] ?? '',
					'calendar_id' => $calendar,
					'ical_uid'    => $event['iCalUID'] ?? '',
					'meet_link'   => Google::meet_link( $event ),
				)
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		$booking = self::get( $booking['id'] );
		$tz      = Plugin::timezone( $cfg['timezone'] );
		Log::info(
			'booking.created',
			sprintf(
				'%1$s (%2$s) booked "%3$s" on %4$s with %5$s.',
				$booking['name'],
				$booking['email'],
				$cfg['title'],
				wp_date( 'Y-m-d H:i', $booking['start'], $tz ) . ' ' . $cfg['timezone'],
				implode( ', ', wp_list_pluck( $team, 'name' ) )
			),
			array(
				'booking_id'  => $booking['id'],
				'employee_id' => $organizer['id'],
			)
		);
		if ( ! $booking['meet_link'] ) {
			Log::warning( 'booking.no_meet_link', 'Google did not return a Meet link for the event. Check that Google Meet is enabled for the account.', array( 'booking_id' => $booking['id'] ) );
		}

		Mailer::booking_created( $booking, $cfg );
		do_action( 'nemvo_booking_created', $booking, $cfg );
		return $booking;
	}

	private static function event_body( array $booking, array $cfg, array $team ): array {
		$attendees = array(
			array(
				'email'       => $booking['email'],
				'displayName' => $booking['name'],
			),
		);
		foreach ( $booking['guests'] as $guest ) {
			$attendees[] = array( 'email' => $guest );
		}
		foreach ( array_slice( $team, 1 ) as $member ) {
			$attendees[] = array(
				'email'       => Employees::calendar_email( $member ),
				'displayName' => $member['name'],
			);
		}

		$vars = self::vars( $booking, $cfg, $cfg['timezone'] );

		return array(
			'summary'                 => self::render( $cfg['meeting_title'], $vars, false ),
			'description'             => self::event_description( $booking, $cfg ),
			'start'                   => array(
				'dateTime' => Google::rfc3339( $booking['start'] ),
				'timeZone' => $cfg['timezone'],
			),
			'end'                     => array(
				'dateTime' => Google::rfc3339( $booking['end'] ),
				'timeZone' => $cfg['timezone'],
			),
			'attendees'               => $attendees,
			'guestsCanSeeOtherGuests' => true,
			'reminders'               => array( 'useDefault' => true ),
			'conferenceData'          => array(
				'createRequest' => array(
					'requestId'             => 'nb-' . $booking['id'] . '-' . wp_generate_password( 8, false ),
					'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
				),
			),
		);
	}

	/**
	 * Text of the calendar invitation (Settings → Calendar invitations). With Google invites this is
	 * the only message the booker receives, so it carries the answers and the change/cancel links.
	 */
	public static function event_description( array $booking, array $cfg ): string {
		$template = (string) Settings::get( 'event_description' );
		if ( empty( $cfg['allow_reschedule'] ) ) {
			$template = preg_replace( '/^.*\{reschedule_link\}.*\R?/m', '', $template );
		}
		$text = self::render( $template, self::vars( $booking, $cfg, $booking['timezone'] ?: $cfg['timezone'] ), false );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * @param string $by 'booker' or 'admin'.
	 * @return true|\WP_Error
	 */
	public static function cancel( array $booking, string $reason, string $by ) {
		if ( 'confirmed' !== $booking['status'] ) {
			return new \WP_Error( 'not_cancellable', __( 'This booking has already been cancelled.', 'nemvo-booking' ), array( 'status' => 409 ) );
		}
		if ( 'booker' === $by && $booking['start'] <= time() ) {
			return new \WP_Error( 'too_late', __( 'This meeting has already started and can no longer be cancelled.', 'nemvo-booking' ), array( 'status' => 409 ) );
		}

		$organizer = Employees::get( $booking['organizer_id'] );
		if ( $booking['event_id'] && $organizer && Employees::is_connected( $organizer ) ) {
			$deleted = Google::delete_event( $organizer, $booking['calendar_id'], $booking['event_id'], 'google' === Settings::get( 'invite_mode' ) );
			if ( is_wp_error( $deleted ) ) {
				Log::warning(
					'google.delete_failed',
					sprintf( 'Could not delete the Google Calendar event: %s. Remove it manually.', $deleted->get_error_message() ),
					array( 'booking_id' => $booking['id'] )
				);
			}
		}

		self::update(
			$booking['id'],
			array(
				'status'        => 'cancelled',
				'cancel_reason' => $reason,
				'cancelled_by'  => $by,
				'cancelled_at'  => Plugin::now_mysql(),
			)
		);
		$booking = self::get( $booking['id'] );

		Log::info(
			'booking.cancelled',
			sprintf(
				'Booking for %1$s (%2$s) on %3$s was cancelled by %4$s.%5$s',
				$booking['name'],
				$booking['email'],
				wp_date( 'Y-m-d H:i', $booking['start'] ),
				'admin' === $by ? __( 'an administrator', 'nemvo-booking' ) : __( 'the booker', 'nemvo-booking' ),
				$reason ? ' Reason: ' . $reason : ''
			),
			array( 'booking_id' => $booking['id'] )
		);

		Mailer::booking_cancelled( $booking, Pages::config( $booking['page_id'] ) );
		do_action( 'nemvo_booking_cancelled', $booking );
		return true;
	}

	/**
	 * Moves a confirmed booking to a new time. The Google event (and its Meet link) is kept and updated.
	 *
	 * @return array|\WP_Error The updated booking.
	 */
	public static function reschedule( array $booking, int $start, string $by = 'booker' ) {
		global $wpdb;
		$cfg = Pages::config( $booking['page_id'] );
		if ( ! self::can_reschedule( $booking, $cfg ) && 'admin' !== $by ) {
			return new \WP_Error( 'not_reschedulable', __( 'This meeting can no longer be rescheduled.', 'nemvo-booking' ), array( 'status' => 409 ) );
		}
		if ( $start === $booking['start'] ) {
			return new \WP_Error( 'same_time', __( 'Please choose a different time.', 'nemvo-booking' ), array( 'status' => 400 ) );
		}

		$lock = 'nemvo_booking_' . get_current_blog_id();
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 15)', $lock ) );
		try {
			Availability::flush_local_cache();
			if ( ! Availability::is_available( $cfg, (int) $booking['duration'], $start, $booking['id'] ) ) {
				return new \WP_Error( 'slot_unavailable', __( 'Sorry, that time is no longer available. Please choose another time.', 'nemvo-booking' ), array( 'status' => 409 ) );
			}

			$organizer = Employees::get( $booking['organizer_id'] );
			$end       = $start + (int) $booking['duration'] * MINUTE_IN_SECONDS;
			$patched   = $organizer && $booking['event_id']
				? Google::patch_event(
					$organizer,
					$booking['calendar_id'],
					$booking['event_id'],
					array(
						'start' => array(
							'dateTime' => Google::rfc3339( $start ),
							'timeZone' => $cfg['timezone'],
						),
						'end'   => array(
							'dateTime' => Google::rfc3339( $end ),
							'timeZone' => $cfg['timezone'],
						),
					),
					'google' === Settings::get( 'invite_mode' )
				)
				: new \WP_Error( 'no_event', 'The booking has no Google Calendar event.' );

			if ( is_wp_error( $patched ) ) {
				Log::error( 'booking.reschedule_failed', 'Could not move the Google Calendar event: ' . $patched->get_error_message(), array( 'booking_id' => $booking['id'] ) );
				return new \WP_Error( 'google_failed', __( 'We could not move the meeting right now. Please try again in a moment.', 'nemvo-booking' ), array( 'status' => 502 ) );
			}

			self::update(
				$booking['id'],
				array(
					'start_utc' => Plugin::to_mysql( $start ),
					'end_utc'   => Plugin::to_mysql( $end ),
					'sequence'  => $booking['sequence'] + 1,
				)
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		$old                  = $booking['start'];
		$booking              = self::get( $booking['id'] );
		$booking['old_start'] = $old;
		$tz                   = Plugin::timezone( $cfg['timezone'] );
		Log::info(
			'booking.rescheduled',
			sprintf(
				'Booking for %1$s (%2$s) was moved from %3$s to %4$s by %5$s.',
				$booking['name'],
				$booking['email'],
				wp_date( 'Y-m-d H:i', $old, $tz ),
				wp_date( 'Y-m-d H:i', $booking['start'], $tz ) . ' ' . $cfg['timezone'],
				'admin' === $by ? 'an administrator' : 'the booker'
			),
			array( 'booking_id' => $booking['id'] )
		);

		Mailer::booking_rescheduled( $booking, $cfg );
		do_action( 'nemvo_booking_rescheduled', $booking, $old );
		return $booking;
	}

	public static function can_reschedule( array $booking, array $cfg ): bool {
		return 'confirmed' === $booking['status'] && $booking['start'] > time() && ! empty( $cfg['allow_reschedule'] ) && '' !== $booking['email'];
	}

	/**
	 * Removes personal data but keeps the booking's time, page and hosts (for statistics).
	 * Also removes the booking's history log entries, which contain names and emails.
	 */
	public static function anonymize( array $booking ) {
		global $wpdb;
		self::update(
			$booking['id'],
			array(
				'name'          => __( '(deleted)', 'nemvo-booking' ),
				'email'         => '',
				'guests'        => '[]',
				'answers'       => '{}',
				'ip'            => '',
				'page_url'      => '',
				'cancel_reason' => null,
				'token'         => '',
			)
		);
		$wpdb->delete( Plugin::table( 'log' ), array( 'booking_id' => $booking['id'] ) );
	}

	public static function cancel_url( array $booking ): string {
		$base = $booking['page_url'] ?: home_url( '/' );
		return add_query_arg( 'nb_cancel', $booking['token'], $base );
	}

	public static function reschedule_url( array $booking ): string {
		$base = $booking['page_url'] ?: home_url( '/' );
		return add_query_arg( 'nb_reschedule', $booking['token'], $base );
	}

	/** Form answers as [label, value] pairs, using the page's current field labels. */
	public static function answer_rows( array $booking, array $cfg ): array {
		$labels = array_column( $cfg['fields'], 'label', 'key' );
		$types  = array_column( $cfg['fields'], 'type', 'key' );
		$rows   = array();
		foreach ( $booking['answers'] as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			if ( 'checkbox' === ( $types[ $key ] ?? '' ) ) {
				$value = $value ? __( 'Yes', 'nemvo-booking' ) : __( 'No', 'nemvo-booking' );
			}
			$rows[] = array( $labels[ $key ] ?? $key, (string) $value );
		}
		return $rows;
	}

	/** Placeholder values (plain text) for templates. */
	public static function vars( array $booking, array $cfg, string $timezone, string $recipient_name = '' ): array {
		$tz    = Plugin::timezone( $timezone );
		$hosts = Employees::many( $booking['employee_ids'] );

		$answers = array();
		foreach ( self::answer_rows( $booking, $cfg ) as $row ) {
			$answers[] = $row[0] . ': ' . $row[1];
		}

		$vars = array();
		foreach ( $booking['answers'] as $key => $value ) {
			$vars[ $key ] = (string) $value;
		}

		return array_merge(
			$vars,
			array(
				'name'           => $booking['name'],
				'email'          => $booking['email'],
				'recipient_name' => $recipient_name ?: $booking['name'],
				'page_title'     => $cfg['title'],
				'date'           => wp_date( Plugin::date_format(), $booking['start'], $tz ),
				'time'           => wp_date( Plugin::time_format(), $booking['start'], $tz ),
				'end_time'       => wp_date( Plugin::time_format(), $booking['end'], $tz ),
				'timezone'       => str_replace( '_', ' ', $tz->getName() ),
				'duration'       => (string) $booking['duration'],
				'host'           => implode( ', ', wp_list_pluck( $hosts, 'name' ) ),
				'meet_link'      => $booking['meet_link'],
				'cancel_link'    => self::cancel_url( $booking ),
				'reschedule_link' => self::reschedule_url( $booking ),
				'old_date'       => isset( $booking['old_start'] ) ? wp_date( Plugin::date_format(), $booking['old_start'], $tz ) : '',
				'old_time'       => isset( $booking['old_start'] ) ? wp_date( Plugin::time_format(), $booking['old_start'], $tz ) : '',
				'booking_url'    => $booking['page_url'] ?: home_url( '/' ),
				'guests'         => $booking['guests'] ? implode( ', ', $booking['guests'] ) : '–',
				'answers'        => implode( "\n", $answers ),
				'cancel_reason'  => $booking['cancel_reason'] ?: '–',
				'site_name'      => get_bloginfo( 'name' ),
			)
		);
	}

	/**
	 * Replaces {placeholders}. In HTML mode values are escaped, URLs linked and newlines kept.
	 */
	public static function render( string $template, array $vars, bool $html = true ): string {
		$replace = array();
		foreach ( $vars as $key => $value ) {
			$replace[ '{' . $key . '}' ] = $html ? nl2br( esc_html( $value ) ) : $value;
		}
		$out = strtr( $template, $replace );
		$out = preg_replace( '/\{[a-z0-9_]+\}/', '', $out );
		if ( $html ) {
			$out = make_clickable( wpautop( $out ) );
		}
		return $out;
	}

	/** Booking summary for the frontend, formatted in the booker's timezone. */
	public static function summary( array $booking, array $cfg ): array {
		$tz = Plugin::timezone( $booking['timezone'] ?: $cfg['timezone'] );
		return array(
			'title'     => $cfg['title'],
			'status'    => $booking['status'],
			'date'      => wp_date( Plugin::date_format(), $booking['start'], $tz ),
			'time'      => wp_date( Plugin::time_format(), $booking['start'], $tz ) . ' – ' . wp_date( Plugin::time_format(), $booking['end'], $tz ),
			'timezone'  => str_replace( '_', ' ', $tz->getName() ),
			'duration'  => $booking['duration'],
			'hosts'     => wp_list_pluck( Employees::many( $booking['employee_ids'] ), 'name' ),
			'meetLink'      => $booking['meet_link'],
			'start'         => $booking['start'],
			'canCancel'     => 'confirmed' === $booking['status'] && $booking['start'] > time(),
			'canReschedule' => self::can_reschedule( $booking, $cfg ),
		);
	}

	/**
	 * @return array{0: array, 1: int} Rows and total.
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$table = Plugin::table( 'bookings' );
		$where = array( '1=1' );
		$vals  = array();
		$order = 'start_utc ASC';
		$now   = Plugin::now_mysql();

		switch ( $args['view'] ?? 'upcoming' ) {
			case 'upcoming':
				$where[] = "status = 'confirmed' AND end_utc >= %s";
				$vals[]  = $now;
				break;
			case 'past':
				$where[] = "status = 'confirmed' AND end_utc < %s";
				$vals[]  = $now;
				$order   = 'start_utc DESC';
				break;
			case 'cancelled':
				$where[] = "status IN ('cancelled','failed')";
				$order   = 'start_utc DESC';
				break;
			default:
				$order = 'start_utc DESC';
		}
		if ( ! empty( $args['page_id'] ) ) {
			$where[] = 'page_id = %d';
			$vals[]  = (int) $args['page_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(name LIKE %s OR email LIKE %s OR answers LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = (int) ( $args['per_page'] ?? 25 );
		$offset    = max( 0, ( (int) ( $args['paged'] ?? 1 ) - 1 ) * $per_page );

		// $where_sql and $order are built above from fixed strings; every value is a placeholder.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", array_merge( array( $table ), $vals ) ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( array( $table ), $vals, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array( array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() ), $total );
	}
}
