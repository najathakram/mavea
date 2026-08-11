<?php
/**
 * Thankyou page — Porcelain Glass order-received screen.
 *
 * Based on the real WooCommerce 8.1.0 template (see
 * design/_reference/woocommerce-templates/checkout/thankyou.php). Every hook
 * and the checkout/order-received.php include are preserved for third-party
 * compatibility (payment plugins, tracking, order-overview data other code
 * may read from the DOM); this override adds the tick, the "Got it. Order
 * #…" hero, and the 3-step .slk-step timeline on top of that.
 *
 * The default "Thank you. Your order has been received." paragraph is
 * silenced via the documented `woocommerce_thankyou_order_received_text`
 * filter (see inc/checkout-view.php) rather than by hiding it, so no markup
 * from checkout/order-received.php — which this file does not own — needs to
 * be touched or guessed at.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/checkout/thankyou.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.1.0
 *
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="woocommerce-order slk-order-received">

	<?php
	if ( $order ) :

		do_action( 'woocommerce_before_thankyou', $order->get_id() );
		?>

		<?php if ( $order->has_status( 'failed' ) ) : ?>

			<div class="slk-toast slk-toast--error slk-order-received__failed">
				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed"><?php esc_html_e( 'Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.', 'woocommerce' ); ?></p>

				<p class="woocommerce-notice woocommerce-notice--error woocommerce-thankyou-order-failed-actions">
					<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="slk-btn slk-btn--primary pay"><?php esc_html_e( 'Pay', 'woocommerce' ); ?></a>
					<?php if ( is_user_logged_in() ) : ?>
						<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" class="slk-btn slk-btn--secondary pay"><?php esc_html_e( 'My account', 'woocommerce' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

		<?php else : ?>

			<?php
			$slk_total          = $order->get_formatted_order_total();
			$slk_order_number   = $order->get_order_number();
			$slk_is_cod         = 'cod' === $order->get_payment_method();
			$slk_phone          = function_exists( 'slk_mask_phone' ) ? slk_mask_phone( $order->get_billing_phone() ) : $order->get_billing_phone();
			$slk_shipping_label = $order->get_shipping_method();
			?>

			<div class="slk-order-received__hero">
				<div class="slk-order-received__tick" aria-hidden="true">&#10003;</div>
				<h1 class="slk-order-received__title">
					<?php
					/* translators: %s: order number. */
					printf( esc_html__( 'Got it. Order #%s.', 'slk' ), esc_html( $slk_order_number ) );
					?>
				</h1>
				<?php if ( $slk_is_cod ) : ?>
					<p class="slk-order-received__sub">
						<?php
						/* translators: %s: formatted order total, e.g. Rs. 15,900. */
						printf( wp_kses_post( __( 'Nothing to pay yet — keep %s ready for the courier.', 'slk' ) ), wp_kses_post( $slk_total ) );
						?>
					</p>
				<?php else : ?>
					<p class="slk-order-received__sub">
						<?php
						/* translators: %s: formatted order total, e.g. Rs. 15,900. */
						printf( wp_kses_post( __( 'Payment received — %s. We start packing today.', 'slk' ) ), wp_kses_post( $slk_total ) );
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php // Preserved for compatibility — the default success text is emptied
			// via the woocommerce_thankyou_order_received_text filter (own hero
			// above carries the message instead), but the hook itself still fires
			// so anything else attached to it keeps working. ?>
			<?php wc_get_template( 'checkout/order-received.php', array( 'order' => $order ) ); ?>

			<div class="slk-panel slk-panel--lifted slk-order-received__timeline">
				<div class="slk-step slk-step--now">
					<div class="slk-step__rail">
						<span class="slk-step__dot">1</span>
						<span class="slk-step__line"></span>
					</div>
					<div>
						<div class="slk-step__label"><?php esc_html_e( 'We call you to confirm', 'slk' ); ?></div>
						<div class="slk-step__desc slk-muted">
							<?php
							if ( $slk_phone ) {
								/* translators: %s: masked phone number. */
								printf( esc_html__( 'Today or tomorrow morning, on %s. A real person, not a robot.', 'slk' ), esc_html( $slk_phone ) );
							} else {
								esc_html_e( 'Today or tomorrow morning. A real person, not a robot.', 'slk' );
							}
							?>
						</div>
					</div>
				</div>
				<div class="slk-step slk-step--todo">
					<div class="slk-step__rail">
						<span class="slk-step__dot">2</span>
						<span class="slk-step__line"></span>
					</div>
					<div>
						<div class="slk-step__label"><?php esc_html_e( 'Your pieces are packed in Galle', 'slk' ); ?></div>
						<div class="slk-step__desc slk-muted"><?php esc_html_e( 'Pressed, wrapped, and handed to the courier.', 'slk' ); ?></div>
					</div>
				</div>
				<div class="slk-step slk-step--todo">
					<div class="slk-step__rail">
						<span class="slk-step__dot">3</span>
					</div>
					<div>
						<div class="slk-step__label"><?php esc_html_e( 'At your door', 'slk' ); ?></div>
						<div class="slk-step__desc slk-muted">
							<?php if ( $slk_shipping_label ) : ?>
								<?php echo esc_html( $slk_shipping_label ); ?><?php if ( $slk_is_cod ) : ?><?php echo esc_html( ' — ' ); ?><?php
									/* translators: %s: formatted order total. */
									printf( wp_kses_post( __( 'pay the courier %s in cash.', 'slk' ) ), wp_kses_post( $slk_total ) );
								?><?php endif; ?>
							<?php elseif ( $slk_is_cod ) : ?>
								<?php
								/* translators: %s: formatted order total. */
								printf( wp_kses_post( __( 'Pay the courier %s in cash.', 'slk' ) ), wp_kses_post( $slk_total ) );
								?>
							<?php else : ?>
								<?php esc_html_e( '1–2 working days in Colombo, 3–5 island-wide.', 'slk' ); ?>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<ul class="woocommerce-order-overview woocommerce-thankyou-order-details order_details slk-order-received__overview slk-panel">

				<li class="woocommerce-order-overview__order order">
					<?php esc_html_e( 'Order number:', 'woocommerce' ); ?>
					<strong><?php echo esc_html( $slk_order_number ); ?></strong>
				</li>

				<li class="woocommerce-order-overview__date date">
					<?php esc_html_e( 'Date:', 'woocommerce' ); ?>
					<strong><?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></strong>
				</li>

				<?php if ( is_user_logged_in() && $order->get_user_id() === get_current_user_id() && $order->get_billing_email() ) : ?>
					<li class="woocommerce-order-overview__email email">
						<?php esc_html_e( 'Email:', 'woocommerce' ); ?>
						<strong><?php echo esc_html( $order->get_billing_email() ); ?></strong>
					</li>
				<?php endif; ?>

				<li class="woocommerce-order-overview__total total">
					<?php esc_html_e( 'Total:', 'woocommerce' ); ?>
					<strong><?php echo wp_kses_post( $slk_total ); ?></strong>
				</li>

				<?php if ( $order->get_payment_method_title() ) : ?>
					<li class="woocommerce-order-overview__payment-method method">
						<?php esc_html_e( 'Payment method:', 'woocommerce' ); ?>
						<strong><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></strong>
					</li>
				<?php endif; ?>

			</ul>

			<div class="slk-order-received__actions">
				<a href="<?php echo esc_url( function_exists( 'slk_whatsapp_track_url' ) ? slk_whatsapp_track_url( $order ) : '#' ); ?>" class="slk-btn slk-btn--primary slk-order-received__whatsapp">
					<?php esc_html_e( 'Track this order on WhatsApp', 'slk' ); ?>
					<span class="slk-order-received__whatsapp-sub"><?php esc_html_e( 'We send updates as it moves', 'slk' ); ?></span>
				</a>
				<a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="slk-btn slk-btn--ghost slk-order-received__back">&larr; <?php esc_html_e( 'Back to the shop', 'slk' ); ?></a>
			</div>

		<?php endif; ?>

		<?php do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() ); ?>
		<?php do_action( 'woocommerce_thankyou', $order->get_id() ); ?>

	<?php else : ?>

		<?php wc_get_template( 'checkout/order-received.php', array( 'order' => false ) ); ?>

	<?php endif; ?>

</div>
