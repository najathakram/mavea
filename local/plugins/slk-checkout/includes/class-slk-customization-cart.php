<?php
/**
 * Customization on the cart: the PDP controls, and everything that carries a
 * shopper's choice from "Add to bag" through to the order and packing slip.
 *
 * SLK_Customization (class-slk-customization.php, a separate work package)
 * owns the data model and is the ONE place a selection is validated and
 * priced — this file never re-derives a fee or a day count itself. It calls:
 *
 *   SLK_Customization::has_any( WC_Product $product ): bool
 *   SLK_Customization::groups_for( WC_Product $product ): array
 *       [ { key, label, required, choices: [ { key, label, fee, extra_days }, … ] }, … ]
 *       — read-only, used here only to RENDER the controls (option value,
 *       per-choice fee shown in the dropdown). Never used to price a
 *       selection; that is resolve()'s job alone.
 *   SLK_Customization::length_for( WC_Product $product ): array
 *       { enabled, fee, extra_days, min_cm, max_cm } — same, render-only.
 *   SLK_Customization::resolve( WC_Product $product, array $selection ): array
 *       $selection = { groups: [ group_key => choice_key, … ], length_cm: mixed }
 *       returns { valid, errors: [string, …], fee, extra_days,
 *                 labels: [ group_label => choice_label, …, ('Custom length' =>
 *                 '120 cm')? ] } — the single gate for "is this selection
 *       legal", used identically at add-to-cart validation and at
 *       add-to-cart persistence so the two can never disagree.
 *
 * WHY A PER-LINE PRICE, NOT $cart->add_fee().
 * A cart fee is one line for the whole cart — WooCommerce cannot tie it back
 * to the item that earned it, so the order and the packing slip could never
 * say which piece the fee belongs to, and a cart with two customized lines
 * would show one combined number nobody could attribute. Setting the
 * product object's own price on woocommerce_before_calculate_totals keeps
 * the markup on its line, the way a different size already prices on its
 * line.
 *
 * WHY A CACHED BASE PRICE, NOT get_price() + fee.
 * woocommerce_before_calculate_totals runs more than once per request (cart
 * totals, then shipping), on the SAME product object each time within that
 * request. Adding the fee to whatever get_price() returns on a second pass
 * would add it on top of the first pass's result. The true base price is
 * read once — the first time this line is seen — and stashed on the
 * product object's own runtime meta (never saved) so every later pass in
 * the same request recomputes from that same starting point instead of
 * compounding.
 *
 * @package slk-checkout
 */

defined( 'ABSPATH' ) || exit;

final class SLK_Customization_Cart {

	/** Cart item data key carrying the resolved selection. */
	public const CART_ITEM_KEY = 'slk_customization';

	/** Hidden (underscore-prefixed) order line item meta key carrying the
	 *  same payload, raw — WooCommerce's own get_formatted_meta_data() hides
	 *  any key starting with "_" from the order screen and packing slip by
	 *  default, so this never duplicates the visible rows next to it. */
	private const ORDER_META_KEY = '_slk_customization';

	/** POST field names the PDP controls submit. */
	public const OPTION_FIELD = 'slk_option';
	public const LENGTH_FIELD = 'slk_custom_length';

	/**
	 * Meta key used to remember a cart line's pre-fee price across repeated
	 * calculate_totals passes in one request. Set on the product object's
	 * RUNTIME instance only (update_meta_data(), never save()) — it must
	 * never reach the database, or it would start describing the product
	 * everywhere instead of one shopper's cart line.
	 */
	private const BASE_PRICE_META = '_slk_customization_base_price';

	public static function init(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_options' ) );

		// Priority 20: SLK_Fulfilment_Admin's own add-to-cart guard runs at
		// 10 and can already have set $passed to false (out of stock,
		// retired with nothing left to sell). This filter only adds its own
		// reasons to refuse; it never has to repeat that check.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 20, 4 );

		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_cart_item_from_session' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'get_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'checkout_create_order_line_item' ), 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_line_fee' ), 20 );

