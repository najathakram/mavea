<?php
/**
 * Product detail page — Porcelain Glass.
 *
 * Pairs with woocommerce/content-single-product.php (the structural override,
 * which adds the 'slk-pdp' class and two data-* attributes but keeps every
 * core hook untouched). Everything else — the two-column desktop layout, the
 * glass summary panel, colour swatches, the size grid, the WhatsApp button,
 * and the trust rows — lives here as CSS + progressive-enhancement JS + extra
 * woocommerce_single_product_summary callbacks, per brand law 8 (prefer hooks
 * and CSS over template overrides).
 *
 * Real hooks used (all confirmed against the reference templates in
 * design/_reference/woocommerce-templates/):
 *   - woocommerce_dropdown_variation_attribute_options_html  (core filter
 *     inside wc_dropdown_variation_attribute_options(), called from
 *     single-product/add-to-cart/variable.php line 41-47 — confirmed real,
 *     not invented)
 *   - woocommerce_single_product_summary                     (35, 45 — the
 *     size-guide link and trust rows slot between the documented core
 *     callbacks at 30/40, exactly as content-single-product.php's docblock
 *     states)
 *   - woocommerce_product_single_add_to_cart_text             (core filter,
 *     single-product-only variant of the add-to-cart button label)
 *
 * Sold-out sizes: WooCommerce's native variation JS only disables a <option>
 * when OTHER selected attributes make a combination unavailable — it does
 * not disable an option just because every variation carrying that value is
 * out of stock. Since the design requires already-sold-out sizes to render
 * struck through on first paint (not just after selection), stock is
 * pre-computed per attribute value in slk_pdp_compute_sold_out_values() from
 * $product->get_available_variations() and handed to the page as a JSON
 * data-attribute (see the template). The client script unions this with the
 * select's live `disabled` state, so combination-based disabling still works
 * on top of it.
 *
 * Colour swatches: no colour-swatch plugin or term meta is present in this
 * codebase, so there is no real source for a colour's hex value. Rendering a
 * filled circle would mean inventing that data. Swatches are therefore 44x44
 * circles carrying the colour name as their accessible label and visible
 * initial — structurally what the design calls for, honestly sourced.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Add to bag" instead of WooCommerce's default single-product button label.
 * Single-product-only filter — the shop grid's "Add to cart" text belongs to
 * whichever agent owns the grid, so it is left alone.
 */
add_filter(
	'woocommerce_product_single_add_to_cart_text',
	static function () {
		return __( 'Add to bag', 'slk' );
	}
);

/* -------------------------------------------------------------------------
 * Stock count — never surfaced, in stock or out.
 *
 * WooCommerce always prints woocommerce_get_stock_html() into the DOM even
 * though style.css hides the in-stock line with display:none — CSS removes
 * it from sight, not from assistive tech or a scraper. Publishing an exact
 * inventory count also runs against the premium positioning. The out-of-
 * stock case still has to render (it pairs with the sold-out card marker in
 * the shop grid), so only the in-stock string is stripped.
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_get_stock_html',
	static function ( $html, $product ) {
		return $product->is_in_stock() ? '' : $html;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * Sold-out size computation.
 * ---------------------------------------------------------------------- */

/**
 * Map each variable product's attributes to the option values that are
 * entirely out of stock (every variation carrying that value is unavailable).
 *
 * Keys are built the same way core builds a variation <select>'s `name`
 * attribute ('attribute_' . sanitize_title( $attribute_name )) — confirmed
 * against add-to-cart/variable.php line 38, which uses the identical
 * sanitize_title() call for the paired <label for="">. This keeps the PHP
 * side and the client-side select.name lookup guaranteed to agree without
 * depending on undocumented internals of get_variation_attributes().
 *
 * @param WC_Product|null $product Product object.
 * @return array<string,array<int,string>>
 */
function slk_pdp_compute_sold_out_values( $product ) {
	$map = array();

	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return $map;
	}

	$variations = $product->get_available_variations();
	if ( empty( $variations ) ) {
		return $map;
	}

	foreach ( array_keys( $product->get_variation_attributes() ) as $attribute_name ) {
		$dom_name = 'attribute_' . sanitize_title( $attribute_name );

		$seen_values    = array();
		$in_stock_values = array();

		foreach ( $variations as $variation ) {
			if ( ! isset( $variation['attributes'][ $dom_name ] ) ) {
				continue;
			}

			$value = $variation['attributes'][ $dom_name ];

			if ( '' === $value ) {
				continue; // "Any <attribute>" wildcard row — nothing precise to mark.
			}

			$seen_values[ $value ] = true;

			if ( ! empty( $variation['is_in_stock'] ) ) {
				$in_stock_values[ $value ] = true;
			}
		}

		$sold_out = array_values( array_diff( array_keys( $seen_values ), array_keys( $in_stock_values ) ) );

		if ( ! empty( $sold_out ) ) {
			$map[ $dom_name ] = $sold_out;
		}
	}

	return $map;
}

/* -------------------------------------------------------------------------
 * Colour / size control: wrap the real <select> so it can be progressively
 * enhanced into swatches or a size grid without touching its name, options,
 * disabled states or change-event contract. If JS fails to load the select
 * still renders and still works.
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_dropdown_variation_attribute_options_html',
	static function ( $html, $args ) {
		$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
		$is_colour = (bool) preg_match( '/colou?r/i', $attribute );

		return '<span class="slk-pdp__variation-select" data-slk-kind="' . ( $is_colour ? 'colour' : 'size' ) . '">'
			. $html
			. '</span>';
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * WhatsApp ask button — woocommerce_after_add_to_cart_button.
 *
 * This hook fires INSIDE form.cart, immediately after the submit button
 * (confirmed in the reference templates: add-to-cart/simple.php line 51 and
 * add-to-cart/variation-add-to-cart-button.php line 33). Printing the button
 * on woocommerce_single_product_summary/31 instead made it a direct child of
 * the summary grid, so it auto-placed into its own implicit row and could
 * never sit beside "Add to bag".
 *
 * Priority 20 is deliberate: the Blocksy parent closes its `.ct-cart-actions`
 * wrapper on this same hook at priority 100 (blocksy/inc/components/
 * woocommerce/single/add-to-cart.php), so anything later than 100 would land
 * outside the flex row again.
 *
 * No WhatsApp number is available in this codebase yet. Rather than render
 * a dead "#" link, the button stays off until a real number is supplied —
 * same single-source-of-truth pattern as slk_wordmark in inc/wordmark.php:
 *
 *     add_filter( 'slk_whatsapp_number', fn() => '94771234567' );
 * ---------------------------------------------------------------------- */

