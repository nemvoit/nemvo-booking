<?php
namespace NemvoBooking\Admin;

use NemvoBooking\Bookings;
use NemvoBooking\Employees;
use NemvoBooking\Log;
use NemvoBooking\Pages;

defined( 'ABSPATH' ) || exit;

class BookingsPage {

	const PAGE = 'nemvo-booking';

	public static function init() {
		add_action( 'admin_post_nemvo_booking_admin_cancel', array( __CLASS__, 'handle_cancel' ) );
	}

	private static function when( array $booking ): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format, $booking['start'] ) . ' – ' . wp_date( get_option( 'time_format' ), $booking['end'] );
	}

	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['view_booking'] ) ) {
			$booking = Bookings::get( absint( $_GET['view_booking'] ) );
			if ( $booking ) {
				self::render_detail( $booking );
				return;
			}
		}

		$view    = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'upcoming';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$page_id = isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
		$paged   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		$per_page          = 25;
		list( $rows, $total ) = Bookings::query( compact( 'view', 'search', 'page_id', 'paged', 'per_page' ) );
		$pages             = Pages::published();
		$views             = array(
			'upcoming'  => __( 'Upcoming', 'nemvo-booking' ),
			'past'      => __( 'Past', 'nemvo-booking' ),
			'cancelled' => __( 'Cancelled & failed', 'nemvo-booking' ),
			'all'       => __( 'All', 'nemvo-booking' ),
		);
		?>
		<div class="wrap nb-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'nemvo-booking' ); ?></h1>
			<hr class="wp-header-end">

			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $views as $key => $label ) {
					$links[] = sprintf(
						'<li><a href="%s" class="%s">%s</a></li>',
						esc_url( add_query_arg( array( 'page' => self::PAGE, 'view' => $key ), admin_url( 'admin.php' ) ) ),
						$key === $view ? 'current' : '',
						esc_html( $label )
					);
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</ul>

			<form method="get" class="nb-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<select name="page_id">
					<option value=""><?php esc_html_e( 'All booking pages', 'nemvo-booking' ); ?></option>
					<?php foreach ( $pages as $id => $title ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $page_id, $id ); ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, email, company…', 'nemvo-booking' ); ?>">
				<?php submit_button( __( 'Filter', 'nemvo-booking' ), '', '', false ); ?>
			</form>

			<table class="widefat striped nb-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'nemvo-booking' ); ?></th>
						<th><?php esc_html_e( 'Booked by', 'nemvo-booking' ); ?></th>
						<th><?php esc_html_e( 'Booking page', 'nemvo-booking' ); ?></th>
						<th><?php esc_html_e( 'Host', 'nemvo-booking' ); ?></th>
						<th><?php esc_html_e( 'Status', 'nemvo-booking' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No bookings found.', 'nemvo-booking' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $booking ) : ?>
					<?php $url = admin_url( 'admin.php?page=' . self::PAGE . '&view_booking=' . $booking['id'] ); ?>
					<tr>
						<td><a href="<?php echo esc_url( $url ); ?>"><strong><?php echo esc_html( self::when( $booking ) ); ?></strong></a></td>
						<td>
							<?php echo esc_html( $booking['name'] ); ?>
							<?php echo ! empty( $booking['answers']['company'] ) ? '<span class="description">· ' . esc_html( $booking['answers']['company'] ) . '</span>' : ''; ?><br>
							<a href="mailto:<?php echo esc_attr( $booking['email'] ); ?>"><?php echo esc_html( $booking['email'] ); ?></a>
						</td>
						<td><?php echo esc_html( get_the_title( $booking['page_id'] ) ?: '–' ); ?></td>
						<td><?php echo esc_html( implode( ', ', wp_list_pluck( Employees::many( $booking['employee_ids'] ), 'name' ) ) ?: '–' ); ?></td>
						<td><?php echo Admin::status_badge( $booking['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="nb-actions">
							<?php if ( $booking['meet_link'] && 'confirmed' === $booking['status'] ) : ?>
								<a href="<?php echo esc_url( $booking['meet_link'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Meet', 'nemvo-booking' ); ?></a> ·
							<?php endif; ?>
							<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Details', 'nemvo-booking' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php Admin::pagination( $total, $per_page, $paged ); ?>
		</div>
		<?php
	}

	private static function render_detail( array $booking ) {
		$cfg          = Pages::config( $booking['page_id'] );
		list( $logs ) = Log::query(
			array(
				'booking_id' => $booking['id'],
				'per_page'   => 100,
			)
		);
		?>
		<div class="wrap nb-admin">
			<h1><?php echo esc_html( $booking['name'] . ' – ' . self::when( $booking ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">← <?php esc_html_e( 'All bookings', 'nemvo-booking' ); ?></a></p>

			<div class="nb-columns">
				<div class="nb-panel">
					<h2><?php esc_html_e( 'Meeting', 'nemvo-booking' ); ?></h2>
					<table class="form-table nb-detail" role="presentation">
						<tr><th><?php esc_html_e( 'Status', 'nemvo-booking' ); ?></th><td><?php echo Admin::status_badge( $booking['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
						<tr><th><?php esc_html_e( 'When', 'nemvo-booking' ); ?></th><td><?php echo esc_html( self::when( $booking ) ); ?> (<?php echo esc_html( wp_timezone_string() ); ?>)<br><span class="description"><?php
						/* translators: 1: time, 2: timezone */
						printf( esc_html__( 'Booker\'s time: %1$s (%2$s)', 'nemvo-booking' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $booking['start'], new \DateTimeZone( $booking['timezone'] ?: 'UTC' ) ) ), esc_html( $booking['timezone'] ) );
						?></span></td></tr>
						<tr><th><?php esc_html_e( 'Length', 'nemvo-booking' ); ?></th><td><?php echo esc_html( sprintf( /* translators: %d: minutes */ __( '%d min', 'nemvo-booking' ), $booking['duration'] ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Booking page', 'nemvo-booking' ); ?></th><td><?php echo esc_html( $cfg['title'] ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Hosts', 'nemvo-booking' ); ?></th><td><?php echo esc_html( implode( ', ', wp_list_pluck( Employees::many( $booking['employee_ids'] ), 'name' ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Google Meet', 'nemvo-booking' ); ?></th><td><?php echo $booking['meet_link'] ? '<a href="' . esc_url( $booking['meet_link'] ) . '" target="_blank" rel="noopener">' . esc_html( $booking['meet_link'] ) . '</a>' : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Guests', 'nemvo-booking' ); ?></th><td><?php echo esc_html( implode( ', ', $booking['guests'] ) ?: '–' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Privacy consent', 'nemvo-booking' ); ?></th><td><?php echo $booking['consent_at'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), \NemvoBooking\Plugin::from_mysql( $booking['consent_at'] ) ) ) : '–'; ?></td></tr>
						<tr><th><?php esc_html_e( 'Booked', 'nemvo-booking' ); ?></th><td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), \NemvoBooking\Plugin::from_mysql( $booking['created_at'] ) ) ); ?> · IP <?php echo esc_html( $booking['ip'] ); ?></td></tr>
						<?php if ( 'cancelled' === $booking['status'] ) : ?>
							<tr><th><?php esc_html_e( 'Cancelled', 'nemvo-booking' ); ?></th><td>
								<?php echo esc_html( 'admin' === $booking['cancelled_by'] ? __( 'by an administrator', 'nemvo-booking' ) : __( 'by the booker', 'nemvo-booking' ) ); ?>
								<?php echo $booking['cancel_reason'] ? '<br>' . esc_html( $booking['cancel_reason'] ) : ''; ?>
							</td></tr>
						<?php endif; ?>
					</table>
				</div>

				<div class="nb-panel">
					<h2><?php esc_html_e( 'Answers', 'nemvo-booking' ); ?></h2>
					<table class="form-table nb-detail" role="presentation">
						<?php foreach ( Bookings::answer_rows( $booking, $cfg ) as $row ) : ?>
							<tr><th><?php echo esc_html( $row[0] ); ?></th><td><?php echo nl2br( esc_html( $row[1] ) ); ?></td></tr>
						<?php endforeach; ?>
					</table>
				</div>
			</div>

			<?php if ( 'confirmed' === $booking['status'] && $booking['token'] ) : ?>
				<div class="nb-panel">
					<h2><?php esc_html_e( 'Move to another time', 'nemvo-booking' ); ?></h2>
					<p><?php esc_html_e( 'This is the booker\'s personal link for picking a new time. Open it to move the meeting yourself (for example after a phone call), or send it to the booker.', 'nemvo-booking' ); ?></p>
					<?php echo Admin::copy_field( Bookings::reschedule_url( $booking ), 'large-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<a class="button" href="<?php echo esc_url( Bookings::reschedule_url( $booking ) ); ?>" target="_blank"><?php esc_html_e( 'Open', 'nemvo-booking' ); ?></a>
				</div>
			<?php endif; ?>

			<?php if ( 'confirmed' === $booking['status'] ) : ?>
				<div class="nb-panel">
					<h2><?php esc_html_e( 'Cancel this booking', 'nemvo-booking' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="nemvo_booking_admin_cancel">
						<input type="hidden" name="id" value="<?php echo esc_attr( $booking['id'] ); ?>">
						<?php wp_nonce_field( 'nemvo_booking_admin_cancel' ); ?>
						<p><label for="nb-reason"><?php esc_html_e( 'Reason (included in the cancellation email)', 'nemvo-booking' ); ?></label><br>
						<textarea id="nb-reason" name="reason" rows="3" class="large-text"></textarea></p>
						<p class="description"><?php esc_html_e( 'Removes the event from the host\'s Google Calendar and notifies the booker, guests and hosts.', 'nemvo-booking' ); ?></p>
						<?php submit_button( __( 'Cancel booking', 'nemvo-booking' ), 'delete', 'submit', false, array( 'data-confirm' => '1' ) ); ?>
					</form>
				</div>
			<?php endif; ?>

			<div class="nb-panel">
				<h2><?php esc_html_e( 'History', 'nemvo-booking' ); ?></h2>
				<?php LogPage::table( $logs ); ?>
			</div>
		</div>
		<?php
	}

	public static function handle_cancel() {
		Admin::check( 'nemvo_booking_admin_cancel' );
		$booking = Bookings::get( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $booking ) {
			wp_die( esc_html__( 'Booking not found.', 'nemvo-booking' ), 404 );
		}
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$result = Bookings::cancel( $booking, $reason, 'admin' );
		$args   = array( 'view_booking' => $booking['id'] );
		$args  += is_wp_error( $result ) ? array( 'nb_error' => rawurlencode( $result->get_error_message() ) ) : array( 'nb_notice' => 'cancelled' );
		Admin::redirect( self::PAGE, $args );
	}
}
