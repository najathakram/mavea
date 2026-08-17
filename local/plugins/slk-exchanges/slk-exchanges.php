<?php
/**
 * Plugin Name: SLK Exchanges
 * Description: Exchange requests — the slk_exchange data model and state machine, the customer portal and the admin board built on top of it.
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: slk
 *
 * A shopper who wants a different size swaps it here rather than by returning
 * for a refund and buying again. This plugin depends on slk-checkout for the
 * settings that already govern that swap — SLK_Fulfilment::exchange_window_days()
 * and ::exchange_send_fee(), one source of truth with the checkout that charges
 * from them — but it owns its own lifecycle: the slk_exchange post type, its
 * state machine, and the customer portal and admin board that drive it.
 *
 * @package slk-exchanges
 */

defined( 'ABSPATH' ) || exit;

define( 'SLK_EXCHANGES_VERSION', '0.1.0' );
define( 'SLK_EXCHANGES_FILE', __FILE__ );
define( 'SLK_EXCHANGES_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Boot. Everything below assumes WooCommerce is loaded, and specifically
 * SLK_Fulfilment (defined in the sibling slk-checkout plugin, whose own
 * plugins_loaded callback runs at priority 5) for the exchange window and
 * send-fee settings — if either is missing this plugin does nothing at all
 * rather than fataling.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'SLK_Fulfilment' ) ) {
			return;
		}

		require_once SLK_EXCHANGES_PATH . 'includes/class-slk-exchange.php';
		require_once SLK_EXCHANGES_PATH . 'includes/class-slk-exchange-emails.php';
		require_once SLK_EXCHANGES_PATH . 'includes/class-slk-exchange-account.php';

		SLK_Exchange::init();
		SLK_Exchange_Emails::init();
		SLK_Exchange_Account::init();

		// The board is admin-only, and its file pulls in core's WP_List_Table
		// at file scope — that belongs nowhere near a storefront request. The
		// guard is safe for the manual-log form too: admin-post.php is admin
		// context, so admin_post_slk_exchange_log_request still registers.
		if ( is_admin() ) {
			require_once SLK_EXCHANGES_PATH . 'includes/class-slk-exchange-admin.php';

			SLK_Exchange_Admin::init();
		}
	},
	10
);

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'slk', false, dirname( plugin_basename( SLK_EXCHANGES_FILE ) ) . '/languages' );
	}
);

/**
 * Activation registers the post type and its custom post statuses immediately
 * — a plugin can be activated before its own plugins_loaded boot above has run
 * in the same request, so the registration cannot be left to that callback —
 * and flushes rewrite rules once so the post type is known to WordPress from
 * the first request onward.
 *
 * Nothing here needs a matching uninstall routine: flushing rewrite rules is
 * always safe to skip on deactivation or uninstall, WordPress simply rebuilds
 * them from whichever plugins are active on the next request that needs them.
 */
register_activation_hook(
	__FILE__,
	static function () {
		if ( class_exists( 'WooCommerce' ) ) {
			require_once SLK_EXCHANGES_PATH . 'includes/class-slk-exchange.php';

			SLK_Exchange::register_post_type();
			SLK_Exchange::register_post_statuses();
		}

		flush_rewrite_rules();
	}
);
