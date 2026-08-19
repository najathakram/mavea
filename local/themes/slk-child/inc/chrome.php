<?php
/**
 * Site chrome — the floating glass header pill, the primary nav, the mobile
 * nav drawer and the four-column footer.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * style.css §3.2 and §3.3 describe a header/footer component that nothing was
 * rendering: `.slk-header`, `.slk-header__inner`, `.slk-wordmark`, `.slk-nav`,
 * `.slk-header__actions`, `.slk-icon-btn`, `.slk-bag`, `.slk-footer`,
 * `.slk-footer__inner`, `.slk-footer__col`, `.slk-footer__blurb` — roughly 170
 * lines of CSS matching no markup, while the site painted Blocksy's stock
 * chrome. This file is the markup those rules were written for. Not one class
 * name here is invented: every one is read out of style.css §3.2/§3.3, or out
 * of §2 (`.slk-eyebrow`, `.slk-scrim`, `.slk-btn`).
 *
 * WHY NOT header.php / footer.php
 * -------------------------------
 * Blocksy owns the document shell. Its header.php opens <html>, buffers
 * `blocksy_output_header()` and echoes it inside #main-container; its
 * footer.php calls `blocksy_output_footer()` and closes the document. Copying
 * those into the child theme would fork the parent's whole header system for
 * the sake of one pill. Instead this file uses the four hooks Blocksy publishes
 * for exactly this purpose (verified in the running container at
 * blocksy/inc/integrations/theme-builders.php and blocksy/header.php):
 *
 *   blocksy:builder:header:enabled  -> false  : suppresses the stock header
 *                                               AND its off-canvas panel
 *   blocksy:builder:footer:enabled  -> false  : suppresses the stock footer
 *   blocksy:header:before                     : where our header prints
 *   blocksy:footer:before                     : where our footer prints
 *
 * Suppressing before rendering is the point: rendering alongside would give
 * the page two headers and two footers.
 *
 * THE HERO MODIFIER
 * -----------------
 * `.slk-header--over` (position:absolute, floats the pill over a full-bleed
 * hero) is wired but OFF by default, because no full-bleed hero component
 * exists in this theme yet — turning it on today would drop the pill on top of
 * ordinary page copy instead of an image. Switch it on per-template the day the
 * hero lands:
 *
 *     add_filter( 'slk_header_over', fn() => is_front_page() );
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Menu locations
 *
 * Three locations, because the design's header nav, footer "Shop" column and
 * footer "Help" column are three different lists. Each has a hand-written
 * fallback so a store with no menus assigned still ships a working nav.
 * ---------------------------------------------------------------------- */

add_action(
	'after_setup_theme',
	static function () {
		register_nav_menus(
			array(
				'slk_primary'     => __( 'Primary (header pill + mobile drawer)', 'slk' ),
				'slk_footer_shop' => __( 'Footer: Shop column', 'slk' ),
				'slk_footer_help' => __( 'Footer: Help column', 'slk' ),
			)
		);
	},
	12
);

/* -------------------------------------------------------------------------
 * 2. Replace Blocksy's chrome — do not render beside it.
 * ---------------------------------------------------------------------- */

add_filter( 'blocksy:builder:header:enabled', '__return_false' );
add_filter( 'blocksy:builder:footer:enabled', '__return_false' );

/* -------------------------------------------------------------------------
 * 3. Link helpers
 *
 * Every fallback link resolves to a real URL or is dropped. A nav item with
 * href="#" is a dead control, and the rest of this theme already refuses to
 * ship one (inc/pdp.php:181, inc/cart.php:61).
 * ---------------------------------------------------------------------- */

/**
 * The shop archive URL, or the home page if WooCommerce is not loaded.
 *
 * @return string
 */
function slk_chrome_shop_url() {
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$url = (string) wc_get_page_permalink( 'shop' );

		if ( '' !== $url ) {
			return $url;
		}
	}

	return home_url( '/' );
}

/**
 * The my-account URL, or '' when there is no published page to send her to.
 *
 * There was previously no desktop route to it at all: the hamburger that
 * opens the drawer (and its "Sign in or create an account" pill) is hidden
 * at >=1000px, leaving only a footer link. slk_chrome_render_header() uses
 * this to add one — dropped, not dead-linked, if WooCommerce is absent.
 *
 * Not wc_get_page_permalink( 'myaccount' ): with no `$fallback` argument that
 * returns get_home_url() whenever the page id is unset, trashed or deleted, so
 * the caller's `if ( $account_url )` guard could never drop the control and a
 * person tapping a glyph labelled "Account" landed back on the home page. The
 * page id is resolved directly instead, then held to the same published test
 * slk_chrome_page_url() applies below.
 *
 * @return string
 */
function slk_chrome_account_url() {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return '';
	}

	$id   = (int) wc_get_page_id( 'myaccount' );
	$page = $id > 0 ? get_post( $id ) : null;

	return ( $page && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
}

/**
 * URL for a product category slug, or '' when the term does not exist.
 *
 * @param string $slug Product category slug.
 * @return string
 */
function slk_chrome_product_cat_url( $slug ) {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return '';
	}

	$term = get_term_by( 'slug', $slug, 'product_cat' );

	if ( ! $term || is_wp_error( $term ) ) {
		return '';
	}

	/*
	 * An empty category returns no URL, so its nav/footer item drops out —
	 * and reappears by itself the day the first product lands in it. Found
	 * live: "Hijabs" had zero products, was linked from every page, and its
	 * archive answered with "No pieces match those filters" when no filters
	 * were applied. A link that leads to an apology is worse than no link.
	 */
	if ( (int) $term->count < 1 ) {
		return '';
	}

	$url = get_term_link( $term );

	return is_wp_error( $url ) ? '' : (string) $url;
}

