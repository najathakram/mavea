<?php
/**
 * Optional email, and what happens when there isn't one.
 *
 * Most Sri Lankan cash-on-delivery shoppers do not want to hand over an email
 * address, and nothing in a COD or bank-transfer order needs one — the whole
 * post-order flow is a phone call and a WhatsApp thread. So email is optional.
 *
 * Two consequences have to be handled, or the store quietly breaks:
 *
 * 1. GATEWAYS THAT NEED AN EMAIL. PayHere posts a receipt to the shopper and
 *    requires an address; an empty one is a failed payment, not a graceful
 *    degradation. So email becomes required, server-side, the moment such a
 *    gateway is chosen (enforced in SLK_Checkout_Fields::validate).
 *
 * 2. WOOCOMMERCE ASSUMES AN EMAIL EXISTS. Order emails, the "order received"
 *    page, customer lookup and most reporting read billing_email. Leaving it
 *    empty produces a scatter of edge cases across core and every plugin. So
 *    when it is absent we synthesise ONE deterministic placeholder address on a
 *    dedicated, non-routable subdomain:
 *
 *        p94771234567@no-reply.<site host>
 *
 *    - Derived from the normalised phone number, so the same shopper reorders
 *      under the same address and the customer record joins up.
 *    - On its own subdomain that has no MX record, so nothing we send can ever
 *      reach a real mailbox or land in someone else's inbox.
 *    - And, belt and braces, every customer-facing email is disabled for orders
 *      carrying the placeholder flag, so we do not generate the bounce in the
 *      first place. Store-admin emails still fire — the shop must still be told
 *      about the order.
 *
 * The flag lives in order meta so a later export, a support agent, or the
 * packing slip can tell a real address from a synthesised one.
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Email_Policy {

	public const PLACEHOLDER_META = '_slk_email_placeholder';

	/**
	 * Customer-facing core order emails, suppressed for placeholder addresses.
	 * Filterable so third-party customer emails (shipment tracking and the
	 * like) can be added without touching this file.
	 */
	private static function customer_email_ids(): array {
		return (array) apply_filters(
			'slk_suppressed_customer_emails',
			array(
				'customer_processing_order',
				'customer_completed_order',
				'customer_on_hold_order',
				'customer_invoice',
				'customer_note',
				'customer_refunded_order',
			)
		);
	}

	public static function init(): void {
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'ensure_email' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'note_placeholder' ) );

		foreach ( self::customer_email_ids() as $email_id ) {
			add_filter( 'woocommerce_email_enabled_' . $email_id, array( __CLASS__, 'suppress' ), 20, 2 );
		}
	}

	/**
	 * Gateways that cannot complete without an email address.
	 *
	 * PayHere is the one in the design. Koko (BNPL) also collects an email in
	 * its own flow; add it here if its gateway plugin turns out to require the
	 * value up front rather than collecting it on its hosted page.
	 */
	public static function gateway_requires_email( $method ): bool {
		$method = (string) $method;
		if ( '' === $method ) {
			return false;
		}

		$requiring = apply_filters(
			'slk_gateways_requiring_email',
			array( 'payhere', 'payhere_gateway', 'payherepay' )
		);

		return in_array( $method, (array) $requiring, true );
	}

	/**
	 * @param WC_Order $order Order under construction (not yet saved).
	 * @param array    $data  Posted checkout data.
	 */
	public static function ensure_email( $order, $data ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( '' !== trim( (string) $order->get_billing_email() ) ) {
			return;
		}

		$phone = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : (string) $order->get_billing_phone();
		$order->set_billing_email( self::placeholder_address( $phone ) );
		$order->update_meta_data( self::PLACEHOLDER_META, 'yes' );
	}

	/**
	 * Order notes need a saved order, so this runs after creation.
	 *
	 * @param WC_Order $order Saved order.
	 */
	public static function note_placeholder( $order ) {
		if ( $order instanceof WC_Order && self::is_placeholder( $order ) ) {
			$order->add_order_note( __( 'No email given. Order is confirmed by phone; customer emails are suppressed.', 'slk' ) );
		}
	}

	/**
	 * Deterministic, syntactically valid, non-routable.
	 */
	public static function placeholder_address( $phone ): string {
		$digits = SLK_Phone::national_digits( $phone );
		$local  = '' !== $digits ? 'p94' . $digits : 'guest' . wp_generate_password( 10, false, false );

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./i', '', $host );
		$host = '' !== (string) $host ? $host : 'localhost.invalid';

		$domain = (string) apply_filters( 'slk_placeholder_email_domain', 'no-reply.' . $host );

		return sanitize_email( $local . '@' . $domain );
	}

	public static function is_placeholder( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		return 'yes' === $order->get_meta( self::PLACEHOLDER_META );
	}

	/**
	 * Note: this filter governs automatic sends. An admin who explicitly picks
	 * "Resend order emails" in the order screen calls the email trigger direct
	 * and will still send — which is the right behaviour, because at that point
	 * a human has decided to.
	 *
	 * @param bool  $enabled Whether the email is enabled.
	 * @param mixed $object  Usually the order; can be a customer or null.
	 */
	public static function suppress( $enabled, $object = null ) {
		if ( $object instanceof WC_Order && self::is_placeholder( $object ) ) {
			return false;
		}

		return $enabled;
	}
}
