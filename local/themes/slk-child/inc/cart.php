<?php
/**
 * Cart — Porcelain Glass styling, stepper behaviour, and the empty-cart
 * screen (design/sections/04-pages.html "EMPTY CART").
 *
 * Owns: woocommerce/cart/cart.php (this theme's override, see that file) and
 * everything needed to restyle the DEFAULT cart/cart-totals.php and
 * cart/cart-empty.php markup — neither of which this theme overrides,
 * per file ownership §7/§8. Both are shaped with hooks + CSS instead:
 *
 *   - cart-totals.php stays the stock WooCommerce template; its existing
 *     classes (.cart_totals, .cart-subtotal, .shipping, .order-total,
 *     .wc-proceed-to-checkout) are restyled into the sticky totals panel
 *     from design/sections/07-desktop.html (#d-cart) /
 *     design/sections/06-mobile.html (#s-cart).
 *   - cart-empty.php stays the stock WooCommerce template too. Its single
 *     hook, `woocommerce_cart_is_empty` (default: wc_empty_cart_message),
 *     is swapped for slk_cart_empty_content() below, which renders the full
 *     empty-state design (icon, heading, copy, CTA, "you looked at these"
 *     rail, WhatsApp prompt). The template's own trailing "Return to shop"
 *     paragraph is left in place (its hook/filters are untouched, so any
 *     plugin relying on `woocommerce_return_to_shop_redirect` /
 *     `_text` still works) but is visually hidden by CSS: our CTA already
 *     goes to the same shop URL, so a second visible link would just be
 *     noise directly under it.
 *
 * Text domain: slk.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 0. WhatsApp contact link — single source, like slk_wordmark.
 *
 * No number has been assigned yet. Default is empty on purpose: the empty
 * cart's WhatsApp prompt only renders once a real number is filtered in
 * (`add_filter( 'slk_whatsapp_number', fn() => '94771234567' );`), so this
 * theme never ships a dead/placeholder contact link.
 * ---------------------------------------------------------------------- */

/**
 * The WhatsApp number to message, digits only with country code (no "+").
 *
 * @return string
 */
function slk_whatsapp_number() {
	return (string) apply_filters( 'slk_whatsapp_number', '' );
}

/**
 * A wa.me link for a given message, or '' if no number is configured.
 *
 * @param string $message Prefilled message text.
 * @return string Escaped-ready URL, or ''.
 */
function slk_whatsapp_url( $message = '' ) {
	$number = slk_whatsapp_number();

	if ( '' === $number ) {
		return '';
	}

	return add_query_arg( 'text', rawurlencode( $message ), 'https://wa.me/' . $number );
}

/* -------------------------------------------------------------------------
 * 0b. Kill Blocksy's own +/- stepper on the cart quantity field.
 *
 * Blocksy prints its own <span class="ct-increase">/<span class="ct-decrease">
 * pair before every woocommerce_quantity_input() (inc/components/woocommerce/
 * general.php, hooked to woocommerce_before_quantity_input_field), gated by
 * the 'has_custom_quantity' theme mod (default 'yes'). Measured on /cart/
 * with product 18 in the bag: the quantity wrapper renders
 * data-type="type-2" with the two ct-* spans INSIDE it, alongside this
 * template's own .slk-qty__btn pill buttons — two stepper controls stacked,
 * same root cause as the PDP. blocksy_get_theme_mod() reads through
 * Blocksy\Database::get_theme_mod(), which runs every value through
 * apply_filters( "theme_mod_{$name}", $value ) — the documented WP core
 * filter point — so short-circuiting it here removes Blocksy's spans at the
 * source instead of fighting them with CSS. Scoped to is_cart() only: the
 * PDP stepper is out of this file's ownership.
 * ---------------------------------------------------------------------- */

add_filter(
	'theme_mod_has_custom_quantity',
	static function ( $value ) {
		return ( function_exists( 'is_cart' ) && is_cart() ) ? false : $value;
	}
);

/*
 * Remove Blocksy's hero band from the cart at the source — the cart template
 * titles itself ("Your bag"), so the band's "Cart" was a second h1 in the
 * served HTML even while display:none'd. Verify, D3.
 */
add_filter(
	'blocksy:single:has-default-hero',
	static function ( $has ) {
		return ( function_exists( 'is_cart' ) && is_cart() ) ? false : $has;
	}
);