/**
 * Build the "ask about this piece" wa.me URL, or '' when no number is set.
 *
 * Shared by the in-form button and the mobile buy dock so both go dark
 * together rather than one of them rendering a dead control.
 *
 * @param WC_Product $product Product object.
 * @return string URL, or empty string when no number is configured.
 */
function slk_pdp_whatsapp_url( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return '';
	}

	/**
	 * Filters the WhatsApp number the PDP "ask" button messages.
	 *
	 * Digits only, country code first, no plus sign (wa.me format).
	 * PLACEHOLDER pending a real business number — see file header.
	 *
	 * @param string $number
	 */
	$number = (string) apply_filters( 'slk_whatsapp_number', '' );
	$number = preg_replace( '/\D/', '', $number );

	if ( empty( $number ) ) {
		return '';
	}

	$message = sprintf(
		/* translators: %s: product name. */
		__( 'Hi, I have a question about the %s.', 'slk' ),
		$product->get_name()
	);

	return sprintf( 'https://wa.me/%1$s?text=%2$s', $number, rawurlencode( $message ) );
}

/**
 * Markup for the round WhatsApp control.
 *
 * @param string $url   wa.me URL.
 * @param string $extra Extra class names.
 * @return string
 */
function slk_pdp_whatsapp_markup( $url, $extra = '' ) {
	return sprintf(
		'<a class="slk-btn slk-btn--secondary slk-btn--icon slk-pdp__whatsapp %3$s" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s"><span aria-hidden="true">W</span></a>',
		esc_url( $url ),
		esc_attr__( 'Ask about this piece on WhatsApp', 'slk' ),
		esc_attr( $extra )
	);
}

add_action(
	'woocommerce_after_add_to_cart_button',
	'slk_pdp_whatsapp_button',
	20
);

/* -------------------------------------------------------------------------
 * Below the summary: the design has NO tab band.
 *
 * WooCommerce ships Description/Reviews as tabs, and Blocksy paints the
 * active one as a dark pill — nothing like the design, which keeps the PDP
 * quiet below the fold: the garment copy in the reading column, then related
 * pieces. And a brand-new store shows no "REVIEWS (0)" tab: an empty counter
 * is anti-trust, the exact opposite of what it is for.
 * ---------------------------------------------------------------------- */

remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );

/*
 * The right column is TWO separate floating boxes (Najath, 2026-08-12): the
 * main card (title → price → cart → trust rows) and, below it, "About this
 * piece" in an identical glass panel. WooCommerce renders everything onto one
 * summary hook, so the split is made by bracketing the main content in its
 * own card div: open at priority 1 (before the title at 5), close at 59
 * (after the trust rows at 45, before the About box at 60). The .summary
 * element itself becomes a transparent sticky column — the CSS strips its
 * old panel skin so the two cards inside it are the only surfaces.
 */

add_action(
	'woocommerce_single_product_summary',
	static function () {
		echo '<div class="slk-summary-card">';
	},
	1
);

add_action(
	'woocommerce_single_product_summary',
	static function () {
		echo '</div>';
	},
	59
);
add_action(
	'woocommerce_single_product_summary',
	static function () {
		$content = get_the_content();

		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return;
		}

		printf(
			'<section class="slk-pdp-about"><h2 class="slk-pdp-about__title">%1$s</h2><div class="slk-pdp-about__body">%2$s</div></section>',
			esc_html__( 'About this piece', 'slk' ),
			wp_kses_post( wpautop( $content ) )
		);
	},
	60
);

/* Related products keep the catalogue's 3:4 portrait (600x800) — Blocksy was
   pulling a squarer size and cropping the models at the forehead. */
add_filter(
	'single_product_archive_thumbnail_size',
	static function ( $size ) {
		return is_product() ? 'woocommerce_thumbnail' : $size;
	}
);

function slk_pdp_whatsapp_button() {
	global $product;

	$url = slk_pdp_whatsapp_url( $product );

	if ( '' === $url ) {
		return;
	}

	echo slk_pdp_whatsapp_markup( $url ); // phpcs:ignore WordPress.Security.EscapingOutput -- escaped in helper.
}

/* -------------------------------------------------------------------------
 * Mobile sticky buy dock — woocommerce_after_single_product_summary, 5.
 *
 * Rendered as a child of div.product (before the tabs at 10), so `position:
 * sticky; bottom:0` — already fully specified in style.css §3.9 — has the
 * whole product block as its containing box and the pill rides the bottom of
 * the viewport for the length of the page. It is NOT placed inside the
 * summary panel: a sticky element can never escape its own short scroll box.
 *
 * The real add-to-cart button in the summary is left exactly where core put
 * it (moving it would break WooCommerce's variation JS, which binds to it by
 * position inside form.cart). Every product type — simple included — gets a
 * proxy button that is `hidden` in the markup and only revealed by the
 * script that wires it to that real submit, so a JS-off reader never sees a
 * dead control (she still has the always-visible submit in the summary card
 * itself). This used to branch: simple products got their own second
 * add-to-cart FORM in the dock, with quantity hardcoded to 1 — so a shopper
 * who set 3 in the visible stepper and tapped the dock (the control she
 * actually reaches for on a phone) added 1 with no indication anything was
 * discarded. The proxy path is now the only path: the summary form is the
 * single source of truth for quantity, variation and any customisation
 * field.
 * ---------------------------------------------------------------------- */

add_action(
	'woocommerce_after_single_product_summary',
	'slk_pdp_buy_dock',
	5
);

