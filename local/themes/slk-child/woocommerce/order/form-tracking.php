<?php
/**
 * Order tracking form.
 *
 * Overrides woocommerce/templates/order/form-tracking.php (v7.0.1).
 *
 * Two changes, both to the words only. Every hook, field name, nonce and the
 * submit button are the stock ones, so the shortcode behaves exactly as
 * WooCommerce expects.
 *
 *   1. The stock intro paragraph is gone. It repeated the page's own intro
 *      line and it promised "the confirmation email you should have
 *      received", which a cash-on-delivery customer here never gets: email is
 *      optional at checkout by design (see slk-checkout's email policy).
 *   2. The field placeholders no longer point at that email either.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

global $post;
?>

<form action="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" method="post" class="woocommerce-form woocommerce-form-track-order track_order">

	<?php do_action( 'woocommerce_order_tracking_form_start' ); ?>

	<p class="form-row form-row-first">
		<label for="orderid"><?php esc_html_e( 'Order number', 'slk' ); ?></label>
		<input class="input-text" type="text" name="orderid" id="orderid"
			value="<?php echo isset( $_REQUEST['orderid'] ) ? esc_attr( wp_unslash( $_REQUEST['orderid'] ) ) : ''; ?>"
			placeholder="<?php esc_attr_e( 'The number we sent you', 'slk' ); ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
	</p>
	<p class="form-row form-row-last">
		<label for="order_email"><?php esc_html_e( 'Email', 'slk' ); ?></label>
		<input class="input-text" type="text" name="order_email" id="order_email"
			value="<?php echo isset( $_REQUEST['order_email'] ) ? esc_attr( wp_unslash( $_REQUEST['order_email'] ) ) : ''; ?>"
			placeholder="<?php esc_attr_e( 'The email you used', 'slk' ); ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
	</p>
	<div class="clear"></div>

	<?php do_action( 'woocommerce_order_tracking_form' ); ?>

	<p class="form-row">
		<button type="submit" class="button<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="track" value="<?php esc_attr_e( 'Track', 'slk' ); ?>">
			<?php esc_html_e( 'Track', 'slk' ); ?>
		</button>
	</p>

	<?php wp_nonce_field( 'woocommerce-order_tracking', 'woocommerce-order-tracking-nonce' ); ?>

	<?php do_action( 'woocommerce_order_tracking_form_end' ); ?>

</form>
