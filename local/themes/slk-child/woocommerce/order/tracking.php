<?php
/**
 * Order tracking result — Porcelain result card.
 *
 * Overrides woocommerce/templates/order/tracking.php (v10.6.0, see
 * design/_reference/woocommerce-templates/order/tracking.php).
 *
 * Reached from page-templates/track-order.php once SLK_Track::resolve() has
 * matched both the order number and the email or mobile number typed in.
 *
 * The stock template prints order number, date and status inside <mark>
 * elements; this theme only resets mark's default browser highlight scoped
 * to .woocommerce-account (inc/account.php), so on this page — outside that
 * scope — those marks would show yellow. Order number and date render as
 * plain meta-type spans instead, and the status gets its own display-serif
 * headline rather than sharing the sentence.
 *
 * The three-step timeline reuses the .slk-step markup and classes from
 * woocommerce/checkout/thankyou.php exactly, mapped from the order's real
 * status: pending/on-hold -> step 1, processing -> step 2, completed ->
 * step 3. Cancelled/refunded/failed orders are not moving through delivery,
 * so they get a single muted line instead of a timeline that would
 * misrepresent them as still in progress.
 *
 * Customer notes keep the stock comment_container/comment-text nesting out —
 * the theme does not style it — in favour of a plain date + body list.
 *
 * do_action( 'woocommerce_view_order', ... ) is preserved: WooCommerce core
 * hooks its own order-details table onto it (table.order_details.shop_table,
 * styled in inc/pages-support.php), and third-party plugins may too.
 *
 * @package slk-child
 *
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

$slk_track_notes  = $order->get_customer_order_notes();
$slk_track_status = $order->get_status();

$slk_track_terminal_statuses = array( 'cancelled', 'refunded', 'failed' );
$slk_track_is_terminal       = in_array( $slk_track_status, $slk_track_terminal_statuses, true );

if ( 'completed' === $slk_track_status ) {
	$slk_track_step = 3;
} elseif ( 'processing' === $slk_track_status ) {
	$slk_track_step = 2;
} else {
	$slk_track_step = 1; // Pending, on-hold, and anything else not yet processing.
}

$slk_track_order_number_html = '<span class="order-number">' . esc_html( $order->get_order_number() ) . '</span>';
$slk_track_order_date_html   = '<span class="order-date">' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</span>';
?>

<div class="slk-track__result">

	<p class="slk-track__result-meta">
		<?php
		printf(
			/* translators: 1: order number (wrapped in a span), 2: order date (wrapped in a span). */
			wp_kses_post( __( 'Order #%1$s placed on %2$s', 'slk' ) ),
			$slk_track_order_number_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_html().
			$slk_track_order_date_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with esc_html().
		);
		?>
	</p>

	<h2 class="slk-track__result-status"><?php echo esc_html( wc_get_order_status_name( $slk_track_status ) ); ?></h2>

	<?php if ( $slk_track_is_terminal ) : ?>

		<p class="slk-track__result-terminal slk-muted"><?php esc_html_e( 'This order is no longer moving through delivery.', 'slk' ); ?></p>

	<?php else : ?>

		<div class="slk-track__timeline">
			<div class="slk-step <?php echo esc_attr( $slk_track_step >= 1 ? 'slk-step--now' : 'slk-step--todo' ); ?>">
				<div class="slk-step__rail">
					<span class="slk-step__dot">1</span>
					<span class="slk-step__line"></span>
				</div>
				<div>
					<div class="slk-step__label"><?php esc_html_e( 'Order received', 'slk' ); ?></div>
					<div class="slk-step__desc slk-muted"><?php esc_html_e( 'We are confirming the details and getting it ready.', 'slk' ); ?></div>
				</div>
			</div>
			<div class="slk-step <?php echo esc_attr( $slk_track_step >= 2 ? 'slk-step--now' : 'slk-step--todo' ); ?>">
				<div class="slk-step__rail">
					<span class="slk-step__dot">2</span>
					<span class="slk-step__line"></span>
				</div>
				<div>
					<div class="slk-step__label"><?php esc_html_e( 'Being packed', 'slk' ); ?></div>
					<div class="slk-step__desc slk-muted"><?php esc_html_e( 'We press it, wrap it, and hand it to the courier.', 'slk' ); ?></div>
				</div>
			</div>
			<div class="slk-step <?php echo esc_attr( $slk_track_step >= 3 ? 'slk-step--now' : 'slk-step--todo' ); ?>">
				<div class="slk-step__rail">
					<span class="slk-step__dot">3</span>
				</div>
				<div>
					<div class="slk-step__label"><?php esc_html_e( 'At your door', 'slk' ); ?></div>
					<div class="slk-step__desc slk-muted"><?php esc_html_e( 'Delivered to you.', 'slk' ); ?></div>
				</div>
			</div>
		</div>

	<?php endif; ?>

	<?php if ( $slk_track_notes ) : ?>
		<div class="slk-track__notes">
			<h3 class="slk-track__notes-h"><?php esc_html_e( 'Order updates', 'slk' ); ?></h3>
			<ol class="slk-track__notes-list">
				<?php foreach ( $slk_track_notes as $slk_track_note ) : ?>
					<li>
						<p class="slk-track__notes-meta"><?php echo esc_html( date_i18n( __( 'l jS \o\f F Y, h:ia', 'slk' ), strtotime( $slk_track_note->comment_date ) ) ); ?></p>
						<div class="slk-track__notes-body"><?php echo wp_kses_post( wpautop( wptexturize( $slk_track_note->comment_content ) ) ); ?></div>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>
	<?php endif; ?>

</div>

<?php do_action( 'woocommerce_view_order', $order->get_id() ); ?>
