<?php
/**
 * Plugin Name: SLK Checkout (Sri Lanka)
 * Description: Sri Lanka checkout rules — 25-district address field, phone-first identity, optional email, landmark field, COD fee and gating, district delivery rates.
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: slk
 *
 * Behavioural layer only. Markup/visuals for the checkout live in the theme.
 * Everything here is HPOS-safe: order data is read and written through CRUD
 * ($order->get_meta() / $order->update_meta_data()), never get_post_meta().
 */

defined( 'ABSPATH' ) || exit;

define( 'SLK_CHECKOUT_VERSION', '1.0.0' );
define( 'SLK_CHECKOUT_FILE', __FILE__ );
define( 'SLK_CHECKOUT_PATH', plugin_dir_path( __FILE__ ) );

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

/**
 * Feature compatibility.
 *
 * - custom_order_tables (HPOS): declared compatible. Nothing in this plugin
 *   touches wp_posts/wp_postmeta for orders.
 * - cart_checkout_blocks: declared INCOMPATIBLE on purpose. Every rule below
 *   (locale, field priorities, after_checkout_validation, cart fee on payment
 *   method change) is classic-checkout machinery. Declaring incompatibility
 *   makes WooCommerce fall back to the classic shortcode checkout instead of
 *   silently rendering a block checkout where none of it applies.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SLK_CHECKOUT_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', SLK_CHECKOUT_FILE, false );
	}
);

/**
 * Boot. Every hook below this point assumes WooCommerce is loaded; if it is
 * deactivated the plugin does nothing at all rather than fataling.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-money.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-districts.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-phone.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-google.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-checkout-fields.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-email-policy.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-payments.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-shipping.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-order-admin.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-calendar.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-fulfilment.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-shipments.php';
		require_once SLK_CHECKOUT_PATH . 'includes/class-slk-fulfilment-admin.php';

		SLK_Districts::init();
		SLK_Checkout_Fields::init();
		SLK_Email_Policy::init();
		SLK_Payments::init();
		SLK_Shipping::init();
		SLK_Order_Admin::init();
		SLK_Shipments::init();
		SLK_Fulfilment_Admin::init();
	},
	5
);

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'slk', false, dirname( plugin_basename( SLK_CHECKOUT_FILE ) ) . '/languages' );
	}
);

/**
 * Activation only flags the shipping zone provisioning; the work itself runs on
 * the next admin request, when WooCommerce is guaranteed to be fully loaded.
 * (A plugin can be activated before WooCommerce in the same request.)
 */
register_activation_hook(
	__FILE__,
	static function () {
		update_option( 'slk_checkout_needs_zone_provision', 'yes', false );
	}
);
