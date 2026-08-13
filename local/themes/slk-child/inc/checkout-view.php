<?php
/**
 * Checkout + order-received presentation — Porcelain Glass.
 *
 * Owns the VIEW only: layout, glass panels, the sticky order-summary aside,
 * payment-method card styling, notice/error styling, and the order-received
 * screen. Field definitions, validation, phone format and the COD fee all
 * belong to the slk-checkout plugin and are not touched here — this file
 * only decorates whatever markup that plugin (and WooCommerce core) renders
 * through the standard hooks used in woocommerce/checkout/form-checkout.php
 * and woocommerce/checkout/thankyou.php.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Small view helpers
 * ---------------------------------------------------------------------- */

/**
 * Mask a Sri Lankan phone number for display, e.g. "+94 77 123 4567" ->
 * "+94 77 ··· ·567". Purely cosmetic — never used for anything but display.
 *
 * @param string $phone Raw phone number as stored on the order.
 * @return string Masked number, or '' if nothing usable was passed.
 */
function slk_mask_phone( $phone ) {
	$digits = preg_replace( '/\D+/', '', (string) $phone );

	if ( strlen( $digits ) < 6 ) {
		return '';
	}

	// Keep the country/operator prefix and the last 3 digits, mask the rest.
	$prefix = substr( $digits, 0, strlen( $digits ) - 6 );
	$mid    = substr( $digits, -6, 3 );
	$last   = substr( $digits, -3 );

	return '+' . $prefix . ' ' . $mid[0] . $mid[1] . ' ··· ·' . $last;
}

/**
 * Build a wa.me link for tracking a specific order.
 *
 * The store's WhatsApp number is not yet known at build time, so it is
 * filterable rather than hardcoded — set it once, anywhere, with:
 *
 *     add_filter( 'slk_whatsapp_number', fn() => '94771234567' );
 *
 * @param WC_Order|null $order Order to reference in the pre-filled message.
 * @return string wa.me URL, or '#' if no number has been configured yet.
 */
function slk_whatsapp_track_url( $order = null ) {
	$number = (string) apply_filters( 'slk_whatsapp_number', '' );
	$number = preg_replace( '/\D+/', '', $number );

	if ( '' === $number ) {
		return '#';
	}

	$message = __( 'Hi, checking on my order', 'slk' );

	if ( $order instanceof WC_Order ) {
		/* translators: %s: order number. */
		$message = sprintf( __( 'Hi, checking on order #%s', 'slk' ), $order->get_order_number() );
	}

	return 'https://wa.me/' . $number . '?text=' . rawurlencode( $message );
}

/**
 * Split the single `billing` checkout group into the design's two numbered
 * panels: "1 · You" (identity) and "2 · Where" (address).
 *
 * WooCommerce has no field group for that distinction — the slk-checkout
 * plugin defines phone/name/email AND country/address/landmark/city/district/
 * postcode inside `billing`, ordered by priority. The cut is therefore made on
 * priority, at the value billing_country carries (40); everything below it is
 * identity, everything from it up is address. Fields are returned in the order
 * WooCommerce already sorted them into (WC_Checkout::get_checkout_fields()
 * uasorts each group by priority), so no reordering happens here.
 *
 * Presentation only: nothing is added, removed or renamed — the two returned
 * arrays always partition the input exactly.
 *
 * @param array $fields Billing fields as returned by get_checkout_fields( 'billing' ).
 * @return array{you: array, where: array}
 */
function slk_checkout_billing_split( $fields ) {
	$split = array(
		'you'   => array(),
		'where' => array(),
	);

	if ( ! is_array( $fields ) ) {
		return $split;
	}

	/**
	 * The priority at which "1 · You" ends and "2 · Where" begins.
	 *
	 * @param int $priority Default 40 — billing_country.
	 */
	$boundary = (int) apply_filters( 'slk_checkout_where_min_priority', 40 );

	foreach ( $fields as $key => $field ) {
		$priority = isset( $field['priority'] ) ? (int) $field['priority'] : 10;
		$group    = $priority >= $boundary ? 'where' : 'you';

		$split[ $group ][ $key ] = $field;
	}

	return $split;
}

