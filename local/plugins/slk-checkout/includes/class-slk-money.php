<?php
/**
 * Money helpers.
 *
 * LKR is a zero-decimal currency in practice: every price on this store is a
 * whole rupee, and the design renders "Rs. 12,500" with no decimal part. The
 * safe way to handle that is to keep *stored* amounts as plain floats of whole
 * rupees and let wc_price() do the formatting — never to hand-format money.
 *
 * Deliberately NOT done here: filtering wc_get_price_decimals() to 0. That
 * constant also drives WC_Cart_Totals line rounding and every gateway's amount
 * string, so flipping it in code changes cart maths store-wide. It belongs in
 * WooCommerce → Settings → General → "Number of decimals" = 0, set once by the
 * merchant. See the note in the plugin summary.
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Money {

	/**
	 * Round an amount to the store's configured precision.
	 *
	 * Used for every amount this plugin hands to WooCommerce (fees, shipping
	 * costs). All of our own constants are whole rupees, so this is a no-op in
	 * the normal case — it exists so a filtered/overridden value can never leak
	 * a long float into a total and shift the last digit of a gateway's amount
	 * string (PayHere hashes number_format($total, 2), so the total must be
	 * exactly what the order stores).
	 */
	public static function round( $amount ): float {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		return round( (float) $amount, (int) $decimals );
	}

	/**
	 * Whole-rupee amount from JSON-sourced config.
	 */
	public static function rupees( $amount ): float {
		return self::round( (float) $amount );
	}
}
