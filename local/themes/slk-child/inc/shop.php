<?php
/**
 * Product card + shop/archive grid — Porcelain Glass.
 *
 * Restyles the WooCommerce loop (woocommerce/content-product.php in this
 * theme + the untouched core archive-product.php) to the .slk-card component
 * contract in design/assets/components.css. The approach follows the house
 * pattern already set by functions.php §5 (sale-flash removal): default WC
 * loop callbacks are unhooked and replaced with slk_* callbacks on the SAME
 * actions and priorities, so the actions themselves stay open for any other
 * plugin's own callbacks — only the house markup changes.
 *
 * Covers: the garment card (portrait 3:4 tile), the colour-led hijab card
 * (round tile, .slk-card--colour, by product_cat slug 'hijabs'), colour
 * swatches under any product that carries a colour attribute, no sale badge
 * / no strikethrough anywhere in the grid, and the responsive grid shape.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Colour-led card detection
 *
 * A product is colour-led (round swatch card, .slk-card--colour) when it sits
 * in the 'hijabs' product category. This is a category-slug signal only — no
 * coupling to product type, so a hijab sold as a variable or simple product
 * both qualify.
 * ---------------------------------------------------------------------- */

/**
 * @param WC_Product|null $product
 * @return bool
 */
function slk_is_colour_led_product( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return false;
	}

	return has_term( 'hijabs', 'product_cat', $product->get_id() );
}

/* -------------------------------------------------------------------------
 * 2. Colour swatches
 *
 * Reads any global attribute taxonomy on the product whose slug matches
 * "colour"/"color" (pa_colour, pa_color, ...). Each term's swatch value comes
 * from its own term meta key 'slk_swatch_hex' (set once per colour term in
 * Products -> Attributes -> Colour -> edit term). No swatch plugin is assumed
 * to be installed; a term without that meta set is skipped rather than
 * guessed at, so the row degrades to "no swatches" instead of a wrong colour.
 *
 * The hex values themselves are per-product data (the actual dye of that
 * fabric), not a theme/UI colour — brand law 5 (tokens only) governs chrome,
 * not photographed/dyed product colour, the same way product photography is
 * exempted as "the only colour on the page" (brand-guidelines.md §3).
 * ---------------------------------------------------------------------- */

/**
 * @param WC_Product|null $product
 * @return array<int,array{name:string,hex:string}>
 */
function slk_get_product_colour_swatches( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return array();
	}

	$taxonomy = null;

	foreach ( array_keys( $product->get_attributes() ) as $key ) {
		if ( 0 === strpos( $key, 'pa_' ) && preg_match( '/colou?r/i', $key ) ) {
			$taxonomy = $key;
			break;
		}
	}

	if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}

	$terms = wc_get_product_terms( $product->get_id(), $taxonomy, array( 'fields' => 'all' ) );

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		return array();
	}

	$swatches = array();

	foreach ( $terms as $term ) {
		$hex = get_term_meta( $term->term_id, 'slk_swatch_hex', true );

		if ( ! $hex ) {
			continue; // No stored swatch colour for this term — skip, don't guess.
		}

		$swatches[] = array(
			'name' => $term->name,
			'hex'  => $hex,
		);
	}

	return $swatches;
}

/* -------------------------------------------------------------------------
 * 3. Card callbacks — replace the default WC loop output
 * ---------------------------------------------------------------------- */

/**
 * Replaces woocommerce_template_loop_product_link_open().
 * Opens the whole-card link: media, name, price and swatches all live inside
 * it, matching every product-card screen in 06-mobile.html / 07-desktop.html.
 */
function slk_template_loop_product_link_open() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$classes = array( 'slk-card' );

	if ( slk_is_colour_led_product( $product ) ) {
		$classes[] = 'slk-card--colour';
	}

	printf(
		'<a href="%1$s" class="%2$s">',
		esc_url( get_permalink( $product->get_id() ) ),
		esc_attr( implode( ' ', $classes ) )
	);
}

