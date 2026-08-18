<?php
/**
 * The Home page — data, provisioning and styling.
 *
 * The markup lives in front-page.php (brand law 8: a template in the theme
 * plus a call to slk_ensure_page() from the feature file that owns it). This
 * file owns everything that is not markup:
 *
 *   1. the Home page + the show_on_front binding, done ONCE;
 *   2. the header's "float over the hero" switch;
 *   3. the queries and counts the template renders;
 *   4. the card renderer, which is NOT a second card design — it hands each
 *      product to woocommerce/content-product.php, the same template the shop
 *      archive uses, so inc/shop.php's callbacks draw the card;
 *   5. the CSS, scoped to the front page.
 *
 * Screens: design/sections/06-mobile.html ("Home") and
 * design/sections/07-desktop.html ("Desktop home"). Copy is reproduced from
 * those mockups verbatim, with three deliberate exceptions, each of which is a
 * brand law overruling a mockup literal:
 *
 *   · Counts ("12 pieces", "All 24") are the real catalogue, not the demo's
 *     numbers. The design shows a count in that sentence; shipping a hard-coded
 *     24 against a 9-piece catalogue would be the one thing this brand's voice
 *     refuses (guidelines §2, "numbers over adjectives" — the numbers have to
 *     be true). The sentence shape is the design's; the number is the store's.
 *     When a count is 0 the phrase is dropped rather than printed as a lie.
 *   · Money is wc_price(), never typed (brand law 3). The COD handling fee in
 *     the "Paying" panel comes from slk_delivery_cod_fee() in inc/pages-help.php,
 *     so the home page and the delivery page can never disagree.
 *   · The mockup's hijab meta line reads "Rs. 1,450 · 3 for Rs. 3,900". The
 *     bundle has no source in the store, so only the real from-price renders;
 *     inventing a multi-buy price would be both false and sale theatre.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. The page, and the front-page binding.
 *
 * front-page.php is what WordPress renders for the site front page, whatever
 * show_on_front says — so the template works from the first request. The
 * option flip below exists so that the front page is a real PAGE (editable,
 * addressable, out of the blog loop) rather than the post index.
 *
 * It runs ONCE, guarded by its own option: if a human later points the front
 * page somewhere else in Settings > Reading, this must not silently drag it
 * back on the next request. The guard stores the id it bound, so the record of
 * what happened survives.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		$id = slk_ensure_page( 'home', __( 'Home', 'slk' ) );

		if ( ! $id ) {
			return;
		}

		if ( get_option( 'slk_front_page_bound' ) ) {
			return;
		}

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $id );
		update_option( 'slk_front_page_bound', $id, false );
	},
	15
);

/* -------------------------------------------------------------------------
 * 2. The header pill floats over the hero.
 *
 * inc/chrome.php ships `.slk-header--over` wired but off, because when it was
 * written no full-bleed hero existed. This is the hero. The switch is tied to
 * the hero IMAGE, not to the page: with no photograph the hero degrades to a
 * panel on the porcelain ground, and an absolutely-positioned pill would then
 * land on top of the headline instead of on top of a picture.
 * ---------------------------------------------------------------------- */

add_filter(
	'slk_header_over',
	static function ( $over ) {
		if ( ! is_front_page() ) {
			return $over;
		}

		return (bool) slk_editorial_image_id( 'hero_group' );
	}
);

/* -------------------------------------------------------------------------
 * 3. Links out.
 *
 * Never a hard-coded path to another page (brand law 8) and never a dead one:
 * a link whose target has not been provisioned yet returns '' and the template
 * drops the control, the same discipline inc/chrome.php uses for its nav.
 * ---------------------------------------------------------------------- */

/**
 * Permalink for a provisioned page, or '' when it does not exist yet.
 *
 * slk_page_url() falls back to the home page so a href is never "#"; on the
 * home page itself that fallback would render a button that goes nowhere, so
 * existence is checked first.
 *
 * @param string $slug Page slug.
 * @return string
 */
