<?php
/**
 * SLK child theme — PHP core.
 *
 * Parent: Blocksy. This file owns bootstrap only: asset enqueue, webfonts,
 * WooCommerce theme support, the inc/ autoloader, sale-flash suppression and
 * the product-grid shape. No business logic, no checkout logic — those live in
 * their own inc/*.php files.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filemtime-keyed version so dev caches bust automatically, with a stable
 * fallback if the file is missing (e.g. during deploy).
 */
function slk_asset_version( $path ) {
	return file_exists( $path ) ? (string) filemtime( $path ) : '0.1.0';
}

/* -------------------------------------------------------------------------
 * 1. Fonts — preconnect
 *
 * Newsreader (display, opsz 6..72, weights 200-500) and Archivo (UI, 300-600).
 * ---------------------------------------------------------------------- */

const SLK_GOOGLE_FONTS_HANDLE = 'slk-google-fonts';

add_filter(
	'wp_resource_hints',
	static function ( $urls, $relation_type ) {
		if ( 'preconnect' === $relation_type ) {
			$urls[] = array(
				'href'        => 'https://fonts.gstatic.com',
				'crossorigin' => 'anonymous',
			);
			$urls[] = array( 'href' => 'https://fonts.googleapis.com' );
		}

		return $urls;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * 2. Stylesheets — parent first, child second, fonts before both.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style(
			SLK_GOOGLE_FONTS_HANDLE,
			'https://fonts.googleapis.com/css2'
				. '?family=Archivo:wght@300;400;500;600'
				. '&family=Newsreader:opsz,wght@6..72,200..500'
				. '&display=swap',
			array(),
			null // Versioning a Google Fonts URL only breaks their cache.
		);

		$parent_style = get_template_directory() . '/style.css';

		if ( file_exists( $parent_style ) ) {
			wp_enqueue_style(
				'slk-parent',
				get_template_directory_uri() . '/style.css',
				array( SLK_GOOGLE_FONTS_HANDLE ),
				slk_asset_version( $parent_style )
			);
			$child_deps = array( SLK_GOOGLE_FONTS_HANDLE, 'slk-parent' );
		} else {
			$child_deps = array( SLK_GOOGLE_FONTS_HANDLE );
		}

		wp_enqueue_style(
			'slk-child',
			get_stylesheet_uri(),
			$child_deps,
			slk_asset_version( get_stylesheet_directory() . '/style.css' )
		);
	},
	20
);

/* -------------------------------------------------------------------------
 * 3. Autoload inc/*.php
 *
 * Every feature file in inc/ is activated here. Other agents own those files;
 * this loader is deliberately dumb — glob, sort, require_once.
 * ---------------------------------------------------------------------- */

add_action(
	'after_setup_theme',
	static function () {
		$files = glob( get_stylesheet_directory() . '/inc/*.php' );

		if ( empty( $files ) ) {
			return;
		}

		sort( $files, SORT_STRING );

		foreach ( $files as $file ) {
			require_once $file;
		}
	},
	5
);

/* -------------------------------------------------------------------------
 * 4. WooCommerce support
 * ---------------------------------------------------------------------- */

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
		add_theme_support( 'wc-product-gallery-slider' );
	},
	11
);

/* -------------------------------------------------------------------------
 * 5. No sale theatre (brand law 3)
 *
 * Verified against the real WooCommerce 11.0.1 templates:
 *   loop/sale-flash.php + content-product.php
 *     -> woocommerce_show_product_loop_sale_flash on
 *        woocommerce_before_shop_loop_item_title, priority 10
 *   content-single-product.php
 *     -> woocommerce_show_product_sale_flash on
 *        woocommerce_before_single_product_summary, priority 10
 *
 * Both actions are removed. The woocommerce_sale_flash filter is also emptied
 * so any third party calling wc_get_template( 'loop/sale-flash.php' ) directly
 * still renders nothing.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
		remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
	},
	20
);

add_filter( 'woocommerce_sale_flash', '__return_empty_string', 99 );

/* -------------------------------------------------------------------------
 * 6. Product grid shape — 3:4 portrait cards
 *
 * 3 across, 12 per page: divisible by 3 (desktop) and by 2 (mobile), so the
 * last row is never a widow.
 * ---------------------------------------------------------------------- */

add_filter( 'loop_shop_columns', static fn() => 3, 20 );
add_filter( 'loop_shop_per_page', static fn() => 12, 20 );

add_filter(
	'woocommerce_product_thumbnails_columns',
	static fn() => 3,
	20
);

/* -------------------------------------------------------------------------
 * 6a. Product imagery — real card and gallery-thumbnail sizes
 *
 * slk_template_loop_product_thumbnail() (inc/shop.php) used to request
 * 'woocommerce_thumbnail' — WooCommerce's hard SQUARE crop — and pour it
 * into the 3:4 portrait card with object-fit:cover: the source photo
 * (1153x1537, an exact 3:4) was cropped to square, cropped again on the
 * sides by the tile, then upscaled ~1.2x on a ~270px card. 'slk_card' is the
 * real 3:4 crop the card actually needs, registered large enough to cover
 * retina at the card's rendered width.
 *
 * The PDP gallery pills are the same defect: a 100x100 square laid out around
 * 196x261 (inc/pdp.php). Blocksy renders that gallery itself
 * (inc/components/gallery.php) and asks wp_get_attachment_image() for the
 * registered 'woocommerce_gallery_thumbnail' BY NAME — it never consults
 * WooCommerce's woocommerce_gallery_thumbnail_size filter, so pointing that
 * filter at a size of our own left the pills on 100x100 (confirmed in the
 * rendered DOM). The registered size itself is the only lever that reaches
 * them; 300x400 is the 3:4 crop the pill needs with headroom for retina.
 *
 * Regenerate thumbnails after deploying this: `wp media regenerate --yes`.
 * ---------------------------------------------------------------------- */

add_image_size( 'slk_card', 600, 800, true );

add_filter(
	'woocommerce_get_image_size_gallery_thumbnail',
	static fn() => array(
		'width'  => 300,
		'height' => 400,
		'crop'   => 1,
	)
);

/*
 * WordPress's default `sizes` guess for a 300w image
 * ("(max-width: 300px) 100vw, 300px") assumes the image can run the full
 * viewport width. The gallery pill never does — it is one of three columns
 * inside the gallery's thumbnail strip (inc/pdp.php), painting at roughly
 * 196px regardless of viewport. Left uncorrected, the browser fetches the
 * largest srcset candidate for a slot a third that size.
 *
 * Matched on the resolved dimensions rather than a size name: core hands this
 * filter the array( width, height ) it read off the image, never the
 * registered name, so a name comparison here can never fire.
 */
add_filter(
	'wp_calculate_image_sizes',
	static function ( $sizes, $size ) {
		if ( ! is_array( $size ) || ! isset( $size[0], $size[1] ) ) {
			return $sizes;
		}

		return ( 300 === (int) $size[0] && 400 === (int) $size[1] ) ? '196px' : $sizes;
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * 7. PDP size-guide route
 *
 * inc/pdp.php applies slk_pdp_size_guide_url and nothing registered it, so
 * the link shipped as '#slk-size-guide' — an anchor to nothing. The Size
 * Guide page is created by inc/pages-brand.php; slk_chrome_page_url() returns
 * '' when it is missing or unpublished, and inc/pdp.php then prints no link
 * at all rather than a dead one — the same discipline the chrome help links
 * already follow.
 * ---------------------------------------------------------------------- */

add_filter(
	'slk_pdp_size_guide_url',
	static fn() => slk_chrome_page_url( 'size-guide' )
);
