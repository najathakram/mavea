<?php
/**
 * How long until a line is ready, and the settings behind that answer.
 *
 * Three cases, decided by stock alone:
 *   in stock             -> dispatch_days
 *   not in stock, active -> making_days + tolerance_days
 *   not in stock, retired -> null, meaning it cannot be bought
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

class SLK_Fulfilment {

	public const OPTION      = 'slk_fulfilment_settings';
	public const META_MAKING = '_slk_making_days';
	public const META_RETIRED = '_slk_retired';

	public static function defaults(): array {
		return array(
			'dispatch_days'        => 1,
			'default_making_days'  => 7,
			'tolerance_days'       => 0,
			'cutoff_hour'          => 15,
			'working_days'         => array( 1, 2, 3, 4, 5, 6 ), // Monday to Saturday.
			'holidays'             => array(),
			'extra_shipment_fee'   => 0, // 0 means charge the normal district fee.
			'split_enabled'        => true,
			'exchange_window_days' => 7,
			'exchange_send_fee'    => 350,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function is_retired( WC_Product $product ): bool {
		$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return 'yes' === get_post_meta( $id, self::META_RETIRED, true );
	}

	public static function making_days( WC_Product $product ): int {
		$id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$own = get_post_meta( $id, self::META_MAKING, true );

		if ( '' !== $own && is_numeric( $own ) ) {
			return max( 0, (int) $own );
		}

		return max( 0, (int) self::settings()['default_making_days'] );
	}

	/**
	 * Days after an order is complete that a shopper can request an exchange.
	 */
	public static function exchange_window_days(): int {
		return max( 0, (int) self::settings()['exchange_window_days'] );
	}

	/**
	 * Rupees charged to send a replacement piece back out on an exchange.
	 */
	public static function exchange_send_fee(): float {
		return max( 0.0, SLK_Money::rupees( self::settings()['exchange_send_fee'] ) );
	}

	/**
	 * Working days until this line is ready to leave, or null when the piece
	 * cannot be supplied at all.
	 *
	 * is_on_backorder( $qty ) is the accurate test for "we do not have this
	 * many on the shelf": it is true only when stock is managed, backorders are
	 * allowed and the held quantity is short. A product with stock management
	 * off and status instock reads as in stock, which is correct.
	 */
	public static function ready_days( WC_Product $product, int $qty ): ?int {
		$settings = self::settings();

		if ( ! $product->is_in_stock() ) {
			return null; // outofstock: retired and gone.
		}

		if ( ! $product->is_on_backorder( $qty ) ) {
			return max( 0, (int) $settings['dispatch_days'] );
		}

		if ( self::is_retired( $product ) ) {
			return null;
		}

		return self::making_days( $product ) + max( 0, (int) $settings['tolerance_days'] );
	}
}