function slk_pdp_buy_dock() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	// External/grouped products have no single price or single submit to mirror.
	if ( ! $product->is_type( array( 'simple', 'variable' ) ) ) {
		return;
	}

	if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return;
	}

	/* LAW 2: the figure comes from wc_price(), never a hand-written symbol.
	   For a variable product get_price() is the lowest variation price; the
	   dock script rewrites the label from the live variation price once a
	   variation is chosen. */
	$price_html = wc_price( $product->get_price() );

	$label = sprintf(
		/* translators: %s: formatted price. */
		__( 'Add to bag · %s', 'slk' ),
		$price_html
	);

	$wa_url = slk_pdp_whatsapp_url( $product );

	/*
	 * The dock ships `hidden` and the script below reveals it once the proxy
	 * is wired. Its only content today is that proxy — the WhatsApp circle
	 * prints only when a number is filtered in — so without the attribute a
	 * reader with no JS got a sticky glass pill wrapped around nothing,
	 * covering the foot of the page and reading as a control that failed to
	 * load. She still has the summary card's own submit.
	 */
	echo '<div class="slk-buy-dock" data-slk-buy-dock hidden><div class="slk-buy-dock__inner">';

	if ( '' !== $wa_url ) {
		echo slk_pdp_whatsapp_markup( $wa_url, 'slk-buy-dock__wa' ); // phpcs:ignore WordPress.Security.EscapingOutput -- escaped in helper.
	}

	/*
	 * Every product type — simple included — goes through the summary form's
	 * real submit via this proxy button, never a second form of its own. The
	 * dock used to ship a bare form (quantity hardcoded to 1 + add-to-cart)
	 * for simple products, so on a phone — where the dock is the button she
	 * actually reaches for — setting quantity 3 in the visible stepper and
	 * tapping the dock silently added 1, with no indication anything was
	 * ignored. The same duplicate-form shape also swallowed any
	 * customisation fields for a customizable piece. The summary form is now
	 * the single source of truth for quantity, variation and customisation
	 * alike; the button stays hidden until the script below has wired it to
	 * that form's real submit.
	 */
	printf(
		'<button type="button" class="slk-btn slk-btn--primary slk-buy-dock__cta" data-slk-dock-proxy hidden>%s</button>',
		wp_kses_post( $label )
	);

	echo '</div></div>';
}

/* -------------------------------------------------------------------------
 * Size-guide link — woocommerce_single_product_summary, priority 35 (after
 * the core add-to-cart block at 30, before the trust rows at 45).
 *
 * The link used to be created only by the PDP script, inside a variation
 * row's label — so a simple product, which never renders a variations table
 * at all, shipped no route to the size guide, and the href itself was the
 * unregistered filter default '#slk-size-guide', an anchor to nothing. It is
 * printed here instead: a direct child of .slk-summary-card, so every product
 * type gets it, and exactly one link exists (the script no longer makes its
 * own). functions.php registers the filter against
 * slk_chrome_page_url( 'size-guide' ).
 *
 * Nothing is printed when the URL is empty — page missing or unpublished —
 * the same never-a-dead-link discipline the chrome help links already apply.
 * ---------------------------------------------------------------------- */

add_action(
	'woocommerce_single_product_summary',
	'slk_pdp_size_guide_link',
	35
);

/**
 * The size-guide destination, or '' when no page is published.
 *
 * @return string URL, or empty string when the link should not render.
 */
function slk_pdp_size_guide_url() {
	/**
	 * Filters the size-guide link target on the PDP.
	 *
	 * Return an empty string to suppress the link entirely.
	 *
	 * @param string $url
	 */
	return (string) apply_filters( 'slk_pdp_size_guide_url', '' );
}

function slk_pdp_size_guide_link() {
	$url = slk_pdp_size_guide_url();

	if ( '' === $url ) {
		return;
	}

	printf(
		'<a class="slk-pdp__size-guide" href="%1$s">%2$s</a>',
		esc_url( $url ),
		esc_html__( 'Size guide · cm', 'slk' )
	);
}

/* -------------------------------------------------------------------------
 * Trust rows — woocommerce_single_product_summary, priority 45 (between the
 * core meta hook at 40 and the sharing hook at 50).
 * ---------------------------------------------------------------------- */

add_action(
	'woocommerce_single_product_summary',
	'slk_pdp_trust_rows',
	45
);

function slk_pdp_trust_rows() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$days_metro  = function_exists( 'slk_delivery_days' ) ? slk_delivery_days( 0 ) : '';
	$days_island = function_exists( 'slk_delivery_days' ) ? slk_delivery_days( 2 ) : '';

	// Column 0 is an slk_chrome_icon() name, not a glyph — style.css:711,
	// "Drawn icons, not glyphs".
	$rows = array(
		array( 'check', __( 'Cash on delivery, with a call to confirm before dispatch', 'slk' ) ),
		array(
			'exchange',
			sprintf(
				/* translators: %d: number of days to start an exchange. */
				__( 'Exchange within %d days, courier collects', 'slk' ),
				function_exists( 'slk_exchange_window_days' ) ? slk_exchange_window_days() : 7
			),
		),
		array(
			'clock',
			$days_metro && $days_island
				? sprintf(
					/* translators: 1: Colombo & Gampaha day range. 2: rest-of-island day range. */
					__( 'Colombo in %1$s · island-wide in %2$s', 'slk' ),
					$days_metro,
					$days_island
				)
				: __( 'One courier, all 25 districts', 'slk' ),
		),
	);

	echo '<div class="slk-pdp__trust">';
	foreach ( $rows as $row ) {
		printf(
			'<div class="slk-pdp__trust-row">%1$s<span>%2$s</span></div>',
			slk_chrome_icon( $row[0], 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
			esc_html( $row[1] )
		);
	}
	echo '</div>';
}

/* -------------------------------------------------------------------------
 * Styles + progressive-enhancement script — single product pages only.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! is_product() ) {
			return;
		}

		$css = '
/* ── Layout ──────────────────────────────────────────────────────────────
   The two-column desktop grid used to be declared here at 992px while
   style.css §3.9 declared its own at 1000px, so 992-999px ran two different
   grids at once and the breakpoint disagreed with every other file
   (style.css:379: "1000px is the only breakpoint in the file").
   The obvious fix — delete this and let style.css §3.9 own the desktop PDP —
   was tried and reverted: under the Blocksy parent the summary is NOT a
   direct child of div.product (Blocksy wraps it), so the §3.9 child selectors
   `> .summary.entry-summary` and `> .woocommerce-product-gallery` never match
   and the sticky panel was simply lost. So the layout
   stays here; only the width is corrected to the single documented
   breakpoint. */