function slk_home_page_link( $slug ) {
	if ( slk_page_id( $slug ) ) {
		return slk_page_url( $slug );
	}

	$page = get_page_by_path( $slug );

	return ( $page && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
}

/**
 * The shop archive, newest first — the design's "Shop the drop" / "New in".
 *
 * @return string
 */
function slk_home_new_in_url() {
	$shop = function_exists( 'slk_chrome_shop_url' ) ? slk_chrome_shop_url() : home_url( '/' );

	return (string) add_query_arg( 'orderby', 'date', $shop );
}

/* -------------------------------------------------------------------------
 * 4. Counts and queries.
 * ---------------------------------------------------------------------- */

/**
 * Published, catalogue-visible products in the store.
 *
 * @return int
 */
function slk_home_product_count() {
	static $count = null;

	if ( null !== $count ) {
		return $count;
	}

	$count = 0;

	if ( function_exists( 'wc_get_products' ) ) {
		$ids = wc_get_products(
			array(
				'limit'              => -1,
				'status'             => 'publish',
				'catalog_visibility' => 'visible',
				'return'             => 'ids',
			)
		);

		$count = is_array( $ids ) ? count( $ids ) : 0;
	}

	return $count;
}

/**
 * Products published in the last seven days — the hero's "New this week".
 *
 * @return int
 */
function slk_home_new_count() {
	static $count = null;

	if ( null !== $count ) {
		return $count;
	}

	$count = 0;

	if ( function_exists( 'wc_get_products' ) ) {
		$ids = wc_get_products(
			array(
				'limit'              => -1,
				'status'             => 'publish',
				'catalog_visibility' => 'visible',
				'date_created'       => '>' . gmdate( 'Y-m-d', time() - WEEK_IN_SECONDS ),
				'return'             => 'ids',
			)
		);

		$count = is_array( $ids ) ? count( $ids ) : 0;
	}

	return $count;
}

/**
 * The "Ready to wear" rail: newest garments.
 *
 * Colour-led products (hijabs) are held back for their own rail below, which is
 * where the design puts them — slk_is_colour_led_product() is inc/shop.php's
 * own test, so "what counts as a hijab" is decided in exactly one place.
 *
 * @param int $limit How many cards.
 * @return WC_Product[]
 */
function slk_home_new_in_products( $limit = 4 ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	$products = wc_get_products(
		array(
			'limit'              => $limit + 8,
			'status'             => 'publish',
			'catalog_visibility' => 'visible',
			'orderby'            => 'date',
			'order'              => 'DESC',
		)
	);

	$rail = array();

	foreach ( (array) $products as $product ) {
		if ( function_exists( 'slk_is_colour_led_product' ) && slk_is_colour_led_product( $product ) ) {
			continue;
		}

		$rail[] = $product;

		if ( count( $rail ) >= $limit ) {
			break;
		}
	}

	return $rail;
}

/**
 * The "Everyday hijabs" rail.
 *
 * @param int $limit How many cards.
 * @return WC_Product[]
 */
function slk_home_hijab_products( $limit = 6 ) {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}

	$products = wc_get_products(
		array(
			'limit'              => $limit,
			'status'             => 'publish',
			'catalog_visibility' => 'visible',
			'category'           => array( 'hijabs' ),
			'orderby'            => 'menu_order',
			'order'              => 'ASC',
		)
	);

	return is_array( $products ) ? $products : array();
}

/**
 * The lowest price in a set of products, or null when none carries one.
 *
 * @param WC_Product[] $products Products.
 * @return float|null
 */
function slk_home_from_price( $products ) {
	$min = null;

	foreach ( (array) $products as $product ) {
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$price = $product->get_price();

		if ( '' === $price || null === $price ) {
			continue;
		}

		$price = (float) $price;

		if ( null === $min || $price < $min ) {
			$min = $price;
		}
	}

	return $min;
}

/**
 * The COD handling fee as display text ("Rs. 150"), or '' when unavailable or
 * set to zero — a fee of zero is never charged at checkout, so no sentence may
 * name "Rs. 0". Whether there IS a fee is a separate question: ask
 * slk_delivery_cod_fee() for that, because '' here also means "cannot print".
 *
 * wc_price() returns markup with a non-breaking space entity; it is stripped
 * and decoded here so the template can esc_html() the sentence it lands in
 * without printing "&amp;nbsp;".
 *
 * @return string
 */
function slk_home_cod_fee_text() {
	if ( ! function_exists( 'slk_delivery_cod_fee' ) || ! function_exists( 'wc_price' ) ) {
		return '';
	}

	$fee = slk_delivery_cod_fee();

	if ( $fee <= 0 ) {
		return '';
	}

	$html = wp_strip_all_tags( wc_price( $fee ) );

	return trim( html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) );
}

/* -------------------------------------------------------------------------
 * 5. Cards — one card design, borrowed, not rebuilt.
 *
 * Every card on this page is rendered by woocommerce/content-product.php via
 * wc_get_template_part(), exactly as the shop archive renders it: the same
 * .slk-card markup, the same swatches, the same "no add-to-cart on a grid
 * card" rule. Nothing about the card is written twice.
 *
 * The wrapper is <div class="woocommerce"><ul class="products"> because
 * style.css §3.7 owns that selector's grid (2-up mobile, 4-up desktop) and is
 * declared the SOLE owner of the column count. The home rail wants exactly
 * that shape, so it asks for it by name instead of re-declaring it.
 * ---------------------------------------------------------------------- */

