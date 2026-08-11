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
.slk-qty__btn{width:36px;height:36px;border:0;background:none;border-radius:50%;font-size:15px;line-height:1;cursor:pointer;color:var(--slk-color-ink);transition:background var(--slk-motion-base) var(--slk-ease)}
.slk-qty__btn:hover:not(:disabled){background:#fff}
.slk-qty__btn:disabled{opacity:.3;cursor:not-allowed}
.slk-qty .quantity{display:inline-flex;margin:0}
.slk-qty__input{width:34px;min-height:36px;border:0;background:none;text-align:center;font:500 13px var(--slk-font-ui);color:var(--slk-color-ink);-moz-appearance:textfield}
.slk-qty__input::-webkit-outer-spin-button,.slk-qty__input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.slk-cart-item__remove{font:400 var(--slk-text-sm)/1 var(--slk-font-ui);color:var(--slk-color-faint);text-decoration:underline;text-underline-offset:3px;min-height:var(--slk-touch);display:inline-flex;align-items:center;padding:0 var(--slk-space-2)}

/* -- continue / actions -------------------------------------------------*/
.slk-cart-continue{display:inline-flex;align-items:center;min-height:var(--slk-touch);font:400 var(--slk-text-sm)/1 var(--slk-font-ui);color:var(--slk-color-muted);text-decoration:none}
.slk-cart-actions{display:flex;flex-wrap:wrap;align-items:flex-start;gap:var(--slk-space-3);padding:var(--slk-space-4) 0}
.slk-cart-coupon{flex:1 1 260px}
.slk-cart-coupon__toggle{cursor:pointer;font:500 var(--slk-text-sm)/1 var(--slk-font-ui);color:var(--slk-color-muted);min-height:var(--slk-touch);display:flex;align-items:center}
.slk-cart-coupon__row{margin-top:var(--slk-space-2)}
.slk-cart-coupon__inline{display:flex;gap:var(--slk-space-2)}
.slk-cart-coupon__inline .slk-input{flex:1}
.slk-cart-update{margin-left:auto}

/* -- collaterals / totals panel (default cart-totals.php markup) ------- */
.slk-cart-collaterals{max-width:1140px;margin:0 auto;padding:0 var(--slk-space-4) var(--slk-space-12)}
@media (min-width:900px){
  .slk-cart{display:inline-block;width:63%;vertical-align:top;padding-right:var(--slk-space-4)}
  .slk-cart-collaterals{display:inline-block;width:37%;vertical-align:top;position:sticky;top:var(--slk-space-6)}
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
