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
				'slk_footer_shop' => __( 'Footer — Shop column', 'slk' ),
				'slk_footer_help' => __( 'Footer — Help column', 'slk' ),
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
 * Editorial pages (size guide, delivery, exchanges) do not exist on a fresh
 * install, so they are included only once someone publishes them. My account
 * always exists under WooCommerce, so the column is never empty.
 *
 * @return array<int,array{label:string,url:string}>
 */
function slk_chrome_help_links() {
	$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';

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

/* -------------------------------------------------------------------------
 * 4. The bag button
 *
 * One function, two callers: the header renders it, and the
 * `woocommerce_add_to_cart_fragments` filter re-renders it so the count
 * updates after an AJAX add-to-cart without a page load. The fragment key is
 * the element's own selector — WooCommerce replaces whatever matches it.
 * ---------------------------------------------------------------------- */

/**
 * The bag icon button, with the live cart count.
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

	return sprintf(
		'<a class="slk-icon-btn slk-bag" href="%1$s" aria-label="%2$s"><span class="slk-bag__count" aria-hidden="true">%3$s</span></a>',
		esc_url( $url ),
		esc_attr(
			sprintf(
				/* translators: %s: number of items in the bag. */
				_n( 'Bag, %s item', 'Bag, %s items', $count, 'slk' ),
				number_format_i18n( $count )
			)
		),
		esc_html( number_format_i18n( $count ) )
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
	?>
	<header class="slk-header <?php echo $over ? 'slk-header--over' : 'slk-header--solid'; ?>">
		<div class="slk-header__inner">
			<?php
			echo slk_wordmark_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
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
					aria-label="<?php esc_attr_e( 'Menu', 'slk' ); ?>"><span aria-hidden="true">☰</span></button>

				<button type="button"
					class="slk-icon-btn"
					data-slk-toggle="slk-header-search"
					aria-controls="slk-header-search"
					aria-expanded="false"
					aria-label="<?php esc_attr_e( 'Search', 'slk' ); ?>"><span aria-hidden="true">⌕</span></button>

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
					aria-label="<?php esc_attr_e( 'Close menu', 'slk' ); ?>"><span aria-hidden="true">✕</span></button>
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
		__( 'Modest ready-to-wear, made in small runs in Galle. Address and business numbers will sit here.', 'slk' )
	);

	$help = slk_chrome_help_links();
	$wa   = function_exists( 'slk_whatsapp_url' )
		? slk_whatsapp_url( __( 'Hi! I have a question.', 'slk' ) )
		: '';
	?>
	<footer class="slk-footer">
		<div class="slk-footer__inner">
			<div class="slk-footer__col">
				<?php
				echo slk_wordmark_markup( array( 'size' => 'md' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
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
				<?php else : ?>
					<p class="slk-footer__blurb"><?php esc_html_e( 'A WhatsApp line opens with the relaunch.', 'slk' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
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

@media (min-width:1000px){
	.slk-drawer__panel{inset:var(--slk-space-4) auto var(--slk-space-4) var(--slk-space-4);width:380px}
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

		var modal = panel.classList.contains('slk-drawer');
		if (modal) {
			document.documentElement.style.overflow = isOpen ? 'hidden' : '';
			open = isOpen ? id : null;
		}

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
		if (e.key !== 'Tab' || !open) { return; }

		// Keep focus inside the dialog while it is modal.
		var panel = panelOf(open);
		var items = panel ? panel.querySelectorAll('a[href], button, input') : [];
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
