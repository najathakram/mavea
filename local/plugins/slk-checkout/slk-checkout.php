<?php
/**
 * Plugin Name: SLK Checkout (Sri Lanka)
 * Description: Sri Lanka checkout rules — 25-district address field, phone-first identity, optional email, COD fee and gating.
 * Version: 0.1.0-scaffold
 * Requires PHP: 8.1
 * Text Domain: slk
 *
 * Scaffold. Full implementation is specified in 00-PLAN.md §5 and is built
 * through the dev-pipeline skill, not hand-edited here.
 */

defined( 'ABSPATH' ) || exit;

define( 'SLK_CHECKOUT_VERSION', '0.1.0-scaffold' );

/**
 * Currency symbol: "Rs." not "රු".
 *
 * WooCommerce ships the Sinhala symbol (රු) for LKR. Sri Lankan online stores
 * price in "Rs." and shoppers read English-language sites, so the default reads
 * as a rendering fault. Implemented ahead of the pipeline because it is a
 * display-correctness one-liner that every price, PDF invoice and design mockup
 * depends on. Most SL stores install a plugin for this; four lines is cheaper.
 */
add_filter(
	'woocommerce_currency_symbol',
	static function ( $symbol, $currency ) {
		return 'LKR' === $currency ? 'Rs.' : $symbol;
	},
	10,
	2
);
