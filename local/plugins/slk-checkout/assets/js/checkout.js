/**
 * Recalculate the totals when the payment method changes.
 *
 * The COD handling fee (Rs. 150) is added server-side in
 * woocommerce_cart_calculate_fees, which reads the chosen payment method from
 * the session. WC_AJAX::update_order_review writes that session value from the
 * posted form and then recalculates — but WooCommerce's own checkout.js only
 * shows and hides the payment boxes on change; it never asks for that AJAX
 * round trip. Without this file the fee line appears one interaction late, or
 * lingers after the shopper switches away from cash on delivery.
 *
 * update_checkout is debounced inside WooCommerce, so rapid switching between
 * options collapses into a single request.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
			$( document.body ).trigger( 'update_checkout' );
		} );
	} );
} )( jQuery );
