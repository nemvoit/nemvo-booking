<?php
/**
 * History log screen.
 *
 * @package NemvoBooking
 */

namespace NemvoBooking\Admin;

use NemvoBooking\Log;
use NemvoBooking\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Bookings → History log.
 */
class Log_Page {

	/**
	 * Renders Bookings → History log.
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification
		$level  = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		// phpcs:enable
		list( $rows, $total ) = Log::query(
			array(
				'level'    => $level,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 50,
			)
		);
		?>
		<div class="wrap nb-admin">
			<h1><?php esc_html_e( 'History log', 'nemvo-booking' ); ?></h1>
			<p class="nb-intro"><?php esc_html_e( 'Everything that happens: bookings and cancellations, emails sent, Google Calendar connections and errors, and blocked spam attempts.', 'nemvo-booking' ); ?></p>
			<form method="get" class="nb-filters">
				<input type="hidden" name="page" value="nemvo-booking-log">
				<select name="level">
					<option value=""><?php esc_html_e( 'All levels', 'nemvo-booking' ); ?></option>
					<?php foreach ( array( 'info', 'warning', 'error' ) as $l ) : ?>
						<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $level, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search…', 'nemvo-booking' ); ?>">
				<?php submit_button( __( 'Filter', 'nemvo-booking' ), '', '', false ); ?>
			</form>
			<?php self::table( $rows ); ?>
			<?php Admin::pagination( $total, 50, $paged ); ?>
		</div>
		<?php
	}

	/**
	 * Prints log entries as a table (also used on a booking's detail screen).
	 *
	 * @param array[] $rows Log rows.
	 */
	public static function table( array $rows ) {
		$format = get_option( 'date_format' ) . ' H:i:s';
		?>
		<table class="widefat striped nb-table nb-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'Level', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'Event', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'Message', 'nemvo-booking' ); ?></th>
					<th><?php esc_html_e( 'By', 'nemvo-booking' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing logged yet.', 'nemvo-booking' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<?php $user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null; ?>
				<tr>
					<td class="nb-nowrap"><?php echo esc_html( wp_date( $format, Plugin::from_mysql( $row['created_at'] ) ) ); ?></td>
					<td><?php echo Admin::status_badge( $row['level'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><code><?php echo esc_html( $row['event'] ); ?></code></td>
					<td>
						<?php echo esc_html( $row['message'] ); ?>
						<?php if ( $row['booking_id'] ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=nemvo-booking&view_booking=' . $row['booking_id'] ) ); ?>">#<?php echo (int) $row['booking_id']; ?></a>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $user ? $user->display_name : __( 'Visitor / system', 'nemvo-booking' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