/**
 * URL for a page slug, or '' when no published page has that slug.
 *
 * @param string $slug Page slug.
 * @return string
 */
function slk_chrome_page_url( $slug ) {
	$page = get_page_by_path( $slug );

	return ( $page && 'publish' === $page->post_status ) ? (string) get_permalink( $page ) : '';
}

/**
 * The primary navigation fallback: New in / Abayas / Dresses / Hijabs.
 *
 * "New in" is the shop archive sorted newest-first — a real listing, not a
 * placeholder. Category items are dropped if the term is missing.
 *
 * @return array<int,array{label:string,url:string}>
 */
function slk_chrome_primary_links() {
	$links = array(
		array(
			'label' => __( 'New in', 'slk' ),
			'url'   => add_query_arg( 'orderby', 'date', slk_chrome_shop_url() ),
		),
		array(
			'label' => __( 'Abayas', 'slk' ),
			'url'   => slk_chrome_product_cat_url( 'abayas' ),
		),
		array(
			'label' => __( 'Dresses', 'slk' ),
			'url'   => slk_chrome_product_cat_url( 'dresses' ),
		),
		array(
			'label' => __( 'Hijabs', 'slk' ),
			'url'   => slk_chrome_product_cat_url( 'hijabs' ),
		),
		// The design's desktop nav carries "Our story" as its final, quieter
		// item — and the crawl proved the page was otherwise orphaned.
		array(
			'label' => __( 'Our story', 'slk' ),
			'url'   => slk_chrome_page_url( 'story' ),
		),
	);

	/**
	 * Filters the header/drawer navigation fallback.
	 *
	 * Only used when no menu is assigned to the `slk_primary` location.
	 *
	 * @param array $links List of ['label' => string, 'url' => string].
	 */
	$links = (array) apply_filters( 'slk_primary_links', $links );

	return array_values( array_filter( $links, static fn( $l ) => ! empty( $l['url'] ) ) );
}

/**
 * The footer "Help" column fallback.
 *
 * Editorial pages (size guide, delivery, exchanges, privacy policy, terms,
 * returns) do not exist on a fresh install, so they are included only once
 * someone publishes them — slk_chrome_page_url() drops any slug with no
 * published page, the same discipline slk_chrome_product_cat_url() applies
 * to an empty category. My account holds the column up on any ordinary
 * WooCommerce install, and drops out by the same rule if that page is gone.
 *
 * @return array<int,array{label:string,url:string}>
 */
function slk_chrome_help_links() {
	$account = slk_chrome_account_url();

	$links = array(
		array(
			'label' => __( 'Size guide', 'slk' ),
			'url'   => slk_chrome_page_url( 'size-guide' ),
		),
		array(
			'label' => __( 'Delivery & COD', 'slk' ),
			'url'   => slk_chrome_page_url( 'delivery' ),
		),
		array(
			'label' => __( 'Exchanges', 'slk' ),
			'url'   => slk_chrome_page_url( 'exchanges' ),
		),
		array(
			'label' => __( 'Track order', 'slk' ),
			'url'   => slk_chrome_page_url( 'track-order' ) ?: $account,
		),
		// FAQ and the account area existed but were reachable from nowhere —
		// found by the crawler, not by intuition. Contact lives in the
		// "Talk to us" column, not here.
		array(
			'label' => __( 'FAQ', 'slk' ),
			'url'   => slk_chrome_page_url( 'faq' ),
		),
		array(
			'label' => __( 'Privacy policy', 'slk' ),
			'url'   => slk_chrome_page_url( 'privacy-policy' ),
		),
		array(
			'label' => __( 'Terms', 'slk' ),
			'url'   => slk_chrome_page_url( 'terms' ),
		),
		array(
			'label' => __( 'Returns', 'slk' ),
			'url'   => slk_chrome_page_url( 'returns' ),
		),
		array(
			'label' => __( 'Sign in or create an account', 'slk' ),
			'url'   => $account,
		),
	);

	/**
	 * Filters the footer Help column fallback.
	 *
	 * @param array $links List of ['label' => string, 'url' => string].
	 */
	$links = (array) apply_filters( 'slk_footer_help_links', $links );

	return array_values( array_filter( $links, static fn( $l ) => ! empty( $l['url'] ) ) );
}

/**
 * Render a `<ul>` of links, marking the current page.
 *
 * @param array  $links Items from one of the *_links() helpers.
 * @param string $class Class for the <ul>.
 * @return string
 */
function slk_chrome_link_list( $links, $class = '' ) {
	if ( empty( $links ) ) {
		return '';
	}

	$current = untrailingslashit( home_url( add_query_arg( array() ) ) );
	$out     = '';

	foreach ( $links as $link ) {
		$is_current = untrailingslashit( $link['url'] ) === $current;

		$out .= sprintf(
			'<li><a href="%1$s"%2$s>%3$s</a></li>',
			esc_url( $link['url'] ),
			$is_current ? ' aria-current="page"' : '',
			esc_html( $link['label'] )
		);
	}

	return sprintf(
		'<ul%1$s>%2$s</ul>',
		$class ? ' class="' . esc_attr( $class ) . '"' : '',
		$out
	);
}