/* CORRECTION (independent verify, D1): the block above was written onto
   .slk-pdp.product — but gallery and summary are NOT its grid children; they
   live inside Blocksy\'s .product-entry-wrapper, so the declared tracks sat
   EMPTY while Blocksy\'s own flex (gallery 33% / summary 67%) actually painted
   the page — the design\'s split (1.25fr/1fr, gallery wide) inverted. Grid the
   wrapper Blocksy really builds, and neutralise its width/margin math. */
@media (min-width: 1000px) {
	.slk-pdp.slk-pdp .product-entry-wrapper{ /* doubled class: Blocksy targets this wrapper with :has(), scoring (0,3,0) — the double bumps us to (0,3,0)+ and our sheet loads later, so we win deterministically */
		display:grid;
		grid-template-columns:minmax(0,1.25fr) minmax(0,1fr);
		column-gap:36px; /* the design\'s exact gutter (07-desktop #d-pdp) */
		align-items:start;
	}
	.slk-pdp.slk-pdp .product-entry-wrapper > .woocommerce-product-gallery{
		width:auto;margin:0;
	}
	/* The summary element is a TRANSPARENT sticky column — its old panel skin
	   moved onto .slk-summary-card, so the two cards inside it (main + About)
	   are the only painted surfaces. Nothing to strip here any more: the
	   slk-panel/slk-panel--lifted classes came off the div in
	   content-single-product.php, which is what was painting a third glass
	   surface around both cards on every phone and tablet. */
	.slk-pdp.slk-pdp .product-entry-wrapper > .summary.entry-summary{
		width:auto;margin-inline-start:0;
		padding:0;
		position:sticky;
		top:var(--slk-space-6);
		align-self:start;
		/* Stripping the skin exposed a Blocksy flex on this element — the two
		   cards sat side by side. One column, one gap. */
		display:grid;
		grid-template-columns:minmax(0,1fr);
		gap:var(--slk-space-6);
	}
	.slk-pdp .slk-pdp-about{margin-top:0} /* the grid gap owns the spacing here */
}

/* ── Gallery: rounded, cropped, CLS-safe (width/height ship with the img tag) ── */
.slk-pdp .woocommerce-product-gallery{
	border-radius:var(--slk-radius-tile);
	overflow:hidden;
}
/* CORRECTION (verify, D7): .woocommerce-product-gallery__image matches ZERO
   elements under Blocksy — its slides are .flexy-item > figure.ct-media-container,
   the same class-mismatch that killed the .flex-control-thumbs rule below.
   Main slide is 3/4, NOT the design\'s 4/5 (07-desktop #d-pdp-1). The frames
   are normalised to 3:4 by local/prepare-product-images.py — the ratio the card
   and the thumbnails below already use — so at 4/5 this box was the one place
   left that still cover-cropped, and it cropped hardest: 16.75% of the height,
   8.4% off the crown of the hijab and 8.4% off the hem, on the largest view of
   the garment the store has. One ratio end to end costs the slide a little
   width and buys back the whole garment. */
.slk-pdp .woocommerce-product-gallery .flexy-item .ct-media-container{
	border-radius:var(--slk-radius-tile);
	overflow:hidden;
	aspect-ratio:var(--slk-ratio-portrait);
}
.slk-pdp .woocommerce-product-gallery .flexy-item .ct-media-container img{
	width:100%;height:100%;object-fit:cover;display:block;
}
/* Blocksy does not print the classic Flexslider .flex-control-thumbs markup;
   its own gallery component uses .flexy-pills[data-type="thumbs"] > ol > li,
   confirmed against the rendered DOM (view-source on /product/amara/) — the
   .flex-control-thumbs selector below never matched anything, which is why
   the two secondary shots rendered at Blocksy\'s untouched default 100x100
   instead of the design\'s half-width 3:4 tiles under the main image. */
.slk-pdp .woocommerce-product-gallery .flexy-pills[data-type="thumbs"]{
	padding-top:var(--slk-space-3);
}
/* All three pills visible, one row (Najath, 2026-08-12): hiding pill 1 made
   the front view unreachable once the carousel moved on — the thumb strip is
   navigation, not a summary. Three columns kills the orphan-row problem the
   old first-child hide was papering over. */

/* Blocksy\'s flexy-arrow-prev/next ship as bare spans: not focusable, no
   role, no name, sized 40x40 (below --slk-touch), and their opacity/
   visibility rules live inside @media(any-hover:hover) — so they are
   invisible on desktop and render as unlabelled plain circles over the
   photograph on touch. The thumbnail pills above already give full,
   keyboard-reachable navigation between all three shots, so the arrows are
   removed outright rather than half-fixed with an aria-label a sighted
   touch shopper still cannot operate without a pointer. */
.slk-pdp .woocommerce-product-gallery .flexy-arrow-prev,
.slk-pdp .woocommerce-product-gallery .flexy-arrow-next{
	display:none;
}

/* The stepper, owned outright. Blocksy\'s type-2 quantity positions its empty
   +/− spans with fractional inset math; every partial override so far produced
   a new deformity (the − rendered as an underscore inside the input, the +
   floated detached). Deterministic layout instead: a 150px pill, 44px round
   controls pinned left and right with their glyphs supplied here, the value
   centred between them. Nothing of Blocksy\'s math survives to fight. */
