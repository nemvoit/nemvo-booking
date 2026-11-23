<?php
/**
 * Employees screen.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking\Admin;

use NemvoBooking\Accounts;
use NemvoBooking\Employees;
use NemvoBooking\Google;
use NemvoBooking\Log;
use NemvoBooking\Pages;
use NemvoBooking\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings → Employees: adding employees and connecting their Google Calendars.
 */
class Employees_Page {

	const PAGE = 'nemvo-booking-employees';

	/**
	 * Hooks in the admin-post handlers.
	 */
	public static function init() {
		foreach ( array( 'save', 'delete', 'connect', 'disconnect', 'link', 'refresh', 'account_add', 'account_link', 'account_refresh', 'account_remove' ) as $action ) {
			add_action( 'admin_post_nemvo_booking_employee_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/**
	 * URL of an admin-post action for an employee, with a nonce.
	 *
	 * @param string $action  Action name.
	 * @param int    $id      Employee ID.
	 * @param int    $account Extra account ID, for the account_* actions.
	 * @return string
	 */
	private static function action_url( string $action, int $id, int $account = 0 ): string {
		$url = admin_url( 'admin-post.php?action=nemvo_booking_employee_' . $action . '&id=' . $id . ( $account ? '&account=' . $account : '' ) );
		return wp_nonce_url( $url, 'nemvo_booking_employee_' . $action );
	}

	/**
	 * The extra account named in the request, which must belong to the employee, or a 404 page.
	 *
	 * @param array $employee Employee.
	 * @return array Account.
	 */
	private static function account_or_die( array $employee ): array {
		$account = Accounts::get( isset( $_REQUEST['account'] ) ? absint( $_REQUEST['account'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $account || $account['employee_id'] !== $employee['id'] ) {
			wp_die( esc_html__( 'Google account not found.', 'nemvo-booking' ), 404 );
		}
		return $account;
	}

	/**
	 * The employee named in the request, or a 404 page.
	 *
	 * @return array Employee.
	 */
	private static function employee_or_die(): array {
		$employee = Employees::get( isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $employee ) {
			wp_die( esc_html__( 'Employee not found.', 'nemvo-booking' ), 404 );
		}
		return $employee;
	}

	/**
	 * Booking page titles per employee ID.
	 *
	 * @return array[] Employee ID => titles.
	 */
	private static function usage(): array {
		$usage = array();
		foreach ( Pages::published() as $id => $title ) {
			foreach ( Pages::config( $id )['employees'] as $employee_id ) {
				$usage[ $employee_id ][] = $title;
			}
		}
		return $usage;
	}

	/**
	 * Renders Bookings → Employees: the list, or one employee when edit is set.
	 */
	public static function render() {
		$edit = isset( $_GET['edit'] ) ? Employees::get( absint( $_GET['edit'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $edit ) {
			self::render_edit( $edit );
			return;
		}

		$employees = Employees::all();
		$usage     = self::usage();
		?>
		<div class="wrap nb-admin">
			<h1><?php esc_html_e( 'Employees', 'nemvo-booking' ); ?></h1>
			<p class="nb-intro"><?php esc_html_e( 'Employees are the people visitors can book meetings with. Each one connects their Google Calendar, so busy times are blocked automatically and new meetings (with a Google Meet link) land directly in their calendar. Employees don\'t need a WordPress account.', 'nemvo-booking' ); ?></p>

			<div class="nb-panel">
				<h2><?php esc_html_e( 'Add employee', 'nemvo-booking' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nb-inline-form">
					<input type="hidden" name="action" value="nemvo_booking_employee_save">
					<?php wp_nonce_field( 'nemvo_booking_employee_save' ); ?>
					<label><span><?php esc_html_e( 'Name', 'nemvo-booking' ); ?></span><input type="text" name="name" required placeholder="Anna Andersson"></label>
					<label><span><?php esc_html_e( 'Email', 'nemvo-booking' ); ?></span><input type="email" name="email" required placeholder="anna@example.com"></label>
					<?php submit_button( __( 'Add employee', 'nemvo-booking' ), 'primary', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'The name is shown to visitors on booking pages. The email receives booking notifications and is suggested when connecting Google.', 'nemvo-booking' ); ?></p>
			</div>

			<table class="widefat striped nb-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Employee', 'nemvo-booking' ); ?></th>
						<th><?php esc_html_e( 'Google Calendar', 'nemvo-booking' ); ?></th>
						<th><?php esc_html_e( 'Booking pages', 'nemvo-booking' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $employees ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No employees yet. Add the first one above.', 'nemvo-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $employees as $employee ) : ?>
					<?php $edit_url = admin_url( 'admin.php?page=' . self::PAGE . '&edit=' . $employee['id'] ); ?>
					<tr>
						<td>
							<img class="nb-avatar" src="<?php echo esc_url( Employees::avatar( $employee ) ); ?>" alt="">
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $employee['name'] ); ?></a></strong><br>
							<span class="description"><?php echo esc_html( $employee['email'] ); ?></span>
						</td>
						<td><?php self::status( $employee ); ?></td>
						<td><?php echo esc_html( Plugin::fallback( implode( ', ', $usage[ $employee['id'] ] ?? array() ), '–' ) ); ?></td>
						<td class="nb-actions">
							<a class="button" href="<?php echo esc_url( $edit_url ); ?>"><?php echo Employees::is_connected( $employee ) ? esc_html__( 'Edit', 'nemvo-booking' ) : esc_html__( 'Connect & edit', 'nemvo-booking' ); ?></a>
							<a class="button-link-delete" data-confirm href="<?php echo esc_url( self::action_url( 'delete', $employee['id'] ) ); ?>"><?php esc_html_e( 'Delete', 'nemvo-booking' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Prints an employee's Google connection status.
	 *
	 * @param array $employee Employee.
	 */
	private static function status( array $employee ) {
		if ( ! Employees::is_connected( $employee ) ) {
			echo '<span class="nb-badge nb-badge--pending">' . esc_html__( 'Not connected', 'nemvo-booking' ) . '</span>';
			return;
		}
		if ( $employee['last_error'] ) {
			echo '<span class="nb-badge nb-badge--error">' . esc_html__( 'Error', 'nemvo-booking' ) . '</span> <span class="description">' . esc_html( $employee['last_error'] ) . '</span>';
			return;
		}
		$accounts = Accounts::for_employee( $employee['id'] );
		foreach ( $accounts as $account ) {
			if ( $account['last_error'] ) {
				echo '<span class="nb-badge nb-badge--error">' . esc_html__( 'Error', 'nemvo-booking' ) . '</span> <span class="description">' . esc_html( $account['google_email'] . ': ' . $account['last_error'] ) . '</span>';
				return;
			}
		}
		echo '<span class="nb-badge nb-badge--confirmed">' . esc_html__( 'Connected', 'nemvo-booking' ) . '</span> <span class="description">' . esc_html( $employee['google_email'] ) . '</span>';
		if ( $accounts ) {
			/* translators: %d: number of other Google accounts */
			echo '<br><span class="description">' . esc_html( sprintf( _n( '+ %d other Google account checked for conflicts', '+ %d other Google accounts checked for conflicts', count( $accounts ), 'nemvo-booking' ), count( $accounts ) ) ) . '</span>';
		}
	}

	/**
	 * Renders the edit screen of one employee.
	 *
	 * @param array $employee Employee.
	 */
	private static function render_edit( array $employee ) {
		$connected = Employees::is_connected( $employee );
		$link      = '';
		if ( $employee['connect_token'] && (int) $employee['connect_expires'] > time() ) {
			// Once connected, the employee's link adds another Google account instead.
			$link = Employees::link_url( $employee, $employee['connect_token'], $connected );
		}
		$usage = self::usage()[ $employee['id'] ] ?? array();
		?>
		<div class="wrap nb-admin">
			<h1><?php echo esc_html( $employee['name'] ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">← <?php esc_html_e( 'All employees', 'nemvo-booking' ); ?></a></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nemvo_booking_employee_save">
				<input type="hidden" name="id" value="<?php echo esc_attr( $employee['id'] ); ?>">
				<?php wp_nonce_field( 'nemvo_booking_employee_save' ); ?>

				<div class="nb-panel">
					<h2><?php esc_html_e( 'Details', 'nemvo-booking' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="nb-name"><?php esc_html_e( 'Name', 'nemvo-booking' ); ?></label></th>
							<td><input type="text" id="nb-name" class="regular-text" name="name" required value="<?php echo esc_attr( $employee['name'] ); ?>"><p class="description"><?php esc_html_e( 'Shown to visitors.', 'nemvo-booking' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="nb-email"><?php esc_html_e( 'Email', 'nemvo-booking' ); ?></label></th>
							<td><input type="email" id="nb-email" class="regular-text" name="email" required value="<?php echo esc_attr( $employee['email'] ); ?>"><p class="description"><?php esc_html_e( 'Receives booking notifications.', 'nemvo-booking' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Photo', 'nemvo-booking' ); ?></th>
							<td>
								<div class="nb-photo" data-photo>
									<img src="<?php echo esc_url( Employees::avatar( $employee ) ); ?>" data-placeholder="<?php echo esc_url( Employees::placeholder( $employee ) ); ?>" alt="">
									<input type="hidden" name="photo_id" value="<?php echo esc_attr( $employee['photo_id'] ? $employee['photo_id'] : '' ); ?>">
									<button type="button" class="button" data-photo-choose><?php esc_html_e( 'Choose photo', 'nemvo-booking' ); ?></button>
									<button type="button" class="button-link button-link-delete" data-photo-remove <?php echo $employee['photo_id'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'nemvo-booking' ); ?></button>
								</div>
								<p class="description"><?php esc_html_e( 'Shown on booking pages with "Show the hosts\' photos" switched on. A square photo works best. Without one, the Gravatar for the email address is used.', 'nemvo-booking' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Booking pages', 'nemvo-booking' ); ?></th>
							<td><?php echo esc_html( Plugin::fallback( implode( ', ', $usage ), __( 'Not used on any booking page yet. Add the employee under "Hosts" when editing a booking page.', 'nemvo-booking' ) ) ); ?></td>
						</tr>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( 'Google Calendar', 'nemvo-booking' ); ?></h2>
					<?php if ( ! Google::is_configured() ) : ?>
						<p class="nb-warning">
							<?php esc_html_e( 'The Google connection has not been set up yet.', 'nemvo-booking' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=nemvo-booking-settings' ) ); ?>"><?php esc_html_e( 'Follow the setup guide in Settings first.', 'nemvo-booking' ); ?></a>
						</p>
					<?php elseif ( $connected ) : ?>
						<p><?php self::status( $employee ); ?></p>
						<p>
							<a class="button" href="<?php echo esc_url( self::action_url( 'refresh', $employee['id'] ) ); ?>"><?php esc_html_e( 'Refresh calendar list', 'nemvo-booking' ); ?></a>
							<a class="button" href="<?php echo esc_url( self::action_url( 'connect', $employee['id'] ) ); ?>"><?php esc_html_e( 'Reconnect', 'nemvo-booking' ); ?></a>
							<a class="button-link-delete" data-confirm href="<?php echo esc_url( self::action_url( 'disconnect', $employee['id'] ) ); ?>"><?php esc_html_e( 'Disconnect', 'nemvo-booking' ); ?></a>
						</p>

						<h3><?php esc_html_e( 'Calendars', 'nemvo-booking' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Times are blocked when this employee has an event marked "Busy" in any of the checked calendars. Events marked "Free" – such as most all-day events – don\'t block bookings. New meetings are added to the calendar selected under "Add meetings here".', 'nemvo-booking' ); ?></p>
						<table class="widefat striped nb-table nb-calendars">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Calendar', 'nemvo-booking' ); ?></th>
									<th><?php esc_html_e( 'Check for conflicts', 'nemvo-booking' ); ?></th>
									<th><?php esc_html_e( 'Add meetings here', 'nemvo-booking' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php
							$target = $employee['target_calendar'] ? $employee['target_calendar'] : 'primary';
							foreach ( $employee['calendars'] as $cal ) :
								$is_target = $target === $cal['id'] || ( 'primary' === $target && $cal['primary'] );
								$writable  = in_array( $cal['role'], array( 'owner', 'writer' ), true );
								?>
								<tr>
									<td><?php echo esc_html( $cal['summary'] ); ?><?php echo $cal['primary'] ? ' <span class="description">(' . esc_html__( 'primary', 'nemvo-booking' ) . ')</span>' : ''; ?></td>
									<td><?php self::busy_checkbox( 'busy_calendars[]', $cal, Employees::busy_calendars( $employee ) ); ?></td>
									<td>
									<?php
									if ( $writable ) :
										?>
										<input type="radio" name="target_calendar" value="<?php echo esc_attr( $cal['primary'] ? '' : $cal['id'] ); ?>" <?php checked( $is_target ); ?>><?php endif; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>

						<?php self::render_accounts( $employee, $link ); ?>
					<?php else : ?>
						<p><?php esc_html_e( 'Not connected yet. There are two ways to connect:', 'nemvo-booking' ); ?></p>
						<ol>
							<li>
								<?php esc_html_e( 'If you can sign in to the employee\'s Google account (or this is you):', 'nemvo-booking' ); ?><br>
								<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'connect', $employee['id'] ) ); ?>"><?php esc_html_e( 'Connect Google Calendar', 'nemvo-booking' ); ?></a>
							</li>
							<li>
								<?php esc_html_e( 'Otherwise, create a personal link and send it to the employee. They sign in with Google, click Allow, and they are connected. The link works for 7 days.', 'nemvo-booking' ); ?><br>
								<?php if ( $link ) : ?>
									<?php echo Admin::copy_field( $link, 'large-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span class="description">
										<?php
										/* translators: %s: expiry date */
										printf( esc_html__( 'Valid until %s.', 'nemvo-booking' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $employee['connect_expires'] ) ) );
										?>
									</span>
									<a href="<?php echo esc_url( self::action_url( 'link', $employee['id'] ) ); ?>"><?php esc_html_e( 'Create a new link', 'nemvo-booking' ); ?></a>
								<?php else : ?>
									<a class="button" href="<?php echo esc_url( self::action_url( 'link', $employee['id'] ) ); ?>"><?php esc_html_e( 'Create connect link', 'nemvo-booking' ); ?></a>
								<?php endif; ?>
							</li>
						</ol>
						<?php if ( $employee['last_error'] ) : ?>
							<p class="nb-warning"><?php echo esc_html( $employee['last_error'] ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<?php submit_button( __( 'Save employee', 'nemvo-booking' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints the "Check for conflicts" checkbox of a calendar, or a note for calendars whose busy
	 * times Google doesn't share (such as public holidays).
	 *
	 * @param string   $name Field name.
	 * @param array    $cal  Calendar from the calendar list.
	 * @param string[] $busy Calendar IDs checked for conflicts.
	 */
	private static function busy_checkbox( string $name, array $cal, array $busy ) {
		if ( ! Google::has_busy_times( $cal['id'] ) ) {
			echo '<input type="checkbox" disabled> <span class="description">' . esc_html__( 'Google doesn\'t share busy times for this calendar. To close booking pages on public holidays, use "Public holidays" in Settings.', 'nemvo-booking' ) . '</span>';
			return;
		}
		printf(
			'<input type="checkbox" name="%1$s" value="%2$s" %3$s>',
			esc_attr( $name ),
			esc_attr( $cal['id'] ),
			checked( in_array( $cal['id'], $busy, true ) || ( $cal['primary'] && in_array( 'primary', $busy, true ) ), true, false )
		);
	}

	/**
	 * Renders the employee's other Google accounts, whose calendars are only checked for conflicts.
	 *
	 * @param array  $employee Employee (connected).
	 * @param string $link     Current link for adding an account, or ''.
	 */
	private static function render_accounts( array $employee, string $link ) {
		$accounts = Accounts::for_employee( $employee['id'] );
		?>
		<h3><?php esc_html_e( 'Other Google accounts', 'nemvo-booking' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Busy times in other Google accounts of this employee – for example a personal Gmail next to a work account – can block bookings too. These accounts are only asked for free/busy information and their list of calendars; meetings are always added with the account above. Accounts outside your Google Workspace need the Google Cloud app\'s audience set to External (see Settings).', 'nemvo-booking' ); ?></p>
		<?php foreach ( $accounts as $account ) : ?>
			<table class="widefat striped nb-table nb-calendars" data-account="<?php echo esc_attr( $account['id'] ); ?>">
				<thead>
					<tr>
						<th>
							<?php echo esc_html( $account['google_email'] ); ?>
							<?php if ( $account['last_error'] ) : ?>
								<span class="nb-badge nb-badge--error"><?php esc_html_e( 'Error', 'nemvo-booking' ); ?></span> <span class="description"><?php echo esc_html( $account['last_error'] ); ?></span>
							<?php endif; ?>
						</th>
						<th><?php esc_html_e( 'Check for conflicts', 'nemvo-booking' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$busy = Accounts::busy_calendars( $account );
				foreach ( $account['calendars'] as $cal ) :
					?>
					<tr>
						<td><?php echo esc_html( $cal['summary'] ); ?><?php echo $cal['primary'] ? ' <span class="description">(' . esc_html__( 'primary', 'nemvo-booking' ) . ')</span>' : ''; ?></td>
						<td><?php self::busy_checkbox( 'account_busy[' . $account['id'] . '][]', $cal, $busy ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<a class="button" href="<?php echo esc_url( self::action_url( 'account_refresh', $employee['id'], $account['id'] ) ); ?>"><?php esc_html_e( 'Refresh calendar list', 'nemvo-booking' ); ?></a>
				<a class="button-link-delete" data-confirm href="<?php echo esc_url( self::action_url( 'account_remove', $employee['id'], $account['id'] ) ); ?>"><?php esc_html_e( 'Remove', 'nemvo-booking' ); ?></a>
			</p>
		<?php endforeach; ?>
		<p>
			<a class="button" href="<?php echo esc_url( self::action_url( 'account_add', $employee['id'] ) ); ?>"><?php esc_html_e( 'Add another Google account', 'nemvo-booking' ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Or send the employee a link: they open it, sign in with the other account and click Allow. The link works for 7 days.', 'nemvo-booking' ); ?>
		</p>
		<?php if ( $link ) : ?>
			<?php echo Admin::copy_field( $link, 'large-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="description">
				<?php
				/* translators: %s: expiry date */
				printf( esc_html__( 'Valid until %s.', 'nemvo-booking' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $employee['connect_expires'] ) ) );
				?>
			</span>
			<a href="<?php echo esc_url( self::action_url( 'account_link', $employee['id'] ) ); ?>"><?php esc_html_e( 'Create a new link', 'nemvo-booking' ); ?></a>
		<?php else : ?>
			<a class="button" href="<?php echo esc_url( self::action_url( 'account_link', $employee['id'] ) ); ?>"><?php esc_html_e( 'Create link', 'nemvo-booking' ); ?></a>
		<?php endif; ?>
		<?php
	}

	/**
	 * Admin-post handler: adds or saves an employee.
	 */
	public static function handle_save() {
		Admin::check( 'nemvo_booking_employee_save' );
		// phpcs:disable WordPress.Security.NonceVerification -- checked above.
		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

		if ( '' === $name || ! is_email( $email ) ) {
			Admin::redirect(
				self::PAGE,
				array_filter(
					array(
						'edit'                => $id,
						'nemvo_booking_error' => rawurlencode( __( 'Please enter a name and a valid email address.', 'nemvo-booking' ) ),
					)
				)
			);
		}

		if ( ! $id ) {
			$id = Employees::create( $name, $email );
			Log::info( 'employee.created', sprintf( 'Employee %1$s (%2$s) was added.', $name, $email ), array( 'employee_id' => $id ) );
			Admin::redirect(
				self::PAGE,
				array(
					'edit'                 => $id,
					'nemvo_booking_notice' => 'created',
				)
			);
		}

		$employee = self::employee_or_die();
		$data     = array(
			'name'  => $name,
			'email' => $email,
		);
		if ( isset( $_POST['photo_id'] ) ) {
			$photo            = absint( $_POST['photo_id'] );
			$data['photo_id'] = $photo && wp_attachment_is_image( $photo ) ? $photo : 0;
		}
		if ( Employees::is_connected( $employee ) ) {
			$known                   = wp_list_pluck( $employee['calendars'], 'id' );
			$busy                    = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['busy_calendars'] ?? array() ) ) );
			$data['busy_calendars']  = array_values( array_filter( array_intersect( $busy, $known ), array( Google::class, 'has_busy_times' ) ) );
			$target                  = sanitize_text_field( wp_unslash( $_POST['target_calendar'] ?? '' ) );
			$data['target_calendar'] = in_array( $target, $known, true ) ? $target : '';
			if ( ! $data['busy_calendars'] ) {
				$data['busy_calendars'] = array( 'primary' );
			}
			$posted = wp_unslash( (array) ( $_POST['account_busy'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
			foreach ( Accounts::for_employee( $id ) as $account ) {
				$checked = array_map( 'sanitize_text_field', (array) ( $posted[ $account['id'] ] ?? array() ) );
				$busy    = array_values( array_filter( array_intersect( $checked, wp_list_pluck( $account['calendars'], 'id' ) ), array( Google::class, 'has_busy_times' ) ) );
				Accounts::update( $account['id'], array( 'busy_calendars' => $busy ? $busy : array( 'primary' ) ) );
			}
		}
		// phpcs:enable
		Employees::update( $id, $data );
		Admin::redirect(
			self::PAGE,
			array(
				'edit'                 => $id,
				'nemvo_booking_notice' => 'saved',
			)
		);
	}

	/**
	 * Admin-post handler: disconnects and deletes an employee.
	 */
	public static function handle_delete() {
		Admin::check( 'nemvo_booking_employee_delete' );
		$employee = self::employee_or_die();
		Accounts::remove_all( $employee['id'] );
		if ( Employees::is_connected( $employee ) && ! Accounts::used_elsewhere( (string) $employee['google_email'], $employee['id'] ) ) {
			Google::revoke( $employee );
		}
		Employees::delete( $employee['id'] );
		Log::info( 'employee.deleted', sprintf( 'Employee %1$s (%2$s) was deleted.', $employee['name'], $employee['email'] ), array( 'employee_id' => $employee['id'] ) );
		Admin::redirect( self::PAGE, array( 'nemvo_booking_notice' => 'deleted' ) );
	}

	/**
	 * Admin-post handler: sends the admin to Google to connect the employee's calendar.
	 */
	public static function handle_connect() {
		Admin::check( 'nemvo_booking_employee_connect' );
		$employee = self::employee_or_die();
		wp_redirect( Google::auth_url( $employee, 'admin' ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Admin-post handler: disconnects the employee's Google Calendar.
	 */
	public static function handle_disconnect() {
		Admin::check( 'nemvo_booking_employee_disconnect' );
		$employee = self::employee_or_die();
		Employees::disconnect( $employee );
		Log::info( 'google.disconnected', sprintf( 'Google Calendar disconnected for %s.', $employee['name'] ), array( 'employee_id' => $employee['id'] ) );
		Admin::redirect(
			self::PAGE,
			array(
				'edit'                 => $employee['id'],
				'nemvo_booking_notice' => 'disconnected',
			)
		);
	}

	/**
	 * Admin-post handler: creates a new connect link for the employee.
	 */
	public static function handle_link() {
		Admin::check( 'nemvo_booking_employee_link' );
		$employee = self::employee_or_die();
		Employees::connect_link( $employee );
		Log::info( 'employee.connect_link', sprintf( 'A Google connect link was created for %s.', $employee['name'] ), array( 'employee_id' => $employee['id'] ) );
		Admin::redirect(
			self::PAGE,
			array(
				'edit'                 => $employee['id'],
				'nemvo_booking_notice' => 'link',
			)
		);
	}

	/**
	 * Admin-post handler: reads the employee's calendar list from Google again.
	 */
	public static function handle_refresh() {
		Admin::check( 'nemvo_booking_employee_refresh' );
		$employee = self::employee_or_die();
		$result   = Employees::refresh_calendars( $employee );
		$args     = is_wp_error( $result ) ? array( 'nemvo_booking_error' => rawurlencode( $result->get_error_message() ) ) : array( 'nemvo_booking_notice' => 'refreshed' );
		Admin::redirect( self::PAGE, array( 'edit' => $employee['id'] ) + $args );
	}

	/**
	 * Admin-post handler: sends the admin to Google to add another account of the employee.
	 */
	public static function handle_account_add() {
		Admin::check( 'nemvo_booking_employee_account_add' );
		$employee = self::employee_or_die();
		if ( ! Employees::is_connected( $employee ) ) {
			Admin::redirect( self::PAGE, array( 'edit' => $employee['id'] ) );
		}
		wp_redirect( Google::auth_url( $employee, 'admin', '', true ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Admin-post handler: creates a link the employee can use to add another Google account.
	 */
	public static function handle_account_link() {
		Admin::check( 'nemvo_booking_employee_account_link' );
		$employee = self::employee_or_die();
		Employees::connect_link( $employee, true );
		Log::info( 'employee.connect_link', sprintf( 'A link to add another Google account was created for %s.', $employee['name'] ), array( 'employee_id' => $employee['id'] ) );
		Admin::redirect(
			self::PAGE,
			array(
				'edit'                 => $employee['id'],
				'nemvo_booking_notice' => 'link',
			)
		);
	}

	/**
	 * Admin-post handler: reads an extra account's calendar list from Google again.
	 */
	public static function handle_account_refresh() {
		Admin::check( 'nemvo_booking_employee_account_refresh' );
		$employee = self::employee_or_die();
		$result   = Accounts::refresh_calendars( self::account_or_die( $employee ) );
		$args     = is_wp_error( $result ) ? array( 'nemvo_booking_error' => rawurlencode( $result->get_error_message() ) ) : array( 'nemvo_booking_notice' => 'refreshed' );
		Admin::redirect( self::PAGE, array( 'edit' => $employee['id'] ) + $args );
	}

	/**
	 * Admin-post handler: removes an extra account and revokes the site's access to it.
	 */
	public static function handle_account_remove() {
		Admin::check( 'nemvo_booking_employee_account_remove' );
		$employee = self::employee_or_die();
		$account  = self::account_or_die( $employee );
		Accounts::remove( $account );
		Log::info( 'google.account_removed', sprintf( 'Google account %1$s of %2$s was removed.', $account['google_email'], $employee['name'] ), array( 'employee_id' => $employee['id'] ) );
		Admin::redirect(
			self::PAGE,
			array(
				'edit'                 => $employee['id'],
				'nemvo_booking_notice' => 'account_removed',
			)
		);
	}
}