/**
 * Replaces woocommerce_template_loop_product_thumbnail().
 * Requests 'slk_card' (600x800, a real 3:4 crop registered in functions.php)
 * — not WooCommerce's default 'woocommerce_thumbnail' square, which used to
 * be cropped a second time by the portrait tile's object-fit:cover and
 * upscaled on top of that, with srcset topping out at 300w. Real product
 * image via core WC accessors (width/height always declared by the
 * registered image size, so nothing shifts). Falls back to
 * wc_placeholder_img() when the product has no image — the shoot hasn't
 * happened yet for most SKUs.
 */
function slk_template_loop_product_thumbnail() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	echo '<div class="slk-card__media">';

	if ( $product->get_image_id() ) {
		echo wp_kses_post( $product->get_image( 'slk_card' ) );
	} else {
		echo wp_kses_post( wc_placeholder_img( 'slk_card' ) );
	}

	echo '</div>';
}

/**
 * Replaces woocommerce_template_loop_product_title().
 * Kept as a real heading (not a styled span) so the grid still has a sane
 * accessibility outline; visual weight comes entirely from .slk-card__name.
 * h3, not h2: every card sits under a section/rail heading that introduces it
 * (home rails, the archive h1, the PDP related rail), so h2 made the garment
 * names peers of "Ready to wear" in the outline. Filterable for any context
 * that legitimately needs another level; the value is whitelisted because it
 * lands in markup unescaped.
 */
function slk_template_loop_product_title() {
	$tag = apply_filters( 'slk_loop_title_tag', 'h3' );

	if ( ! in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) {
		$tag = 'h3';
	}

	echo '<' . $tag . ' class="slk-card__name">' . esc_html( get_the_title() ) . '</' . $tag . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $tag whitelisted above.
}

/**
 * Replaces woocommerce_template_loop_price().
 * Price only — get_price_html() is filtered below (slk_strip_sale_price_html)
 * so a compare-at/strikethrough price can never reach this markup even if a
 * sale price is set in wp-admin (brand law 3 / §7: no sale theatre).
 */
function slk_template_loop_price() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$price_html = $product->get_price_html();

	if ( ! $price_html ) {
		return;
	}

	echo '<div class="slk-card__price">' . wp_kses_post( $price_html ) . '</div>';
}

/**
 * New: colour swatch row. Hooked after the default price priority (10) but
 * before the link closes (after_shop_loop_item, priority 5), so the row sits
 * inside the anchor along with everything else, matching the mockups.
 */
function slk_template_loop_swatches() {
	global $product;

	$swatches = slk_get_product_colour_swatches( $product );

	if ( empty( $swatches ) ) {
		return;
	}

	echo '<div class="slk-swatches">';

	foreach ( $swatches as $swatch ) {
		printf(
			'<span class="slk-swatch" style="background:%1$s" title="%2$s"></span>',
			esc_attr( $swatch['hex'] ),
			esc_attr( $swatch['name'] )
		);
	}

	echo '</div>';
}

/**
 * Replaces woocommerce_template_loop_product_link_close().
 */
function slk_template_loop_product_link_close() {
	echo '</a>';
}