.slk-pdp form.cart .quantity[data-type]{
	position:relative;
	width:150px;min-width:150px;min-height:48px;
	background:rgba(35,34,32,.05);
	border-radius:var(--slk-radius-pill);
}
.slk-pdp form.cart .quantity .ct-decrease,
.slk-pdp form.cart .quantity .ct-increase{
	position:absolute;top:50%;transform:translateY(-50%);
	inset-inline:auto;
	width:var(--slk-touch);height:var(--slk-touch);
	display:grid;place-items:center;
	border-radius:50%;cursor:pointer;
	font:400 16px/1 var(--slk-font-ui);color:var(--slk-color-ink);
	transition:background var(--slk-motion-base) var(--slk-ease);
}
.slk-pdp form.cart .quantity .ct-decrease{left:2px}
.slk-pdp form.cart .quantity .ct-increase{right:2px}
.slk-pdp form.cart .quantity .ct-decrease:hover,
.slk-pdp form.cart .quantity .ct-increase:hover{background:var(--slk-color-white)}
/* These are plain spans, not buttons (Blocksy\'s markup) — the PDP script
   below gives them role="button", tabindex and an aria-label, so they also
   need a visible keyboard-focus ring; nothing supplies one by default on a
   non-interactive element. */
.slk-pdp form.cart .quantity .ct-decrease:focus-visible,
.slk-pdp form.cart .quantity .ct-increase:focus-visible{
	outline:2px solid var(--slk-color-ink);outline-offset:2px;
}
.slk-pdp form.cart .quantity .ct-decrease::before{content:"\2212"}
.slk-pdp form.cart .quantity .ct-increase::before{content:"\002B"}
.slk-pdp form.cart .quantity input.qty{
	width:100%;min-height:48px;margin:0;
	padding-inline:var(--slk-touch);text-align:center;
	border:0;background:none;
	font:500 14px/1 var(--slk-font-ui);color:var(--slk-color-ink);
	appearance:textfield;-moz-appearance:textfield;
}
.slk-pdp form.cart .quantity input.qty::-webkit-outer-spin-button,
.slk-pdp form.cart .quantity input.qty::-webkit-inner-spin-button{appearance:none;margin:0}

/* The two floating cards of the right column. Identical skin — the About box
   is the main card\'s twin, not an inset (Najath\'s correction, 2026-08-12). */
.slk-pdp .slk-summary-card,
.slk-pdp .slk-pdp-about{
	padding:30px;
	background:var(--slk-glass-solid);
	border:1px solid var(--slk-glass-edge);
	border-radius:28px;
	backdrop-filter:blur(20px);
	-webkit-backdrop-filter:blur(20px);
	box-shadow:var(--slk-shadow-lift);
}
.slk-pdp-about{
	margin-top:var(--slk-space-6);
}
.slk-pdp-about__title{
	font-family:var(--slk-font-display);font-weight:300;font-size:var(--slk-text-xl);margin:0 0 var(--slk-space-2);
}
.slk-pdp-about__body{
	font:400 var(--slk-text-base)/1.7 var(--slk-font-ui);color:var(--slk-color-ink-soft);
}
.slk-pdp-about__body p:last-child{margin-bottom:0}

/* Related products: Blocksy stamps ct-hidden-sm/ct-hidden-md on its wrapper,
   hiding the rail from every phone and tablet — the shoppers who need it
   most.
   CORRECTION: these rules were written under a .slk-pdp ancestor, which never
   matches — section.related.products lives inside article.post-N, a SIBLING of
   div#product-N.slk-pdp, so the rail was unhidden nowhere and unstyled even on
   desktop. Drop the ancestor; the width/heading rules re-point at the article
   the section really sits in. Blocksy\'s utility is display:none !important, so
   the un-hide has to match it. */
.related.products.ct-hidden-sm.ct-hidden-md{display:block !important}
.single-product article > .related.products{
	max-width:var(--slk-container);
	margin:var(--slk-space-12) auto 0;
	padding-inline:var(--slk-gutter);
}
.single-product article > .related.products > h2{
	font-family:var(--slk-font-display);font-weight:300;font-size:27px;margin:0 0 var(--slk-space-4);
}
.slk-pdp .woocommerce-product-gallery .flexy-pills[data-type="thumbs"] ol{
	display:grid;grid-template-columns:repeat(3,1fr);gap:var(--slk-space-3);
	list-style:none;margin:0;padding:0;
}
.slk-pdp .woocommerce-product-gallery .flexy-pills[data-type="thumbs"] li{
	/* Blocksy\'s flexy.min.css sets width:var(--thumbs-width, 20%) on this
	   same element (.flexy-pills ol li) for its default horizontal-strip
	   layout; that rule has no competing width declaration here to beat, so
	   it still won even though our own selector out-specifies it on every
	   other property. Fill the grid column explicitly instead of leaving
	   width ungoverned. */
	width:100%;aspect-ratio:3/4;border-radius:var(--slk-radius-tile);overflow:hidden;cursor:pointer;
}
.slk-pdp .woocommerce-product-gallery .flexy-pills[data-type="thumbs"] .ct-media-container,
.slk-pdp .woocommerce-product-gallery .flexy-pills[data-type="thumbs"] img{
	width:100%;height:100%;object-fit:cover;display:block;
}

/* ── Summary panel: map WooCommerce\'s real element classes onto a grid ──
   Blocksy does NOT leave form.cart as a direct child of .entry-summary: it
   wraps it in div.ct-product-add-to-cart and injects span.ct-product-divider
   siblings. Those unnamed children auto-placed into fresh implicit rows of
   this grid, which is where the dead vertical gaps under the description
   came from — and it meant `> form.cart{grid-area:cart}` never matched, so
   the cart row was never a flex row either. Both wrapper shapes are named
   below; the dividers are not part of this design. */
/* This internal layout moved from .slk-pdp__summary onto .slk-summary-card
   when the column was split into two floating boxes: the named children
   (title/price/desc/cart/trust) live inside the card now, and leaving the
   areas on the summary made it a phantom two-column grid that laid the two
   CARDS side by side (measured: gridCols "0px 466px"). */
.slk-pdp .slk-summary-card{
	display:grid;
	grid-template-columns:1fr auto;
	gap:var(--slk-space-6) var(--slk-space-3);
	grid-template-areas:
		"title price"
		"desc  desc"
		"cart  cart"
		"guide guide"
		"trust trust";
}
/* Blocksy gives every entry-summary layer a 35px margin-bottom of its own.
   Inside a gap-based grid that margin is pure dead space and stacked with the
   row gap to ~51px between the description and the cart row. One rhythm, owned
   by the grid. The extra class raises specificity over Blocksy\'s dynamic CSS,
   which is printed after this block. */