/* -------------------------------------------------------------------------
 * 1b. One address form, never two.
 *
 * The design has a single "2 · Where" panel. Without this, WooCommerce offers
 * a "Ship to a different address?" checkbox and a second, duplicate address
 * form in the shipping group — which is also the only thing the old panel 2
 * contained. Forcing billing-only shipping makes
 * WC_Cart::needs_shipping_address() false, so form-shipping.php renders the
 * Delivery-notes field and nothing else.
 *
 * pre_option_ short-circuits both the stored option and the registered
 * default, so this holds whether or not the option row exists. Front end only:
 * wp-admin keeps showing the merchant the real stored setting.
 * ---------------------------------------------------------------------- */

add_filter(
	'pre_option_woocommerce_ship_to_destination',
	static function ( $value ) {
		return is_admin() ? $value : 'billing_only';
	},
	20
);

add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false', 20 );

/*
 * Remove Blocksy's hero band from checkout AT THE SOURCE (documented gate,
 * blocksy/template-parts/single.php). The old style.css display:none hid the
 * duplicate title visually but left a second <h1> in the served HTML —
 * and on checkout, which titles itself in the template, that was the ONLY
 * h1, leaving zero once hidden. Verify, D3.
 */
add_filter(
	'blocksy:single:has-default-hero',
	static function ( $has ) {
		return ( function_exists( 'is_checkout' ) && is_checkout() ) ? false : $has;
	}
);

/* -------------------------------------------------------------------------
 * 2. Decorate native field markup with the design-system classes.
 *
 * Presentation only: this adds CSS classes to whatever fields the
 * slk-checkout plugin defines, it does not add, remove or validate fields.
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_form_field_args',
	static function ( $args, $key, $value ) {
		$args['class']       = array_merge( (array) ( $args['class'] ?? array() ), array( 'slk-field' ) );
		$args['label_class'] = array_merge( (array) ( $args['label_class'] ?? array() ), array() );

		$type = $args['type'] ?? 'text';

		if ( in_array( $type, array( 'select', 'country', 'state' ), true ) ) {
			$args['input_class'] = array_merge( (array) ( $args['input_class'] ?? array() ), array( 'slk-select' ) );
		} elseif ( 'textarea' === $type ) {
			$args['input_class'] = array_merge( (array) ( $args['input_class'] ?? array() ), array( 'slk-textarea' ) );
		} elseif ( ! in_array( $type, array( 'checkbox', 'radio' ), true ) ) {
			$args['input_class'] = array_merge( (array) ( $args['input_class'] ?? array() ), array( 'slk-input' ) );
		}

		return $args;
	},
	20,
	3
);

/* -------------------------------------------------------------------------
 * 3. Silence the default order-received text — the custom hero in
 * thankyou.php carries that message instead. Documented core filter; the
 * hook that prints it (checkout/order-received.php) still fires as-is.
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_thankyou_order_received_text',
	static function () {
		return '';
	},
	20,
	2
);

/* -------------------------------------------------------------------------
 * 4. Assets — checkout + order-received only.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		// slk-child is a STYLE handle (functions.php enqueues no script under
		// that name), so inline CSS hangs off it correctly...
		wp_add_inline_style( 'slk-child', slk_checkout_view_css() );

		if ( ! is_order_received_page() ) {
			// ...but inline JS cannot: wp_add_inline_script() resolves handles
			// against WP_Scripts, where 'slk-child' does not exist, and
			// WP_Scripts::add_data() then returns false and drops the script
			// silently. Register a real (dependency-only, src-less) handle for
			// it, the same way inc/cart.php and inc/pdp.php do. jQuery is a
			// declared dependency because the payload binds WooCommerce's own
			// `updated_checkout` event.
			wp_register_script( 'slk-checkout-view', false, array( 'jquery' ), null, true );
			wp_enqueue_script( 'slk-checkout-view' );
			wp_add_inline_script( 'slk-checkout-view', slk_checkout_view_js() );
		}
	},
	40
);

/**
 * The checkout + order-received CSS. Tokens and component classes only —
 * see design/assets/design-tokens.css and design/assets/components.css.
 *
 * @return string
 */