/**
 * Every gateway the merchant has switched on, keyed by gateway id.
 *
 * Enabled, not available: get_available_payment_gateways() answers for the
 * current cart, and cash on delivery runs its own is_available() there —
 * which reads false against a cart that needs no shipping, an empty one
 * included. Both callers below print on pages where the cart is normally
 * empty (the home page, the shop, a PDP, and the footer of every one of
 * them), so the available set answered `array()` on almost every view and
 * took the line and the chips with it. front-page.php:68-85 already reads
 * the enabled set for exactly this reason. It is the cart-independent answer
 * to "what can this store take money with", and it still turns a new
 * gateway's chip on by itself the moment that gateway is enabled.
 *
 * @return array<string,WC_Payment_Gateway>
 */
function slk_chrome_enabled_gateways() {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return array();
	}

	$enabled = array();

	foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
		if ( isset( $gateway->enabled ) && 'yes' === $gateway->enabled ) {
			$enabled[ $id ] = $gateway;
		}
	}

	return $enabled;
}

/**
 * The quiet line above the header pill: cash-on-delivery availability, read
 * live from the enabled gateways, with the live free-delivery threshold
 * appended when the merchant has one configured. Never hardcoded — dropped
 * entirely the moment cash on delivery is not enabled, so no copy about a
 * threshold is ever printed without the sentence it belongs to.
 *
 * @return string Plain text (escaped by the caller), or '' to render nothing.
 */
function slk_chrome_announcement_text() {
	$gateways = slk_chrome_enabled_gateways();

	if ( ! isset( $gateways['cod'] ) ) {
		return '';
	}

	$threshold = 0.0;

	// SLK_Shipping::free_over() is the live setting on the slk_delivery
	// shipping method instance; 0 means the merchant switched free delivery
	// off, same reading pages-help.php's slk_delivery_free_over() gives it.
	if ( class_exists( 'SLK_Shipping' ) && method_exists( 'SLK_Shipping', 'free_over' ) ) {
		$threshold = (float) SLK_Shipping::free_over();
	}

	if ( $threshold > 0 ) {
		return sprintf(
			/* translators: %s: free-delivery threshold, e.g. "Rs. 15,000". */
			__( 'Cash on delivery island-wide - Free delivery over %s', 'slk' ),
			wp_strip_all_tags( html_entity_decode( wc_price( $threshold ) ) )
		);
	}

	return __( 'Cash on delivery island-wide', 'slk' );
}

/**
 * Titles of every enabled payment gateway, for the footer "We accept" chips.
 * Text only, straight from get_title() — never a fabricated brand mark — and
 * empty when nothing is enabled, so the row drops out with it. Cash on
 * delivery today; a PayHere or Mintpay chip appears by itself the day that
 * gateway is switched on.
 *
 * @return array<int,string>
 */
function slk_chrome_payment_gateway_titles() {
	$titles = array();

	foreach ( slk_chrome_enabled_gateways() as $gateway ) {
		$title = trim( wp_strip_all_tags( (string) $gateway->get_title() ) );

		if ( '' !== $title ) {
			$titles[] = $title;
		}
	}

	return $titles;
}

/* -------------------------------------------------------------------------
 * 4. The bag button
 *
 * One function, two callers: the header renders it, and the
 * `woocommerce_add_to_cart_fragments` filter re-renders it so the count
 * updates after an AJAX add-to-cart without a page load. The fragment key is
 * the element's own selector — WooCommerce replaces whatever matches it.
 * ---------------------------------------------------------------------- */

/**
 * Inline SVG icons for the header controls.
 *
 * These were text glyphs (⌕ ☰ ✕). A glyph is at the mercy of whatever font
 * resolves it: the search character rendered as a small lopsided ring at 16px
 * in Archivo, which is why the control read as a smudge rather than a button.
 * Drawn paths give the same thin-stroke language as the wordmark, stay crisp
 * at any size, and inherit currentColor.
 *
 * The set is not header-only: `check`, `exchange` (the two-way arrow) and
 * `clock` are the three trust glyphs the home assurances and the PDP trust
 * rows used to print as literal ✓ / ⇄ / ◷. U+25F7 in particular is carried by
 * neither Archivo nor Newsreader, so it substituted or tofu'd; drawn at the
 * same 1.35 stroke as their neighbours they finally match. Those rows set
 * 12.5px text, hence the $size argument — 19px beside it reads as a badge.
 *
 * @param string $name  search|bag|menu|close|check|exchange|clock.
 * @param int    $size  Rendered px, both axes. Default 19 (the header buttons).
 * @return string SVG markup, or '' for an unknown name.
 */