.slk-pdp.product .slk-summary-card > *{ margin-block:0; }
/* style.css (owned by nobody this round; no touching per house rule) carries
   .woocommerce div.product form.cart{ margin: var(--slk-space-6) 0 0; } at
   specificity (0,3,2) — two type selectors (div, form) plus three classes.
   Every reset attempted here, including the rule above, tops out at (0,3,0)
   or (0,3,1) and loses the cascade regardless of load order, so form.cart
   kept a real extra 24px margin-top stacked on top of the grid\'s own
   row-gap between the description and the cart row (measured: 48px instead
   of the intended 24px). !important is the only way to win this without
   editing the file we do not own. */
.slk-pdp__summary form.cart{ margin:0 !important; }
.slk-summary-card > .ct-product-divider{ display:none; }
.slk-summary-card > .ct-product-add-to-cart{ grid-area:cart;margin:0; }
.slk-summary-card > .ct-product-add-to-cart > form.cart{ margin:0; }
/* Prefixed with .slk-pdp.product (0,4,1) so this beats style.css\'s
   `.woocommerce div.product .product_title.entry-title{margin:0 0 6px}`
   (0,4,2 — wins on margin alone) and, on the price rule below, its
   `.woocommerce div.product p.price{font:500 16px/1 …}` (0,3,2), which
   otherwise outranks the un-prefixed (0,2,1) version of this selector
   regardless of load order and keeps the price at 16px instead of the
   intended var(--slk-text-xl). PDP summary typography has one owner — this
   file — so the fix raises specificity here rather than editing style.css. */
.slk-pdp.product .slk-summary-card > h1.product_title{
	grid-area:title;margin:0;font-size:var(--slk-display-s);font-weight:400;
}
.slk-pdp.product .slk-summary-card > p.price{
	grid-area:price;margin:0;font:500 var(--slk-text-xl)/1 var(--slk-font-ui);white-space:nowrap;
}
/* Raising the h1 to (0,4,1) above also lifted it past style.css\'s desktop
   step — @media (min-width:1000px){ .woocommerce div.product
   .product_title.entry-title{font-size:28px} } — which scores the same
   (0,4,1) and now loses the tie, because this block prints after style.css.
   The title silently stayed 24px at every width. Whichever file owns the
   selector owns its breakpoint too. */
@media (min-width: 1000px){
	.slk-pdp.product .slk-summary-card > h1.product_title{ font-size:28px; }
}
.slk-summary-card > .woocommerce-product-details__short-description{
	grid-area:desc;font:400 var(--slk-text-sm)/1.6 var(--slk-font-ui);color:var(--slk-color-muted);
}
.slk-summary-card > .woocommerce-product-rating,
.slk-summary-card > .product_meta{
	/* Not part of this design; hidden without removing the hook. */
	display:none;
}
.slk-summary-card > form.cart{ grid-area:cart; }
.slk-pdp__summary form.cart{
	margin:0;
	display:flex;flex-wrap:wrap;align-items:flex-start;gap:var(--slk-space-4);
}
.slk-pdp__summary form.cart > table.variations{
	flex:1 1 100%;width:100%;border-collapse:collapse;
}
.slk-pdp__summary form.cart table.variations tr{ display:block; }
.slk-pdp__summary form.cart table.variations th.label,
.slk-pdp__summary form.cart table.variations td.value{
	display:block;text-align:left;padding:0;border:0;
}
.slk-pdp__summary form.cart table.variations tr + tr{ margin-top:var(--slk-space-6); }
.slk-pdp__summary form.cart table.variations th.label label{
	display:flex;align-items:center;justify-content:space-between;
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);
	letter-spacing:.08em;text-transform:uppercase;color:var(--slk-color-muted);
	margin-bottom:var(--slk-space-3);
}
.slk-pdp__summary .slk-pdp__attr-current{ text-transform:none;letter-spacing:0; }
.slk-pdp__summary .slk-pdp__size-guide{
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);text-decoration:underline;text-underline-offset:3px;
	color:var(--slk-color-ink);min-height:var(--slk-touch);display:inline-flex;align-items:center;
}
/* The link is a direct child of the card now (printed on
   woocommerce_single_product_summary/35), so it needs a named row: an unnamed
   child auto-places into an implicit row BELOW the trust list, away from the
   sizes it explains. justify-self keeps the rule the width of the words
   rather than the width of the card. */
.slk-pdp .slk-summary-card > .slk-pdp__size-guide{ grid-area:guide;justify-self:start; }
.slk-pdp__summary .reset_variations{ font:400 12px/1 var(--slk-font-ui);color:var(--slk-color-muted); }
.slk-pdp__summary .slk-pdp__fit-hint{
	font:400 12px/1.6 var(--slk-font-ui);color:var(--slk-color-muted);padding-top:var(--slk-space-2);
}

/* Colour swatches — real select stays functional and off-screen (JS only). */
.slk-swatch-group{ display:flex;flex-wrap:wrap;gap:var(--slk-space-2); }
.slk-swatch{
	width:44px;height:44px;border-radius:50%;
	border:1px solid rgba(35,34,32,.16);
	background:var(--slk-glass-solid);
	cursor:pointer;
	display:grid;place-items:center;
	font:500 12px/1 var(--slk-font-ui);
	transition:transform var(--slk-motion-base) var(--slk-ease), border-color var(--slk-motion-base) var(--slk-ease);
}
.slk-swatch:hover{ transform:scale(1.08); }
.slk-swatch[aria-pressed="true"]{ border:2px solid var(--slk-color-ink); }
.slk-swatch[disabled]{
	color:var(--slk-color-disabled-ink);cursor:not-allowed;position:relative;overflow:hidden;transform:none;
}
.slk-swatch[disabled]::after{
	content:"";position:absolute;left:6px;right:6px;top:50%;height:1px;background:rgba(35,34,32,.3);
}
.slk-swatch__dot{ text-transform:uppercase; }

