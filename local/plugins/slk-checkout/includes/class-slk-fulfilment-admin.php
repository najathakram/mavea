<?php
/**
 * Everything a shop admin touches to run the delivery promise: the settings
 * screen, the per-product fields, and the guard that keeps an unavailable
 * piece out of a shopper's cart.
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Fulfilment_Admin {

	public static function init(): void {
		add_filter( 'woocommerce_get_sections_shipping', array( __CLASS__, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_shipping', array( __CLASS__, 'add_settings' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( __CLASS__, 'sanitize_option' ), 10, 3 );

		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'render_product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_fields' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen: WooCommerce -> Settings -> Shipping -> Delivery   */
	/* promise. One field per key in SLK_Fulfilment::defaults(), all      */
	/* stored in the single SLK_Fulfilment::OPTION array.                 */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $sections Shipping tab sections.
	 */
	public static function add_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$sections['slk_fulfilment'] = __( 'Delivery promise', 'slk' );

		return $sections;
	}

	/**
	 * @param array  $settings        Settings for the current shipping section.
	 * @param string $current_section Section being rendered.
	 */
	public static function add_settings( $settings, $current_section ) {
		if ( 'slk_fulfilment' !== $current_section ) {
			return $settings;
		}

		return self::settings_fields();
	}

	/**
	 * Bracket-notation id for one key of SLK_Fulfilment::OPTION, the form
	 * WooCommerce's settings API reads and writes the whole array through.
	 */
	private static function field_id( string $key ): string {
		return SLK_Fulfilment::OPTION . '[' . $key . ']';
	}

	/**
	 * The free delivery threshold lives on the "Sri Lanka delivery" shipping
	 * method instance, not in SLK_Fulfilment::OPTION: SLK_Shipping::free_over()
	 * reads the instance option `free_over`, and this field must write the
	 * exact same option so there is one threshold, not two that can disagree.
	 *
	 * If the shipping zone has not been provisioned yet (no active instance
	 * to write to), the field is shown disabled rather than silently writing
	 * nowhere.
	 */
	private static function free_over_field(): array {
		$method = SLK_Shipping::active_method();

		if ( ! $method instanceof WC_Shipping_Method ) {
			return array(
				'title'             => __( 'Free delivery threshold (Rs.)', 'slk' ),
				'desc'              => __( 'Not available yet: the Sri Lanka shipping zone has not been set up. Reload this page after visiting WooCommerce -> Settings -> Shipping.', 'slk' ),
				'id'                => 'slk_fulfilment_free_over_unavailable',
				'type'              => 'number',
				'value'             => (string) SLK_Shipping::FREE_OVER,
				'custom_attributes' => array(
					'min'      => '0',
					'step'     => '1',
					'disabled' => 'disabled',
				),
				'desc_tip'          => false,
			);
		}

		return array(
			'title'             => __( 'Free delivery threshold (Rs.)', 'slk' ),
			'desc'              => __( 'Cart value of the goods, before delivery, at or above which a shipment travels free. Set to 0 to switch free delivery off.', 'slk' ),
			'id'                => $method->get_instance_option_key() . '[free_over]',
			'type'              => 'number',
			'value'             => (string) SLK_Money::rupees( $method->get_option( 'free_over', SLK_Shipping::FREE_OVER ) ),
			'custom_attributes' => array(
				'min'  => '0',
				'step' => '1',
			),
			'desc_tip'          => true,
		);
	}

	private static function settings_fields(): array {
		$settings = SLK_Fulfilment::settings();

		$weekdays = array(
			'0' => __( 'Sunday', 'slk' ),
			'1' => __( 'Monday', 'slk' ),
			'2' => __( 'Tuesday', 'slk' ),
			'3' => __( 'Wednesday', 'slk' ),
			'4' => __( 'Thursday', 'slk' ),
			'5' => __( 'Friday', 'slk' ),
			'6' => __( 'Saturday', 'slk' ),
		);

		return array(

			array(
				'title' => __( 'Delivery promise', 'slk' ),
				'type'  => 'title',
				'desc'  => __( 'How a ready date is worked out for a cart line, and how a split order is charged. Items ready the same day always travel together, delivery is charged per shipment, and a shipment at or above the free delivery threshold below travels free.', 'slk' ),
				'id'    => 'slk_fulfilment_options',
			),

			array(
				'title'             => __( 'Dispatch time', 'slk' ),
				'desc'              => __( 'Working days before a piece already on the shelf leaves.', 'slk' ),
				'id'                => self::field_id( 'dispatch_days' ),
				'type'              => 'number',
				'value'             => (string) (int) $settings['dispatch_days'],
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			array(
				'title'             => __( 'Default making time', 'slk' ),
				'desc'              => __( 'Working days to make a piece, when the product does not set its own making time.', 'slk' ),
				'id'                => self::field_id( 'default_making_days' ),
				'type'              => 'number',
				'value'             => (string) (int) $settings['default_making_days'],
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			array(
				'title'             => __( 'Extra buffer', 'slk' ),
				'desc'              => __( 'Extra working days added on top of the making time, to absorb delays.', 'slk' ),
				'id'                => self::field_id( 'tolerance_days' ),
				'type'              => 'number',
				'value'             => (string) (int) $settings['tolerance_days'],
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			array(
				'title'             => __( 'Order cut-off hour', 'slk' ),
				'desc'              => __( 'An order placed at or after this hour is counted from the next day. Use 0 to turn the cut-off off.', 'slk' ),
				'id'                => self::field_id( 'cutoff_hour' ),
				'type'              => 'number',
				'value'             => (string) (int) $settings['cutoff_hour'],
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '23',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			array(
				'title'    => __( 'Working days', 'slk' ),
				'desc'     => __( 'Days counted when working out a ready date.', 'slk' ),
				'id'       => self::field_id( 'working_days' ),
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width: 350px;',
				'options'  => $weekdays,
				'value'    => array_map( 'strval', array_map( 'intval', (array) $settings['working_days'] ) ),
				'desc_tip' => true,
			),

			array(
				'title'    => __( 'Holidays', 'slk' ),
				'desc'     => __( 'One date per line, in the form year-month-day. For example 2026-12-25.', 'slk' ),
				'id'       => self::field_id( 'holidays' ),
				'type'     => 'textarea',
				'value'    => implode( "\n", (array) $settings['holidays'] ),
				'css'      => 'min-width: 350px; height: 120px;',
				'desc_tip' => false,
			),

			array(
				'title'             => __( 'Extra shipment fee', 'slk' ),
				'desc'              => __( 'Rupees charged for each shipment after the first, when a cart is split. Leave at 0 to charge the normal district rate for every shipment.', 'slk' ),
				'id'                => self::field_id( 'extra_shipment_fee' ),
				'type'              => 'number',
				'value'             => (string) SLK_Money::rupees( $settings['extra_shipment_fee'] ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'desc_tip'          => true,
			),

			self::free_over_field(),

			array(
				'title' => __( 'Split shipments', 'slk' ),
				'desc'  => __( 'Let a shopper choose to have each piece sent as soon as it is ready.', 'slk' ),
				'id'    => self::field_id( 'split_enabled' ),
				'type'  => 'checkbox',
				'value' => ! empty( $settings['split_enabled'] ) ? 'yes' : 'no',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'slk_fulfilment_options',
			),
		);
	}

	/**
	 * Three of the nine settings need a shape the generic handling in
	 * WC_Admin_Settings::save_fields() does not produce on its own:
	 * "holidays" is a list, not a blob of text; "split_enabled" is read
	 * elsewhere with a plain truthy check, so it has to land as a real
	 * boolean rather than the string "no" (which PHP treats as truthy); and
	 * "working_days" has to be rebuilt from the raw post, because the
	 * multiselect branch runs array_filter() over the posted values and
	 * array_filter() throws away Sunday, whose value is the string "0".
	 *
	 * @param mixed $value     Sanitised value produced by the field's type.
	 * @param array $option    The field definition.
	 * @param mixed $raw_value Raw posted value before type handling.
	 */
	public static function sanitize_option( $value, $option, $raw_value ) {
		if ( ! is_array( $option ) || ! isset( $option['id'] ) ) {
			return $value;
		}

		if ( self::field_id( 'holidays' ) === $option['id'] ) {
			return self::sanitize_holidays( is_string( $raw_value ) ? $raw_value : '' );
		}

		if ( self::field_id( 'working_days' ) === $option['id'] ) {
			return self::sanitize_working_days( $raw_value );
		}

		if ( self::field_id( 'split_enabled' ) === $option['id'] ) {
			return 'yes' === $value;
		}

		return $value;
	}

	/**
	 * A week with no working day in it would push every ready date to the
	 * far end of the calendar guard, so an empty selection falls back to the
	 * default week and says so.
	 *
	 * @param mixed $raw Raw posted value: weekday numbers, or null when the
	 *                   field was submitted with nothing selected.
	 * @return array<int,int>
	 */
	private static function sanitize_working_days( $raw ): array {
		$days = array();

		foreach ( (array) $raw as $day ) {
			if ( is_numeric( $day ) && (int) $day >= 0 && (int) $day <= 6 ) {
				$days[] = (int) $day;
			}
		}

		$days = array_values( array_unique( $days ) );
		sort( $days );

		if ( empty( $days ) ) {
			WC_Admin_Settings::add_error( __( 'Pick at least one working day. The store default week has been kept.', 'slk' ) );

			return array_map( 'intval', (array) SLK_Fulfilment::defaults()['working_days'] );
		}

		return $days;
	}

	/**
	 * @return array<int,string>
	 */
	private static function sanitize_holidays( string $raw ): array {
		$dates = array();

		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );

			if ( '' !== $line && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $line ) ) {
				$dates[] = $line;
			}
		}

		return array_values( array_unique( $dates ) );
	}

	/* ------------------------------------------------------------------ */
	/* Product fields: Inventory tab, making time and retired.            */
	/* ------------------------------------------------------------------ */

	public static function render_product_fields(): void {
		global $product_object;

		if ( ! $product_object instanceof WC_Product ) {
			return;
		}

		$product_id = $product_object->get_id();
		$making     = get_post_meta( $product_id, SLK_Fulfilment::META_MAKING, true );
		$retired    = get_post_meta( $product_id, SLK_Fulfilment::META_RETIRED, true );

		echo '<div class="options_group show_if_simple show_if_variable slk-fulfilment-fields">';

		woocommerce_wp_text_input(
			array(
				'id'                => SLK_Fulfilment::META_MAKING,
				'label'             => __( 'Making time (working days)', 'slk' ),
				'desc_tip'          => true,
				'description'       => __( 'Working days to make this piece. Leave blank to use the store default making time.', 'slk' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => is_numeric( $making ) ? $making : '',
			)
		);

		printf(
			'<p class="form-field slk-fulfilment-open-orders"><label>%1$s</label><span>%2$s</span></p>',
			esc_html__( 'Open orders holding this product', 'slk' ),
			esc_html( number_format_i18n( self::open_order_count( $product_id ) ) )
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => SLK_Fulfilment::META_RETIRED,
				'label'       => __( 'Retired', 'slk' ),
				'description' => __( 'Sell remaining stock only. Once stock runs out this product can no longer be bought.', 'slk' ),
				'value'       => 'yes' === $retired ? 'yes' : 'no',
			)
		);

		echo '</div>';
	}

	/**
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product_fields( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$posted_making = isset( $_POST[ SLK_Fulfilment::META_MAKING ] )
			? sanitize_text_field( wp_unslash( $_POST[ SLK_Fulfilment::META_MAKING ] ) )
			: '';

		if ( '' !== $posted_making && is_numeric( $posted_making ) ) {
			$product->update_meta_data( SLK_Fulfilment::META_MAKING, (string) max( 0, (int) $posted_making ) );
		} else {
			$product->delete_meta_data( SLK_Fulfilment::META_MAKING );
		}

		$product->update_meta_data(
			SLK_Fulfilment::META_RETIRED,
			isset( $_POST[ SLK_Fulfilment::META_RETIRED ] ) ? 'yes' : 'no'
		);
	}

	/**
	 * Orders that still owe this product a place in a shipment: processing,
	 * plus any status other than "pending payment" whose slug reads as a
	 * pending/unconfirmed step (a COD confirmation stage, for example) —
	 * registered by whatever plugin owns that lifecycle, not hard-coded
	 * here, so a later status change does not silently stop counting.
	 *
	 * wc_get_orders() has no "contains this product" query var, HPOS or not.
	 * The order item tables are the same table under HPOS, so asking them
	 * first narrows the work to the orders that actually hold this product,
	 * rather than loading every open order on the store into memory on every
	 * product edit screen. The status filter then goes back through
	 * wc_get_orders(), which reads whichever orders table is in use.
	 */
	private static function open_order_count( int $product_id ): int {
		global $wpdb;

		if ( $product_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT items.order_id
				FROM {$wpdb->prefix}woocommerce_order_items AS items
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS item_meta
					ON item_meta.order_item_id = items.order_item_id
				WHERE items.order_item_type = 'line_item'
					AND item_meta.meta_key = '_product_id'
					AND item_meta.meta_value = %s",
				(string) $product_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( empty( $order_ids ) ) {
			return 0;
		}

		$open = wc_get_orders(
			array(
				'status'   => self::open_order_statuses(),
				'post__in' => array_map( 'absint', $order_ids ),
				'limit'    => -1,
				'type'     => 'shop_order',
				'return'   => 'ids',
			)
		);

		return count( (array) $open );
	}

	/**
	 * @return array<int,string>
	 */
	private static function open_order_statuses(): array {
		$statuses = array( 'wc-processing' );

		foreach ( array_keys( (array) wc_get_order_statuses() ) as $status ) {
			if ( 'wc-pending' === $status ) {
				continue; // Pending payment: not yet a confirmed order.
			}
			if ( false !== strpos( $status, 'pending' ) ) {
				$statuses[] = $status;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/* ------------------------------------------------------------------ */
	/* Cart guard: a piece with no ready date cannot be supplied at all.  */
	/* ------------------------------------------------------------------ */

	private static function unavailable_message(): string {
		return __( 'Sorry, this size is not available.', 'slk' );
	}

	/**
	 * @param bool $passed       Whether the add-to-cart is allowed so far.
	 * @param int  $product_id   Product id.
	 * @param int  $quantity     Quantity requested.
	 * @param int  $variation_id Variation id, 0 for a simple product.
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed ) {
			return $passed;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $passed;
		}

		if ( null === SLK_Fulfilment::ready_days( $product, (int) $quantity ) ) {
			wc_add_notice( self::unavailable_message(), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Drops a line that was in the cart before its stock ran out from under
	 * it: the add-to-cart guard only stops a NEW line, so a line already
	 * sitting in the cart needs its own check on every cart/checkout view.
	 */
	public static function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;

			if ( null === SLK_Fulfilment::ready_days( $product, $qty ) ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( self::unavailable_message(), 'error' );
			}
		}
	}
}
