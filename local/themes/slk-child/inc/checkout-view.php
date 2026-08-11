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

		// slk-child is already enqueued by functions.php; hang inline CSS/JS
		// off that handle so load order and cache-busting stay correct.
		wp_add_inline_style( 'slk-child', slk_checkout_view_css() );

		if ( ! is_order_received_page() ) {
			wp_add_inline_script( 'slk-child', slk_checkout_view_js() );
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

/* ── Layout ───────────────────────────────────────────────────────────── */
.slk-checkout{max-width:1140px;margin:0 auto;padding:0 var(--slk-space-4) var(--slk-space-12)}
.slk-checkout__grid{display:grid;gap:var(--slk-space-4)}
.slk-checkout__fields{display:grid;gap:var(--slk-space-4)}
.slk-checkout__panel{padding:var(--slk-space-6)}
.slk-checkout__panel-label{margin-bottom:var(--slk-space-4)}
.slk-checkout__aside{display:grid;gap:var(--slk-space-3);align-content:start}
.slk-checkout__next{font:400 11.5px/1.6 var(--slk-font-ui);text-align:center;padding-top:2px}
.slk-order-summary{padding:var(--slk-space-6)}
@media (min-width:960px){
  .slk-checkout__grid{grid-template-columns:1.5fr 1fr;align-items:start;gap:var(--slk-space-8)}
  .slk-checkout__fields{grid-template-columns:1fr}
  .slk-checkout__aside{position:sticky;top:var(--slk-space-6)}
}

/* ── Fields (native WooCommerce markup, decorated via woocommerce_form_field_args) ── */
.slk-checkout .form-row{margin:0 0 var(--slk-space-4)}
.slk-checkout .form-row label{display:block;font:500 12px/1 var(--slk-font-ui);margin-bottom:7px;color:var(--slk-color-ink)}
.slk-checkout .form-row .required{color:var(--slk-color-error);text-decoration:none}
.slk-checkout .form-row .optional{color:var(--slk-color-faint);font-weight:400}
.slk-checkout .form-row.woocommerce-invalid label{color:var(--slk-color-error)}
.slk-checkout .form-row.woocommerce-invalid .slk-input,
.slk-checkout .form-row.woocommerce-invalid .slk-select{border:1.5px solid var(--slk-color-error)}
.slk-checkout .form-row .woocommerce-input-wrapper .description,
.slk-checkout .form-row .woocommerce-invalid-message{display:flex;gap:var(--slk-space-2);padding-top:var(--slk-space-2);font:400 12px/1.5 var(--slk-font-ui);color:var(--slk-color-error)}
.slk-checkout select.slk-select{appearance:none;background-image:linear-gradient(45deg,transparent 50%,var(--slk-color-muted) 50%),linear-gradient(135deg,var(--slk-color-muted) 50%,transparent 50%);background-position:calc(100% - 20px) center,calc(100% - 15px) center;background-size:5px 5px,5px 5px;background-repeat:no-repeat}

/* ── Notices as glass toasts (native WC classes, no markup change needed) ── */
.slk-checkout .woocommerce-error,
.slk-checkout .woocommerce-message,
.slk-checkout .woocommerce-info{
  display:flex;flex-direction:column;gap:8px;list-style:none;
  margin:0 0 var(--slk-space-4);padding:14px 16px;
  border-radius:20px;font:400 12.5px/1.45 var(--slk-font-ui);
}
.slk-checkout .woocommerce-error{background:var(--slk-color-error-tint);border:1px solid rgba(154,40,32,.2);color:#7a1f19}
.slk-checkout .woocommerce-message,
.slk-checkout .woocommerce-info{background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge)}
.slk-checkout .woocommerce-error li,
.slk-checkout .woocommerce-message li{padding:0}

/* ── Payment method cards ─────────────────────────────────────────────── */
.slk-checkout__payment-slot ul.payment_methods,
#order_review ul.payment_methods{list-style:none;margin:0;padding:0;display:grid;gap:var(--slk-space-2)}
.slk-checkout__payment-slot li.wc_payment_method,
#order_review li.wc_payment_method{
  border:1px solid var(--slk-field-border);border-radius:var(--slk-radius-field);
  padding:var(--slk-space-4);background:var(--slk-glass-solid);
  transition:background var(--slk-motion-base) var(--slk-ease),border-color var(--slk-motion-base) var(--slk-ease);
}
.slk-checkout__payment-slot li.wc_payment_method:has(input:checked),
#order_review li.wc_payment_method:has(input:checked){border:2px solid var(--slk-color-ink);background:#fff}
.slk-checkout__payment-slot li.wc_payment_method label,
#order_review li.wc_payment_method label{display:flex;align-items:center;gap:var(--slk-space-3);font:500 13.5px/1.3 var(--slk-font-ui);cursor:pointer;min-height:var(--slk-touch);margin:0}
.slk-checkout__payment-slot li.wc_payment_method input[type=radio],
#order_review li.wc_payment_method input[type=radio]{width:20px;height:20px;accent-color:var(--slk-color-ink);flex:none}
.slk-checkout__payment-slot .payment_box,
#order_review .payment_box{margin-top:var(--slk-space-2);padding:var(--slk-space-3) 0 0 32px;font:400 12px/1.6 var(--slk-font-ui);color:var(--slk-color-muted)}
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
		if (methods && methods.parentElement !== slot) {
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