.slk-pdp__variation-select select.slk-pdp__sr-select{
	position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
	clip:rect(0,0,0,0);white-space:nowrap;border:0;
}

/* Add to bag + WhatsApp, side by side. The WhatsApp anchor is now printed on
   woocommerce_after_add_to_cart_button, so it is a real sibling of the submit
   inside whichever row wrapper is in play: Blocksy\'s .ct-cart-actions, the
   variable-product .woocommerce-variation-add-to-cart, or form.cart itself. */
.slk-pdp__summary form.cart > .single_variation_wrap,
.slk-pdp__summary form.cart .woocommerce-variation-add-to-cart,
.slk-pdp__summary form.cart .ct-cart-actions{
	display:flex;flex:1 1 100%;gap:var(--slk-space-2);align-items:center;flex-wrap:wrap;
}
/* The submit carries width:100% from §3.9, which as a flex-basis:auto item
   claims the whole row and pushes the WhatsApp circle onto a third line.
   flex:1 1 0 + width:auto lets it share the row instead. The quantity keeps
   its own full-width line above, as in the mockup. */
/* flex:1 1 100% forces the quantity control onto its own full-width row
   (the wrap trick), but Blocksy\'s .ct-increase/.ct-decrease are absolutely
   positioned at inset-inline-end/start:9% of THIS element\'s own box,
   sized for its native width:130px (type-2.scss). Stretching the box to
   the full 700+px row stretches that 9% math with it, so the "+" button
   measured ~540px away from the "-"/input pair instead of hugging it -
   read live as a detached second stepper. max-width re-clamps the resolved
   box back to Blocksy\'s own --quantity-width so the two controls hug the
   input again, while flex-basis:100% still does its job of forcing the wrap. */
.slk-pdp__summary form.cart .quantity{ flex:1 1 100%; max-width:var(--quantity-width, 130px); }
.slk-pdp__summary form.cart .single_add_to_cart_button{
	flex:1 1 0;width:auto;min-width:0;min-height:var(--slk-touch);
}
.slk-pdp__whatsapp{ flex:none;width:var(--slk-touch);height:var(--slk-touch); }

.slk-pdp__trust{ grid-area:trust;display:grid;gap:var(--slk-space-2); }
.slk-pdp__trust-row{
	display:flex;gap:var(--slk-space-3);
	font:400 12.5px/1.5 var(--slk-font-ui);color:var(--slk-color-ink-soft);
}

/* ── Mobile sticky buy dock ─────────────────────────────────────────────
   The pill itself (.slk-buy-dock / __inner) is fully specified in
   style.css §3.9; only the two controls inside it belong here. The dock is a
   mobile affordance — above 1000px the summary panel is sticky in its own
   column and the dock would be a duplicate, so it is removed outright rather
   than relying on style.css\'s de-styling of it at that width. */
.slk-buy-dock__form{ flex:1 1 auto;margin:0;display:flex; }
.slk-buy-dock__cta{
	flex:1 1 auto;width:100%;min-height:48px;border:0;border-radius:24px;
	font:500 13.5px/1 var(--slk-font-ui);
}
/* The dock and its proxy both ship `hidden` and are revealed only once the
   script has wired the button to the summary form. The user agent implements
   that attribute as [hidden]{display:none}, which style.css\'s
   .slk-btn{display:inline-flex} overrides outright — author origin beats UA
   origin whatever the specificity — so with JS off the dock painted a
   live-looking "Add to bag" that did nothing. The wrapper carries the same
   override so a JS-off reader gets no dock at all rather than an empty glass
   pill. Same [hidden] override every other hideable component in the theme
   carries. */
.slk-buy-dock[hidden]{ display:none; }
.slk-buy-dock__cta[hidden]{ display:none; }
.slk-buy-dock__cta .woocommerce-Price-amount{ font:inherit;color:inherit; }
.slk-buy-dock__wa{ width:48px;height:48px;flex:none; }
@media (min-width: 1000px){
	.slk-buy-dock{ display:none; }
}
';

		wp_add_inline_style( 'slk-child', $css );

		wp_register_script( 'slk-pdp', false, array(), null, true );
		wp_enqueue_script( 'slk-pdp' );

		$js = <<<'JS'
