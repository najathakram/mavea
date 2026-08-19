<?php
/**
 * Order tracking form.
 *
 * Overrides woocommerce/templates/order/form-tracking.php (v7.0.1).
 *
 * Every hook, field name and nonce are the stock ones, so this form keeps
 * working through the stock [woocommerce_order_tracking] shortcode as well
 * as through page-templates/track-order.php's phone-aware lookup (see
 * SLK_Track::resolve() in slk-order-flow) — neither reads this file, both
 * just read the same $_POST fields it posts.
 *
 *   1. The stock intro paragraph is gone. It repeated the page's own intro
 *      line and it promised "the confirmation email you should have
 *      received", which a cash-on-delivery customer here never gets: email is
 *      optional at checkout by design (see slk-checkout's email policy).
 *   2. The second field is relabelled "Email or mobile number" — a
 *      phone-only COD customer can look their order up with the mobile
 *      number they gave at checkout. The field name stays order_email; the
 *      resolver, not this template, decides what was typed into it.
 *   3. The submit button carries slk-btn slk-btn--primary alongside its
 *      stock classes, so the pill styling stops depending on a
 *      button[name="track"] attribute selector.
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
		<label for="order_email"><?php esc_html_e( 'Email or mobile number', 'slk' ); ?></label>
		<input class="input-text" type="text" name="order_email" id="order_email"
			value="<?php echo isset( $_REQUEST['order_email'] ) ? esc_attr( wp_unslash( $_REQUEST['order_email'] ) ) : ''; ?>"
			placeholder="<?php esc_attr_e( 'The email or number you used', 'slk' ); ?>" /><?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
	</p>
	<div class="clear"></div>

	<?php do_action( 'woocommerce_order_tracking_form' ); ?>

	<p class="form-row">
		<button type="submit" class="button slk-btn slk-btn--primary<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" name="track" value="<?php esc_attr_e( 'Track', 'slk' ); ?>">
			<?php esc_html_e( 'Track', 'slk' ); ?>
		</button>
	</p>

	<?php wp_nonce_field( 'woocommerce-order_tracking', 'woocommerce-order-tracking-nonce' ); ?>

	<?php do_action( 'woocommerce_order_tracking_form_end' ); ?>

</form>