/**
 * Render a set of products as the house product cards.
 *
 * @param WC_Product[] $products Products to render.
 * @param string       $class    Extra class on the .woocommerce wrapper.
 */
function slk_home_render_cards( $products, $class = '' ) {
	if ( empty( $products ) || ! function_exists( 'wc_get_template_part' ) ) {
		return;
	}

	$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

	if ( function_exists( 'wc_setup_loop' ) ) {
		wc_setup_loop(
			array(
				'columns'      => 4,
				'is_shortcode' => true,
				'is_paginated' => false,
				'total'        => count( $products ),
			)
		);
	}

	printf(
		'<div class="woocommerce%1$s"><ul class="products">',
		$class ? ' ' . esc_attr( $class ) : ''
	);

	foreach ( $products as $product ) {
		$post_object = get_post( $product->get_id() );

		if ( ! $post_object ) {
			continue;
		}

		$GLOBALS['post']    = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored below.
		$GLOBALS['product'] = $product;

		setup_postdata( $GLOBALS['post'] );

		wc_get_template_part( 'content', 'product' );
	}

	echo '</ul></div>';

	if ( function_exists( 'wc_reset_loop' ) ) {
		wc_reset_loop();
	}

	unset( $GLOBALS['product'] );
	$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring.
	wp_reset_postdata();
}

/* -------------------------------------------------------------------------
 * 6. Styles.
 *
 * Front page only. Tokens only, one 1000px breakpoint, mobile-first — the
 * base rules are the 390px screen and the single query is the 1280px one
 * (brand laws 6 and 7). Nothing here restyles a component that already
 * exists: the panels are .slk-glass/.slk-panel, the buttons are .slk-btn, the
 * cards are .slk-card, the trust rows are .slk-assurance, the section rhythm
 * is .slk-section. What is added is only the geometry those pieces sit in.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! is_front_page() ) {
			return;
		}

		$css = <<<'CSS'
/* -- hero ---------------------------------------------------------------
   Full-bleed image with the glass panel over it. 4:5 on the phone, 16:9 on
   the desktop, both straight off the mockups. The panel sits inside a
   .slk-container so its left edge lines up with the wordmark in the header
   pill above it, which is what makes the desktop composition read.        */
.slk-hero{position:relative}
.slk-hero__media{overflow:hidden;aspect-ratio:4/5}
.slk-hero__media img{display:block;width:100%;height:100%;object-fit:cover}
.slk-hero__inner{position:absolute;inset-inline:0;bottom:var(--slk-space-3)}
.slk-hero__panel{padding:var(--slk-space-6)}
/* Over a photograph the glass composite drops the eyebrow's inherited muted
   to 3.67-4.04:1 across a large share of both hero crops, and it moves again
   whenever the photo is swapped. ink-soft holds 6.6-7.0:1 on the same
   composite. Scoped to the eyebrow deliberately — raising --slk-glass alpha
   would repaint every glass surface in the store. */
.slk-hero__panel .slk-eyebrow{display:block;margin-bottom:var(--slk-space-3);color:var(--slk-color-ink-soft)}
.slk-hero__title{margin:0 0 var(--slk-space-4);font-size:var(--slk-display-m)}
.slk-hero__actions{display:flex;gap:var(--slk-space-2)}
.slk-hero__actions .slk-btn--primary{flex:1}
.slk-hero__actions .slk-btn--secondary{flex:none}

/* No photograph yet: the panel stops floating and sits on the ground, and
   inc/chrome.php's --over pill stays off. An empty frame reads as broken;
   an unadorned headline reads as a page that has not been shot yet.        */
.slk-hero--plain .slk-hero__inner{position:static;padding-block:var(--slk-space-12) 0}

/* -- category chips (phone only) ----------------------------------------
   07-desktop.html has no chip row: above 1000px the same four destinations
   are in the header pill, so repeating them would be furniture.            */
.slk-home__chips{
	display:flex;gap:var(--slk-space-2);
	padding-block:var(--slk-space-6) 0;
	overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;
}
.slk-home__chips::-webkit-scrollbar{display:none}
.slk-home__chips .slk-btn{flex:none;white-space:nowrap}

/* -- section furniture --------------------------------------------------- */
/* min-width as well as min-height: with a short label ("All 9 →") the link
   measured 37px wide — under the guidelines §8 floor. It is right-aligned in
   the section head, so the extra width grows the hit box into empty space:
   bigger target, identical appearance. Same fix as inc/chrome.php's footer
   list. */