/* -------------------------------------------------------------------------
 * 0c. No discounts culture — no coupon UI, anywhere.
 *
 * design/sections/07-desktop.html #d-cart has no "Have a coupon?" row: this
 * brand runs no discount codes (see hard brand rule on 1-of-1 pieces, and
 * the store-wide "no sale theatre" law). `woocommerce_coupons_enabled` is
 * the one documented filter both wc_coupons_enabled() call sites read —
 * woocommerce/templates/cart/cart.php (the coupon form this theme's
 * cart.php override still guards with `if ( wc_coupons_enabled() )`) and
 * woocommerce/templates/checkout/form-coupon.php — so a single switch here
 * removes the row from both cart and checkout without touching either
 * template's markup.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_coupons_enabled', '__return_false' );

/* -------------------------------------------------------------------------
 * 0d. "Delivery" / "Delivery — <district>", not "Shipment".
 *
 * Measured: WC_Cart::get_shipping_package_name() (includes/class-wc-cart.php)
 * hardcodes `_x( 'Shipment', 'shipping packages', 'woocommerce' )` as the <th>
 * of the shipping row's real markup (cart/cart-shipping.php) whenever the
 * cart has exactly one shipping package — which is every case here, this
 * store never splits an order into multiple packages. That text is not a
 * Blocksy or slk-checkout string; it is core WooCommerce's own default and
 * the one documented filter point for it is `woocommerce_shipping_package_name`.
 * District is billing_state (see slk-checkout's class-slk-checkout-fields.php
 * and class-slk-shipping.php — shipping is billing-only per the filter in
 * inc/checkout-view.php, and SL states/districts share the same value, e.g.
 * option value="Colombo" label="Colombo" — no code-to-label lookup needed).
 * Before checkout, WC()->customer only carries a billing_state if a logged-in
 * customer's account/last order already set one; otherwise it is '' and the
 * plain "Delivery" label is used, per design/sections/07-desktop.html #d-cart.
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_shipping_package_name',
	static function ( $name ) {
		$district = WC()->customer ? WC()->customer->get_billing_state() : '';

		if ( '' === $district ) {
			return __( 'Delivery', 'slk' );
		}

		/* translators: %s: district name. */
		return sprintf( __( 'Delivery — %s', 'slk' ), $district );
	},
	10,
	1
);

/* -------------------------------------------------------------------------
 * 1. Empty cart — "you looked at these" (design/sections/04-pages.html)
 *
 * Reads WooCommerce's own `woocommerce_recently_viewed` cookie (set by core
 * in WC_Template_Loader / wc_track_product_view — no extra tracking added
 * here). Falls back to featured products so the rail never renders empty
 * on a first-ever visit.
 * ---------------------------------------------------------------------- */

/**
 * Up to $limit products to show in the empty-cart "you looked at these" rail.
 *
 * @param int $limit Max products.
 * @return WC_Product[]
 */
function slk_cart_empty_related_products( $limit = 2 ) {
	$product_ids = array();

	if ( ! empty( $_COOKIE['woocommerce_recently_viewed'] ) ) {
		$product_ids = wp_parse_id_list( (array) explode( '|', wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, WooCommerce-owned cookie.
		$product_ids = array_reverse( $product_ids ); // Most-recently-viewed first.
	}

	$products = array();

	foreach ( $product_ids as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product && $product->exists() && $product->is_visible() ) {
			$products[] = $product;
		}

		if ( count( $products ) >= $limit ) {
			return $products;
		}
	}

	if ( count( $products ) < $limit ) {
		$fallback_ids = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $limit - count( $products ),
				'orderby' => 'popularity',
				'return'  => 'ids',
				'exclude' => array_map(
					static function ( $product ) {
						return $product->get_id();
					},
					$products
				),
			)
		);

		foreach ( $fallback_ids as $fallback_id ) {
			$product = wc_get_product( $fallback_id );

			if ( $product && $product->exists() ) {
				$products[] = $product;
			}
		}
	}

	return $products;
}

/**
 * Render the empty-cart screen content in place of the default
 * wc_empty_cart_message().
 */