		// SLK_Fulfilment owns the ready-date machinery outright; this is the
		// one line that lets a customized cart line lengthen its own
		// estimate without that class knowing customization exists at all.
		add_filter( 'slk_line_making_days', array( __CLASS__, 'filter_line_making_days' ), 10, 3 );
		add_filter( 'slk_packing_slip_data', array( __CLASS__, 'packing_slip_data' ), 20, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* PDP: option groups + custom length, above the add-to-cart button.  */
	/* ------------------------------------------------------------------ */

	/**
	 * woocommerce_before_add_to_cart_button.
	 *
	 * Fires inside form.cart for both simple and variable products (for a
	 * variable product, inside .single_variation_wrap — the same place core
	 * prints its own live price and stock text once a variation is chosen).
	 * $product on this hook is always the parent object (WooCommerce never
	 * swaps this global for a specific variation; that choice is made by
	 * client-side JS after the page has rendered), which is exactly the
	 * object SLK_Customization's own config_id() would resolve to anyway.
	 */
	public static function render_options(): void {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// A retired product will never carry the extra making time a
		// customization adds, so offering the controls here would promise
		// production the merchant already said would not happen. The same
		// rule is enforced again, explicitly, in validate_add_to_cart(): a
		// cached page or a JavaScript-off submission could still try to post
		// these fields after the fact.
		if ( SLK_Fulfilment::is_retired( $product ) ) {
			return;
		}

		if ( ! SLK_Customization::has_any( $product ) ) {
			return;
		}

		$groups = SLK_Customization::groups_for( $product );
		$length = SLK_Customization::length_for( $product );

		echo '<div class="slk-customize">';

		foreach ( $groups as $group ) {
			self::render_group( $group );
		}

		if ( ! empty( $length['enabled'] ) ) {
			self::render_length_field( $length );
		}

		echo '</div>';
	}

	/**
	 * One option group as a <select> — required groups drop the blank
	 * "None" row so the browser's own required attribute has no valid empty
	 * value to fall back to, matching the server-side rule in
	 * SLK_Customization::resolve().
	 *
	 * @param array $group { key, label, required, choices }.
	 */
	private static function render_group( array $group ): void {
		$key      = isset( $group['key'] ) ? (string) $group['key'] : '';
		$label    = isset( $group['label'] ) ? (string) $group['label'] : '';
		$required = ! empty( $group['required'] );
		$choices  = isset( $group['choices'] ) && is_array( $group['choices'] ) ? $group['choices'] : array();

		if ( '' === $key || '' === $label || empty( $choices ) ) {
			return;
		}

		$field_id = 'slk-option-' . $key;

		printf(
			'<div class="slk-customize__group"><label class="slk-customize__label" for="%1$s">%2$s%3$s</label>',
			esc_attr( $field_id ),
			esc_html( $label ),
			$required ? ' <span class="slk-customize__required" aria-hidden="true">*</span>' : ''
		);

		printf(
			'<select class="slk-customize__select" name="%1$s" id="%2$s"%3$s>',
			esc_attr( self::group_field_name( $key ) ),
			esc_attr( $field_id ),
			$required ? ' required' : ''
		);

		if ( ! $required ) {
			printf( '<option value="">%s</option>', esc_html__( 'None', 'slk' ) );
		}

		foreach ( $choices as $choice ) {
			$choice_key   = isset( $choice['key'] ) ? (string) $choice['key'] : '';
			$choice_label = isset( $choice['label'] ) ? (string) $choice['label'] : '';

			if ( '' === $choice_key || '' === $choice_label ) {
				continue;
			}

			$fee = isset( $choice['fee'] ) ? SLK_Money::rupees( $choice['fee'] ) : 0.0;

			$option_text = $choice_label;
			if ( $fee > 0 ) {
				$option_text = sprintf(
					/* translators: 1: choice label. 2: extra fee, formatted. */
					__( '%1$s (+%2$s)', 'slk' ),
					$choice_label,
					self::format_price_plain( $fee )
				);
			}

			printf(
				'<option value="%1$s">%2$s</option>',
				esc_attr( $choice_key ),
				esc_html( $option_text )
			);
		}

		echo '</select></div>';
	}

	/**
	 * The optional custom-length field.
	 *
	 * @param array $length { enabled, fee, extra_days, min_cm, max_cm }.
	 */
	private static function render_length_field( array $length ): void {
		$fee = isset( $length['fee'] ) ? SLK_Money::rupees( $length['fee'] ) : 0.0;
		$min = isset( $length['min_cm'] ) ? (int) $length['min_cm'] : 0;
		$max = isset( $length['max_cm'] ) ? (int) $length['max_cm'] : 0;

		$hint_parts = array();
		if ( $min > 0 && $max > 0 ) {
			$hint_parts[] = sprintf(
				/* translators: 1: minimum length in cm. 2: maximum length in cm. */
				__( '%1$d to %2$d cm', 'slk' ),
				$min,
				$max
			);
		}
		if ( $fee > 0 ) {
			$hint_parts[] = sprintf(
				/* translators: %s: extra fee for a custom length, formatted. */
				__( 'plus %s', 'slk' ),
				self::format_price_plain( $fee )
			);
		}

		printf(
			'<div class="slk-customize__group slk-customize__length"><label class="slk-customize__label" for="slk-custom-length">%1$s%2$s</label>',
			esc_html__( 'Custom length (cm)', 'slk' ),
			$hint_parts ? ' <span class="slk-customize__hint">(' . esc_html( implode( ', ', $hint_parts ) ) . ')</span>' : ''
		);

		printf(
			'<input class="slk-customize__input" type="number" inputmode="decimal" step="1" name="%1$s" id="slk-custom-length"%2$s%3$s placeholder="%4$s" />',
			esc_attr( self::LENGTH_FIELD ),
			$min > 0 ? ' min="' . esc_attr( (string) $min ) . '"' : '',
			$max > 0 ? ' max="' . esc_attr( (string) $max ) . '"' : '',
			esc_attr__( 'Leave blank for standard length', 'slk' )
		);

		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Add-to-cart validation.                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * woocommerce_add_to_cart_validation.
	 *
	 * A required group with no choice, or a length outside the configured
	 * range, is refused HERE — never silently at checkout, where a shopper
	 * could not fix it without abandoning the order.
	 *
	 * @param bool $passed       Whether the add-to-cart is allowed so far.
	 * @param int  $product_id   Product id (always the parent, per core).
	 * @param int  $quantity     Quantity requested.
	 * @param int  $variation_id Variation id, 0 for a simple product.
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed ) {
			return $passed; // Already refused by another guard.
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product instanceof WC_Product ) {
			return $passed;
		}

		if ( ! SLK_Customization::has_any( $product ) ) {
			return $passed;
		}

		$resolved = SLK_Customization::resolve( $product, self::posted_selection() );

		/*
		 * Retired stock can still be sold AS IT STANDS, but never customized:
		 * customizing it would promise making time the merchant has already said
		 * will not happen for this piece.
		 *
		 * So the refusal is conditional on her having actually chosen something.
		 * Refusing outright, which is what this did, took a retired piece with a
		 * customization block still configured on it off the shelf completely —
		 * the last one in stock could not be bought at all, plain, by anyone. The
		 * PDP renders no controls for a retired piece, so in the ordinary case
		 * nothing is selected, nothing is charged, and the line passes through as
		 * the plain garment it is.
		 */
		if ( SLK_Fulfilment::is_retired( $product ) ) {
			if ( ! empty( $resolved['labels'] ) ) {
				wc_add_notice( __( 'Sorry, this piece is no longer available to customize.', 'slk' ), 'error' );
				return false;
			}

			return $passed;
		}

		if ( empty( $resolved['valid'] ) ) {
			foreach ( (array) ( $resolved['errors'] ?? array() ) as $error ) {
				wc_add_notice( (string) $error, 'error' );
			}
			return false;
		}

		return $passed;
	}