function slk_checkout_view_css() {
	return '
.slk-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}

/* ── Layout ───────────────────────────────────────────────────────────────
   THIS FILE OWNS THE CHECKOUT LAYOUT. One grid, on #customer_details
   (.slk-checkout__grid), at the single site-wide 1000px breakpoint. The form
   itself is a plain block: it has exactly one child, #customer_details, so any
   grid declared on form.checkout would pack the whole checkout into that
   grid\'s first track and leave the second empty. The `display:block` below is
   the guard for that — style.css §3.11 must drop its own desktop grid block
   (its `form.checkout` grid + the `> #customer_details / > #order_review /
   > #order_review_heading` placements, which no longer match anything since
   #order_review moved inside .slk-checkout__aside).
   ------------------------------------------------------------------------ */
.slk-checkout{max-width:1140px;margin:0 auto;padding:0 var(--slk-space-4) var(--slk-space-12)}
.woocommerce-checkout #customer_details.slk-checkout__grid{display:grid;gap:var(--slk-space-4);width:100%;float:none}
.slk-checkout__fields{display:grid;gap:var(--slk-space-4)}
.slk-checkout__panel{padding:var(--slk-space-6)}
.slk-checkout__panel-label{margin-bottom:var(--slk-space-4)}
.slk-checkout__aside{display:grid;gap:var(--slk-space-3);align-content:start}
.slk-checkout__next{font:400 11.5px/1.6 var(--slk-font-ui);text-align:center;padding-top:2px}
.slk-order-summary{padding:var(--slk-space-6)}
@media (min-width:1000px){
  .woocommerce-checkout form.checkout.slk-checkout{display:block}
  .woocommerce-checkout #customer_details.slk-checkout__grid{grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);align-items:start;gap:var(--slk-space-8)}
  .slk-checkout__fields{grid-template-columns:1fr}
  .slk-checkout__aside{position:sticky;top:var(--slk-space-6)}
}

/* ── One panel per step ───────────────────────────────────────────────────
   The template supplies the glass (.slk-panel on #slk-panel-you /
   #slk-panel-where / #slk-payment-panel). WooCommerce\'s own group wrappers
   sit INSIDE those panels and must therefore be transparent pass-throughs —
   otherwise every step renders as two stacked translucent fills, two 1px
   near-white borders and ~48px of inset padding, and the (empty, because
   shipping is billing-only) .woocommerce-shipping-fields renders as a bare
   glass box of its own. style.css §3.11 must drop
   .woocommerce-billing-fields / -shipping-fields / -additional-fields from its
   glass rule; these declarations are the template\'s side of that contract and
   are inert once it does.
   ------------------------------------------------------------------------ */
.slk-checkout .woocommerce-billing-fields,
.slk-checkout .woocommerce-shipping-fields,
.slk-checkout .woocommerce-additional-fields{
  background:none;border:0;border-radius:0;padding:0;margin:0;box-shadow:none;
  backdrop-filter:none;-webkit-backdrop-filter:none;
}
.slk-checkout .woocommerce-additional-fields > h3{
  font:500 11px/1 var(--slk-font-ui);letter-spacing:.18em;text-transform:uppercase;
  color:var(--slk-color-muted);margin:var(--slk-space-4) 0 var(--slk-space-4);
}

/* ── Sign in row (Google + "sign in" link) and the divider before the fields ──
   inc/account.php renders this at the top of "1 · You" (inside
   .woocommerce-billing-fields, via the woocommerce_before_checkout_billing_form
   hook that form-billing.php already fires) for a logged-out shopper only.
   The markup it actually emits, matched here verbatim:
     .slk-checkout__signin           – outer wrapper; block, spacing only. The
                                       flex row must NOT live here, or the
                                       divider below becomes a third flex item
                                       and "or" lands beside the sign-in link.
       .slk-checkout__signin-row     – the row itself: Google button beside the
                                       "Already have an account? Sign in" link.
         .slk-google-button          – exactly what SLK_Google::button() (WP3)
                                       returns, along with its __icon / __text
                                       children. Deliberately kept lighter than
                                       #place_order — secondary to the form,
                                       not a hero.
         a.showlogin                 – WooCommerce\'s own class; its click
                                       handler (revealing the login form) is
                                       already bound sitewide, nothing new to
                                       wire up here.
       .slk-checkout__signin-divider – the "or" rule between the row and Mobile
                                       number; carries its own <span>or</span>.
   None of it prints for a logged-in shopper, and the Google button is absent
   whenever Google is not configured, so this CSS is simply unused on those
   pageviews. ── */
