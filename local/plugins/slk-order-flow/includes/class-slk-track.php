<?php
/**
 * Order lookup by order number plus email OR Sri Lankan mobile.
 *
 * Core's own [woocommerce_order_tracking] shortcode requires the order
 * number and the billing EMAIL, but this checkout is phone-first with email
 * optional (see slk-checkout), so a phone-only COD customer cannot use core
 * tracking at all. This class is the phone-aware replacement: the order
 * number must still match, and now either the billing email OR the last 9
 * digits of the billing phone may serve as the second factor.
 *
 * SECURITY: this must never become an order-enumeration surface. Both the
 * order number and the contact have to match or resolve() returns null —
 * there is no code path here that looks an order up by phone or email
 * alone, and a wrong order number and a wrong contact fail identically.
 *
 * @package slk-order-flow
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Track {

	/** Per-IP lookup budget: 10 attempts, 10-minute window. */
	private const RATE_LIMIT_MAX    = 10;
	private const RATE_LIMIT_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Per-IP lookup budget, shared by EVERY entry point into resolve() —
	 * the assist widget's ajax handler and the /track-order/ form POST both
	 * spend from this one counter. Keeping it here rather than in the caller
	 * is the point: a budget that only covered the ajax path would be
	 * sidestepped by posting the cheaper page form instead, and guessing
	 * order ids against a known mobile number is exactly the enumeration
	 * this class exists to refuse.
	 *
	 * A simple transient counter, not a sliding log — matching the
	 * complexity of every other transient in this codebase
	 * (class-slk-studio-today.php, class-slk-exchange-admin.php).
	 * REMOTE_ADDR rather than a forwarded-for header: the header is
	 * client-suppliable, so trusting it would let the limit be spoofed away.
	 *
	 * @return bool True when this IP has spent its budget and the lookup
	 *              should fail with the caller's neutral message.
	 */
	public static function rate_limited(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $ip ) {
			return false; // Nothing to key the limit on; the nonce still gates cross-site abuse.
		}

		$key   = 'slk_track_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return false;
	}

	/**
	 * Resolve an order from its order number and a contact value that is
	 * either an email address or a Sri Lankan mobile number.
	 *
	 * @param int|string $order_id Raw order number as posted by the form; may
	 *                              carry a leading '#' and surrounding space.
	 * @param string     $contact  Raw contact value as posted by the form.
	 * @return WC_Order|null The order on a full match, null on anything else.
	 */
	public static function resolve( $order_id, $contact ): ?WC_Order {
		$order_id = absint( preg_replace( '/[^0-9]/', '', (string) $order_id ) );

		if ( ! $order_id ) {
			return null;
		}

		$contact = trim( (string) $contact );

		if ( '' === $contact ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		if ( str_contains( $contact, '@' ) ) {
			$email = $order->get_billing_email();

			if ( '' === $email || 0 !== strcasecmp( $contact, $email ) ) {
				return null;
			}

			return $order;
		}

		$digits = preg_replace( '/[^0-9]/', '', $contact );

		if ( ! $digits || strlen( $digits ) < 9 ) {
			return null;
		}

		$contact_last9 = substr( $digits, -9 );

		if ( '7' !== $contact_last9[0] ) {
			return null;
		}

		$phone_digits = preg_replace( '/[^0-9]/', '', (string) $order->get_billing_phone() );

		if ( ! $phone_digits || strlen( $phone_digits ) < 9 ) {
			return null;
		}

		$phone_last9 = substr( $phone_digits, -9 );

		if ( ! hash_equals( $phone_last9, $contact_last9 ) ) {
			return null;
		}

		return $order;
	}
}