function slk_cart_empty_content() {
	$shop_url = wc_get_page_permalink( 'shop' );
	?>
	<div class="slk-cart-empty">
		<div class="slk-cart-empty__icon" aria-hidden="true">&#9671;</div>
		<h1 class="slk-cart-empty__title"><?php esc_html_e( 'Nothing in your bag yet.', 'slk' ); ?></h1>
		<p class="slk-cart-empty__copy">
			<?php esc_html_e( 'Nothing is charged until a person confirms your order.', 'slk' ); ?>
		</p>
		<?php if ( $shop_url ) : ?>
			<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( $shop_url ); ?>">
				<?php esc_html_e( "See what's new", 'slk' ); ?>
			</a>
		<?php endif; ?>
	</div>
	<?php

	$related = slk_cart_empty_related_products( 2 );

	if ( ! empty( $related ) ) {
		?>
		<div class="slk-cart-empty__related">
			<div class="slk-eyebrow slk-cart-empty__related-label"><?php esc_html_e( 'You looked at these', 'slk' ); ?></div>
			<div class="slk-cart-empty__rail">
				<?php foreach ( $related as $product ) : ?>
					<a class="slk-card slk-cart-empty__card" href="<?php echo esc_url( $product->get_permalink() ); ?>">
						<span class="slk-card__media">
							<?php echo $product->get_image( 'woocommerce_thumbnail' ); // PHPCS: XSS ok, WooCommerce-escaped. ?>
						</span>
						<span class="slk-card__name"><?php echo esc_html( $product->get_name() ); ?></span>
						<span class="slk-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	$whatsapp_url = slk_whatsapp_url( __( "Hi! I'm looking for something specific.", 'slk' ) );

	if ( $whatsapp_url ) {
		?>
		<a class="slk-panel slk-cart-empty__whatsapp" href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener noreferrer">
			<span class="slk-cart-empty__whatsapp-text">
				<?php esc_html_e( 'Looking for something specific?', 'slk' ); ?>
				<span class="slk-cart-empty__whatsapp-sub"><?php esc_html_e( "Ask on WhatsApp — we'll send options", 'slk' ); ?></span>
			</span>
			<span class="slk-cart-empty__whatsapp-badge" aria-hidden="true">W</span>
		</a>
		<?php
	}
}

add_action(
	'init',
	static function () {
		remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', 10 );
		add_action( 'woocommerce_cart_is_empty', 'slk_cart_empty_content', 10 );
	},
	20
);

/* -------------------------------------------------------------------------
 * 2. Styles — cart line items, totals collateral, empty state, coupon.
 *
 * Only enqueued on the cart page. Tokens only (design-tokens.css, already
 * loaded on the 'slk-child' handle by functions.php); no raw hex.
 *
 * Desktop layout: ONE grid at the single 1000px breakpoint. This block used to
 * lay the cart out with display:inline-block + width:63%/37% at min-width:900px,
 * nested inside the grid style.css §3.10 establishes on the same wrapper at
 * 1000px. Grid blockified the two children but the percentage widths survived,
 * so at >=1000px each column rendered at 63%/37% OF ITS OWN TRACK, leaving two
 * dead gutters — and the page changed shape 100px early. The wrapper is the
 * WooCommerce container holding form.woocommerce-cart-form.slk-cart and
 * div.cart-collaterals.slk-cart-collaterals as siblings (see
 * woocommerce/cart/cart.php); widths now come from the tracks and the children
 * just fill them. Measured in the browser at 1280px: 681.84 + 32 + 426.16 =
 * 1140, exactly the wrapper width, ratio 1.6:1 — no gutter left over.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		$css = '
/* -- line items -------------------------------------------------------- */
.slk-cart{max-width:1140px;margin:0 auto;padding:var(--slk-space-6) var(--slk-space-4) 0}
.slk-cart__head{display:flex;align-items:baseline;gap:var(--slk-space-3);margin-bottom:var(--slk-space-4)}
.slk-cart__title{font-size:var(--slk-display-s);margin:0}
.slk-cart__count{font-family:var(--slk-font-ui);font-size:var(--slk-text-sm);color:var(--slk-color-faint);font-weight:400}
.slk-cart-list{list-style:none;margin:0 0 var(--slk-space-4);padding:0;display:grid;gap:var(--slk-space-3)}
.slk-cart-item{display:flex;gap:var(--slk-space-4);padding:var(--slk-space-3);background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);border-radius:var(--slk-radius-card)}
.slk-cart-item__media{width:96px;aspect-ratio:var(--slk-ratio-portrait);flex:none;border-radius:var(--slk-radius-tile);overflow:hidden}
.slk-cart-item__media img{width:100%;height:100%;object-fit:cover;display:block}
.slk-cart-item__body{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:space-between;gap:var(--slk-space-3)}
.slk-cart-item__top{display:flex;justify-content:space-between;gap:var(--slk-space-3)}
.slk-cart-item__name{font:500 var(--slk-text-base)/1.35 var(--slk-font-ui)}
.slk-cart-item__name a{color:inherit;text-decoration:none}
.slk-cart-item__meta{font:400 var(--slk-text-sm)/1.6 var(--slk-font-ui);color:var(--slk-color-muted);padding-top:3px}
.slk-cart-item__meta p{margin:0}
.slk-cart-item__price{font:500 var(--slk-text-base)/1.35 var(--slk-font-ui);white-space:nowrap}
.slk-cart-item__footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:var(--slk-space-3)}
.slk-cart-item__subtotal{margin-left:auto;font:500 var(--slk-text-base)/1 var(--slk-font-ui)}

