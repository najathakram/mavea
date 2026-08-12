<?php
/**
 * Delivery rates for the 25 districts.
 *
 * WHY A SHIPPING METHOD AND NOT A FEE.
 * The tempting shortcut is another woocommerce_cart_calculate_fees callback
 * that adds "Delivery Rs. 400" to the cart. It is wrong in ways that only show
 * up later: fees are not shipping, so they are invisible to the cart's shipping
 * calculator, to "free delivery over Rs. 15,000" logic, to tax classes, to
 * order splitting, to the REST/courier export, and to every report that asks
 * what shipping was collected. Refunds treat them differently too. A real
 * WC_Shipping_Method costs about eighty lines and puts the amount in the field
 * every other part of WooCommerce already reads — $order->get_shipping_total().
 *
 * The method is instance-based (added to a shipping zone). The zone containing
 * Sri Lanka is provisioned once, automatically, so the store is not left in a
 * state where checkout says "no shipping methods available".
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Shipping {

	public const METHOD_ID = 'slk_delivery';

	/** Rs., from design/assets/sri-lanka-commerce.json. */
	public const FEE_METRO    = 350;
	public const FEE_REGIONAL = 400;
	public const FEE_ISLAND   = 450;
	public const FREE_OVER    = 15000;

	public static function init(): void {
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_method' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_provision_zone' ) );
	}

	public static function load_method(): void {
		if ( ! class_exists( 'SLK_Shipping_Method' ) ) {
			require_once SLK_CHECKOUT_PATH . 'includes/class-slk-shipping-method.php';
		}
	}

	/**
	 * @param array $methods Registered shipping methods.
	 */
	public static function register_method( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}

		$methods[ self::METHOD_ID ] = 'SLK_Shipping_Method';

		return $methods;
	}

	/**
	 * Default rate for a district, before the free-delivery threshold.
	 */
	public static function fee_for_district( $district ): float {
		switch ( SLK_Districts::tier( $district ) ) {
			case SLK_Districts::TIER_METRO:
				$fee = self::FEE_METRO;
				break;
			case SLK_Districts::TIER_REGIONAL:
				$fee = self::FEE_REGIONAL;
				break;
			default:
				$fee = self::FEE_ISLAND;
		}

		return SLK_Money::rupees( $fee );
	}

	/**
	 * Human label for the tier, used in the rate label and on the slip.
	 */
	public static function tier_label( $district ): string {
		switch ( SLK_Districts::tier( $district ) ) {
			case SLK_Districts::TIER_METRO:
				return __( '1 to 2 working days', 'slk' );
			case SLK_Districts::TIER_REGIONAL:
				return __( '2 to 3 working days', 'slk' );
			default:
				return __( '3 to 5 working days', 'slk' );
		}
	}

	/**
	 * Create a "Sri Lanka" shipping zone carrying this method, once, if no zone
	 * already offers it. Idempotent: it looks for an existing instance first,
	 * and clears its own flag whatever the outcome.
	 */
	public static function maybe_provision_zone(): void {
		if ( 'yes' !== get_option( 'slk_checkout_needs_zone_provision' ) ) {
			return;
		}
		if ( ! class_exists( 'WC_Shipping_Zones' ) || ! class_exists( 'WC_Shipping_Zone' ) ) {
			return; // WooCommerce not ready yet; try again next admin request.
		}

		delete_option( 'slk_checkout_needs_zone_provision' );

		foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
			foreach ( (array) ( $zone_data['shipping_methods'] ?? array() ) as $method ) {
				if ( isset( $method->id ) && self::METHOD_ID === $method->id ) {
					return; // Already set up — never duplicate.
				}
			}
		}

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( __( 'Sri Lanka', 'slk' ) );
		$zone->add_location( SLK_Districts::COUNTRY, 'country' );
		$zone->save();
		$zone->add_shipping_method( self::METHOD_ID );
	}
}
