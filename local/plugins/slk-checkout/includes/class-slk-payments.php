<?php
/**
 * Payment rules: COD handling fee, gateway order, Koko threshold, and gating
 * PayHere/Mintpay until each has a merchant id configured.
 *
 * @package slk
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Payments {

	/** Rs. 150, from design/assets/sri-lanka-commerce.json. Whole rupees. */
	public const COD_FEE = 150;

	/** Koko / pay-in-3 appears from Rs. 10,000. */
	public const KOKO_MIN = 10000;

	/**
	 * Field key the fee lives under inside the COD gateway's own settings
	 * array (`woocommerce_cod_settings`) — kept next to the payment method
	 * it belongs to instead of a new settings screen.
	 */
	private const COD_FEE_OPTION_KEY = 'slk_handling_fee';

	public static function init(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'cod_handling_fee' ), 20 );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'sort_and_gate' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'record_cod_fee' ), 30, 2 );
		add_action( 'wp_loaded', array( __CLASS__, 'silence_gateway_advertising' ) );
		add_filter( 'woocommerce_settings_api_form_fields_cod', array( __CLASS__, 'add_cod_fee_field' ) );
		add_filter( 'slk_cod_handling_fee', array( __CLASS__, 'cod_fee_from_gateway_option' ) );
	}

	/**
	 * Third-party gateways advertise on our checkout. Mintpay's price-breakdown
	 * file hooks woocommerce_review_order_before_payment and prints "Use code
	 * MINT20 for 20% OFF up to Rs. 1000 on your first transaction", in its own
	 * bold capitals, inside the order summary.
	 *
	 * This store runs no discount codes and shows no sale theatre. That is a
	 * brand rule, not a preference, and it is not suspended because a plugin
	 * would like to run a promotion on someone else's shopfront. The banner also
	 * offers a code we neither issue nor honour, so it is a promise we cannot
	 * keep to a shopper who tries it.
	 *
	 * Removed by callback name rather than by disabling the gateway, because the
	 * banner renders whether or not Mintpay is configured or even available.
	 *
	 * One hook, because one is all the plugin registers: verified against the
	 * installed copy, price-breakdown/index.php line 367. If a future version
	 * advertises somewhere else, this is where to add it.
	 */
	public static function silence_gateway_advertising(): void {
		remove_action( 'woocommerce_review_order_before_payment', 'mintpay_display_price_breakdown_in_checkout_page' );
	}

	/* ------------------------------------------------------------------ */
	/* COD handling fee                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Rs. 150, added only when cash on delivery is the selected method.
	 *
	 * The fee is stated up front in the design ("+ Rs. 150" on the COD option,
	 * and a "COD handling" line in the summary), so it must appear in the total
	 * the moment the shopper picks COD and disappear the moment they pick
	 * anything else. WooCommerce reads the selection from the session, which
	 * WC_AJAX::update_order_review writes from the posted form before it calls
	 * calculate_totals() — so the only missing piece is a client-side trigger
	 * for that AJAX call on payment-method change. Core does not ship one (it
	 * only toggles the payment box), so we add it: assets/js/checkout.js.
	 *
	 * @param WC_Cart $cart Cart being totalled.
	 */
	public static function cod_handling_fee( $cart ) {
		if ( ! $cart instanceof WC_Cart || $cart->is_empty() ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		/*
		 * Deliberately NOT $cart->needs_payment().
		 *
		 * needs_payment() is get_total() > 0, and inside
		 * woocommerce_cart_calculate_fees the total has not been computed yet —
		 * fees are an INPUT to it. Measured in the running store: the guard read
		 * needs_payment = 0 on every pass while the same call outside the hook
		 * read 1, so the fee was silently skipped every single time and the
		 * Rs. 150 handling charge never reached a customer-visible total.
		 *
		 * The contents total IS known at this point, so use that: it answers the
		 * question actually being asked — is there anything here to pay for.
		 */
		if ( (float) $cart->get_cart_contents_total() <= 0 ) {
			return;
		}

		// Checkout only. The session remembers the last chosen method, so
		// without this the fee would surface on the cart page before the
		// shopper has chosen anything — and the design deliberately says there
		// that COD handling is "shown before you place the order".
		// WOOCOMMERCE_CHECKOUT is defined by both WC_AJAX::update_order_review
		// and WC_Checkout::process_checkout.
		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) && ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			return;
		}

		if ( 'cod' !== (string) WC()->session->get( 'chosen_payment_method' ) ) {
			return;
		}

		$fee = SLK_Money::rupees( apply_filters( 'slk_cod_handling_fee', self::COD_FEE ) );
		if ( $fee <= 0 ) {
			return;
		}

		// Not taxable: it is a courier handling charge, not a sale of goods.
		// Whole rupees in, whole rupees out — nothing here can introduce a
		// fractional cent that would shift the amount string a gateway hashes.
		$cart->add_fee( __( 'Cash on delivery handling', 'slk' ), $fee, false );
	}

	/**
	 * Stamp the fee on the order so a later reconciliation (or the courier
	 * remittance check) can tell the collected amount from the goods value
	 * without re-deriving it from the fee lines.
	 *
	 * @param WC_Order $order Order under construction.
	 * @param array    $data  Posted data.
	 */
	public static function record_cod_fee( $order, $data ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'cod' !== (string) $order->get_payment_method() ) {
			return;
		}

		$order->update_meta_data( '_slk_cod_fee', (string) SLK_Money::rupees( apply_filters( 'slk_cod_handling_fee', self::COD_FEE ) ) );
	}

	/**
	 * Adds the handling fee to Cash on delivery's own settings screen
	 * (WooCommerce → Settings → Payments → Cash on delivery), so staff can
	 * change it there without a deploy. Lives on the gateway that charges
	 * it rather than a new settings page.
	 *
	 * Default matches self::COD_FEE, so a fresh install — where this field
	 * has never been saved — renders exactly the total it does today.
	 *
	 * @param array $fields COD gateway's form fields, keyed by field id.
	 */
	public static function add_cod_fee_field( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$fields[ self::COD_FEE_OPTION_KEY ] = array(
			'title'             => __( 'COD handling fee (Rs.)', 'slk' ),
			'type'              => 'number',
			'default'           => (string) self::COD_FEE,
			'desc_tip'          => true,
			'description'       => __( 'Added to the total when cash on delivery is chosen. Whole rupees.', 'slk' ),
			'custom_attributes' => array(
				'min'  => '0',
				'step' => '1',
			),
		);

		return $fields;
	}

	/**
	 * Reads the dashboard value back through the same slk_cod_handling_fee
	 * filter both cod_handling_fee() and record_cod_fee() already call, so
	 * neither needed to change — this is the only thing that has to know
	 * where the number now lives.
	 *
	 * Falls back to the incoming value (self::COD_FEE) whenever the field
	 * has never been saved, which is what a fresh install passes in.
	 *
	 * @param mixed $fee Value passed into the slk_cod_handling_fee filter.
	 */
	public static function cod_fee_from_gateway_option( $fee ) {
		$settings = get_option( 'woocommerce_cod_settings', array() );

		if ( is_array( $settings ) && isset( $settings[ self::COD_FEE_OPTION_KEY ] ) && '' !== $settings[ self::COD_FEE_OPTION_KEY ] ) {
			return $settings[ self::COD_FEE_OPTION_KEY ];
		}

		return $fee;
	}

	/**
	 * Live COD handling fee, in rupees. For anything outside this class
	 * that needs the number — the theme's slk_delivery_cod_fee() proxies
	 * this instead of keeping its own copy.
	 */
	public static function cod_fee(): float {
		return SLK_Money::rupees( apply_filters( 'slk_cod_handling_fee', self::COD_FEE ) );
	}

	/* ------------------------------------------------------------------ */
	/* Gateway order and availability                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * COD first, then pay-now, then bank transfer, then BNPL — the order the
	 * design puts them in, and the order that matches how this market buys.
	 * Gateway IDs not in the list keep their relative position at the end.
	 *
	 * Koko is removed below its minimum cart value. Its absence is explained in
	 * the design's copy rather than left silent, but that line is the theme's
	 * to render; this only decides availability.
	 *
	 * PayHere and Mintpay are removed outright while unconfigured, before
	 * sorting even runs, so a half-built gateway is never something a shopper
	 * can order the list by.
	 *
	 * @param array $gateways Available gateways, keyed by id.
	 */
	public static function sort_and_gate( $gateways ) {
		if ( ! is_array( $gateways ) || empty( $gateways ) ) {
			return $gateways;
		}

		$gateways = self::gate_unconfigured( $gateways );

		// Koko threshold.
		$total = self::cart_items_total();
		if ( null !== $total ) {
			$minimum = SLK_Money::rupees( apply_filters( 'slk_koko_minimum_total', self::KOKO_MIN ) );
			if ( $total < $minimum ) {
				// 'darazbnpl' is the real one. paykoko-bnpl-payment-gateway 3.0.4
				// registers its gateway as darazbnpl (class Darazbnpl_WC, option
				// woocommerce_darazbnpl_settings) because the plugin is derived
				// from a Daraz BNPL integration and kept the internal naming. The
				// three guessed ids below never matched anything, so the minimum
				// would not have applied and Koko would have shown on a Rs 2,000
				// cart. Verified against the installed plugin 2026-08-19.
				foreach ( (array) apply_filters( 'slk_koko_gateway_ids', array( 'darazbnpl', 'koko', 'kokopayments', 'koko_payment' ) ) as $koko_id ) {
					unset( $gateways[ $koko_id ] );
				}
			}
		}

		$preferred = (array) apply_filters(
			'slk_gateway_order',
			array( 'cod', 'payhere', 'payhere_gateway', 'bacs', 'mintpay', 'darazbnpl', 'koko', 'kokopayments' )
		);

		$sorted = array();
		foreach ( $preferred as $id ) {
			if ( isset( $gateways[ $id ] ) ) {
				$sorted[ $id ] = $gateways[ $id ];
				unset( $gateways[ $id ] );
			}
		}

		return self::add_descriptions( $sorted + $gateways );
	}

	/**
	 * Hide a gateway that is active but has no merchant id set, so a shopper
	 * is never offered a payment method that cannot actually take their
	 * money. PayHere and Mintpay both read their merchant id from their own
	 * settings under the key `merchant_id`, which `get_option()` on the
	 * gateway instance already resolves for us.
	 *
	 * @param array $gateways Available gateways, keyed by id.
	 */
	private static function gate_unconfigured( $gateways ) {
		$ids = (array) apply_filters( 'slk_credentialed_gateway_ids', array( 'payhere', 'payhere_gateway', 'mintpay', 'darazbnpl' ) );

		foreach ( $ids as $id ) {
			if ( ! isset( $gateways[ $id ] ) || ! is_object( $gateways[ $id ] ) || ! method_exists( $gateways[ $id ], 'get_option' ) ) {
				continue;
			}

			// No credentials — nothing to pay with.
			if ( '' === trim( (string) $gateways[ $id ]->get_option( 'merchant_id' ) ) ) {
				unset( $gateways[ $id ] );
				continue;
			}

			/*
			 * Credentials present but still in sandbox is the DANGEROUS state,
			 * and it is the one the merchant-id check above waves through: the
			 * method looks live at checkout and sends a real shopper to the
			 * provider's sandbox, where no money moves. All three of these
			 * plugins ship test_mode defaulting to 'yes' and none of them warns
			 * in wp-admin. Hide the method until sandbox is switched off, so the
			 * failure is a missing payment option — visible, and noticed before
			 * launch — rather than a silent one taken at the checkout.
			 */
			if ( 'yes' === strtolower( trim( (string) $gateways[ $id ]->get_option( 'test_mode' ) ) ) ) {
				unset( $gateways[ $id ] );
			}
		}

		return $gateways;
	}

	/**
	 * Short, plain descriptions under each gateway name, written in the
	 * shopper's own words rather than the plugin's own marketing copy.
	 *
	 * @param array $gateways Available gateways, keyed by id.
	 */
	private static function add_descriptions( $gateways ) {
		$descriptions = (array) apply_filters(
			'slk_gateway_descriptions',
			array(
				'cod'             => __( 'Pay in cash when your order arrives.', 'slk' ),
				'payhere'         => __( 'Pay by card, eZ Cash, helaPay or LankaQR.', 'slk' ),
				'payhere_gateway' => __( 'Pay by card, eZ Cash, helaPay or LankaQR.', 'slk' ),
				'mintpay'         => __( 'Split the cost into instalments with Mintpay.', 'slk' ),
			)
		);

		foreach ( $descriptions as $id => $description ) {
			if ( isset( $gateways[ $id ] ) && is_object( $gateways[ $id ] ) ) {
				$gateways[ $id ]->description = $description;
			}
		}

		return $gateways;
	}

	/**
	 * Value of the goods in the cart, or null when there is no cart to read.
	 *
	 * Deliberately the cart CONTENTS total, not get_total(): the grand total is
	 * still being assembled while gateways are resolved (fees and shipping both
	 * depend on the chosen gateway), and reading it here invites a feedback
	 * loop. "Over Rs. 10,000" in the design is about what is in the bag anyway,
	 * not about what delivery costs. On the order-pay page and in the admin
	 * there is no cart, and the gate is skipped rather than guessed.
	 */
	private static function cart_items_total(): ?float {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}

		return SLK_Money::round( (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax() );
	}

	/* ------------------------------------------------------------------ */
	/* Recalculation trigger                                               */
	/* ------------------------------------------------------------------ */

	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'slk-checkout',
			plugins_url( 'assets/js/checkout.js', SLK_CHECKOUT_FILE ),
			array( 'jquery', 'wc-checkout' ),
			SLK_CHECKOUT_VERSION,
			true
		);
	}
}