function slk_chrome_icon( $name, $size = 19 ) {
	$open = sprintf(
		'<svg class="slk-icon" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">',
		(int) $size
	);

	$paths = array(
		'search'   => '<circle cx="11" cy="11" r="6.25"/><path d="M15.6 15.6 20 20"/>',
		// A tote: two handles over a soft-shouldered body — closer to a garment
		// bag than a supermarket basket.
		'bag'      => '<path d="M5.4 8.2h13.2l-1 11.1a1.6 1.6 0 0 1-1.6 1.45H8a1.6 1.6 0 0 1-1.6-1.45Z"/><path d="M9 10.4V7.3a3 3 0 0 1 6 0v3.1"/>',
		'menu'     => '<path d="M4 8h16M4 16h16"/>',
		'close'    => '<path d="M6 6l12 12M18 6 6 18"/>',
		'check'    => '<path d="M4.75 12.5 9.6 17.35 19.25 6.9"/>',
		// The two-way arrow: out on the top rail, back on the bottom one.
		'exchange' => '<path d="M4 9h15M15.5 5.5 19 9l-3.5 3.5"/><path d="M20 15H5M8.5 11.5 5 15l3.5 3.5"/>',
		'clock'    => '<circle cx="12" cy="12" r="7.6"/><path d="M12 7.5V12l3.1 1.9"/>',
		// A head over shoulders — the desktop account route's glyph, drawn to
		// the same stroke weight and viewBox as its search/bag neighbours.
		'account'  => '<circle cx="12" cy="8.4" r="3.5"/><path d="M5.3 19.2c1.1-3.9 3.9-5.9 6.7-5.9s5.6 2 6.7 5.9"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return $open . $paths[ $name ] . '</svg>';
}

/**
 * The bag icon button, with the live cart count.
 *
 * Empty bag shows the tote outline, not a "0" — a zero in a filled dark
 * circle is a counter announcing nothing, and it was the first thing the eye
 * landed on. The filled circle and count appear only once she has something.
 *
 * @return string
 */
function slk_chrome_bag_html() {
	$count = 0;
	$url   = home_url( '/' );

	if ( function_exists( 'WC' ) && WC()->cart ) {
		$count = (int) WC()->cart->get_cart_contents_count();
		$url   = (string) wc_get_cart_url();
	}

	$label = $count
		? sprintf(
			/* translators: %s: number of items in the bag. */
			_n( 'Bag, %s item', 'Bag, %s items', $count, 'slk' ),
			number_format_i18n( $count )
		)
		: __( 'Bag, empty', 'slk' );

	$inner = $count
		? '<span class="slk-bag__count">' . esc_html( number_format_i18n( $count ) ) . '</span>'
		: slk_chrome_icon( 'bag' );

	return sprintf(
		'<a class="slk-icon-btn slk-bag%1$s" href="%2$s" aria-label="%3$s">%4$s</a>',
		$count ? ' slk-bag--filled' : '',
		esc_url( $url ),
		esc_attr( $label ),
		$inner
	);
}

add_filter(
	'woocommerce_add_to_cart_fragments',
	static function ( $fragments ) {
		$fragments['a.slk-bag'] = slk_chrome_bag_html();

		return $fragments;
	}
);

/* -------------------------------------------------------------------------
 * 5. The header
 *
 * Structure is exactly what style.css §3.2 documents:
 *
 *   .slk-header > .slk-header__inner > .slk-wordmark
 *                                    + .slk-nav
 *                                    + .slk-header__actions
 *
 * The wordmark comes from slk_wordmark_markup() — the brand name is never
 * written here (see inc/wordmark.php).
 *
 * `.slk-nav--drawer-only` on the menu trigger is the counterpart of §3.2's
 * `@media (min-width:1000px){ .slk-nav{display:block} .slk-nav--drawer-only{display:none} }`:
 * below 1000px the nav is hidden and the trigger shows, above it they swap.
 * ---------------------------------------------------------------------- */

/**
 * Print the header pill.
 */
function slk_chrome_render_header() {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	/**
	 * Filters whether the pill floats over a full-bleed hero (`--over`)
	 * rather than sitting on the porcelain ground (`--solid`).
	 *
	 * @param bool $over Default false — no hero component exists yet.
	 */
	$over = (bool) apply_filters( 'slk_header_over', false );

	$links = slk_chrome_primary_links();

	/*
	 * The announcement is ground-coloured meta with no fill of its own (§7):
	 * muted ink, uppercase, transparent. That only reads where the header
	 * sits on the porcelain ground. Under `--over` the header is absolutely
	 * positioned across the top of a full-bleed hero photograph
	 * (style.css:588-593, switched on by inc/home.php for the front page), so
	 * the line would print onto the picture with nothing behind it and its
	 * box would push the glass pill down over the composition. Dropped there
	 * rather than restyled — the plan asks for a quiet line, not a banner.
	 */
	$announce = $over ? '' : slk_chrome_announcement_text();
	?>
	<header class="slk-header <?php echo $over ? 'slk-header--over' : 'slk-header--solid'; ?>">
		<?php if ( $announce ) : ?>
			<p class="slk-header__announce"><?php echo esc_html( $announce ); ?></p>
		<?php endif; ?>

		<div class="slk-header__inner">
			<?php
			// WP3: the real wordmark artwork, text fallback built in --
			// see slk_wordmark_render() in inc/wordmark.php.
			echo slk_wordmark_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				array(
					'size' => 'md',
					'link' => true,
				)
			);
			?>

			<nav class="slk-nav" aria-label="<?php esc_attr_e( 'Primary', 'slk' ); ?>">
				<?php
				if ( has_nav_menu( 'slk_primary' ) ) {
					wp_nav_menu(
						array(
							'theme_location' => 'slk_primary',
							'container'      => false,
							'depth'          => 1,
							'items_wrap'     => '<ul>%3$s</ul>',
						)
					);
				} else {
					echo slk_chrome_link_list( $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>
			</nav>

			<div class="slk-header__actions">
				<button type="button"
					class="slk-icon-btn slk-nav--drawer-only"
					data-slk-toggle="slk-drawer"
					aria-controls="slk-drawer"
					aria-expanded="false"
					aria-label="<?php esc_attr_e( 'Menu', 'slk' ); ?>"><?php echo slk_chrome_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></button>

				<?php
				/*
				 * Desktop-only: below 1000px the drawer (with its own "Sign in
				 * or create an account" pill) is the account route, so this
				 * stays out of the actions cluster entirely rather than just
				 * visually hidden — see .slk-icon-btn--account in style.css.
				 */
				$account_url = slk_chrome_account_url();
				if ( $account_url ) :
					?>
					<a class="slk-icon-btn slk-icon-btn--account"
						href="<?php echo esc_url( $account_url ); ?>"
						aria-label="<?php esc_attr_e( 'Account', 'slk' ); ?>"><?php echo slk_chrome_icon( 'account' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></a>
				<?php endif; ?>

				<button type="button"
					class="slk-icon-btn"
					data-slk-toggle="slk-header-search"
					aria-controls="slk-header-search"
					aria-expanded="false"
					aria-label="<?php esc_attr_e( 'Search', 'slk' ); ?>"><?php echo slk_chrome_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></button>

				<?php echo slk_chrome_bag_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</div>
		</div>

		<div class="slk-header__search" id="slk-header-search" hidden>
			<form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
				<label class="slk-header__search-label" for="slk-header-search-field"><?php esc_html_e( 'Search', 'slk' ); ?></label>
				<div class="slk-header__search-row">
					<input class="slk-input"
						id="slk-header-search-field"
						type="search"
						name="s"
						value="<?php echo esc_attr( get_search_query() ); ?>"
						placeholder="<?php esc_attr_e( 'Abaya, linen, hijab…', 'slk' ); ?>">
					<input type="hidden" name="post_type" value="product">
					<button type="submit" class="slk-btn slk-btn--primary"><?php esc_html_e( 'Search', 'slk' ); ?></button>
				</div>
			</form>
		</div>
	</header>

	<div class="slk-drawer" id="slk-drawer" hidden>
		<div class="slk-scrim" data-slk-close="slk-drawer"></div>
		<div class="slk-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Menu', 'slk' ); ?>">
			<div class="slk-drawer__head">
				<?php
				echo slk_wordmark_markup( array( 'size' => 'md' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				?>
				<button type="button"
					class="slk-icon-btn slk-drawer__close"
					data-slk-close="slk-drawer"
					aria-label="<?php esc_attr_e( 'Close menu', 'slk' ); ?>"><?php echo slk_chrome_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?></button>
			</div>

			<nav class="slk-drawer__nav" aria-label="<?php esc_attr_e( 'Mobile', 'slk' ); ?>">
				<?php
				if ( has_nav_menu( 'slk_primary' ) ) {
					wp_nav_menu(
						array(
							'theme_location' => 'slk_primary',
							'container'      => false,
							'depth'          => 1,
							'items_wrap'     => '<ul>%3$s</ul>',
						)
					);
				} else {
					echo slk_chrome_link_list( $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>
			</nav>

			<div class="slk-drawer__foot">
				<div class="slk-drawer__pills">
					<?php foreach ( slk_chrome_help_links() as $link ) : ?>
						<a class="slk-drawer__pill" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
					<?php endforeach; ?>
				</div>
				<?php
				// Rendered only when a number is configured — never a dead href.
				$wa = function_exists( 'slk_whatsapp_url' )
					? slk_whatsapp_url( __( 'Hi! I have a question.', 'slk' ) )
					: '';

				if ( $wa ) :
					?>
					<a class="slk-drawer__wa" href="<?php echo esc_url( $wa ); ?>">
						<span class="slk-drawer__wa-text">
							<?php esc_html_e( 'Ask us on WhatsApp', 'slk' ); ?>
							<small><?php esc_html_e( 'replies until 8pm', 'slk' ); ?></small>
						</span>
						<span class="slk-drawer__wa-mark" aria-hidden="true">W</span>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

add_action( 'blocksy:header:before', 'slk_chrome_render_header' );

/* -------------------------------------------------------------------------
 * 6. The footer
 *
 * `.slk-footer__inner` is a 1-column grid on mobile and `2fr 1fr 1fr 1.4fr` at
 * 1000px (style.css §3.3) — wordmark + blurb, Shop, Help, WhatsApp card, in
 * that order, matching 07-desktop.html:97-119.
 * ---------------------------------------------------------------------- */

/**
 * Print the footer.
 */
function slk_chrome_render_footer() {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	/**
	 * Filters the footer blurb under the wordmark.
	 *
	 * @param string $blurb Plain text.
	 */
	$blurb = (string) apply_filters(
		'slk_footer_blurb',
		__( 'Modest ready-to-wear, made in Sri Lanka to export standard.', 'slk' )
	);

	$help           = slk_chrome_help_links();
	$wa             = function_exists( 'slk_whatsapp_url' )
		? slk_whatsapp_url( __( 'Hi! I have a question.', 'slk' ) )
		: '';
	$payment_titles = slk_chrome_payment_gateway_titles();
	?>
	<footer class="slk-footer">
		<div class="slk-footer__inner">
			<div class="slk-footer__col">
				<?php
				// WP3: the real wordmark artwork, text fallback built in --
				// see slk_wordmark_render() in inc/wordmark.php.
				echo slk_wordmark_render( array( 'size' => 'md' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				?>
				<p class="slk-footer__blurb"><?php echo esc_html( $blurb ); ?></p>
			</div>

			<div class="slk-footer__col">
				<span class="slk-eyebrow"><?php esc_html_e( 'Shop', 'slk' ); ?></span>
				<?php
				if ( has_nav_menu( 'slk_footer_shop' ) ) {
					wp_nav_menu(
						array(
							'theme_location' => 'slk_footer_shop',
							'container'      => false,
							'depth'          => 1,
							'items_wrap'     => '<ul class="slk-footer__list">%3$s</ul>',
						)
					);
				} else {
					echo slk_chrome_link_list( slk_chrome_primary_links(), 'slk-footer__list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>
			</div>

			<div class="slk-footer__col">
				<span class="slk-eyebrow"><?php esc_html_e( 'Help', 'slk' ); ?></span>
				<?php
				if ( has_nav_menu( 'slk_footer_help' ) ) {
					wp_nav_menu(
						array(
							'theme_location' => 'slk_footer_help',
							'container'      => false,
							'depth'          => 1,
							'items_wrap'     => '<ul class="slk-footer__list">%3$s</ul>',
						)
					);
				} else {
					echo slk_chrome_link_list( $help, 'slk-footer__list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				}
				?>
			</div>

			<div class="slk-footer__col">
				<span class="slk-eyebrow"><?php esc_html_e( 'Talk to us', 'slk' ); ?></span>
				<?php if ( $wa ) : ?>
					<a class="slk-footer__wa" href="<?php echo esc_url( $wa ); ?>">
						<span class="slk-footer__wa-text">
							<?php esc_html_e( 'WhatsApp us', 'slk' ); ?>
							<small><?php esc_html_e( 'replies until 8pm', 'slk' ); ?></small>
						</span>
						<span class="slk-footer__wa-mark" aria-hidden="true">W</span>
					</a>
				<?php endif; ?>
				<?php $slk_contact = slk_chrome_page_url( 'contact' ); ?>
				<?php if ( $slk_contact ) : ?>
					<ul class="slk-footer__list">
						<li><a href="<?php echo esc_url( $slk_contact ); ?>"><?php esc_html_e( 'Contact us', 'slk' ); ?></a></li>
					</ul>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $payment_titles ) : ?>
			<div class="slk-footer__accepts">
				<span class="slk-eyebrow"><?php esc_html_e( 'We accept', 'slk' ); ?></span>
				<ul class="slk-footer__accepts-list">
					<?php foreach ( $payment_titles as $payment_title ) : ?>
						<li><?php echo esc_html( $payment_title ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php
		/*
		 * The ownership line, a SIBLING of the grid rather than a fifth column
		 * so the `2fr 1fr 1fr 1.4fr` track list at style.css:778 is untouched.
		 * It pairs with the privacy/terms/returns entries in the Help column:
		 * a footer that links a privacy policy and names nobody as its owner is
		 * the half-finished state. The name comes from slk_wordmark_text() —
		 * prose is one of the two places the É is allowed (inc/wordmark.php).
		 */
		?>
		<p class="slk-footer__legal">
			<?php
			printf(
				/* translators: 1: four-digit year. 2: the brand name. */
				esc_html__( '© %1$s %2$s. All rights reserved.', 'slk' ),
				esc_html( date_i18n( 'Y' ) ),
				esc_html( slk_wordmark_text() )
			);
			?>
		</p>
	</footer>
	<?php
}

add_action( 'blocksy:footer:before', 'slk_chrome_render_footer' );

/* -------------------------------------------------------------------------
 * 7. The parts style.css does not already carry
 *
 * §3.2/§3.3 own the pill, the nav, the icon buttons, the bag and the footer
 * grid. What they do not describe — because the drawer and the search panel
 * were never built — is added here, in the same idiom the other inc/ files
 * use: tokens only, no raw hex, one 1000px breakpoint.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		$css = <<<'CSS'
/* Anchors borrowed into the icon-button shape need the link reset. */
a.slk-icon-btn{text-decoration:none}
.slk-bag__count{font:500 12px/1 var(--slk-font-ui)}
/* style.css:713 handles the icon inside a button. Icons also sit inline beside
   a sentence now (the home assurances, the PDP trust rows), and those are flex
   rows — without this the drawn box is squeezed by the text next to it. The
   icon buttons are grid, so flex:none is inert there. */
.slk-icon{flex:none}

/* -- header search panel ------------------------------------------------ */
.slk-header__search{
	max-width:var(--slk-container);
	margin:var(--slk-space-2) auto 0;
	padding:var(--slk-space-4);
	background:var(--slk-glass);
	backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	-webkit-backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);
	box-shadow:var(--slk-shadow-lift);
}
.slk-header__search[hidden]{display:none}
.slk-header__search-label{
	display:block;
	margin-bottom:var(--slk-space-2);
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);
	letter-spacing:var(--slk-track-label);
	text-transform:uppercase;
	color:var(--slk-color-muted);
}
/* The field and the button are §2 components (.slk-input, .slk-btn--primary);
   all this row does is size them. */
.slk-header__search-row{display:flex;gap:var(--slk-space-2)}
.slk-header__search-row .slk-input{flex:1;min-width:0}
.slk-header__search-row .slk-btn{flex:none}

/* -- header announcement line (slk_chrome_announcement_text()) ---------- */
/* Ground-coloured, not a banner: no fill, no border, just quiet centred
   meta sitting above the pill. An empty string from the PHP means the <p>
   never prints, so there is no empty-bar state to style around — and there
   is deliberately no `--over` variant: the renderer drops the line rather
   than print it onto a hero photograph. */
.slk-header__announce{
	margin:0;
	padding:10px 0;
	text-align:center;
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);
	letter-spacing:var(--slk-track-label);
	text-transform:uppercase;
	color:var(--slk-color-muted);
}

/* -- mobile nav drawer (design/sections/04-pages.html, "Mobile nav") ----- */
.slk-drawer{position:fixed;inset:0;z-index:80}
.slk-drawer[hidden]{display:none}
.slk-drawer .slk-scrim{background:rgba(35,34,32,.32)}
.slk-drawer__panel{
	position:absolute;inset:var(--slk-space-2);
	display:flex;flex-direction:column;
	background:rgba(250,249,246,.82);
	backdrop-filter:blur(30px) saturate(1.4);
	-webkit-backdrop-filter:blur(30px) saturate(1.4);
	border:1px solid var(--slk-glass-edge);
	border-radius:26px;
	box-shadow:0 24px 60px rgba(35,34,32,.28);
	overflow:auto;
	transition:transform var(--slk-motion-base) var(--slk-ease),
	           opacity var(--slk-motion-base) var(--slk-ease);
}
.slk-drawer__head{
	display:flex;align-items:center;justify-content:space-between;
	padding:var(--slk-space-2) 10px var(--slk-space-2) var(--slk-space-6);
}
.slk-drawer__close{background:rgba(35,34,32,.06)}
.slk-drawer__nav{padding:14px var(--slk-space-6) 0}
.slk-drawer__nav ul{list-style:none;margin:0;padding:0;display:grid}
.slk-drawer__nav li{border-bottom:1px solid var(--slk-hairline)}
.slk-drawer__nav li:last-child{border-bottom:0}
.slk-drawer__nav a{
	display:flex;align-items:center;min-height:56px;
	font-family:var(--slk-font-display);font-weight:300;font-size:26px;
	color:var(--slk-color-ink);text-decoration:none;
}
.slk-drawer__nav [aria-current=page]{color:var(--slk-color-muted)}
.slk-drawer__foot{margin-top:auto;padding:0 var(--slk-space-6) var(--slk-space-6)}
.slk-drawer__pills{display:flex;flex-wrap:wrap;gap:var(--slk-space-2);padding-bottom:var(--slk-space-4)}
.slk-drawer__pill{
	display:inline-flex;align-items:center;min-height:var(--slk-touch);
	padding:0 14px;
	background:var(--slk-glass-solid);
	border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-pill);
	font:400 12px/1 var(--slk-font-ui);
	color:var(--slk-color-ink);text-decoration:none;
}
.slk-drawer__pill:hover{background:var(--slk-color-white)}

/* -- the WhatsApp card: drawer (ink) and footer (glass) ----------------- */
.slk-drawer__wa,.slk-footer__wa{
	display:flex;align-items:center;gap:var(--slk-space-3);
	min-height:54px;padding:var(--slk-space-2) 10px var(--slk-space-2) 18px;
	border-radius:var(--slk-radius-pill);text-decoration:none;
}
.slk-drawer__wa{background:var(--slk-color-ink);color:var(--slk-color-on-ink)}
.slk-footer__wa{
	background:rgba(255,255,255,.70);
	border:1px solid var(--slk-glass-edge);
	color:var(--slk-color-ink);
	box-shadow:0 8px 22px rgba(35,34,32,.08);
	min-height:52px;
}
.slk-drawer__wa-text,.slk-footer__wa-text{
	flex:1;font:500 var(--slk-text-sm)/1.35 var(--slk-font-ui);
}
.slk-drawer__wa-text small,.slk-footer__wa-text small{
	display:block;font-weight:400;font-size:var(--slk-text-xs);
}
.slk-drawer__wa-text small{opacity:.7}
.slk-footer__wa-text small{color:var(--slk-color-faint)}
.slk-drawer__wa-mark,.slk-footer__wa-mark{
	flex:none;width:36px;height:36px;border-radius:50%;
	display:grid;place-items:center;font:600 11px var(--slk-font-ui);
}
.slk-drawer__wa-mark{background:var(--slk-color-on-ink);color:var(--slk-color-ink)}
.slk-footer__wa-mark{background:var(--slk-color-ink);color:var(--slk-color-on-ink)}
/* The footer card is a link, so §3.3's `.slk-footer a` inline-flex would
   fight the flex row above. Re-assert the card's own display. */
.slk-footer a.slk-footer__wa{display:flex}

/* -- footer lists ------------------------------------------------------- */
.slk-footer__list{list-style:none;margin:0;padding:0;display:grid;align-content:start}
/* §3.3 gives footer links the 44px min-height but not the width, so short
   labels ("New in", "Hijabs") measured 36-43px wide on the running page —
   under the guidelines §8 floor. The links are inline-flex and left-aligned,
   so a min-width only grows the hit box into empty column space: bigger
   target, identical appearance. */
.slk-footer__list a{font:400 13px/1.5 var(--slk-font-ui);min-width:var(--slk-touch)}
.slk-footer__col > .slk-wordmark{margin-bottom:var(--slk-space-3)}
.slk-footer__blurb{margin:0}
/* The copyright line repeats .slk-footer__inner's container so it lines up
   under the wordmark, and borrows the blurb's type — no new voice for four
   quiet words. */
.slk-footer__legal{
	max-width:var(--slk-container);
	margin:0 auto;
	padding:0 var(--slk-gutter) var(--slk-space-6);
	font:400 12.5px/1.7 var(--slk-font-ui);
	color:var(--slk-color-muted);
}

/* -- footer "We accept" chips (slk_chrome_payment_gateway_titles()) ------ */
/* Another grid sibling, same container as .slk-footer__legal below it.
   Text chips only — no gateway is ever drawn as a logo here. */
.slk-footer__accepts{
	max-width:var(--slk-container);
	margin:0 auto;
	padding:0 var(--slk-gutter) var(--slk-space-4);
	display:flex;align-items:center;flex-wrap:wrap;gap:var(--slk-space-3);
}
.slk-footer__accepts-list{
	display:flex;flex-wrap:wrap;gap:var(--slk-space-2);
	margin:0;padding:0;list-style:none;
}
.slk-footer__accepts-list li{
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);
	letter-spacing:.08em;
	border:1px solid var(--slk-hairline);
	border-radius:var(--slk-radius-pill);
	padding:6px 12px;
	color:var(--slk-color-muted);
}

@media (min-width:1000px){
	.slk-drawer__panel{inset:var(--slk-space-4) auto var(--slk-space-4) var(--slk-space-4);width:380px}
	/* Matches the grid's own padding-inline:0 at this width. */
	.slk-footer__legal{padding-inline:0}
	.slk-footer__accepts{padding-inline:0}
}

@media (prefers-reduced-motion:reduce){
	.slk-drawer__panel{transition-duration:1ms}
}
CSS;

		wp_add_inline_style( 'slk-child', $css );

		/*
		 * Inline-only script: registered with an empty src so WordPress prints
		 * the inline block in the footer without a second HTTP request. No
		 * jQuery dependency — the drawer is 40 lines of DOM work.
		 */
		wp_register_script( 'slk-chrome', '', array(), null, true );
		wp_enqueue_script( 'slk-chrome' );

		$js = <<<'JS'
(function () {
	var open = null;

	// Position-based scroll lock for the drawer. `overflow:hidden` on <html>
	// used to stop <html> being the scroll container, so the browser clamped
	// scrollTop to 0 and the page snapped to the top the instant the drawer
	// opened. Pinning <body> with position:fixed at its current offset keeps
	// the page visually still without losing the reading position, which is
	// restored on close. scrollLockY doubles as the re-entrancy guard: a
	// second lock() call while already locked would otherwise read
	// window.scrollY as 0 (the page is already fixed) and clobber the real
	// offset, and a stray unlock() call with nothing locked would scroll the
	// page to 0. Both are no-ops here, which is what keeps this idempotent
	// across the drawer and the non-modal search panel sharing setState().
	var scrollLockY = null;

	function lockScroll() {
		if (scrollLockY !== null) { return; }
		scrollLockY = window.scrollY || window.pageYOffset || 0;
		var body = document.body.style;
		body.position = 'fixed';
		body.top = '-' + scrollLockY + 'px';
		body.left = '0';
		body.right = '0';
		body.width = '100%';
	}

	function unlockScroll() {
		if (scrollLockY === null) { return; }
		var y = scrollLockY;
		scrollLockY = null;
		var body = document.body.style;
		body.position = '';
		body.top = '';
		body.left = '';
		body.right = '';
		body.width = '';
		window.scrollTo(0, y);
	}

	// Shared with the filter sheet in inc/moments.php, which is modal on the
	// same viewports the drawer is. Two independent locks would stack: the
	// second would read window.scrollY off an already-fixed <body> as 0 and
	// lose the real offset. One owner of scrollLockY, two callers.
	window.slkScrollLock = { lock: lockScroll, unlock: unlockScroll };

	function panelOf(id) { return document.getElementById(id); }

	function triggersFor(id) {
		return document.querySelectorAll('[data-slk-toggle="' + id + '"]');
	}

	function setState(id, isOpen) {
		var panel = panelOf(id);
		if (!panel) { return; }

		if (isOpen) { panel.removeAttribute('hidden'); }
		else { panel.setAttribute('hidden', ''); }

		Array.prototype.forEach.call(triggersFor(id), function (t) {
			t.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
		});

		if (panel.classList.contains('slk-drawer')) {
			// Restore scroll position before focus returns (below), so the
			// focus return does not itself scroll the page anywhere.
			if (isOpen) { lockScroll(); } else { unlockScroll(); }
		}

		// Tracked for every panel, modal or not, so Escape can close whatever
		// is open — see the keydown handler below.
		open = isOpen ? id : null;

		if (isOpen) {
			var first = panel.querySelector('input, button, a[href]');
			if (first) { first.focus(); }
		} else {
			var trigger = triggersFor(id)[0];
			if (trigger) { trigger.focus(); }
		}
	}

	document.addEventListener('click', function (e) {
		var toggle = e.target.closest('[data-slk-toggle]');
		if (toggle) {
			e.preventDefault();
			var id = toggle.getAttribute('data-slk-toggle');
			var panel = panelOf(id);
			setState(id, panel ? panel.hasAttribute('hidden') : false);
			return;
		}

		var closer = e.target.closest('[data-slk-close]');
		if (closer) {
			e.preventDefault();
			setState(closer.getAttribute('data-slk-close'), false);
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && open) { setState(open, false); return; }

		// Focus-trapping stays modal-only: a non-modal panel (the search bar)
		// never had a trap, and widening `open` to track it too must not
		// change that.
		var panel = open ? panelOf(open) : null;
		if (e.key !== 'Tab' || !panel || !panel.classList.contains('slk-drawer')) { return; }

		var items = panel.querySelectorAll('a[href], button, input');
		if (!items.length) { return; }

		var first = items[0];
		var last = items[items.length - 1];

		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	});
})();
JS;

		wp_add_inline_script( 'slk-chrome', $js );
	},
	30
);