/* -------------------------------------------------------------------------
 * 4. Swap the hooks
 *
 * Same pattern as functions.php §5: remove_action the default callback,
 * add_action the slk_ replacement at the identical priority, so nothing else
 * that targets these actions (or their priorities) is disturbed.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		remove_action( 'woocommerce_before_shop_loop_item', 'woocommerce_template_loop_product_link_open', 10 );
		add_action( 'woocommerce_before_shop_loop_item', 'slk_template_loop_product_link_open', 10 );

		remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
		add_action( 'woocommerce_before_shop_loop_item_title', 'slk_template_loop_product_thumbnail', 10 );

		remove_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title', 10 );
		add_action( 'woocommerce_shop_loop_item_title', 'slk_template_loop_product_title', 10 );

		// No star ratings on the grid card in the design — leave priority 5 empty.
		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5 );

		remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
		add_action( 'woocommerce_after_shop_loop_item_title', 'slk_template_loop_price', 10 );
		add_action( 'woocommerce_after_shop_loop_item_title', 'slk_template_loop_swatches', 15 );

		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_product_link_close', 5 );
		add_action( 'woocommerce_after_shop_loop_item', 'slk_template_loop_product_link_close', 5 );

		// No per-card "Add to cart" button in any Shop / Desktop shop / Hijabs
		// browse mockup — "Add to bag" lives on the PDP action dock only.
		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
	},
	20
);

/* -------------------------------------------------------------------------
 * 5. No sale theatre — price-level (brand law 3 / §7)
 *
 * functions.php §5 already removes the sale *badge*. This strips the
 * strikethrough/compare-at price WooCommerce's own get_price_html() would
 * otherwise wrap in <del>/<ins> the moment a sale price is ever set in
 * wp-admin — belt-and-braces so a future sale price on a product never
 * surfaces theatre anywhere the price renders, card or PDP.
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_get_price_html',
	static function ( $price_html, $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
			return $price_html;
		}

		return wc_price( wc_get_price_to_display( $product ) );
	},
	20,
	2
);

/* -------------------------------------------------------------------------
 * 6. Grid shape — owned by style.css §3.7, NOT by this file.
 *
 * style.css sets `.woocommerce ul.products` to a 2-up grid with the li resets,
 * then 4-up at the single 1000px breakpoint (3-up only inside .slk-shop-layout,
 * beside the filter rail). This file used to re-declare the identical selector
 * at min-width:768px with repeat(3,1fr) via wp_add_inline_style — printed after
 * style.css on the same handle at equal specificity, so it won the cascade and
 * forced 3-up at every width >= 768px, destroying both the 4-up desktop rail
 * and the 3-up-beside-sidebar distinction, and adding a fifth breakpoint to a
 * design that has one. Deleted deliberately: do not re-add a grid rule here.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * 7. The archive head row — the design's one-baseline title.
 *
 * 07-desktop.html "Desktop shop" puts EVERYTHING in a single row above the
 * grid: "Ready to wear  24 pieces" (Newsreader 300 at 34px, count inline in
 * Archivo 15 faint) with the sort pill right-aligned on the same baseline.
 * No "SHOWING ALL 9 RESULTS" line, no separate controls band, no second
 * title. So:
 *   - Blocksy's archive hero band is gated OFF at the source (its customizer
 *     mod, not CSS) — this file renders the page's single real <h1>.
 *   - WooCommerce's result-count paragraph is unhooked; the count becomes
 *     part of the title.
 *   - The ordering <form> stays where WooCommerce puts it; the head row and
 *     the (now count-less) listing-top are placed on one grid row by the CSS
 *     below.
 * ---------------------------------------------------------------------- */

/**
 * True on every view where this file renders the listing head, i.e. the one
 * real <h1>. Blocksy's hero must be off in exactly these cases and nowhere
 * else — a plain (non-product) search still wants its own title band.
 */
function slk_is_product_listing() {
	return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
}

/*
 * Blocksy picks the hero band's theme_mod by view prefix, so one key is not
 * enough: a product search is `search`, not `woo_categories`, and used to
 * print "Search Results for dress" above our own head — two <h1>s on the page.
 */
foreach ( array( 'woo_categories', 'search' ) as $slk_hero_prefix ) {
	add_filter(
		"theme_mod_{$slk_hero_prefix}_hero_enabled",
		static function ( $value ) {
			return slk_is_product_listing() ? 'no' : $value;
		}
	);
}
unset( $slk_hero_prefix );

remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );

add_action(
	'woocommerce_before_shop_loop',
	static function () {
		if ( ! slk_is_product_listing() ) {
			return;
		}

		$count = (int) wc_get_loop_prop( 'total' );

		printf(
			'<header class="slk-shop-head"><h1 class="slk-shop-head__title">%1$s <span class="slk-shop-head__count">%2$s</span></h1></header>',
			esc_html( woocommerce_page_title( false ) ),
			esc_html(
				sprintf(
					/* translators: %d: number of products in the listing. */
					_n( '%d piece', '%d pieces', $count, 'slk' ),
					$count
				)
			)
		);
	},
	5
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! slk_is_product_listing() ) {
			return;
		}

		wp_add_inline_style(
			'slk-child',
			'
/* One-baseline archive head (design: Desktop shop). Head left, sort right;
   ul.products spans below. NOT a grid re-declaration — §6 still applies. */
.slk-shop-head__title{font-family:var(--slk-font-display);font-weight:300;font-size:var(--slk-display-s);margin:0}
/* muted, not faint: faint (#8a867e) is meta/disabled only and measures 3.19:1
   on the page ground — this count is content. */
.slk-shop-head__count{font-family:var(--slk-font-ui);font-size:15px;color:var(--slk-color-muted);font-weight:400;margin-inline-start:6px}
@media (min-width:1000px){
	.slk-shop-head__title{font-size:34px}
	/* The one-baseline row is a DESKTOP composition. Below 1000px the `auto`
	   track is sized by the 210px sort pill (inc/select.php), which left the
	   h1 and the Filters button ~95px on a 360px viewport — so mobile falls
	   back to normal block flow. */
	.slk-shop-results{display:grid;grid-template-columns:1fr auto;align-items:baseline;column-gap:var(--slk-space-3)}
	.slk-shop-results > .slk-shop-head{grid-column:1}
	.slk-shop-results > .woo-listing-top{grid-column:2;margin:0}
	/* Every other child placed explicitly: an unplaced child auto-fills the
	   next free cell, and the empty .woocommerce-notices-wrapper was landing
	   in the sort pill\'s. Blocksy emits nav.ct-pagination, not
	   nav.woocommerce-pagination, so both are named. .slk-moments-empty is
	   here because the live filter swaps it in for ul.products on a
	   zero-result facet change: same slot, so it needs the same placement.
	   A zero-result page LOAD never reaches this, since no head or sort pill
	   renders and the auto track collapses to nothing. */
	.slk-shop-results > .slk-filterbar,
	.slk-shop-results > .woocommerce-notices-wrapper,
	.slk-shop-results > ul.products,
	.slk-shop-results > .slk-moments-empty,
	.slk-shop-results > nav.woocommerce-pagination,
	.slk-shop-results > nav.ct-pagination{grid-column:1 / -1}
}
'
		);
	},
	30
);