.slk-checkout__signin{margin-bottom:var(--slk-space-4)}
.slk-checkout__signin-row{
  display:flex;flex-wrap:wrap;align-items:center;gap:var(--slk-space-3);
}
.slk-checkout__signin .slk-google-button{
  min-height:var(--slk-touch);padding:0 20px;border-radius:var(--slk-radius-pill);
  border:1px solid var(--slk-field-border);background:var(--slk-color-white);
  color:var(--slk-color-ink);font:500 13px/1 var(--slk-font-ui);
  display:inline-flex;align-items:center;justify-content:center;gap:var(--slk-space-2);
  text-decoration:none;cursor:pointer;
  transition:border-color var(--slk-motion-base) var(--slk-ease),transform var(--slk-motion-base) var(--slk-ease);
}
.slk-checkout__signin .slk-google-button:hover{border-color:var(--slk-color-ink);transform:translateY(-1px)}
/* The icon span SLK_Google::button() emits is empty and aria-hidden, so its
   mark comes from CSS — the same way the account area draws its WhatsApp mark.
   A letterform also keeps this rule inside the tokens-only, no-raw-hex rule. */
.slk-checkout__signin .slk-google-button__icon{
  width:18px;height:18px;flex:none;display:grid;place-items:center;
  font:600 12px/1 var(--slk-font-ui);color:var(--slk-color-ink);
}
.slk-checkout__signin .slk-google-button__icon::before{content:"G"}
.slk-checkout__signin .showlogin{
  display:inline-flex;align-items:center;min-height:var(--slk-touch);
  font:400 12.5px/1.4 var(--slk-font-ui);color:var(--slk-color-muted);
  text-decoration:underline;text-underline-offset:3px;
}
.slk-checkout__signin-divider{
  display:flex;align-items:center;gap:var(--slk-space-3);
  margin:var(--slk-space-4) 0 0;
  font:500 11px/1 var(--slk-font-ui);letter-spacing:.14em;text-transform:uppercase;
  color:var(--slk-color-faint);
}
.slk-checkout__signin-divider::before,
.slk-checkout__signin-divider::after{content:"";flex:1;height:1px;background:var(--slk-hairline)}

/* ── Fields (native WooCommerce markup, decorated via woocommerce_form_field_args) ── */
.slk-checkout .form-row{margin:0 0 var(--slk-space-4)}
/* woocommerce_form_field() gives a type="hidden" field the same
   <p class="form-row"> wrapper as a visible one (wc-template-functions.php:
   only the label\'s `for` attribute is suppressed). billing_country and
   billing_last_name are posted but never shown, so each left an empty row
   carrying the 16px bottom margin above — one of them at the very top of the
   "2 · Where" panel, reading as an unexplained gap. Hide the rows, never the
   inputs: both values still have to post. */
.slk-checkout #billing_country_field,
.slk-checkout #billing_last_name_field{display:none}
.slk-checkout .form-row label{display:block;font:500 12px/1 var(--slk-font-ui);margin-bottom:7px;color:var(--slk-color-ink)}
.slk-checkout .form-row .required{color:var(--slk-color-error);text-decoration:none}
.slk-checkout .form-row .optional{color:var(--slk-color-faint);font-weight:400}
.slk-checkout .form-row.woocommerce-invalid label{color:var(--slk-color-error)}
.slk-checkout .form-row.woocommerce-invalid .slk-input,
.slk-checkout .form-row.woocommerce-invalid .slk-select{border:1.5px solid var(--slk-color-error)}
/* `.description` is WooCommerce\'s HINT text, not its error text — it carries
   "This is the number we call to confirm" and "Optional, needed only for card
   payment". Styling it as an error painted every hint on the page in error red,
   so a correctly-filled form read as three failures. Hints are faint; only the
   real validation message (.woocommerce-invalid-message, and .description
   inside a field WooCommerce has marked invalid) is error red. */