	/* ------------------------------------------------------------------ */
	/* Carrying the selection through the cart.                          */
	/* ------------------------------------------------------------------ */

	/**
	 * woocommerce_add_cart_item_data.
	 *
	 * Re-resolves the same posted fields validate_add_to_cart() already
	 * approved (WooCommerce does not hand this filter that result, only the
	 * raw request again), then stores the resolved fee/days/labels on the
	 * cart item together with a hash of that selection.
	 *
	 * The hash is deterministic on the selection's own content, not on time:
	 * two shoppers choosing the identical options still land on one merged
	 * cart line, which is the behaviour a plain product already has. What
	 * it guarantees is the other direction — a customized line's cart item
	 * data is never empty the way a plain line's is, so it can never merge
	 * WITH a plain line of the same product. Verified, not assumed:
	 * WooCommerce hashes cart_item_data into the line's own key
	 * (WC_Cart::generate_cart_id()), and this array is part of that data.
	 *
	 * @param array $cart_item_data Cart item data assembled so far.
	 * @param int   $product_id     Product id.
	 * @param int   $variation_id   Variation id, 0 for a simple product.
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! is_array( $cart_item_data ) ) {
			$cart_item_data = array();
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product instanceof WC_Product || ! SLK_Customization::has_any( $product ) ) {
			return $cart_item_data;
		}

		if ( SLK_Fulfilment::is_retired( $product ) ) {
			return $cart_item_data; // Refused already in validate_add_to_cart(); leave the line plain.
		}

		$resolved = SLK_Customization::resolve( $product, self::posted_selection() );

		// validate_add_to_cart() already stopped an invalid selection before
		// WooCommerce ever calls this filter. Failing safe here — leaving
		// the line plain rather than trusting an unresolved payload — is
		// what happens if that guard is ever bypassed (a direct add_to_cart()
		// call from other code, for instance).
		if ( empty( $resolved['valid'] ) ) {
			return $cart_item_data;
		}

		// Nothing chosen at all (no groups required, no length entered) —
		// a customizable product bought with its defaults is a plain line.
		if ( empty( $resolved['labels'] ) ) {
			return $cart_item_data;
		}

		$payload = array(
			'labels'     => $resolved['labels'],
			'fee'        => SLK_Money::rupees( $resolved['fee'] ?? 0 ),
			'extra_days' => max( 0, (int) ( $resolved['extra_days'] ?? 0 ) ),
		);

		$payload['hash'] = self::selection_hash( $payload );

		$cart_item_data[ self::CART_ITEM_KEY ] = $payload;

		return $cart_item_data;
	}

	/**
	 * woocommerce_get_cart_item_from_session.
	 *
	 * @param array $cart_item Cart item being rebuilt.
	 * @param array $values    Session values for this cart item.
	 */
	public static function get_cart_item_from_session( $cart_item, $values ) {
		if ( ! is_array( $cart_item ) || ! is_array( $values ) ) {
			return $cart_item;
		}

		if ( isset( $values[ self::CART_ITEM_KEY ] ) && is_array( $values[ self::CART_ITEM_KEY ] ) ) {
			$cart_item[ self::CART_ITEM_KEY ] = $values[ self::CART_ITEM_KEY ];
		}

		return $cart_item;
	}