(function(){
	function ready(fn){
		if(document.readyState!=='loading'){fn();}else{document.addEventListener('DOMContentLoaded',fn);}
	}

	ready(function(){
		document.querySelectorAll('.variations_form').forEach(initForm);
		initDock();
		initQuantitySteppers();
	});

	/*
	 * Blocksy's quantity +/- controls ship as bare <span class="ct-increase">
	 * / <span class="ct-decrease">, with their glyphs supplied purely by CSS
	 * ::before content (see the stepper block above) — no role, no name, not
	 * in the tab order. Their DOM order also runs backwards from the CSS
	 * above, which pins decrease to the left edge of the pill and increase to
	 * the right: a keyboard/AT user tabbing through hits "increase" before
	 * "decrease", reversed from what she sees painted. Fixed in place rather
	 * than replaced with real <button> elements, so whatever click handling
	 * Blocksy itself binds to these specific nodes keeps working — only the
	 * DOM order and the accessibility surface change.
	 */
	function initQuantitySteppers(){
		document.querySelectorAll('.slk-pdp form.cart .quantity[data-type]').forEach(function(quantity){
			var decrease = quantity.querySelector('.ct-decrease');
			var increase = quantity.querySelector('.ct-increase');
			var input = quantity.querySelector('input.qty');
			if(!decrease || !increase || !input) return;

			// Reorder to decrease, input, increase — matching the painted
			// order — regardless of what Blocksy originally shipped.
			quantity.appendChild(decrease);
			quantity.appendChild(input);
			quantity.appendChild(increase);

			var labels = (window.slkPdp && window.slkPdp.qtyLabels) || {};

			[
				[ decrease, labels.decrease || 'Decrease quantity' ],
				[ increase, labels.increase || 'Increase quantity' ]
			].forEach(function(pair){
				var el = pair[0], label = pair[1];
				el.setAttribute('role', 'button');
				el.setAttribute('tabindex', '0');
				el.setAttribute('aria-label', label);
				el.addEventListener('keydown', function(e){
					if(e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar'){
						e.preventDefault();
						el.dispatchEvent(new MouseEvent('click', { bubbles:true, cancelable:true }));
					}
				});
			});
		});
	}

	/*
	 * Buy dock. Every product type ships the same hidden proxy button — only
	 * the summary's own form knows the chosen quantity, variation or
	 * customisation options, so the dock defers to it rather than keeping a
	 * second, disconnected form of its own. The button is rendered `hidden`
	 * inside a dock that is `hidden` too, and both are revealed here only
	 * once the button has been wired to the real submit — a reader with no
	 * JS never sees a control that would not work, nor an empty pill where
	 * one would have been; she still has the always-visible submit in the
	 * summary card itself.
	 */
	function initDock(){
		var dock = document.querySelector('[data-slk-buy-dock]');
		if(!dock) return;

		var proxy = dock.querySelector('[data-slk-dock-proxy]');
		if(!proxy) return; // nothing to wire — the dock stays hidden.

		var form = document.querySelector('.slk-pdp__summary form.cart');
		var realBtn = form ? form.querySelector('.single_add_to_cart_button') : null;
		if(!realBtn){ dock.parentNode.removeChild(dock); return; }

		dock.hidden = false;
		proxy.hidden = false;

		proxy.addEventListener('click', function(){
			if(realBtn.disabled){
				// Nothing chosen yet — send the reader to the size/colour controls.
				form.scrollIntoView({ block:'center' });
				return;
			}
			realBtn.click();
		});

		var priceSlot = proxy.querySelector('.woocommerce-Price-amount');
		var basePrice = priceSlot ? priceSlot.outerHTML : '';

		function syncDock(){
			proxy.setAttribute('aria-disabled', realBtn.disabled ? 'true' : 'false');
			if(!priceSlot) return;
			var live = form.querySelector('.woocommerce-variation-price .woocommerce-Price-amount');
			var next = (live && !realBtn.disabled) ? live.outerHTML : basePrice;
			if(priceSlot.outerHTML !== next){
				priceSlot.outerHTML = next;
				priceSlot = proxy.querySelector('.woocommerce-Price-amount');
			}
		}

		new MutationObserver(syncDock).observe(form, {
			childList:true, subtree:true, attributes:true, attributeFilter:['disabled','class','style']
		});
		syncDock();
	}

	function initForm(form){
		var summary = form.closest('.slk-pdp__summary');
		var soldOutMap = {};
		if(summary && summary.dataset.slkSoldOut){
			try{ soldOutMap = JSON.parse(summary.dataset.slkSoldOut) || {}; }catch(e){ soldOutMap = {}; }
		}
		var fitHint = summary ? summary.dataset.slkFitHint : '';

		form.querySelectorAll('table.variations tr').forEach(function(row){
			var wrap = row.querySelector('.slk-pdp__variation-select');
			var select = wrap ? wrap.querySelector('select') : row.querySelector('select');
			if(!select) return;

			var kind = wrap ? wrap.getAttribute('data-slk-kind') : 'size';
			var isColour = kind === 'colour';
			var soldOut = soldOutMap[select.name] || [];

			var labelEl = row.querySelector('th.label label');
			var valueCell = row.querySelector('td.value');
			if(!valueCell) return;

			var currentSpan = null;
			if(isColour && labelEl){
				currentSpan = document.createElement('span');
				currentSpan.className = 'slk-pdp__attr-current';
				labelEl.appendChild(currentSpan);
			}

			var ui = document.createElement('div');
			ui.className = isColour ? 'slk-swatch-group' : 'slk-sizes';
			ui.setAttribute('role','group');
			if(labelEl){ ui.setAttribute('aria-label', labelEl.textContent.trim()); }

			var pairs = [];

			Array.prototype.forEach.call(select.options, function(opt){
				if(!opt.value) return;

				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = isColour ? 'slk-swatch' : 'slk-size';
				btn.setAttribute('aria-label', opt.textContent.trim());

				if(isColour){
					var dot = document.createElement('span');
					dot.className = 'slk-swatch__dot';
					dot.setAttribute('aria-hidden','true');
					dot.textContent = opt.textContent.trim().charAt(0);
					btn.appendChild(dot);
				} else {
					btn.textContent = opt.textContent.trim();
				}

				btn.addEventListener('click', function(){
					if(btn.disabled) return;
					select.value = opt.value;
					select.dispatchEvent(new Event('change', { bubbles:true }));
				});

				ui.appendChild(btn);
				pairs.push({ btn:btn, opt:opt, soldOut: soldOut.indexOf(opt.value) !== -1 });
			});

			function sync(){
				pairs.forEach(function(pair){
					var pressed = pair.opt.selected;
					pair.btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
					pair.btn.disabled = pair.opt.disabled || pair.soldOut;
					if(isColour && pressed && currentSpan){
						currentSpan.textContent = ' · ' + pair.opt.textContent.trim();
					}
				});
			}

			select.addEventListener('change', sync);
			var observer = new MutationObserver(sync);
			observer.observe(select, { childList:true, subtree:true, attributes:true, attributeFilter:['disabled','selected'] });

			select.classList.add('slk-pdp__sr-select');
			valueCell.appendChild(ui);
			sync();

			if(!isColour){
				/* The size-guide link used to be created here, in the size
				   row's label — which meant a simple product got none at
				   all. slk_pdp_size_guide_link() prints it from PHP for every
				   product type now; a second one here would only duplicate
				   it. */
				if(fitHint){
					var hint = document.createElement('p');
					hint.className = 'slk-pdp__fit-hint';
					hint.textContent = fitHint;
					valueCell.appendChild(hint);
				}
			}
		});
	}
})();
JS;

		wp_add_inline_script( 'slk-pdp', $js );

		wp_localize_script(
			'slk-pdp',
			'slkPdp',
			array(
				'qtyLabels' => array(
					'decrease' => __( 'Decrease quantity', 'slk' ),
					'increase' => __( 'Increase quantity', 'slk' ),
				),
			)
		);
	},
	25 // After the parent/child stylesheets (20) so wp_add_inline_style attaches correctly.
);