.slk-checkout .form-row .woocommerce-input-wrapper .description{
  display:block;padding-top:6px;
  font:400 11.5px/1.55 var(--slk-font-ui);color:var(--slk-color-faint)
}
.slk-checkout .form-row.woocommerce-invalid .woocommerce-input-wrapper .description,
.slk-checkout .form-row .woocommerce-invalid-message{display:flex;gap:var(--slk-space-2);padding-top:var(--slk-space-2);font:400 12px/1.5 var(--slk-font-ui);color:var(--slk-color-error)}
.slk-checkout select.slk-select{appearance:none;background-image:linear-gradient(45deg,transparent 50%,var(--slk-color-muted) 50%),linear-gradient(135deg,var(--slk-color-muted) 50%,transparent 50%);background-position:calc(100% - 20px) center,calc(100% - 15px) center;background-size:5px 5px,5px 5px;background-repeat:no-repeat}

/* ── Account checkbox ("Save my details for next time") + its hint ───────
   Native WooCommerce markup, untouched (woocommerce/checkout/form-billing.php):
   .woocommerce-account-fields > p.create-account > label.woocommerce-form__
   label-for-checkbox > #createaccount, relabelled by the slk-checkout plugin.
   The hint line ("Orders on an account earn points towards credit.") is
   printed by that same plugin as <p class="slk-field__hint slk-account-hint">,
   NOT as a WooCommerce `.description`, so it is matched on .slk-account-hint
   here. The left offset lines it up with the label text beside the checkbox
   rather than with the checkbox box, so it reads as part of the You step
   instead of an afterthought bolted below it. */
.slk-checkout .woocommerce-account-fields{margin-top:var(--slk-space-1)}
.slk-checkout .woocommerce-account-fields p.create-account{margin:0}
.slk-checkout .woocommerce-account-fields label.woocommerce-form__label-for-checkbox{
  display:flex;align-items:flex-start;gap:10px;min-height:var(--slk-touch);
  font:500 12.5px/1.4 var(--slk-font-ui);color:var(--slk-color-ink);cursor:pointer;
}
.slk-checkout .woocommerce-account-fields input.input-checkbox{
  width:20px;height:20px;flex:none;margin-top:2px;accent-color:var(--slk-color-ink);
}
.slk-checkout .woocommerce-account-fields .slk-account-hint{
  display:block;padding-top:0;margin:var(--slk-space-1) 0 0 30px;
  font:400 11.5px/1.5 var(--slk-font-ui);color:var(--slk-color-faint);
}

/* ── Notices as glass toasts (native WC classes, no markup change needed) ── */
.slk-checkout .woocommerce-error,
.slk-checkout .woocommerce-message,
.slk-checkout .woocommerce-info{
  display:flex;flex-direction:column;gap:8px;list-style:none;
  margin:0 0 var(--slk-space-4);padding:14px 16px;
  border-radius:20px;font:400 12.5px/1.45 var(--slk-font-ui);
}
.slk-checkout .woocommerce-error{background:var(--slk-color-error-tint);border:1px solid color-mix(in srgb, var(--slk-color-error) 20%, transparent);color:var(--slk-color-error-ink)}
.slk-checkout .woocommerce-message,
.slk-checkout .woocommerce-info{background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge)}
.slk-checkout .woocommerce-error li,
.slk-checkout .woocommerce-message li{padding:0}

