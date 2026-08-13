<?php
/**
 * Grouping the cart into shipments, and pricing each one.
 *
 * Mode "together" is one shipment leaving on the latest ready date.
 * Mode "split" is one shipment per distinct ready date.
 *
 * Fees follow the order value rule the store already advertises, applied to
 * each shipment: a shipment worth the free-delivery threshold or more travels
 * free, anything less pays. That is what makes the extra cost of splitting
 * avoidable, by putting more into the same shipment.
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

class SLK_Shipments {

	public const MODE_TOGETHER = 'together';
	public const MODE_SPLIT    = 'split';
	public const SESSION_KEY   = 'slk_ship_mode';

	public static function init(): void {
		add_action( 'wp_ajax_slk_set_ship_mode', array( __CLASS__, 'ajax_set_mode' ) );
		add_action( 'wp_ajax_nopriv_slk_set_ship_mode', array( __CLASS__, 'ajax_set_mode' ) );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'stamp_mode_on_packages' ) );
	}

	/**
	 * Whether the shopper is allowed to split at all. The setting is the only
	 * switch, so it is read here rather than only where the radios are drawn:
	 * turning it off has to stop a session that is already splitting, not just
	 * hide the control that got it there.
	 */
	public static function split_enabled(): bool {
		return ! empty( SLK_Fulfilment::settings()['split_enabled'] );
	}

	public static function mode(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! self::split_enabled() ) {
			return self::MODE_TOGETHER;
		}

		$mode = WC()->session->get( self::SESSION_KEY );

		return self::MODE_SPLIT === $mode ? self::MODE_SPLIT : self::MODE_TOGETHER;
	}

	public static function set_mode( string $mode ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$mode = ( self::MODE_SPLIT === $mode && self::split_enabled() ) ? self::MODE_SPLIT : self::MODE_TOGETHER;

		if ( $mode === WC()->session->get( self::SESSION_KEY ) ) {
			return;
		}

		WC()->session->set( self::SESSION_KEY, $mode );

		// The mode changes what delivery costs, and WooCommerce serves shipping
		// rates from the session until the package hash moves. The hash carries
		// the mode (see stamp_mode_on_packages), so the stored rates are stale
		// the moment the mode does: drop them and price the cart again, or the
		// cart would print the new shipments beside the old delivery line.
		WC()->session->set( 'shipping_for_package_0', null );

		if ( WC()->cart ) {
			WC()->cart->calculate_shipping();
		}
	}

	/**
	 * Put the ship mode inside the shipping package.
	 *
	 * WooCommerce caches each package's rates against a hash of the package
	 * itself, and the mode lives on the session, which the hash never sees. A
	 * shopper switching to one shipment per ready date would keep the old cost.
	 * Stamping the mode onto every package makes the hash move with it.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public static function stamp_mode_on_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}

		$mode = self::mode();

		foreach ( $packages as $index => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $index ]['slk_ship_mode'] = $mode;
			}
		}

		return $packages;
	}

	public static function ajax_set_mode(): void {
		check_ajax_referer( 'slk-ship-mode', 'nonce' );
		self::set_mode( isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '' );
		wp_send_json_success();
	}

	/**
	 * @return array<int,array{ready_days:int,ready_date:DateTimeImmutable,keys:array<int,string>,subtotal:float}>
	 */
	public static function build( $cart = null, ?string $mode = null ): array {
		$cart = $cart ?: ( function_exists( 'WC' ) ? WC()->cart : null );
		if ( ! $cart ) {
			return array();
		}

		$settings = SLK_Fulfilment::settings();
		$mode     = $mode ?: self::mode();
		$start    = SLK_Calendar::start( $settings );

		$by_days = array();
		$slowest = 0;

		foreach ( $cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$qty  = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$days = SLK_Fulfilment::ready_days( $product, $qty );

			if ( null === $days ) {
				continue; // Cannot be supplied; WP4's validator keeps it out of the cart.
			}

			$line_total = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;
			$slowest    = max( $slowest, $days );

			if ( ! isset( $by_days[ $days ] ) ) {
				$by_days[ $days ] = array( 'keys' => array(), 'subtotal' => 0.0 );
			}
			$by_days[ $days ]['keys'][]   = $key;
			$by_days[ $days ]['subtotal'] += $line_total;
		}

		if ( empty( $by_days ) ) {
			return array();
		}

		if ( self::MODE_TOGETHER === $mode ) {
			$keys     = array();
			$subtotal = 0.0;
			foreach ( $by_days as $group ) {
				$keys      = array_merge( $keys, $group['keys'] );
				$subtotal += $group['subtotal'];
			}

			return array(
				array(
					'ready_days' => $slowest,
					'ready_date' => SLK_Calendar::add_working_days( $start, $slowest, $settings ),
					'keys'       => $keys,
					'subtotal'   => $subtotal,
				),
			);
		}

		ksort( $by_days, SORT_NUMERIC );

		$shipments = array();
		foreach ( $by_days as $days => $group ) {
			$shipments[] = array(
				'ready_days' => (int) $days,
				'ready_date' => SLK_Calendar::add_working_days( $start, (int) $days, $settings ),
				'keys'       => $group['keys'],
				'subtotal'   => $group['subtotal'],
			);
		}

		return $shipments;
	}

	/**
	 * The date a single cart line is ready, regardless of when it travels.
	 */
	public static function line_ready_date( WC_Product $product, int $qty ): ?DateTimeImmutable {
		$settings = SLK_Fulfilment::settings();
		$days     = SLK_Fulfilment::ready_days( $product, $qty );

		if ( null === $days ) {
			return null;
		}

		return SLK_Calendar::add_working_days( SLK_Calendar::start( $settings ), $days, $settings );
	}

	public static function fee_for_shipment( float $subtotal, string $district, bool $is_first ): float {
		$free_over = SLK_Shipping::free_over();

		if ( $free_over > 0 && $subtotal >= $free_over ) {
			return 0.0;
		}

		$district_fee = SLK_Shipping::fee_for_district( $district );

		if ( $is_first ) {
			return $district_fee;
		}

		$extra = SLK_Money::rupees( SLK_Fulfilment::settings()['extra_shipment_fee'] );

		return $extra > 0 ? $extra : $district_fee;
	}

	/**
	 * Total delivery cost across every shipment.
	 */
	public static function total_fee( string $district, $cart = null ): float {
		$total = 0.0;
		foreach ( self::build( $cart ) as $i => $shipment ) {
			$total += self::fee_for_shipment( (float) $shipment['subtotal'], $district, 0 === $i );
		}

		return $total;
	}

	/**
	 * How much more this shipment needs to travel free, or 0 when it already
	 * does and when the merchant has switched free delivery off, because then
	 * there is no amount that would earn it.
	 */
	public static function shortfall( float $subtotal ): float {
		$free_over = SLK_Shipping::free_over();

		if ( $free_over <= 0 ) {
			return 0.0;
		}

		return max( 0.0, $free_over - $subtotal );
	}
}
