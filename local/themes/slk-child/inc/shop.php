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
 * Real product image via core WC accessors (width/height always declared by
 * the registered image size, so nothing shifts). Falls back to
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
		echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) );
	} else {
		echo wp_kses_post( wc_placeholder_img( 'woocommerce_thumbnail' ) );
	}

	echo '</div>';
}

/**
 * Replaces woocommerce_template_loop_product_title().
 * Kept as a real heading (not a styled span) so the grid still has a sane
 * accessibility outline; visual weight comes entirely from .slk-card__name.
 */
function slk_template_loop_product_title() {
	echo '<h2 class="slk-card__name">' . esc_html( get_the_title() ) . '</h2>';
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