/* ── Payment method cards ─────────────────────────────────────────────── */
.slk-checkout__payment-slot ul.payment_methods,
#order_review ul.payment_methods{list-style:none;margin:0;padding:0;display:grid;gap:var(--slk-space-2)}
.slk-checkout__payment-slot li.wc_payment_method,
#order_review li.wc_payment_method{
  position:relative;
  border:1px solid var(--slk-field-border);border-radius:var(--slk-radius-field);
  padding:var(--slk-space-4);background:var(--slk-glass-solid);
  transition:background var(--slk-motion-base) var(--slk-ease),border-color var(--slk-motion-base) var(--slk-ease);
}
.slk-checkout__payment-slot li.wc_payment_method:has(input:checked),
#order_review li.wc_payment_method:has(input:checked){border:2px solid var(--slk-color-ink);background:var(--slk-color-white)}
.slk-checkout__payment-slot li.wc_payment_method label,
#order_review li.wc_payment_method label{display:flex;align-items:center;gap:var(--slk-space-3);font:500 13.5px/1.3 var(--slk-font-ui);cursor:pointer;min-height:var(--slk-touch);margin:0}
/* Click-to-select fix: measured live, the card <li> is ~198x228px but its
   <label> is only ~164x44px, so most of the card was dead space and a
   shopper\'s first click usually missed. The label\'s ::after is stretched
   over the whole card (the li above already carries position:relative, so
   inset:0 spans the full card) so a click anywhere on it checks the radio.
   The radio and .payment_box below get a higher z-index so the payment
   description\'s own fields/links stay clickable above the overlay.
   WooCommerce\'s markup and change events are untouched.
   Scoped to both locations this file\'s JS can leave the payment list in
   (moved into #slk-payment-slot, or left in #order_review if JS is off),
   matching every other rule in this section. */
.slk-checkout__payment-slot li.wc_payment_method > label::after,
#order_review li.wc_payment_method > label::after{
  content:"";position:absolute;inset:0;z-index:1;cursor:pointer;
}
.slk-checkout__payment-slot li.wc_payment_method input[type=radio],
#order_review li.wc_payment_method input[type=radio]{width:20px;height:20px;accent-color:var(--slk-color-ink);flex:none;position:relative;z-index:2}
.slk-checkout__payment-slot .payment_box,
#order_review .payment_box{margin-top:var(--slk-space-2);padding:var(--slk-space-3) 0 0 32px;font:400 12px/1.6 var(--slk-font-ui);color:var(--slk-color-muted);position:relative;z-index:2}
.slk-checkout__payment-slot .payment_box p,
#order_review .payment_box p{margin:0 0 8px}

/* ── Order review table (itemised list, subtotal, delivery, COD, total) ── */
#order_review table.shop_table{width:100%;border-collapse:collapse;margin:0 0 var(--slk-space-4)}
#order_review table.shop_table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
#order_review table.shop_table tbody,
#order_review table.shop_table tfoot{display:block}
#order_review table.shop_table tr{display:flex;justify-content:space-between;align-items:baseline;gap:var(--slk-space-3);padding:8px 0;border-bottom:1px solid var(--slk-hairline)}
#order_review table.shop_table tr:last-child{border-bottom:0}
#order_review table.shop_table .product-name{flex:1;font:400 13px/1.5 var(--slk-font-ui);color:var(--slk-color-ink-soft)}
#order_review table.shop_table .product-name .product-quantity{color:var(--slk-color-faint)}
#order_review table.shop_table .product-total,
#order_review table.shop_table td[data-title]{font:500 13px/1.5 var(--slk-font-ui);color:var(--slk-color-ink);white-space:nowrap}
#order_review table.shop_table .order-total{border-top:1px solid var(--slk-hairline);margin-top:6px;padding-top:14px}
#order_review table.shop_table .order-total th,
#order_review table.shop_table .order-total td{font:500 16px/1 var(--slk-font-ui)}

/* ── Place order button — stays visible when disabled, never hidden ──── */
#order_review .form-row.place-order{margin-top:var(--slk-space-2)}
#place_order{
  width:100%;display:inline-flex;align-items:center;justify-content:center;gap:var(--slk-space-2);
  min-height:54px;border:0;border-radius:var(--slk-radius-pill);
  background:var(--slk-color-ink);color:var(--slk-color-on-ink);
  font:500 14px/1 var(--slk-font-ui);cursor:pointer;
  transition:transform var(--slk-motion-base) var(--slk-ease),box-shadow var(--slk-motion-base) var(--slk-ease);
}
#place_order:hover{transform:translateY(-2px);box-shadow:var(--slk-shadow-press)}
#place_order:active{transform:scale(.98)}
#place_order:disabled{opacity:.35;cursor:not-allowed;transform:none;box-shadow:none}
.slk-checkout .blockUI.blockOverlay{background:rgba(250,249,246,.55) !important;backdrop-filter:blur(4px)}
.woocommerce-terms-and-conditions-wrapper{font:400 12px/1.6 var(--slk-font-ui);color:var(--slk-color-muted);margin-bottom:var(--slk-space-3)}