	/**
	 * woocommerce_get_item_data — the cart and checkout review tables.
	 *
	 * @param array $item_data Rows printed so far.
	 * @param array $cart_item Cart item.
	 */
	public static function get_item_data( $item_data, $cart_item ) {
		if ( ! is_array( $item_data ) ) {
			$item_data = array();
		}

		$custom = self::customization_from_cart_item( $cart_item );
		if ( null === $custom ) {
			return $item_data;
		}

		foreach ( self::describe_selection( $custom ) as $row ) {
			$item_data[] = $row;
		}

		return $item_data;
	}

	/**
	 * woocommerce_checkout_create_order_line_item.
	 *
	 * Every chosen option becomes its own visible meta row, exactly like the
	 * printed rows in the cart, so the order screen and the packing slip
	 * both show it with no further wiring. A hidden (underscore-prefixed)
	 * row alongside carries the raw numbers, for any future automation that
	 * needs them rather than the printed sentence.
	 *
	 * @param WC_Order_Item_Product $item          Order line item being created.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values         Cart item.
	 * @param WC_Order              $order          Order.
	 */
	public static function checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$custom = self::customization_from_cart_item( $values );
		if ( null === $custom ) {
			return;
		}

		foreach ( self::describe_selection( $custom ) as $row ) {
			$item->add_meta_data( $row['key'], $row['value'] );
		}