.slk-home__all{
	display:inline-flex;align-items:center;justify-content:flex-end;
	min-height:var(--slk-touch);min-width:var(--slk-touch);
	font:500 var(--slk-text-sm)/1 var(--slk-font-ui);
	color:var(--slk-color-muted);text-decoration:none;white-space:nowrap;
}
.slk-home__all:hover{color:var(--slk-color-ink)}
.slk-home__meta{font:400 var(--slk-text-sm)/1 var(--slk-font-ui);color:var(--slk-color-muted);white-space:nowrap}

/* -- the hijab rail ------------------------------------------------------
   The ONLY place this file changes the shape of ul.products, and it is a
   different layout mode rather than a rival column count: a scroll-snap rail
   of round colour tiles (06-mobile.html:57) instead of a grid. Scoped to the
   rail wrapper with three classes so it cannot reach the shop archive, whose
   grid style.css §3.7 owns outright.                                       */
.woocommerce.slk-home__rail ul.products{
	display:flex;gap:var(--slk-space-3);
	overflow-x:auto;scroll-snap-type:x proximity;
	scrollbar-width:none;-webkit-overflow-scrolling:touch;
	padding-block:var(--slk-space-1);
}
.woocommerce.slk-home__rail ul.products::-webkit-scrollbar{display:none}
.woocommerce.slk-home__rail ul.products li.product{
	flex:0 0 86px;scroll-snap-align:start;text-align:center;
}

/* -- paying + atelier ---------------------------------------------------- */
.slk-home__care{display:grid;gap:var(--slk-space-4)}
.slk-home__pay{
	display:flex;flex-direction:column;align-items:flex-start;
	gap:var(--slk-space-3);padding:var(--slk-space-6);
}
.slk-home__pay h2{margin:0;font-size:var(--slk-display-s)}
.slk-home__pay p{
	margin:0;
	font:400 var(--slk-text-base)/var(--slk-leading-body) var(--slk-font-ui);
	color:var(--slk-color-muted);
}
.slk-home__pay .slk-btn{margin-top:var(--slk-space-2)}
.slk-home__atelier{
	position:relative;margin:0;
	border-radius:var(--slk-radius-card);overflow:hidden;
}
.slk-home__atelier--framed{aspect-ratio:16/10}
.slk-home__atelier img{display:block;width:100%;height:100%;object-fit:cover}
.slk-home__caption{padding:var(--slk-space-4) var(--slk-space-6);border-radius:var(--slk-radius-tile)}
.slk-home__atelier--framed .slk-home__caption{
	position:absolute;inset-inline:var(--slk-space-3);bottom:var(--slk-space-3);
}
.slk-home__caption h2{margin:0;font:500 var(--slk-text-base)/1.4 var(--slk-font-ui)}
.slk-home__caption p{margin:0;font:400 var(--slk-text-sm)/1.5 var(--slk-font-ui);color:var(--slk-color-ink-soft)}

/* -- trust strip (design/sections/03-components.html, "Trust strip") ----- */
.slk-home__assurances{list-style:none;margin:0;padding:0}

@media (min-width:1000px){
	.slk-hero__media{aspect-ratio:16/9}
	.slk-hero__inner{bottom:var(--slk-space-12)}
	/* Panel sits bottom-LEFT, which is where the mockup had it and where the
	   campaign hero puts its quiet space: the library frame walks the trio
	   through the middle and seats the third against the marble counter on the
	   right, so a right-hand panel lands squarely on her face and the beading
	   down her front (measured against the 440px panel at 1440px). Left of the
	   pink hem there is nothing but floor. The panel follows the space — it
	   just moved back now that the photograph did. */
	.slk-hero__panel{max-width:440px;padding:var(--slk-space-8);margin-inline-end:auto}
	.slk-hero__title{font-size:var(--slk-display-l)}
	.slk-hero__actions .slk-btn--primary{flex:none}

	.slk-home__chips{display:none}

	.slk-home__all,
	.slk-home__meta{font-size:var(--slk-text-base)}

	.woocommerce.slk-home__rail ul.products{gap:22px;overflow-x:visible}
	.woocommerce.slk-home__rail ul.products li.product{flex:0 0 110px}

	.slk-home__care{grid-template-columns:1fr 1fr;gap:var(--slk-space-6);align-items:stretch}
	.slk-home__pay{padding:var(--slk-space-8)}
	.slk-home__pay h2{font-size:var(--slk-display-m)}
	/* Beside the panel the frame stops being a fixed ratio and stretches to
	   the taller of the two columns; the photograph fills whatever that is. */
	.slk-home__atelier--framed{aspect-ratio:auto;min-height:340px}
	.slk-home__atelier--framed img{position:absolute;inset:0}

	.slk-home__assurances{grid-template-columns:repeat(3,minmax(0,1fr))}
}
CSS;

		wp_add_inline_style( 'slk-child', $css );
	},
	30
);