/* ── Order-received screen ───────────────────────────────────────────── */
.slk-order-received{max-width:640px;margin:0 auto;padding:var(--slk-space-8) var(--slk-space-4) var(--slk-space-12);display:grid;gap:var(--slk-space-4)}
.slk-order-received__hero{text-align:center;padding:var(--slk-space-4) 0 0}
.slk-order-received__tick{width:64px;height:64px;border-radius:50%;background:var(--slk-color-ink);color:var(--slk-color-on-ink);display:grid;place-items:center;font-size:24px;margin:0 auto var(--slk-space-4);box-shadow:var(--slk-shadow-float)}
.slk-order-received__title{font-family:var(--slk-font-display);font-weight:300;font-size:var(--slk-display-s);line-height:var(--slk-leading-tight);margin:0 0 10px}
.slk-order-received__sub{font:400 13.5px/1.65 var(--slk-font-ui);color:var(--slk-color-muted);max-width:34ch;margin:0 auto}
.slk-order-received .woocommerce-thankyou-order-received:empty{display:none}
.slk-order-received__timeline{padding:var(--slk-space-6);display:grid;gap:var(--slk-space-4)}
.slk-step__label{font:500 13px/1.4 var(--slk-font-ui)}
.slk-step__desc{font:400 12px/1.55 var(--slk-font-ui);padding-top:2px}
.slk-order-received__overview{padding:var(--slk-space-4) var(--slk-space-6);list-style:none;margin:0;display:grid;gap:6px;font:400 12.5px/1.6 var(--slk-font-ui);color:var(--slk-color-muted)}
.slk-order-received__overview li{display:flex;justify-content:space-between;gap:var(--slk-space-3)}
.slk-order-received__overview strong{color:var(--slk-color-ink);font-weight:500}
.slk-order-received__actions{display:grid;gap:var(--slk-space-3)}
.slk-order-received__whatsapp{flex-direction:column;align-items:flex-start;text-align:left;min-height:56px;padding:12px 20px;gap:2px}
.slk-order-received__whatsapp-sub{display:block;font:400 11.5px/1.3 var(--slk-font-ui);opacity:.7}
.slk-order-received__back{justify-self:center}
';
}

/**
 * The checkout page JS: moves the native payment-method list from inside
 * #order_review / #payment into the "3 · Paying" panel, and re-runs on
 * WooCommerce's own `updated_checkout` event so it survives AJAX refreshes
 * (address/coupon changes). Presentation only — WooCommerce's own submit
 * handling stays attached to #place_order regardless of where it sits in
 * the DOM, since it is still inside the same <form>.
 *
 * Progressive enhancement: with JS disabled the payment methods simply stay
 * inside the order-summary panel where WooCommerce put them.
 *
 * @return string
 */
function slk_checkout_view_js() {
	return "
(function(){
	function slkArrangeCheckout(){
		var slot = document.getElementById('slk-payment-slot');
		var payment = document.getElementById('payment');
		if (!slot || !payment) { return; }
		var methods = payment.querySelector('ul.payment_methods');
		if (!methods) { return; }
		// WC_AJAX::update_order_review returns the whole
		// .woocommerce-checkout-payment fragment, so every AJAX refresh
		// rebuilds #payment with a fresh ul.payment_methods while the one we
		// moved earlier is still sitting in the slot. Drop the stale copy
		// before adopting the new one — two lists would mean duplicate
		// element ids and two radio groups sharing name=\"payment_method\".
		Array.prototype.forEach.call(slot.querySelectorAll('ul.payment_methods'), function (stale) {
			if (stale !== methods) { stale.parentNode.removeChild(stale); }
		});
		if (methods.parentElement !== slot) {
			slot.appendChild(methods);
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', slkArrangeCheckout);
	} else {
		slkArrangeCheckout();
	}
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout', slkArrangeCheckout);
	}
})();
";
}
