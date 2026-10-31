<?php
namespace NemvoBooking\Admin;

use NemvoBooking\Employees;
use NemvoBooking\Google;
use NemvoBooking\Holidays;
use NemvoBooking\Log;
use NemvoBooking\Pages;
use NemvoBooking\Protection;
use NemvoBooking\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_setting(
			'nemvo_booking',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$d     = Settings::defaults();
		$out   = array(
			'google_client_id'     => sanitize_text_field( $input['google_client_id'] ?? '' ),
			'google_client_secret' => sanitize_text_field( $input['google_client_secret'] ?? '' ),
			'invite_mode'          => in_array( $input['invite_mode'] ?? '', array( 'google', 'wordpress' ), true ) ? $input['invite_mode'] : 'google',
			'event_description'    => sanitize_textarea_field( $input['event_description'] ?? '' ) ?: $d['event_description'],
			'holiday_calendars'    => array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['holiday_calendars'] ?? array() ) ), array_keys( Holidays::calendars() ) ) ),
			'holiday_custom'       => sanitize_textarea_field( $input['holiday_custom'] ?? '' ),
			'include_observances'  => empty( $input['include_observances'] ) ? 0 : 1,
			'blocked_dates'        => sanitize_textarea_field( $input['blocked_dates'] ?? '' ),
			'from_name'            => sanitize_text_field( $input['from_name'] ?? '' ),
			'from_email'           => sanitize_email( $input['from_email'] ?? '' ),
			'accent_color'         => sanitize_hex_color( $input['accent_color'] ?? '' ) ?: $d['accent_color'],
			'time_format'          => '12' === ( $input['time_format'] ?? '' ) ? '12' : '24',
			'week_numbers'         => empty( $input['week_numbers'] ) ? 0 : 1,
			'log_retention_days'   => max( 0, absint( $input['log_retention_days'] ?? 365 ) ),
			'delete_on_uninstall'  => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
			'honeypot_enabled'     => empty( $input['honeypot_enabled'] ) ? 0 : 1,
			'min_submit_seconds'   => min( 600, absint( $input['min_submit_seconds'] ?? 0 ) ),
			'headless_check'       => empty( $input['headless_check'] ) ? 0 : 1,
			'rate_limit'           => min( 10000, absint( $input['rate_limit'] ?? 0 ) ),
			'blocklist'            => sanitize_textarea_field( $input['blocklist'] ?? '' ),
			'cap_enabled'          => empty( $input['cap_enabled'] ) ? 0 : 1,
			'cap_url'              => untrailingslashit( esc_url_raw( $input['cap_url'] ?? '' ) ),
			'cap_internal_url'     => untrailingslashit( esc_url_raw( $input['cap_internal_url'] ?? '' ) ),
			'cap_site_key'         => sanitize_text_field( $input['cap_site_key'] ?? '' ),
			'cap_secret'           => sanitize_text_field( $input['cap_secret'] ?? '' ),
			'cap_mode'             => 'invisible' === ( $input['cap_mode'] ?? '' ) ? 'invisible' : 'visible',
			'cap_script_url'       => esc_url_raw( $input['cap_script_url'] ?? '' ),
			'alert_email'          => implode( ', ', array_filter( array_map( 'sanitize_email', explode( ',', (string) ( $input['alert_email'] ?? '' ) ) ), 'is_email' ) ),
			'alert_employee'       => empty( $input['alert_employee'] ) ? 0 : 1,
			'privacy_consent'      => empty( $input['privacy_consent'] ) ? 0 : 1,
			'privacy_text'         => sanitize_text_field( $input['privacy_text'] ?? '' ) ?: $d['privacy_text'],
			'retention_days'       => min( 3650, absint( $input['retention_days'] ?? 0 ) ),
		);
		foreach ( array_keys( Settings::email_types() ) as $type ) {
			$out[ "email_{$type}_enabled" ] = empty( $input[ "email_{$type}_enabled" ] ) ? 0 : 1;
			$out[ "email_{$type}_subject" ] = sanitize_text_field( $input[ "email_{$type}_subject" ] ?? '' ) ?: $d[ "email_{$type}_subject" ];
			$out[ "email_{$type}_body" ]    = wp_kses_post( $input[ "email_{$type}_body" ] ?? '' ) ?: $d[ "email_{$type}_body" ];
		}

		// Clear cached holidays when the source changes.
		$old = Settings::all();
		if ( $old['holiday_calendars'] !== $out['holiday_calendars'] || $old['holiday_custom'] !== $out['holiday_custom'] || (int) $old['include_observances'] !== $out['include_observances'] ) {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_nemvo\\_booking\\_hol\\_%' OR option_name LIKE '\\_transient\\_timeout\\_nemvo\\_booking\\_hol\\_%'" );
		}
		if ( $old['google_client_id'] !== $out['google_client_id'] ) {
			Log::info( 'settings.google', 'Google OAuth client settings were changed.' );
		}

		// Check the Cap connection right away so mistakes show up here, not as failed bookings.
		static $tested = false; // WordPress may run this callback twice on the first save.
		if ( $out['cap_enabled'] && ! $tested ) {
			$tested = true;
			if ( ! $out['cap_url'] || ! $out['cap_site_key'] || ! $out['cap_secret'] ) {
				add_settings_error( Settings::OPTION, 'cap', __( 'Cap is enabled but the URL, site key or secret key is missing, so Cap is not used yet.', 'nemvo-booking' ) );
			} else {
				$result = Protection::test_cap( $out );
				if ( is_wp_error( $result ) ) {
					/* translators: %s: error message */
					add_settings_error( Settings::OPTION, 'cap', sprintf( __( 'Could not verify the Cap connection: %s', 'nemvo-booking' ), $result->get_error_message() ) );
				} else {
					add_settings_error( Settings::OPTION, 'cap', __( 'Cap connection verified.', 'nemvo-booking' ), 'success' );
				}
			}
		}
		return $out;
	}

	private static function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	public static function render() {
		$s         = Settings::all();
		$defaults  = Settings::defaults();
		$employees = Employees::all();
		$connected = count( array_filter( $employees, array( Employees::class, 'is_connected' ) ) );
		$pages     = Pages::published();
		?>
		<div class="wrap nb-admin nb-settings">
			<h1><?php esc_html_e( 'Booking settings', 'nemvo-booking' ); ?></h1>
			<?php settings_errors(); ?>

			<div class="nb-panel nb-checklist">
				<h2><?php esc_html_e( 'Getting started', 'nemvo-booking' ); ?></h2>
				<ol>
					<li class="<?php echo Google::is_configured() ? 'is-done' : ''; ?>">
						<strong><?php esc_html_e( 'Connect this site to Google', 'nemvo-booking' ); ?></strong> –
						<?php esc_html_e( 'follow the guide below and save your Client ID and Client secret.', 'nemvo-booking' ); ?>
					</li>
					<li class="<?php echo $connected ? 'is-done' : ''; ?>">
						<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=nemvo-booking-employees' ) ); ?>"><?php esc_html_e( 'Add employees', 'nemvo-booking' ); ?></a></strong> –
						<?php
						/* translators: 1: connected employees, 2: total employees */
						printf( esc_html__( 'and connect their Google Calendars (%1$d of %2$d connected).', 'nemvo-booking' ), (int) $connected, count( $employees ) );
						?>
					</li>
					<li class="<?php echo $pages ? 'is-done' : ''; ?>">
						<strong><a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Pages::CPT ) ); ?>"><?php esc_html_e( 'Create a booking page', 'nemvo-booking' ); ?></a></strong> –
						<?php esc_html_e( 'choose hosts, meeting lengths, opening hours and form fields.', 'nemvo-booking' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Insert it on a page', 'nemvo-booking' ); ?></strong> –
						<?php
						printf(
							/* translators: %s: shortcode example */
							esc_html__( 'edit a page, add the "Booking page" block and pick the booking page in it. Or paste the shortcode shown on the booking page, e.g. %s. Each booking page shows step-by-step instructions (including GeneratePress/GenerateBlocks tips when those are active) and which pages it is on.', 'nemvo-booking' ),
							'<code>[nemvo_booking id="123"]</code>'
						);
						?>
					</li>
				</ol>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'nemvo_booking' ); ?>

				<div class="nb-panel">
					<h2><?php esc_html_e( '1. Google connection', 'nemvo-booking' ); ?></h2>
					<p><?php esc_html_e( 'Bookings use Google Calendar to check when employees are busy, to create the meeting in their calendar and to generate a Google Meet link. To allow this, the site needs its own "OAuth client" in Google Cloud. This is free and takes about 10 minutes. You only do it once.', 'nemvo-booking' ); ?></p>

					<details class="nb-guide" <?php echo Google::is_configured() ? '' : 'open'; ?>>
						<summary><?php esc_html_e( 'Step-by-step guide', 'nemvo-booking' ); ?></summary>
						<ol>
							<li>
								<strong><?php esc_html_e( 'Create a Google Cloud project.', 'nemvo-booking' ); ?></strong><br>
								<?php
								printf(
									/* translators: %s: link */
									esc_html__( 'Open %s and sign in with a Google account that belongs to your company (ideally a Google Workspace admin). Name the project, e.g. "Website bookings", and click Create. Make sure the new project is selected in the project picker at the top.', 'nemvo-booking' ),
									'<a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">console.cloud.google.com/projectcreate</a>'
								);
								?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Enable the Google Calendar API.', 'nemvo-booking' ); ?></strong><br>
								<?php
								printf(
									/* translators: %s: link */
									esc_html__( 'Open %s and click Enable. (Google Meet links are created through the Calendar API, so no separate Meet API is needed.)', 'nemvo-booking' ),
									'<a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener">' . esc_html__( 'Google Calendar API', 'nemvo-booking' ) . '</a>'
								);
								?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Configure the consent screen.', 'nemvo-booking' ); ?></strong><br>
								<?php
								printf(
									/* translators: %s: link */
									esc_html__( 'Open %s and click Get started. Enter an app name (e.g. your company name) and a support email.', 'nemvo-booking' ),
									'<a href="https://console.cloud.google.com/auth/overview" target="_blank" rel="noopener">' . esc_html__( 'Google Auth Platform', 'nemvo-booking' ) . '</a>'
								);
								?>
								<ul>
									<li><?php echo wp_kses_post( __( '<strong>Audience: Internal</strong> – recommended if all employees use your Google Workspace. No review by Google is needed and access never expires.', 'nemvo-booking' ) ); ?></li>
									<li><?php echo wp_kses_post( __( '<strong>Audience: External</strong> – needed for personal Gmail accounts. After creating it, go to <em>Audience</em> and click <strong>Publish app</strong>. Apps left in "Testing" lose access after 7 days and every employee would need to reconnect. Google will show an "unverified app" warning when connecting; click <em>Advanced → Go to (app name)</em> to continue.', 'nemvo-booking' ) ); ?></li>
								</ul>
								<?php esc_html_e( 'You don\'t have to add scopes manually; the plugin asks for "See and edit events on all your calendars" and "See all your calendars" when an employee connects.', 'nemvo-booking' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Create an OAuth client.', 'nemvo-booking' ); ?></strong><br>
								<?php
								printf(
									/* translators: %s: link */
									esc_html__( 'Open %s, click Create client and choose Application type "Web application". Under "Authorized redirect URIs", click Add URI and paste exactly this address:', 'nemvo-booking' ),
									'<a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener">' . esc_html__( 'Clients', 'nemvo-booking' ) . '</a>'
								);
								?>
								<div class="nb-redirect"><?php echo self::copy_field_html( Google::redirect_uri() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
								<?php esc_html_e( 'Click Create. Leave "Authorized JavaScript origins" empty.', 'nemvo-booking' ); ?>
								<?php if ( 0 !== strpos( Google::redirect_uri(), 'https://' ) && false === strpos( Google::redirect_uri(), '://localhost' ) ) : ?>
									<br><strong class="nb-warning"><?php esc_html_e( 'Google requires https:// for redirect URIs (except localhost). Enable SSL on this site first.', 'nemvo-booking' ); ?></strong>
								<?php endif; ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Copy the Client ID and Client secret', 'nemvo-booking' ); ?></strong>
								<?php esc_html_e( 'that Google shows into the fields below and click Save settings. (If you closed the dialog, open the client in the list; the secret can be found or re-created there.)', 'nemvo-booking' ); ?>
							</li>
							<li>
								<strong><?php esc_html_e( 'Connect each employee\'s calendar.', 'nemvo-booking' ); ?></strong><br>
								<?php
								printf(
									/* translators: %s: link to the Employees page */
									esc_html__( 'Go to %s, add the employee and either click "Connect Google Calendar" (if you can sign in to their Google account) or click "Create connect link" and send the link to the employee. They sign in, approve access and are done – no WordPress account needed.', 'nemvo-booking' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=nemvo-booking-employees' ) ) . '">' . esc_html__( 'Bookings → Employees', 'nemvo-booking' ) . '</a>'
								);
								?>
							</li>
						</ol>
						<p class="description"><?php esc_html_e( 'Troubleshooting: "redirect_uri_mismatch" means the redirect URI in Google doesn\'t exactly match the one above (check http vs https and www). "access_denied" usually means the app is Internal but the employee uses an account outside your Workspace, or the app is External and still in Testing without the employee added as a test user.', 'nemvo-booking' ); ?></p>
					</details>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nb-client-id"><?php esc_html_e( 'Client ID', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="text" id="nb-client-id" class="large-text code" name="<?php echo esc_attr( self::name( 'google_client_id' ) ); ?>" value="<?php echo esc_attr( $s['google_client_id'] ); ?>" autocomplete="off">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-client-secret"><?php esc_html_e( 'Client secret', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="password" id="nb-client-secret" class="regular-text code" name="<?php echo esc_attr( self::name( 'google_client_secret' ) ); ?>" value="<?php echo esc_attr( $s['google_client_secret'] ); ?>" placeholder="GOCSPX-…" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'Changing the client later requires all employees to reconnect.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'nemvo-booking' ); ?></th>
							<td>
								<?php if ( Google::is_configured() ) : ?>
									<span class="nb-ok">✓ <?php esc_html_e( 'Client configured.', 'nemvo-booking' ); ?></span>
									<?php
									/* translators: 1: connected employees, 2: total employees */
									printf( esc_html__( '%1$d of %2$d employees have connected their calendar.', 'nemvo-booking' ), (int) $connected, count( $employees ) );
									?>
								<?php else : ?>
									<span class="nb-warning"><?php esc_html_e( 'Not configured yet.', 'nemvo-booking' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-alert-email"><?php esc_html_e( 'Connection alerts', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="text" id="nb-alert-email" class="regular-text" name="<?php echo esc_attr( self::name( 'alert_email' ) ); ?>" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
								<p class="description"><?php esc_html_e( 'If an employee\'s Google connection stops working (password changed, access removed, app still in Testing mode, calendar deleted…), their booking pages show no times. An email is sent here once when that happens, with a link to reconnect. Separate several addresses with commas. Empty = the site admin email. The connection is also checked once a day, and a warning is shown in wp-admin until it is fixed.', 'nemvo-booking' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'alert_employee' ) ); ?>" value="1" <?php checked( $s['alert_employee'] ); ?>> <?php esc_html_e( 'Also email the affected employee (they can reconnect themselves with the link)', 'nemvo-booking' ); ?></label>
							</td>
						</tr>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( '2. Calendar invitations', 'nemvo-booking' ); ?></h2>
					<p><?php esc_html_e( 'Every booking creates an event with a Google Meet link in the organizing host\'s calendar. Everyone receives exactly one email per booking, change or cancellation: the one with the calendar invitation. Choose who sends it:', 'nemvo-booking' ); ?></p>
					<fieldset class="nb-radios">
						<label>
							<input type="radio" name="<?php echo esc_attr( self::name( 'invite_mode' ) ); ?>" value="google" <?php checked( $s['invite_mode'], 'google' ); ?>>
							<strong><?php esc_html_e( 'Sent by Google Calendar (recommended)', 'nemvo-booking' ); ?></strong><br>
							<span class="description"><?php esc_html_e( 'Google emails the invitation from the organizing host\'s calendar to the booker, guests and other hosts, with the Meet link, time and the invitation text below. Updates and cancellations also come from Google. Accept/decline answers show up in the host\'s calendar. WordPress only emails the organizing host (Google doesn\'t notify the organizer of their own event), using the host emails below.', 'nemvo-booking' ); ?></span>
						</label>
						<label>
							<input type="radio" name="<?php echo esc_attr( self::name( 'invite_mode' ) ); ?>" value="wordpress" <?php checked( $s['invite_mode'], 'wordpress' ); ?>>
							<strong><?php esc_html_e( 'Sent by WordPress', 'nemvo-booking' ); ?></strong><br>
							<span class="description"><?php esc_html_e( 'Google sends nothing. Instead, each person gets one of the emails below with the invitation (.ics) attached, which Gmail, Outlook and Apple Mail show with Accept/Decline buttons. Use this if you want all email to come from this website and to fully control the wording. Make sure WordPress can send email reliably (e.g. with WP Mail SMTP).', 'nemvo-booking' ); ?></span>
						</label>
					</fieldset>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nb-event-description"><?php esc_html_e( 'Invitation text', 'nemvo-booking' ); ?></label></th>
							<td>
								<textarea id="nb-event-description" class="large-text" rows="7" name="<?php echo esc_attr( self::name( 'event_description' ) ); ?>"><?php echo esc_textarea( $s['event_description'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'The description of the calendar event, shown in the invitation email and in everyone\'s calendar. When Google sends the invitations, this is all the booker receives, so keep the answers and the links here. Plain text; links become clickable. The same placeholders as in the emails below work here (e.g. {answers}, {reschedule_link}, {cancel_link}, {company}); the line with {reschedule_link} is left out on booking pages where rescheduling is off. The event title is set per booking page.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( '3. Emails', 'nemvo-booking' ); ?></h2>
					<p><?php esc_html_e( 'These emails are sent by WordPress (wp_mail). You can change the sender, switch each email on or off, and edit subjects and texts. Basic HTML such as <strong> and <a> is allowed in the texts; line breaks are kept and links become clickable.', 'nemvo-booking' ); ?></p>
					<p><strong>
						<?php
						echo 'google' === $s['invite_mode']
							? esc_html__( 'Invitations are currently sent by Google, so only the three "(to hosts)" emails are used, and only for the organizing host. Switch to "Sent by WordPress" above to use all of them.', 'nemvo-booking' )
							: esc_html__( 'Invitations are currently sent by WordPress: every email below is used, each with the invitation attached (except to the organizing host, who already has the event).', 'nemvo-booking' );
						?>
					</strong></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nb-from-name"><?php esc_html_e( 'Sender name', 'nemvo-booking' ); ?></label></th>
							<td><input type="text" id="nb-from-name" class="regular-text" name="<?php echo esc_attr( self::name( 'from_name' ) ); ?>" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-from-email"><?php esc_html_e( 'Sender email', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="email" id="nb-from-email" class="regular-text" name="<?php echo esc_attr( self::name( 'from_email' ) ); ?>" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="<?php echo esc_attr( 'bookings@' . wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>">
								<p class="description"><?php esc_html_e( 'Leave empty to use the WordPress default. Use an address on your own domain so emails aren\'t marked as spam. Replies go to the host (for booker emails) or the booker (for host emails).', 'nemvo-booking' ); ?></p>
								<p class="description"><?php esc_html_e( 'All email is sent with wp_mail(), so mail plugins such as WP Mail SMTP are used automatically – nothing to configure here. If WP Mail SMTP\'s "Force From Email"/"Force From Name" is on, its sender wins over the fields above.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
					</table>

					<details class="nb-placeholders">
						<summary><?php esc_html_e( 'Available placeholders', 'nemvo-booking' ); ?></summary>
						<table class="widefat striped">
							<?php foreach ( Settings::placeholders() as $tag => $label ) : ?>
								<tr><td><code><?php echo esc_html( $tag ); ?></code></td><td><?php echo esc_html( $label ); ?></td></tr>
							<?php endforeach; ?>
						</table>
					</details>

					<?php foreach ( Settings::email_types() as $type => $info ) : ?>
						<div class="nb-email" data-default-subject="<?php echo esc_attr( $defaults[ "email_{$type}_subject" ] ); ?>" data-default-body="<?php echo esc_attr( $defaults[ "email_{$type}_body" ] ); ?>">
							<div class="nb-email-head">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::name( "email_{$type}_enabled" ) ); ?>" value="1" <?php checked( $s[ "email_{$type}_enabled" ] ); ?>>
									<strong><?php echo esc_html( $info[0] ); ?></strong>
								</label>
								<span class="description"><?php echo esc_html( $info[1] ); ?><?php echo 0 === strpos( $type, 'employee_' ) ? ' ' . esc_html__( 'With Google invitations: only to the organizing host.', 'nemvo-booking' ) : ' ' . esc_html__( 'Only used when invitations are sent by WordPress.', 'nemvo-booking' ); ?></span>
								<button type="button" class="button-link" data-reset-email><?php esc_html_e( 'Restore default', 'nemvo-booking' ); ?></button>
							</div>
							<label class="nb-sr" for="nb-<?php echo esc_attr( $type ); ?>-subject"><?php esc_html_e( 'Subject', 'nemvo-booking' ); ?></label>
							<input type="text" id="nb-<?php echo esc_attr( $type ); ?>-subject" class="large-text" data-subject name="<?php echo esc_attr( self::name( "email_{$type}_subject" ) ); ?>" value="<?php echo esc_attr( $s[ "email_{$type}_subject" ] ); ?>">
							<label class="nb-sr" for="nb-<?php echo esc_attr( $type ); ?>-body"><?php esc_html_e( 'Text', 'nemvo-booking' ); ?></label>
							<textarea id="nb-<?php echo esc_attr( $type ); ?>-body" class="large-text" rows="9" data-body name="<?php echo esc_attr( self::name( "email_{$type}_body" ) ); ?>"><?php echo esc_textarea( $s[ "email_{$type}_body" ] ); ?></textarea>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( '4. Public holidays and closed days', 'nemvo-booking' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Public holidays', 'nemvo-booking' ); ?></th>
							<td>
								<fieldset class="nb-holiday-list">
									<legend class="nb-sr"><?php esc_html_e( 'Countries', 'nemvo-booking' ); ?></legend>
									<?php foreach ( Holidays::calendars() as $id => $label ) : ?>
										<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'holiday_calendars' ) ); ?>[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, (array) $s['holiday_calendars'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Tick every country whose public holidays should close your booking pages, e.g. where you and your colleagues live. Booking pages with "Closed on public holidays" are then closed on all of them. Holidays are read from Google\'s public holiday calendars through a connected employee\'s account and cached for a day.', 'nemvo-booking' ); ?></p>
								<label for="nb-holiday-custom"><strong><?php esc_html_e( 'Other Google calendars', 'nemvo-booking' ); ?></strong></label>
								<textarea id="nb-holiday-custom" class="large-text code" rows="2" name="<?php echo esc_attr( self::name( 'holiday_custom' ) ); ?>" placeholder="en.japanese#holiday@group.v.calendar.google.com"><?php echo esc_textarea( $s['holiday_custom'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Optional. One calendar ID per line – another country\'s holiday calendar, or a shared company calendar with all-day "closed" events. Find a calendar\'s ID in Google Calendar under Settings → (calendar) → Integrate calendar.', 'nemvo-booking' ); ?></p>
								<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'include_observances' ) ); ?>" value="1" <?php checked( $s['include_observances'] ); ?>> <?php esc_html_e( 'Also close on observances (e.g. Christmas Eve, Midsummer Eve, New Year\'s Eve)', 'nemvo-booking' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-blocked"><?php esc_html_e( 'Closed dates', 'nemvo-booking' ); ?></label></th>
							<td>
								<textarea id="nb-blocked" class="large-text code" rows="5" name="<?php echo esc_attr( self::name( 'blocked_dates' ) ); ?>" placeholder="2026-12-24&#10;2026-12-27 - 2027-01-01 Christmas break"><?php echo esc_textarea( $s['blocked_dates'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Extra days when nobody can be booked on any booking page. One date (YYYY-MM-DD) or range (YYYY-MM-DD - YYYY-MM-DD) per line; text after the date is ignored and can be used as a note. For one person\'s vacation, simply add a "Busy" event to their Google Calendar instead.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( '5. Privacy (GDPR)', 'nemvo-booking' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Consent checkbox', 'nemvo-booking' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'privacy_consent' ) ); ?>" value="1" <?php checked( $s['privacy_consent'] ); ?>> <?php esc_html_e( 'Visitors must tick a checkbox before booking', 'nemvo-booking' ); ?></label>
								<input type="text" class="large-text" name="<?php echo esc_attr( self::name( 'privacy_text' ) ); ?>" value="<?php echo esc_attr( $s['privacy_text'] ); ?>">
								<p class="description">
									<?php
									$policy = get_privacy_policy_url();
									echo esc_html__( '{privacy_policy} becomes a link to your privacy policy page, {site_name} your site name. The time of consent is saved with each booking.', 'nemvo-booking' ) . ' ';
									if ( $policy ) {
										echo '<a href="' . esc_url( $policy ) . '" target="_blank">' . esc_html__( 'View privacy policy page', 'nemvo-booking' ) . '</a>';
									} else {
										echo '<span class="nb-warning">' . esc_html__( 'No privacy policy page is set yet –', 'nemvo-booking' ) . '</span> <a href="' . esc_url( admin_url( 'options-privacy.php' ) ) . '">' . esc_html__( 'choose one under Settings → Privacy', 'nemvo-booking' ) . '</a>.';
									}
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-retention-bookings"><?php esc_html_e( 'Delete personal data', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="number" id="nb-retention-bookings" class="small-text" min="0" name="<?php echo esc_attr( self::name( 'retention_days' ) ); ?>" value="<?php echo esc_attr( $s['retention_days'] ); ?>"> <?php esc_html_e( 'days after the meeting', 'nemvo-booking' ); ?>
								<p class="description"><?php esc_html_e( 'Names, emails, answers, guests and IP addresses are then removed from the booking and its history log; the time, booking page and hosts are kept for statistics. Runs once a day. 0 keeps data until deleted by hand. Events in Google Calendar are not touched.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Requests from visitors', 'nemvo-booking' ); ?></th>
							<td>
								<p class="description">
									<?php
									printf(
										/* translators: 1: link to export tool, 2: link to erase tool, 3: link to policy guide */
										esc_html__( 'If someone asks for their data, use WordPress\'s %1$s or %2$s tools with their email address – bookings (as booker or guest) are included. Suggested text for your privacy policy is available in the %3$s.', 'nemvo-booking' ),
										'<a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Export Personal Data', 'nemvo-booking' ) . '</a>',
										'<a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Erase Personal Data', 'nemvo-booking' ) . '</a>',
										'<a href="' . esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ) . '">' . esc_html__( 'privacy policy guide', 'nemvo-booking' ) . '</a>'
									);
									?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( '6. Spam and abuse protection', 'nemvo-booking' ); ?></h2>
					<p><?php esc_html_e( 'Every booking creates a real calendar event and sends emails, so it is worth keeping bots out. The checks below are combined; blocked attempts are listed in the History log as "spam.blocked" so you can see what is caught.', 'nemvo-booking' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Honeypot', 'nemvo-booking' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'honeypot_enabled' ) ); ?>" value="1" <?php checked( $s['honeypot_enabled'] ); ?>> <?php esc_html_e( 'Add a hidden field that people never see but simple bots fill in', 'nemvo-booking' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-min-seconds"><?php esc_html_e( 'Time-to-submit check', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="number" id="nb-min-seconds" class="small-text" min="0" max="600" name="<?php echo esc_attr( self::name( 'min_submit_seconds' ) ); ?>" value="<?php echo esc_attr( $s['min_submit_seconds'] ); ?>"> <?php esc_html_e( 'seconds', 'nemvo-booking' ); ?>
								<p class="description"><?php esc_html_e( 'Reject bookings sent faster than this after the calendar loaded. People need far longer to pick a time and fill in the form; scripts don\'t. The start time is signed by the server, so it can\'t be faked and works with page caching. 0 turns the check off.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Headless browser detection', 'nemvo-booking' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'headless_check' ) ); ?>" value="1" <?php checked( $s['headless_check'] ); ?>> <?php esc_html_e( 'Block automated browsers and scripts', 'nemvo-booking' ); ?></label>
								<p class="description"><?php esc_html_e( 'Looks for signs of automation such as navigator.webdriver, "HeadlessChrome", Puppeteer/Playwright/Selenium, and HTTP libraries like curl or python-requests. Works without Cap. If you use Cap, also enable "block automated browsers" on the Cap site key – it runs a deeper check inside the browser.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-rate-limit"><?php esc_html_e( 'Rate limit', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="number" id="nb-rate-limit" class="small-text" min="0" name="<?php echo esc_attr( self::name( 'rate_limit' ) ); ?>" value="<?php echo esc_attr( $s['rate_limit'] ); ?>"> <?php esc_html_e( 'booking attempts per IP address per hour', 'nemvo-booking' ); ?>
								<p class="description"><?php esc_html_e( '0 turns the limit off. Behind a proxy or CDN, make sure WordPress sees the visitor\'s real IP address (REMOTE_ADDR), otherwise everyone shares one limit.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-blocklist"><?php esc_html_e( 'Blocklist', 'nemvo-booking' ); ?></label></th>
							<td>
								<textarea id="nb-blocklist" class="large-text code" rows="4" name="<?php echo esc_attr( self::name( 'blocklist' ) ); ?>" placeholder="spammer@example.com&#10;mailinator.com&#10;203.0.113.7"><?php echo esc_textarea( $s['blocklist'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One entry per line: an email address, a domain (also blocks its subdomains) or an IP address. Lines starting with # are ignored.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Cap – self-hosted proof-of-work CAPTCHA', 'nemvo-booking' ); ?></h3>
					<p><?php esc_html_e( 'Cap makes the visitor\'s browser solve a small computational puzzle (usually under a second) before booking. There are no images to click, no cookies and no third-party tracking. It runs as two Docker containers on your own server: Cap itself and Valkey, which Cap uses for storage.', 'nemvo-booking' ); ?></p>

					<details class="nb-guide" <?php echo $s['cap_enabled'] ? '' : 'open'; ?>>
						<summary><?php esc_html_e( 'How to run Cap', 'nemvo-booking' ); ?></summary>
						<ol>
							<li>
								<?php esc_html_e( 'On a server with Docker, create a folder with a file named docker-compose.yml containing:', 'nemvo-booking' ); ?>
<pre class="nb-code">services:
  cap:
    image: tiago2/cap:latest
    container_name: cap
    restart: unless-stopped
    ports:
      - "3000:3000"
    environment:
      ADMIN_KEY: "<?php esc_html_e( 'replace-with-a-random-string-of-at-least-32-characters', 'nemvo-booking' ); ?>"
      REDIS_URL: redis://valkey:6379
    depends_on:
      valkey:
        condition: service_healthy

  valkey:
    image: valkey/valkey:9-alpine
    container_name: cap-valkey
    restart: unless-stopped
    command: valkey-server --save 60 1 --loglevel warning --maxmemory-policy noeviction
    volumes:
      - valkey-data:/data
    healthcheck:
      test: ["CMD", "valkey-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 5

volumes:
  valkey-data:</pre>
								<?php
								printf(
									/* translators: %s: command */
									esc_html__( 'Generate the admin key with e.g. %s. It is the password for Cap\'s dashboard.', 'nemvo-booking' ),
									'<code>openssl rand -hex 24</code>'
								);
								?>
							</li>
							<li>
								<?php
								printf(
									/* translators: 1: command, 2: command */
									esc_html__( 'Start it with %1$s. Check that both containers run with %2$s.', 'nemvo-booking' ),
									'<code>docker compose up -d</code>',
									'<code>docker compose ps</code>'
								);
								?>
							</li>
							<li><?php esc_html_e( 'Make Cap reachable over HTTPS from the internet, e.g. as https://cap.example.com, through your reverse proxy (Caddy, nginx, Traefik…) forwarding to port 3000. Visitors\' browsers talk to Cap directly, and a site on https:// can\'t use an http:// address.', 'nemvo-booking' ); ?></li>
							<li><?php esc_html_e( 'Open Cap\'s dashboard at that address, log in with the admin key and create a site key. Copy the site key and its secret key (the secret is shown once). In the site key\'s settings: keep "instrumentation challenges" on, and turn on "block automated browsers" for Cap\'s own headless browser detection. You can also restrict the key to your site\'s domain (CORS origins) and tune the difficulty.', 'nemvo-booking' ); ?></li>
							<li><?php esc_html_e( 'Fill in the fields below, tick "Use Cap" and save. The connection is tested when you save.', 'nemvo-booking' ); ?></li>
						</ol>
						<p class="description"><?php esc_html_e( 'To update Cap later: docker compose pull && docker compose up -d. If the dashboard isn\'t reachable, Cap\'s documentation suggests adding network_mode: "host" to the cap service.', 'nemvo-booking' ); ?></p>
					</details>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Use Cap', 'nemvo-booking' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( self::name( 'cap_enabled' ) ); ?>" value="1" <?php checked( $s['cap_enabled'] ); ?>> <?php esc_html_e( 'Require a solved Cap challenge for every booking', 'nemvo-booking' ); ?></label>
								<p class="description"><?php esc_html_e( 'If Cap can\'t be reached, bookings are refused (and an error is logged), so keep the Cap server running while this is enabled.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-cap-url"><?php esc_html_e( 'Cap URL', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="url" id="nb-cap-url" class="regular-text code" name="<?php echo esc_attr( self::name( 'cap_url' ) ); ?>" value="<?php echo esc_attr( $s['cap_url'] ); ?>" placeholder="https://cap.example.com">
								<p class="description"><?php esc_html_e( 'The public address of your Cap server, without the site key.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-cap-internal"><?php esc_html_e( 'Internal URL (optional)', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="url" id="nb-cap-internal" class="regular-text code" name="<?php echo esc_attr( self::name( 'cap_internal_url' ) ); ?>" value="<?php echo esc_attr( $s['cap_internal_url'] ); ?>" placeholder="http://cap:3000">
								<p class="description"><?php esc_html_e( 'Address WordPress uses to verify answers, if it differs from the public one – e.g. http://cap:3000 when WordPress runs in the same Docker network. Leave empty to use the Cap URL.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-cap-key"><?php esc_html_e( 'Site key', 'nemvo-booking' ); ?></label></th>
							<td><input type="text" id="nb-cap-key" class="regular-text code" name="<?php echo esc_attr( self::name( 'cap_site_key' ) ); ?>" value="<?php echo esc_attr( $s['cap_site_key'] ); ?>" autocomplete="off"></td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-cap-secret"><?php esc_html_e( 'Secret key', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="password" id="nb-cap-secret" class="regular-text code" name="<?php echo esc_attr( self::name( 'cap_secret' ) ); ?>" value="<?php echo esc_attr( $s['cap_secret'] ); ?>" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'The site key\'s secret – not the dashboard admin key.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Widget', 'nemvo-booking' ); ?></th>
							<td>
								<fieldset class="nb-radios">
									<label><input type="radio" name="<?php echo esc_attr( self::name( 'cap_mode' ) ); ?>" value="visible" <?php checked( $s['cap_mode'], 'visible' ); ?>> <strong><?php esc_html_e( 'Visible', 'nemvo-booking' ); ?></strong> – <span class="description"><?php esc_html_e( 'An "I\'m a human" box above the booking button that the visitor clicks.', 'nemvo-booking' ); ?></span></label>
									<label><input type="radio" name="<?php echo esc_attr( self::name( 'cap_mode' ) ); ?>" value="invisible" <?php checked( $s['cap_mode'], 'invisible' ); ?>> <strong><?php esc_html_e( 'Invisible', 'nemvo-booking' ); ?></strong> – <span class="description"><?php esc_html_e( 'The puzzle is solved in the background when the visitor clicks "Confirm booking".', 'nemvo-booking' ); ?></span></label>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-cap-script"><?php esc_html_e( 'Widget script URL (optional)', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="url" id="nb-cap-script" class="regular-text code" name="<?php echo esc_attr( self::name( 'cap_script_url' ) ); ?>" value="<?php echo esc_attr( $s['cap_script_url'] ); ?>" placeholder="https://cap.example.com/assets/widget.js">
								<p class="description">
									<?php
									printf(
										/* translators: %s: Cap widget version */
										esc_html__( 'Leave empty to use the Cap widget that comes with this plugin (version %s), so visitors load nothing from elsewhere. To use the widget from your own Cap server instead, start Cap with ENABLE_ASSETS_SERVER=true and enter its /assets/widget.js address. The widget then loads its WASM files from the same folder.', 'nemvo-booking' ),
										esc_html( Protection::CAP_VERSION )
									);
									?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( '7. Appearance', 'nemvo-booking' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nb-accent"><?php esc_html_e( 'Accent color', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="text" id="nb-accent" class="nb-color" name="<?php echo esc_attr( self::name( 'accent_color' ) ); ?>" value="<?php echo esc_attr( $s['accent_color'] ); ?>" data-default-color="<?php echo esc_attr( $defaults['accent_color'] ); ?>">
								<p class="description"><?php esc_html_e( 'Used for buttons, available dates and times in the booking calendar, and the line at the top of emails.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Time format', 'nemvo-booking' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( self::name( 'time_format' ) ); ?>" value="24" <?php checked( $s['time_format'], '24' ); ?>> 14:30</label>
								&nbsp;&nbsp;
								<label><input type="radio" name="<?php echo esc_attr( self::name( 'time_format' ) ); ?>" value="12" <?php checked( $s['time_format'], '12' ); ?>> 2:30 PM</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Week numbers', 'nemvo-booking' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'week_numbers' ) ); ?>" value="1" <?php checked( $s['week_numbers'] ); ?>> <?php esc_html_e( 'Show ISO week numbers in the date picker', 'nemvo-booking' ); ?></label></td>
						</tr>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( '8. Data', 'nemvo-booking' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nb-retention"><?php esc_html_e( 'Keep history log for', 'nemvo-booking' ); ?></label></th>
							<td>
								<input type="number" id="nb-retention" class="small-text" min="0" name="<?php echo esc_attr( self::name( 'log_retention_days' ) ); ?>" value="<?php echo esc_attr( $s['log_retention_days'] ); ?>"> <?php esc_html_e( 'days', 'nemvo-booking' ); ?>
								<p class="description"><?php esc_html_e( 'Older log entries are deleted automatically. 0 keeps them forever. Bookings themselves are never deleted automatically.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Uninstall', 'nemvo-booking' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::name( 'delete_on_uninstall' ) ); ?>" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all bookings, employees, booking pages, log and settings when the plugin is deleted', 'nemvo-booking' ); ?></label></td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save settings', 'nemvo-booking' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function copy_field_html( string $value ): string {
		return Admin::copy_field( $value, 'large-text' );
	}
}
