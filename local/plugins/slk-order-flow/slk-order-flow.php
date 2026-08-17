<?php
/**
 * Plugin Name: SLK Order Flow (COD + Loyalty Points)
 * Description: COD confirmation lifecycle (not yet built) and the loyalty points system: earning, redemption credit, balance and ledger.
 * Version: 0.2.0
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: slk
 *
 * The COD confirmation lifecycle (custom order statuses, stock reservation at
 * order creation, SMS hooks, WhatsApp confirm actions, ops role) described in
 * 00-PLAN.md §6 is still scaffold only and is built later through
 * the dev-pipeline skill. Loyalty points, below, are real.
 */

defined( 'ABSPATH' ) || exit;

define( 'SLK_ORDER_FLOW_VERSION', '0.2.0' );
define( 'SLK_ORDER_FLOW_FILE', __FILE__ );
define( 'SLK_ORDER_FLOW_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Boot. Everything below assumes WooCommerce is loaded; if it is deactivated
 * this plugin does nothing at all rather than fataling. Points also call
 * SLK_Money (defined in the sibling slk-checkout plugin), so that is checked
 * for too rather than fataling if slk-checkout is ever switched off.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'SLK_Money' ) ) {
			return;
		}

		require_once SLK_ORDER_FLOW_PATH . 'includes/class-slk-points.php';
		require_once SLK_ORDER_FLOW_PATH . 'includes/class-slk-points-admin.php';

		SLK_Points::init();
		SLK_Points_Admin::init();
	},
	10
);

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'slk', false, dirname( plugin_basename( SLK_ORDER_FLOW_FILE ) ) . '/languages' );
	}
);
