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