/* -- quantity stepper — real input, styled as a pill ------------------- */
.slk-qty{display:flex;align-items:center;gap:2px;background:rgba(35,34,32,.05);border-radius:var(--slk-radius-pill);padding:2px}
.slk-qty__btn{width:var(--slk-touch);height:var(--slk-touch);border:0;background:none;border-radius:50%;font-size:15px;line-height:1;cursor:pointer;color:var(--slk-color-ink);transition:background var(--slk-motion-base) var(--slk-ease)}
.slk-qty__btn:hover:not(:disabled){background:var(--slk-color-white)}
.slk-qty__btn:disabled{opacity:.3;cursor:not-allowed}
.slk-qty .quantity{display:inline-flex;margin:0}
.slk-qty__input{width:34px;min-height:36px;border:0;background:none;text-align:center;font:500 13px var(--slk-font-ui);color:var(--slk-color-ink);-moz-appearance:textfield}
.slk-qty__input::-webkit-outer-spin-button,.slk-qty__input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.slk-cart-item__remove{font:400 var(--slk-text-sm)/1 var(--slk-font-ui);color:var(--slk-color-faint);text-decoration:underline;text-underline-offset:3px;min-height:var(--slk-touch);display:inline-flex;align-items:center;padding:0 var(--slk-space-2)}

/* -- continue / actions -------------------------------------------------*/
.slk-cart-continue{display:inline-flex;align-items:center;min-height:var(--slk-touch);font:400 var(--slk-text-sm)/1 var(--slk-font-ui);color:var(--slk-color-muted);text-decoration:none}
.slk-cart-actions{display:flex;flex-wrap:wrap;align-items:flex-start;gap:var(--slk-space-3);padding:var(--slk-space-4) 0}
.slk-cart-update{margin-left:auto}

/* -- collaterals / totals panel (default cart-totals.php markup) ------- */
.slk-cart-collaterals{max-width:1140px;margin:0 auto;padding:0 var(--slk-space-4) var(--slk-space-12)}
/* Desktop cart: one grid, at the one breakpoint the design has. */
@media (min-width:1000px){
  .woocommerce-cart .site-main .woocommerce,
  .woocommerce-cart .entry-content > .woocommerce{
    display:grid;
    grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);
    column-gap:var(--slk-space-8);
    align-items:start;
  }
  .woocommerce-cart .woocommerce > *{grid-column:1 / -1}
  .woocommerce-cart .woocommerce > form.woocommerce-cart-form{grid-column:1;grid-row:2}
  .woocommerce-cart .woocommerce > .cart-collaterals{grid-column:2;grid-row:2}
  .slk-cart{width:auto;max-width:none;margin:0;padding-right:0}
  .slk-cart-collaterals{
    width:auto;max-width:none;margin:0;padding-left:0;padding-right:0;
    position:sticky;top:var(--slk-space-6);
  }
}
.cart_totals{background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);border-radius:var(--slk-radius-card);padding:var(--slk-space-6);box-shadow:var(--slk-shadow-lift)}
.cart_totals h2{font-size:var(--slk-text-lg);margin:0 0 var(--slk-space-3)}
.cart_totals table.shop_table{width:100%;border-collapse:collapse}
.cart_totals table.shop_table tr{display:flex;justify-content:space-between;gap:var(--slk-space-3)}
.cart_totals table.shop_table th,.cart_totals table.shop_table td{border:0;padding:7px 0;font:400 var(--slk-text-sm)/1.5 var(--slk-font-ui);color:var(--slk-color-muted);text-align:right}
.cart_totals table.shop_table th{text-align:left;font-weight:400}
.cart_totals table.shop_table td{color:var(--slk-color-ink)}
.cart_totals tr.order-total{border-top:1px solid var(--slk-hairline);margin-top:var(--slk-space-2);padding-top:var(--slk-space-2)}
.cart_totals tr.order-total th,.cart_totals tr.order-total td{font:500 var(--slk-text-xl)/1.5 var(--slk-font-ui);color:var(--slk-color-ink)}
.cart_totals .woocommerce-shipping-methods{list-style:none;margin:0;padding:0;text-align:right}
.wc-proceed-to-checkout{margin-top:var(--slk-space-4)}
.wc-proceed-to-checkout a.checkout-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:52px;background:var(--slk-color-ink);color:var(--slk-color-on-ink);border-radius:var(--slk-radius-pill);font:500 var(--slk-text-base)/1 var(--slk-font-ui);text-decoration:none;transition:transform var(--slk-motion-base) var(--slk-ease),box-shadow var(--slk-motion-base) var(--slk-ease)}
.wc-proceed-to-checkout a.checkout-button:hover{transform:translateY(-2px);box-shadow:var(--slk-shadow-press)}

