<?php
/**
 * Checkout Form — Porcelain Glass presentation.
 *
 * Based on the real WooCommerce 9.4.0 template (see
 * design/_reference/woocommerce-templates/checkout/form-checkout.php). Every
 * hook from that file is preserved and fires exactly once; this override only
 * adds wrapper markup and CSS/JS hooks (see inc/checkout-view.php) that turn
 * the same field output into the "1 · You / 2 · Where / 3 · Paying" glass
 * panels plus a sticky order-summary aside.
 *
 * Panels 1 and 2 are emitted by woocommerce/checkout/form-billing.php, which
 * also fires woocommerce_checkout_shipping; this file emits panel 3 and the
 * aside. Field logic (which keys exist, validation, COD fee, phone format)
 * belongs to the slk-checkout plugin — this file only arranges and styles
 * whatever that plugin renders through the standard woocommerce_checkout_billing
 * / woocommerce_checkout_shipping / woocommerce_checkout_order_review hooks.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/checkout/form-checkout.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_before_checkout_form', $checkout );

// If checkout registration is disabled and not logged in, the user cannot checkout.
if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}

?>

<form name="checkout" method="post" class="checkout woocommerce-checkout slk-checkout" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

	<?php if ( $checkout->get_checkout_fields() ) : ?>

		<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>

		<div class="slk-checkout__grid" id="customer_details">

			<div class="slk-checkout__fields col2-set">

				<?php
				/**
				 * Panels "1 · You" and "2 · Where".
				 *
				 * Both are emitted by woocommerce/checkout/form-billing.php,
				 * which splits the single `billing` field group across them by
				 * priority — every one of the design's identity AND address
				 * fields is defined in that one group, so splitting on
				 * billing/shipping here would leave "2 · Where" empty. That
				 * template also fires woocommerce_checkout_shipping at the end
				 * of panel 2 (the notes field), which is why it is not fired
				 * again here. See the header of form-billing.php.
				 */
				do_action( 'woocommerce_checkout_billing' );
				?>

				<div class="slk-panel slk-panel--lifted slk-checkout__panel" id="slk-payment-panel">
					<div class="slk-eyebrow slk-checkout__panel-label"><?php esc_html_e( '3 · Paying', 'slk' ); ?></div>
					<div class="slk-checkout__payment-slot" id="slk-payment-slot">
						<?php // Populated by JS: the real ul.payment_methods is moved here from
						// inside #order_review once WooCommerce renders/updates it (see
						// inc/checkout-view.php). If JS is unavailable the native markup
						// simply stays inside the order-review panel below — degrades, does
						// not break. ?>
						<noscript>
							<p class="slk-muted"><?php esc_html_e( 'Payment methods appear in the order summary below.', 'slk' ); ?></p>
						</noscript>
					</div>
				</div>

			</div>

			<div class="slk-checkout__aside">

				<?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>

				<h2 id="order_review_heading" class="slk-sr-only"><?php esc_html_e( 'Your order', 'woocommerce' ); ?></h2>

				<?php do_action( 'woocommerce_checkout_before_order_review' ); ?>

				<div id="order_review" class="woocommerce-checkout-review-order slk-panel slk-panel--lifted slk-order-summary">
					<?php do_action( 'woocommerce_checkout_order_review' ); ?>
				</div>

				<?php do_action( 'woocommerce_checkout_after_order_review' ); ?>

				<p class="slk-checkout__next slk-muted"><?php esc_html_e( 'Next: we call to confirm, then dispatch.', 'slk' ); ?></p>

			</div>

		</div>

		<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>

	<?php endif; ?>

</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