/* -------------------------------------------------------------------------
 * 8. Saved pieces — heart toggle on the card.
 *
 * Guarded everywhere with class_exists( 'SLK_Saved' ) (slk-order-flow): a
 * deploy that ships this theme without that plugin renders no toggle at
 * all rather than a fatal error or a dead button. SLK_Saved::render_toggle()
 * draws the control itself (both the shop-card and the PDP variant); this
 * file only decides where it is printed and carries its CSS + click script.
 *
 * Printed one priority BEFORE the card's own <a> opens (8, vs. 10 for
 * slk_template_loop_product_link_open above) — a SIBLING of the anchor
 * inside the same <li class="product">, not a descendant of it. A real,
 * working control — a plain <a> to the account page signed out, a <button>
 * signed in — cannot legally nest inside another <a>, and a nested anchor
 * is not just invalid, it is actively broken: the parser closes and
 * reopens the outer link around it, splitting the card's own click target
 * into two disconnected pieces. Absolutely positioned in CSS below instead,
 * over the card's top-right corner, using li.product as the positioning
 * context.
 *
 * This block runs on every page a WooCommerce product loop can appear on
 * (shop, category, home rails, related/cross-sell — nothing here is gated
 * to slk_is_product_listing()), because the heart has to show up wherever a
 * .slk-card does.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		if ( ! class_exists( 'SLK_Saved' ) ) {
			return;
		}

		add_action( 'woocommerce_before_shop_loop_item', 'slk_shop_render_save_toggle', 8 );
	},
	20
);

function slk_shop_render_save_toggle() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	SLK_Saved::render_toggle( $product->get_id(), 'card' );
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! class_exists( 'SLK_Saved' ) ) {
			return;
		}

		$css = <<<'CSS'
.woocommerce ul.products li.product{position:relative}
.slk-save-btn{cursor:pointer;background:none}
.slk-save-btn__icon{display:block}
.slk-save-btn.is-saved .slk-save-btn__icon{fill:currentColor}
.slk-save-btn--card{
	position:absolute;top:8px;right:8px;z-index:2;
	display:inline-flex;align-items:center;justify-content:center;
	padding:12px;margin:0;border:0;
	background:var(--slk-glass-solid);
	border-radius:var(--slk-radius-pill);
	color:var(--slk-color-ink);
	text-decoration:none;
	transition:transform var(--slk-motion-base) var(--slk-ease), background var(--slk-motion-base) var(--slk-ease);
}
.slk-save-btn--card:hover{background:#fff}
.slk-save-btn--card:active{transform:scale(.92)}
.slk-save-btn--card .slk-save-btn__icon{width:20px;height:20px}
CSS;

		wp_add_inline_style( 'slk-child', $css );

		if ( ! is_user_logged_in() ) {
			return; // Signed out, the control is a plain <a> to the account page — nothing to wire up.
		}

		wp_register_script( 'slk-saved', false, array(), '1.0.0', true );
		wp_enqueue_script( 'slk-saved' );

		$js = <<<'JS'
(function () {
	"use strict";

	function setState( btn, saved ) {
		if ( typeof window.slkSaved === 'undefined' ) {
			return;
		}

		btn.classList.toggle( 'is-saved', saved );
		btn.setAttribute( 'aria-pressed', saved ? 'true' : 'false' );
		btn.setAttribute( 'aria-label', saved ? window.slkSaved.labelSaved : window.slkSaved.labelSave );

		var text = btn.querySelector( '[data-slk-save-label]' );
		if ( text ) {
			text.textContent = saved ? window.slkSaved.textSaved : window.slkSaved.textSave;
		}
	}

	function onSaveClick( event ) {
		var btn = event.target.closest( '[data-slk-save]' );
		if ( ! btn || btn.disabled || typeof window.slkSaved === 'undefined' ) {
			return;
		}

		event.preventDefault();

		var id = btn.getAttribute( 'data-slk-save-id' );
		if ( ! id ) {
			return;
		}

		var wasSaved = btn.classList.contains( 'is-saved' );

		btn.disabled = true;
		setState( btn, ! wasSaved ); // Optimistic; reverted below on failure.

		var body = new URLSearchParams();
		body.set( 'action', window.slkSaved.action );
		body.set( 'product_id', id );
		body.set( 'nonce', window.slkSaved.nonce );

		window.fetch( window.slkSaved.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! json.data ) {
					throw new Error( 'slk-saved-failed' );
				}
				setState( btn, !! json.data.saved );
			} )
			.catch( function () {
				setState( btn, wasSaved );
			} )
			.finally( function () {
				btn.disabled = false;
			} );
	}

	document.addEventListener( 'click', onSaveClick );
})();
JS;

		wp_add_inline_script( 'slk-saved', $js );

		wp_localize_script(
			'slk-saved',
			'slkSaved',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => SLK_Saved::AJAX_ACTION,
				'nonce'      => wp_create_nonce( SLK_Saved::NONCE_ACTION ),
				'labelSave'  => __( 'Save this piece', 'slk' ),
				'labelSaved' => __( 'Remove from saved pieces', 'slk' ),
				'textSave'   => __( 'Save this piece', 'slk' ),
				'textSaved'  => __( 'Saved', 'slk' ),
			)
		);
	},
	31
);