/* -- empty cart ---------------------------------------------------------*/
.slk-cart-empty{max-width:420px;margin:0 auto;padding:var(--slk-space-12) var(--slk-space-4) 0;text-align:center}
.slk-cart-empty__icon{width:66px;height:66px;border-radius:50%;background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);display:grid;place-items:center;margin:0 auto var(--slk-space-4);box-shadow:var(--slk-shadow-lift);font-size:22px;color:var(--slk-color-faint)}
.slk-cart-empty__title{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-2)}
.slk-cart-empty__copy{font:400 var(--slk-text-base)/1.65 var(--slk-font-ui);color:var(--slk-color-muted);max-width:32ch;margin:0 auto var(--slk-space-4)}
.slk-cart-empty__related{max-width:600px;margin:var(--slk-space-8) auto 0;padding:0 var(--slk-space-4)}
.slk-cart-empty__related-label{margin-bottom:var(--slk-space-3)}
.slk-cart-empty__rail{display:flex;gap:var(--slk-space-3);overflow-x:auto;padding-bottom:var(--slk-space-2)}
.slk-cart-empty__card{flex:none;width:150px}
.slk-cart-empty__whatsapp{max-width:600px;margin:var(--slk-space-6) auto 0;display:flex;align-items:center;gap:var(--slk-space-3);padding:var(--slk-space-2) var(--slk-space-3) var(--slk-space-2) var(--slk-space-4);text-decoration:none;color:inherit}
.slk-cart-empty__whatsapp-text{flex:1;font:500 var(--slk-text-sm)/1.35 var(--slk-font-ui)}
.slk-cart-empty__whatsapp-sub{display:block;font-weight:400;color:var(--slk-color-muted);font-size:var(--slk-text-xs)}
.slk-cart-empty__whatsapp-badge{width:38px;height:38px;border-radius:50%;background:var(--slk-color-ink);color:var(--slk-color-on-ink);display:grid;place-items:center;font:600 12px var(--slk-font-ui);flex:none}
/* The default cart-empty.php "Return to shop" link is redundant with our
   own CTA above (same URL) — hidden rather than duplicated. Its hook and
   filters still run; only the rendered link is visually removed. */
.woocommerce-cart .return-to-shop{display:none}
		';

		wp_add_inline_style( 'slk-child', $css );
	},
	30
);

/* -------------------------------------------------------------------------
 * 3. Behaviour — stepper buttons drive the real quantity <input>.
 *
 * Progressive enhancement only: the input stays a real, labelled, keyboard-
 * and screen-reader-usable field (from woocommerce_quantity_input()); the
 * −/+ buttons and the auto-submit are pure enhancement. Without JS the
 * input and the visible "Update cart" button still work.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		wp_register_script( 'slk-cart', false, array(), '1.0.0', true );
		wp_enqueue_script( 'slk-cart' );

		$js = <<<'JS'
(function () {
	"use strict";

	function onStepClick( event ) {
		var button = event.target.closest( '[data-slk-qty-step]' );
		if ( ! button ) {
			return;
		}

		var wrap  = button.closest( '.slk-qty' );
		var input = wrap && wrap.querySelector( 'input.qty' );
		if ( ! input ) {
			return;
		}

		var step = parseFloat( button.getAttribute( 'data-slk-qty-step' ), 10 );
		var min  = input.min !== '' ? parseFloat( input.min, 10 ) : 0;
		var max  = input.max !== '' ? parseFloat( input.max, 10 ) : Infinity;
		var next = ( parseFloat( input.value, 10 ) || 0 ) + step;

		next = Math.max( min, Math.min( max, next ) );

		if ( next === parseFloat( input.value, 10 ) ) {
			return;
		}

		input.value = next;
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		var form = input.closest( 'form.slk-cart' );
		if ( form && form.requestSubmit ) {
			window.clearTimeout( onStepClick.timer );
			onStepClick.timer = window.setTimeout( function () {
				form.requestSubmit();
			}, 400 );
		}
	}

	document.addEventListener( 'click', onStepClick );
})();
JS;

		wp_add_inline_script( 'slk-cart', $js );
	},
	31
);