		$item->add_meta_data( self::ORDER_META_KEY, $custom, true );
	}

	/**
	 * slk_packing_slip_data.
	 *
	 * The slip is what the studio actually works from, so a customized line has
	 * to carry its instructions there and not only onto the order screen. Without
	 * this the person cutting the piece reads the product name and makes the
	 * standard one, and the difference is not discovered until the parcel is
	 * opened.
	 *
	 * Read back off the ORDER, never off the cart: by the time a slip is printed
	 * the session is long gone, and the order is the only record that still knows
	 * what she picked.
	 *
	 * @param array    $data  Slip payload so far.
	 * @param WC_Order $order Order being printed.
	 * @return array
	 */
	public static function packing_slip_data( $data, $order ) {
		if ( ! is_array( $data ) || ! $order instanceof WC_Order ) {
			return $data;
		}

		$lines = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$custom = $item->get_meta( self::ORDER_META_KEY, true );

			if ( ! is_array( $custom ) || empty( $custom['labels'] ) ) {
				continue;
			}

			$lines[] = array(
				'item_id' => (int) $item_id,
				'name'    => $item->get_name(),
				'qty'     => (int) $item->get_quantity(),
				'rows'    => self::describe_selection( $custom ),
			);
		}

		if ( $lines ) {
			$data['customization'] = $lines;
		}

		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Per-line pricing.                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * woocommerce_before_calculate_totals, priority 20.
	 *
	 * @param WC_Cart $cart Cart being totalled.
	 */
	public static function apply_line_fee( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$custom = self::customization_from_cart_item( $cart_item );
			if ( null === $custom ) {
				continue;
			}

			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$fee = isset( $custom['fee'] ) ? SLK_Money::rupees( $custom['fee'] ) : 0.0;
			if ( $fee <= 0 ) {
				continue; // Nothing to add; leave the product's own price alone.
			}

			// See the file header: this line reads once, on the first
			// calculate_totals pass this request sees for this cart line,
			// so a second pass in the same request cannot compound the fee.
			if ( '' === $product->get_meta( self::BASE_PRICE_META, true ) ) {
				$product->update_meta_data( self::BASE_PRICE_META, (string) $product->get_price() );
			}

			$base = (float) $product->get_meta( self::BASE_PRICE_META, true );
			$product->set_price( SLK_Money::rupees( $base + $fee ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Ready-date effect.                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * slk_line_making_days — SLK_Fulfilment's per-line making-days filter.
	 *
	 * @param int             $days      Ready days computed so far (dispatch
	 *                                   days for a line we hold, making plus
	 *                                   tolerance for one we still make).
	 * @param WC_Product|null $product   Product being priced (unused here —
	 *                                   the resolved extra already lives on
	 *                                   the cart item, so there is nothing
	 *                                   left to look up from the product).
	 * @param array|null      $cart_item The cart item this figure is for.
	 */
	public static function filter_line_making_days( $days, $product, $cart_item ) {
		$custom = self::customization_from_cart_item( is_array( $cart_item ) ? $cart_item : array() );
		if ( null === $custom ) {
			return $days;
		}

		$extra = isset( $custom['extra_days'] ) ? max( 0, (int) $custom['extra_days'] ) : 0;

		return (int) $days + $extra;
	}

	/* ------------------------------------------------------------------ */
	/* Shared helpers.                                                    */
	/* ------------------------------------------------------------------ */

	private static function group_field_name( string $key ): string {
		return self::OPTION_FIELD . '[' . $key . ']';
	}

	/**
	 * Read and sanitize the customization fields from the current request,
	 * in the shape SLK_Customization::resolve() expects.
	 *
	 * No nonce: WooCommerce's own add-to-cart POST carries none either
	 * (WC_Form_Handler::add_to_cart_action() reads product id and quantity
	 * straight off $_POST), so this form has nothing more to protect than
	 * core's own fields already leave unprotected. Every value read here is
	 * re-validated against the product's actual configured groups and
	 * length inside resolve() — a tampered value can only select a real
	 * choice or fail validation, never be trusted directly.
	 */
	private static function posted_selection(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see docblock above.
		$raw_groups = isset( $_POST[ self::OPTION_FIELD ] ) ? wp_unslash( $_POST[ self::OPTION_FIELD ] ) : array();
		$raw_length = isset( $_POST[ self::LENGTH_FIELD ] ) ? wp_unslash( $_POST[ self::LENGTH_FIELD ] ) : '';
		// phpcs:enable

		$groups = array();
		if ( is_array( $raw_groups ) ) {
			foreach ( $raw_groups as $key => $value ) {
				if ( is_scalar( $value ) && '' !== $value ) {
					$groups[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
				}
			}
		}

		return array(
			'groups'    => $groups,
			'length_cm' => is_scalar( $raw_length ) ? sanitize_text_field( (string) $raw_length ) : '',
		);
	}

	/**
	 * A hash of the selection's own content — see add_cart_item_data() for
	 * why this exists alongside WooCommerce's own cart-id hashing.
	 *
	 * @param array $payload The resolved labels/fee/days about to be stored.
	 */
	private static function selection_hash( array $payload ): string {
		return substr( md5( (string) wp_json_encode( $payload['labels'] ) ), 0, 16 );
	}

	/**
	 * The stored customization payload on a cart item, or null when the
	 * line carries none.
	 *
	 * @param array $cart_item Cart item (or session values array — both
	 *                          shapes carry the key the same way).
	 */
	private static function customization_from_cart_item( $cart_item ): ?array {
		if ( ! is_array( $cart_item ) || empty( $cart_item[ self::CART_ITEM_KEY ] ) || ! is_array( $cart_item[ self::CART_ITEM_KEY ] ) ) {
			return null;
		}

		return $cart_item[ self::CART_ITEM_KEY ];
	}

	/**
	 * The printable {key, value} rows for a resolved selection — shared by
	 * the cart/checkout review table and the order line item meta, so the
	 * two can never say something different about the same line. One extra
	 * row states the total extra charge, since SLK_Customization::resolve()
	 * only returns fee as a single total, not broken out per choice.
	 *
	 * @param array $custom Resolved payload from customization_from_cart_item().
	 * @return array<int,array{key:string,value:string}>
	 */
	private static function describe_selection( array $custom ): array {
		$rows = array();

		/*
		 * `labels` is a list of {label, value} pairs. The label-keyed map it used
		 * to be is still accepted, because a cart held in a session from before
		 * that change would otherwise render its rows as "0", "1", "2" — a live
		 * bag is not worth breaking over an internal shape.
		 */
		foreach ( (array) ( $custom['labels'] ?? array() ) as $key => $row ) {
			if ( is_array( $row ) ) {
				$rows[] = array(
					'key'   => (string) ( $row['label'] ?? '' ),
					'value' => (string) ( $row['value'] ?? '' ),
				);
				continue;
			}

			$rows[] = array( 'key' => (string) $key, 'value' => (string) $row );
		}

		$fee = isset( $custom['fee'] ) ? SLK_Money::rupees( $custom['fee'] ) : 0.0;
		if ( $fee > 0 ) {
			$rows[] = array(
				'key'   => __( 'Customization fee', 'slk' ),
				'value' => self::format_price_plain( $fee ),
			);
		}

		return $rows;
	}

	/**
	 * A rupee amount as plain text, for contexts that cannot hold markup
	 * (a <select> option, a comma-joined hint). Still sourced from
	 * wc_price() — never a hand-written "Rs." — with its markup stripped
	 * and its "&nbsp;" entity decoded back to a real space so it does not
	 * print literally once esc_html() runs on the surrounding string.
	 */
	private static function format_price_plain( float $amount ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}
}
