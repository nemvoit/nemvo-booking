<?php
namespace NemvoBooking\Admin;

use NemvoBooking\Bookings;
use NemvoBooking\Employees;
use NemvoBooking\Google;
use NemvoBooking\Pages;
use NemvoBooking\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Edit screen for booking pages.
 */
class PageEditor {

	public static function init() {
		add_action( 'add_meta_boxes_' . Pages::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . Pages::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . Pages::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Pages::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title' ) );
		add_filter( 'post_updated_messages', array( __CLASS__, 'messages' ) );
	}

	public static function title_placeholder( $text, $post ) {
		return Pages::CPT === $post->post_type ? __( 'e.g. 30 minute intro call', 'nemvo-booking' ) : $text;
	}

	public static function messages( $messages ) {
		$messages[ Pages::CPT ] = array_fill( 0, 11, __( 'Booking page saved.', 'nemvo-booking' ) );
		return $messages;
	}

	public static function after_title( $post ) {
		if ( Pages::CPT !== $post->post_type ) {
			return;
		}
		echo '<p class="description nb-title-help">' . esc_html__( 'The title is shown to visitors above the calendar and used in emails and the calendar event.', 'nemvo-booking' ) . '</p>';
		$cfg = Pages::config( $post->ID );
		if ( 'publish' === $post->post_status && ! Pages::is_bookable( $cfg ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This booking page cannot be booked yet: select at least one host, and make sure every selected host has connected their Google Calendar.', 'nemvo-booking' ) . '</p></div>';
		}
	}

	public static function meta_boxes( $post ) {
		add_meta_box( 'nb-hosts', __( 'Hosts', 'nemvo-booking' ), array( __CLASS__, 'box_hosts' ), null, 'normal', 'high' );
		add_meta_box( 'nb-scheduling', __( 'Meeting length and booking window', 'nemvo-booking' ), array( __CLASS__, 'box_scheduling' ), null, 'normal', 'high' );
		add_meta_box( 'nb-availability', __( 'Weekly opening hours', 'nemvo-booking' ), array( __CLASS__, 'box_availability' ), null, 'normal', 'high' );
		add_meta_box( 'nb-form', __( 'Booking form', 'nemvo-booking' ), array( __CLASS__, 'box_form' ), null, 'normal', 'default' );
		add_meta_box( 'nb-after', __( 'After booking', 'nemvo-booking' ), array( __CLASS__, 'box_after' ), null, 'normal', 'default' );
		add_meta_box( 'nb-embed', __( 'Insert on a page', 'nemvo-booking' ), array( __CLASS__, 'box_embed' ), null, 'side', 'high' );
	}

	private static function cfg( $post ): array {
		return Pages::config( $post->ID );
	}

	public static function box_hosts( $post ) {
		$cfg       = self::cfg( $post );
		$employees = Employees::all();
		$organizer = $cfg['employees'][0] ?? 0;
		wp_nonce_field( 'nemvo_booking_page', 'nemvo_booking_page_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Who is in the meeting? Every selected host attends, and a time is only offered when all of them are free. For example: one page for you, one for a colleague, and one that books both of you.', 'nemvo-booking' ); ?></p>
		<?php if ( ! $employees ) : ?>
			<p class="nb-warning">
				<?php esc_html_e( 'No employees yet.', 'nemvo-booking' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nemvo-booking-employees' ) ); ?>"><?php esc_html_e( 'Add employees first →', 'nemvo-booking' ); ?></a>
			</p>
		<?php else : ?>
			<table class="widefat striped nb-table nb-hosts-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Host', 'nemvo-booking' ); ?></th>
						<th><?php esc_html_e( 'Organizer', 'nemvo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $employees as $employee ) : ?>
					<tr>
						<td>
							<label>
								<input type="checkbox" name="nb[employees][]" value="<?php echo esc_attr( $employee['id'] ); ?>" <?php checked( in_array( $employee['id'], $cfg['employees'], true ) ); ?>>
								<img class="nb-avatar" src="<?php echo esc_url( Employees::avatar( $employee ) ); ?>" alt="">
								<?php echo esc_html( $employee['name'] ); ?>
							</label>
							<?php if ( ! Employees::is_connected( $employee ) ) : ?>
								<span class="nb-badge nb-badge--pending"><?php esc_html_e( 'Google not connected', 'nemvo-booking' ); ?></span>
							<?php endif; ?>
						</td>
						<td><input type="radio" name="nb[organizer]" value="<?php echo esc_attr( $employee['id'] ); ?>" <?php checked( $organizer, $employee['id'] ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: employee name */ __( '%s organizes', 'nemvo-booking' ), $employee['name'] ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The organizer\'s Google Calendar holds the meeting and the Google Meet link; the other hosts are invited to it. With a single host, that host is the organizer.', 'nemvo-booking' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function box_scheduling( $post ) {
		$cfg = self::cfg( $post );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Meeting lengths', 'nemvo-booking' ); ?></th>
				<td>
					<?php foreach ( array( 15, 20, 30, 45, 60, 90, 120 ) as $minutes ) : ?>
						<label class="nb-chip"><input type="checkbox" name="nb[durations][]" value="<?php echo esc_attr( $minutes ); ?>" <?php checked( in_array( $minutes, $cfg['durations'], true ) ); ?>> <?php echo esc_html( sprintf( /* translators: %d: minutes */ __( '%d min', 'nemvo-booking' ), $minutes ) ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Select one or more. With several, the visitor chooses the length.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nb-default-duration"><?php esc_html_e( 'Preselected length', 'nemvo-booking' ); ?></label></th>
				<td>
					<select id="nb-default-duration" name="nb[default_duration]">
						<?php foreach ( array( 15, 20, 30, 45, 60, 90, 120 ) as $minutes ) : ?>
							<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( $cfg['default_duration'], $minutes ); ?>><?php echo esc_html( sprintf( /* translators: %d: minutes */ __( '%d min', 'nemvo-booking' ), $minutes ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nb-interval"><?php esc_html_e( 'Start times every', 'nemvo-booking' ); ?></label></th>
				<td>
					<select id="nb-interval" name="nb[interval]">
						<?php foreach ( array( 10, 15, 20, 30, 45, 60, 90, 120 ) as $minutes ) : ?>
							<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( $cfg['interval'], $minutes ); ?>><?php echo esc_html( sprintf( /* translators: %d: minutes */ __( '%d min', 'nemvo-booking' ), $minutes ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'How often a meeting can start, e.g. every 30 min gives 09:00, 09:30, 10:00…', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nb-min-notice"><?php esc_html_e( 'Minimum notice', 'nemvo-booking' ); ?></label></th>
				<td>
					<input type="number" id="nb-min-notice" class="small-text" min="0" name="nb[min_notice]" value="<?php echo esc_attr( $cfg['min_notice'] ); ?>"> <?php esc_html_e( 'hours', 'nemvo-booking' ); ?>
					<p class="description"><?php esc_html_e( 'How far in advance a meeting must be booked. 24 means nothing can be booked within the next 24 hours.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nb-max-days"><?php esc_html_e( 'Book up to', 'nemvo-booking' ); ?></label></th>
				<td>
					<input type="number" id="nb-max-days" class="small-text" min="1" max="730" name="nb[max_days]" value="<?php echo esc_attr( $cfg['max_days'] ); ?>"> <?php esc_html_e( 'days into the future', 'nemvo-booking' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Buffer time', 'nemvo-booking' ); ?></th>
				<td>
					<label><input type="number" class="small-text" min="0" max="240" name="nb[buffer_before]" value="<?php echo esc_attr( $cfg['buffer_before'] ); ?>"> <?php esc_html_e( 'min before', 'nemvo-booking' ); ?></label>
					&nbsp;
					<label><input type="number" class="small-text" min="0" max="240" name="nb[buffer_after]" value="<?php echo esc_attr( $cfg['buffer_after'] ); ?>"> <?php esc_html_e( 'min after', 'nemvo-booking' ); ?></label>
					<p class="description"><?php esc_html_e( 'Free time required around the meeting, so hosts aren\'t booked back-to-back with other events.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function box_availability( $post ) {
		$cfg  = self::cfg( $post );
		$days = array(
			'mon' => __( 'Monday', 'nemvo-booking' ),
			'tue' => __( 'Tuesday', 'nemvo-booking' ),
			'wed' => __( 'Wednesday', 'nemvo-booking' ),
			'thu' => __( 'Thursday', 'nemvo-booking' ),
			'fri' => __( 'Friday', 'nemvo-booking' ),
			'sat' => __( 'Saturday', 'nemvo-booking' ),
			'sun' => __( 'Sunday', 'nemvo-booking' ),
		);
		?>
		<p class="description"><?php esc_html_e( 'When meetings can take place. Hosts\' busy events in Google Calendar are always excluded on top of this. Leave a day empty to close it. Use commas for breaks, e.g. "09:00-12:00, 13:00-17:00".', 'nemvo-booking' ); ?></p>
		<table class="form-table nb-hours" role="presentation">
			<?php foreach ( $days as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="nb-day-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="text" id="nb-day-<?php echo esc_attr( $key ); ?>" class="regular-text code" name="nb[availability][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $cfg['availability'][ $key ] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Closed', 'nemvo-booking' ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="nb-timezone"><?php esc_html_e( 'Time zone', 'nemvo-booking' ); ?></label></th>
				<td>
					<select id="nb-timezone" name="nb[timezone]"><?php echo wp_timezone_choice( $cfg['timezone'], get_user_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select>
					<p class="description"><?php esc_html_e( 'The opening hours above are in this time zone. Visitors see times converted to their own time zone.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Public holidays', 'nemvo-booking' ); ?></th>
				<td>
					<label><input type="checkbox" name="nb[exclude_holidays]" value="1" <?php checked( $cfg['exclude_holidays'] ); ?>> <?php esc_html_e( 'Closed on public holidays', 'nemvo-booking' ); ?></label>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to settings */
							esc_html__( 'Which countries\' holidays are used is set in %s. Closed dates from the settings always apply.', 'nemvo-booking' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=nemvo-booking-settings' ) ) . '">' . esc_html__( 'Settings', 'nemvo-booking' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function box_form( $post ) {
		$cfg   = self::cfg( $post );
		$types = array(
			'text'     => __( 'Text', 'nemvo-booking' ),
			'email'    => __( 'Email', 'nemvo-booking' ),
			'tel'      => __( 'Phone', 'nemvo-booking' ),
			'url'      => __( 'Website', 'nemvo-booking' ),
			'textarea' => __( 'Long text', 'nemvo-booking' ),
			'select'   => __( 'Dropdown', 'nemvo-booking' ),
			'checkbox' => __( 'Checkbox', 'nemvo-booking' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nb-description"><?php esc_html_e( 'Description', 'nemvo-booking' ); ?></label></th>
				<td>
					<textarea id="nb-description" class="large-text" rows="4" name="nb[description]" placeholder="<?php esc_attr_e( 'A short call to get to know each other and see how we can help.', 'nemvo-booking' ); ?>"><?php echo esc_textarea( $cfg['description'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Shown next to the calendar. Basic HTML allowed.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Host photos', 'nemvo-booking' ); ?></th>
				<td>
					<label><input type="checkbox" name="nb[show_photos]" value="1" <?php checked( $cfg['show_photos'] ); ?>> <?php esc_html_e( 'Show the hosts\' photos above the title', 'nemvo-booking' ); ?></label>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Employees page */
							esc_html__( 'Each host\'s photo is set under %s. Hosts without a photo use their profile picture from Gravatar, if avatars are switched on under Settings → Discussion, or are left out.', 'nemvo-booking' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=nemvo-booking-employees' ) ) . '">' . esc_html__( 'Bookings → Employees', 'nemvo-booking' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>

		<h4><?php esc_html_e( 'Questions', 'nemvo-booking' ); ?></h4>
		<p class="description"><?php esc_html_e( 'The visitor fills these in after picking a time. Answers are shown in the calendar event, the emails and under Bookings. Name and email are always required. The key is used as a placeholder in emails, e.g. {company}.', 'nemvo-booking' ); ?></p>
		<table class="widefat nb-fields">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'Key', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'Type', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'Placeholder / dropdown options', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'Required', 'nemvo-booking' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cfg['fields'] as $i => $field ) : ?>
					<?php self::field_row( (string) $i, $field, $types ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" data-add-field><?php esc_html_e( '+ Add question', 'nemvo-booking' ); ?></button></p>
		<template id="nb-field-template">
			<?php
			self::field_row(
				'__i__',
				array(
					'key'         => '',
					'label'       => '',
					'type'        => 'text',
					'required'    => 0,
					'placeholder' => '',
					'options'     => '',
				),
				$types
			);
			?>
		</template>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Guests', 'nemvo-booking' ); ?></th>
				<td>
					<label><input type="checkbox" name="nb[allow_guests]" value="1" <?php checked( $cfg['allow_guests'] ); ?>> <?php esc_html_e( 'Let visitors invite colleagues by email', 'nemvo-booking' ); ?></label>
					&nbsp;
					<label><?php esc_html_e( 'Max', 'nemvo-booking' ); ?> <input type="number" class="small-text" min="1" max="50" name="nb[max_guests]" value="<?php echo esc_attr( $cfg['max_guests'] ); ?>"></label>
					<p class="description"><?php esc_html_e( 'Guests are added to the meeting and receive the calendar invitation.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function field_row( string $i, array $field, array $types ) {
		$core = in_array( $field['key'], array( 'name', 'email' ), true );
		$name = 'nb[fields][' . $i . ']';
		?>
		<tr class="nb-field-row<?php echo $core ? ' is-core' : ''; ?>">
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="<?php esc_attr_e( 'Question', 'nemvo-booking' ); ?>"></td>
			<td>
				<?php if ( $core ) : ?>
					<code><?php echo esc_html( $field['key'] ); ?></code>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $field['key'] ); ?>">
				<?php else : ?>
					<input type="text" class="widefat code" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $field['key'] ); ?>" placeholder="<?php esc_attr_e( 'auto', 'nemvo-booking' ); ?>">
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $core ) : ?>
					<?php echo esc_html( $types[ $field['type'] ] ); ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( $field['type'] ); ?>">
				<?php else : ?>
					<select name="<?php echo esc_attr( $name ); ?>[type]" data-field-type>
						<?php foreach ( $types as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $field['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
			</td>
			<td>
				<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[placeholder]" value="<?php echo esc_attr( $field['placeholder'] ); ?>" placeholder="<?php esc_attr_e( 'Placeholder text', 'nemvo-booking' ); ?>">
				<textarea class="widefat nb-options" rows="3" name="<?php echo esc_attr( $name ); ?>[options]" placeholder="<?php esc_attr_e( 'One option per line', 'nemvo-booking' ); ?>" <?php echo 'select' === $field['type'] ? '' : 'hidden'; ?>><?php echo esc_textarea( $field['options'] ); ?></textarea>
			</td>
			<td class="nb-center">
				<?php if ( $core ) : ?>
					✓<input type="hidden" name="<?php echo esc_attr( $name ); ?>[required]" value="1">
				<?php else : ?>
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( $field['required'] ); ?>>
				<?php endif; ?>
			</td>
			<td class="nb-row-actions">
				<button type="button" class="button-link" data-move="-1" aria-label="<?php esc_attr_e( 'Move up', 'nemvo-booking' ); ?>">↑</button>
				<button type="button" class="button-link" data-move="1" aria-label="<?php esc_attr_e( 'Move down', 'nemvo-booking' ); ?>">↓</button>
				<?php if ( ! $core ) : ?>
					<button type="button" class="button-link button-link-delete" data-remove-field aria-label="<?php esc_attr_e( 'Remove', 'nemvo-booking' ); ?>">✕</button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function box_after( $post ) {
		$cfg = self::cfg( $post );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="nb-thank-you"><?php esc_html_e( 'Thank you message', 'nemvo-booking' ); ?></label></th>
				<td>
					<?php
					wp_editor(
						$cfg['thank_you'],
						'nb-thank-you',
						array(
							'textarea_name' => 'nb[thank_you]',
							'textarea_rows' => 6,
							'media_buttons' => false,
							'teeny'         => true,
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Shown after booking, above a summary with date, time and the Google Meet link. Placeholders such as {name}, {email}, {date}, {time}, {host} and {meet_link} work here too.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Changes', 'nemvo-booking' ); ?></th>
				<td>
					<label><input type="checkbox" name="nb[allow_reschedule]" value="1" <?php checked( $cfg['allow_reschedule'] ); ?>> <?php esc_html_e( 'Let the booker pick a new time', 'nemvo-booking' ); ?></label>
					<p class="description"><?php esc_html_e( 'Adds a "pick a new time" link to the confirmation email ({reschedule_link}). The meeting keeps its Google Meet link and everyone receives the updated time. Cancelling is always possible until the meeting starts.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nb-redirect"><?php esc_html_e( 'Redirect instead', 'nemvo-booking' ); ?></label></th>
				<td>
					<input type="url" id="nb-redirect" class="regular-text" name="nb[redirect_url]" value="<?php echo esc_attr( $cfg['redirect_url'] ); ?>" placeholder="https://">
					<p class="description"><?php esc_html_e( 'Optional. Send the visitor to this page after booking instead of showing the message (useful for conversion tracking).', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="nb-meeting-title"><?php esc_html_e( 'Calendar event title', 'nemvo-booking' ); ?></label></th>
				<td>
					<input type="text" id="nb-meeting-title" class="regular-text" name="nb[meeting_title]" value="<?php echo esc_attr( $cfg['meeting_title'] ); ?>">
					<p class="description"><?php esc_html_e( 'Title of the event in everyone\'s calendar. Placeholders: {page_title}, {name}, {company} and other field keys.', 'nemvo-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Emails', 'nemvo-booking' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %s: link to settings */
						esc_html__( 'Confirmation and notification emails are edited in %s.', 'nemvo-booking' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=nemvo-booking-settings' ) ) . '">' . esc_html__( 'Settings', 'nemvo-booking' ) . '</a>'
					);
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function box_embed( $post ) {
		$shortcode = '[nemvo_booking id="' . (int) $post->ID . '"]';
		if ( 'publish' !== $post->post_status ) {
			echo '<p class="nb-warning">' . esc_html__( 'Publish this booking page first, then add it to a page.', 'nemvo-booking' ) . '</p>';
		}
		?>
		<p><strong><?php esc_html_e( 'With the block editor', 'nemvo-booking' ); ?></strong></p>
		<ol class="nb-steps">
			<li><?php esc_html_e( 'Open the page (or create one under Pages → Add New Page).', 'nemvo-booking' ); ?></li>
			<li><?php esc_html_e( 'Click + (Add block), search for "Booking page" and insert it.', 'nemvo-booking' ); ?></li>
			<li>
				<?php
				/* translators: %s: booking page title */
				printf( esc_html__( 'Pick "%s" in the block.', 'nemvo-booking' ), esc_html( get_the_title( $post ) ?: __( '(this booking page)', 'nemvo-booking' ) ) );
				?>
			</li>
			<li><?php esc_html_e( 'Optional: choose "Wide width" in the block toolbar to give the calendar more room. Then update the page.', 'nemvo-booking' ); ?></li>
		</ol>
		<p><strong><?php esc_html_e( 'Anywhere else', 'nemvo-booking' ); ?></strong> – <?php esc_html_e( 'classic editor, Shortcode block, widgets, page builders or theme hooks – paste:', 'nemvo-booking' ); ?></p>
		<?php echo Admin::copy_field( $shortcode, 'widefat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<p class="description"><?php esc_html_e( 'The calendar adapts to the space it gets: side by side from about 900 px wide, stacked in narrower columns and on phones. Use one booking calendar per page.', 'nemvo-booking' ); ?></p>

		<?php if ( 'generatepress' === get_template() ) : ?>
			<p><strong>GeneratePress</strong></p>
			<ul class="nb-steps">
				<li><?php esc_html_e( 'In the page\'s Layout panel (block editor sidebar or the box below the editor), set Sidebars to "No Sidebars" so the calendar gets the full content width.', 'nemvo-booking' ); ?></li>
				<li><?php esc_html_e( 'Keep Content Container on "Default". "Full Width" removes the side padding, so only use it if you place the block inside a container that adds its own width and padding.', 'nemvo-booking' ); ?></li>
				<li><?php esc_html_e( 'With GP Premium you can also show the calendar through Elements: create a Block element, add the Booking page block, and choose where it appears.', 'nemvo-booking' ); ?></li>
			</ul>
		<?php endif; ?>
		<?php if ( defined( 'GENERATEBLOCKS_VERSION' ) ) : ?>
			<p><strong>GenerateBlocks</strong></p>
			<ul class="nb-steps">
				<li><?php esc_html_e( 'The Booking page block works inside Container, Grid and Element blocks. Give the container a max width (e.g. 1100 px) and padding to build a landing section around the calendar.', 'nemvo-booking' ); ?></li>
			</ul>
		<?php endif; ?>

		<?php
		$used_on = Pages::embedded_on( (int) $post->ID );
		if ( $used_on ) :
			?>
			<p><strong><?php esc_html_e( 'Shown on', 'nemvo-booking' ); ?></strong></p>
			<ul class="nb-steps">
				<?php foreach ( $used_on as $page ) : ?>
					<li><a href="<?php echo esc_url( get_permalink( $page ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $page ) ?: '#' . $page->ID ); ?></a> · <a href="<?php echo esc_url( get_edit_post_link( $page ) ); ?>"><?php esc_html_e( 'Edit', 'nemvo-booking' ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php elseif ( 'publish' === $post->post_status ) : ?>
			<p class="nb-warning"><?php esc_html_e( 'Not added to any page yet, so visitors can\'t see it.', 'nemvo-booking' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['nemvo_booking_page_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nemvo_booking_page_nonce'] ) ), 'nemvo_booking_page' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$input = isset( $_POST['nb'] ) && is_array( $_POST['nb'] ) ? wp_unslash( $_POST['nb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in Pages::sanitize().
		if ( isset( $input['fields'] ) ) {
			$input['fields'] = array_values( $input['fields'] );
		}
		update_post_meta( $post_id, Pages::META, Pages::sanitize( $input ) );
	}

	public static function columns( $columns ) {
		return array(
			'cb'          => $columns['cb'],
			'title'       => $columns['title'],
			'nb_hosts'    => __( 'Hosts', 'nemvo-booking' ),
			'nb_duration' => __( 'Length', 'nemvo-booking' ),
			'nb_upcoming' => __( 'Upcoming bookings', 'nemvo-booking' ),
			'nb_shown_on' => __( 'Shown on', 'nemvo-booking' ),
			'nb_code'     => __( 'Shortcode', 'nemvo-booking' ),
		);
	}

	public static function column( $column, $post_id ) {
		$cfg = Pages::config( $post_id );
		switch ( $column ) {
			case 'nb_hosts':
				$names = wp_list_pluck( Employees::many( $cfg['employees'] ), 'name' );
				echo esc_html( $names ? implode( ', ', $names ) : '–' );
				if ( ! Pages::is_bookable( $cfg ) ) {
					echo '<br><span class="nb-badge nb-badge--pending">' . esc_html__( 'Not bookable', 'nemvo-booking' ) . '</span>';
				}
				break;
			case 'nb_duration':
				echo esc_html(
					implode(
						', ',
						array_map(
							function ( $d ) {
								/* translators: %d: minutes */
								return sprintf( __( '%d min', 'nemvo-booking' ), $d );
							},
							$cfg['durations']
						)
					)
				);
				break;
			case 'nb_upcoming':
				list( , $total ) = Bookings::query(
					array(
						'view'     => 'upcoming',
						'page_id'  => $post_id,
						'per_page' => 1,
					)
				);
				printf( '<a href="%s">%d</a>', esc_url( admin_url( 'admin.php?page=nemvo-booking&page_id=' . $post_id ) ), (int) $total );
				break;
			case 'nb_shown_on':
				$links = array();
				foreach ( Pages::embedded_on( (int) $post_id ) as $page ) {
					$links[] = '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank">' . esc_html( get_the_title( $page ) ?: '#' . $page->ID ) . '</a>';
				}
				echo $links ? implode( ', ', $links ) : '<span class="nb-badge nb-badge--pending">' . esc_html__( 'Not on any page', 'nemvo-booking' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;
			case 'nb_code':
				printf( '<code>[nemvo_booking id="%d"]</code>', (int) $post_id );
				break;
		}
	}
}
