<?php
/**
 * What the shop and the courier see.
 *
 * The landmark is useless if it only lives in the database — it has to reach
 * the person packing the parcel and the person driving to the door. So it is
 * shown on the order screen (which, under HPOS, is the same meta box) and it is
 * part of the packing slip data structure the order-flow plugin prints from.
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Order_Admin {

	public static function init(): void {
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_admin_panel' ) );
		add_filter( 'slk_packing_slip_data', array( __CLASS__, 'packing_slip_data' ), 10, 2 );
	}

	/**
	 * @param WC_Order $order Order being edited.
	 */
	public static function render_admin_panel( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$landmark = SLK_Checkout_Fields::get_landmark( $order );
		$district = (string) $order->get_billing_state();

		echo '<div class="slk-order-panel">';

		if ( '' !== $landmark ) {
			echo '<p><strong>' . esc_html__( 'Nearest landmark or junction', 'slk' ) . ':</strong><br />' . esc_html( $landmark ) . '</p>';
		}

		if ( '' !== $district ) {
			echo '<p><strong>' . esc_html__( 'District', 'slk' ) . ':</strong> ' . esc_html( $district ) . '</p>';
		}

		if ( SLK_Email_Policy::is_placeholder( $order ) ) {
			echo '<p><strong>' . esc_html__( 'Email', 'slk' ) . ':</strong> ' .
				esc_html__( 'None given. This is a placeholder address and customer emails are suppressed, so confirm by phone.', 'slk' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Everything the packing slip and the courier handoff need, in one array.
	 *
	 * @param array    $data  Slip data assembled so far.
	 * @param WC_Order $order Order.
	 */
	public static function packing_slip_data( $data, $order ) {
		if ( ! is_array( $data ) || ! $order instanceof WC_Order ) {
			return $data;
		}

		$district = (string) $order->get_billing_state();

		$data['name']         = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$data['phone']        = (string) $order->get_billing_phone();
		$data['address_1']    = (string) $order->get_billing_address_1();
		$data['landmark']     = SLK_Checkout_Fields::get_landmark( $order );
		$data['city']         = (string) $order->get_billing_city();
		$data['district']     = $district;
		$data['postcode']     = (string) $order->get_billing_postcode();
		$data['cod']          = 'cod' === $order->get_payment_method();
		$data['collect']      = $data['cod'] ? (float) $order->get_total() : 0.0;
		$data['email_is_placeholder'] = SLK_Email_Policy::is_placeholder( $order );

		return $data;
	}
}

/**
 * Packing slip payload for an order.
 *
 * Kept as a plain function so the order-flow plugin and any courier export can
 * call it without coupling to this plugin's class names.
 *
 * @param WC_Order $order Order.
 */
function slk_get_packing_slip_data( $order ): array {
	if ( ! $order instanceof WC_Order ) {
		return array();
	}

	return (array) apply_filters( 'slk_packing_slip_data', array( 'order_number' => $order->get_order_number() ), $order );
}
