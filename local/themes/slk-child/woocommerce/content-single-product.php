<?php
/**
 * The template for displaying product content in the single-product.php template
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/content-single-product.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * ===========================================================================
 * SLK CHILD — Porcelain Glass PDP.
 * Based verbatim on the real WooCommerce 3.6.0 template (see the @version
 * below, unchanged from core). The only edits are:
 *   1. An extra 'slk-pdp' class added via wc_product_class()'s first arg, so
 *      the whole product wrapper can be targeted for the two-column desktop
 *      grid without touching any other theme's `.product` styling.
 *   2. Extra classes + two data-* attributes added to the existing
 *      `.summary.entry-summary` div — no markup removed, no hooks removed,
 *      no hooks reordered.
 *        - data-slk-sold-out : JSON map of {attribute_dom_name: [sold-out
 *          option values]}, computed in inc/pdp.php from real variation
 *          stock data, so sold-out sizes can render struck-through instead
 *          of relying on WooCommerce's native (combination-only) disabling.
 *        - data-slk-fit-hint : the 'slk_pdp_fit_hint' filter's output
 *          (empty by default — no per-size measurement source exists yet).
 *   3. woocommerce_before_single_product_summary and
 *      woocommerce_single_product_summary run exactly as core dictates.
 *      The visual restyle (image grid, sticky glass panel, colour swatches,
 *      size pills, WhatsApp button, trust rows) lives entirely in
 *      inc/pdp.php as CSS + progressive-enhancement JS + extra hook
 *      callbacks, per brand law 7 (file ownership) and law 8 (prefer hooks
 *      over template overrides).
 * ===========================================================================
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.6.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

/**
 * Hook: woocommerce_before_single_product.
 *
 * @hooked woocommerce_output_all_notices - 10
 */
do_action( 'woocommerce_before_single_product' );

if ( post_password_required() ) {
	echo get_the_password_form(); // WPCS: XSS ok.
	return;
}

// Computed once here so the data-* attributes below stay pure output, no
// business logic in the template itself (that lives in inc/pdp.php).
$slk_sold_out_map = function_exists( 'slk_pdp_compute_sold_out_values' ) ? slk_pdp_compute_sold_out_values( $product ) : array();
$slk_fit_hint      = apply_filters( 'slk_pdp_fit_hint', '', $product );
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'slk-pdp', $product ); ?>>

	<?php
	/**
	 * Hook: woocommerce_before_single_product_summary.
	 *
	 * @hooked woocommerce_show_product_sale_flash - 10 (removed theme-wide, brand law 3)
	 * @hooked woocommerce_show_product_images - 20
	 */
	do_action( 'woocommerce_before_single_product_summary' );
	?>

	<div class="summary entry-summary slk-panel slk-panel--lifted slk-pdp__summary"
		data-slk-sold-out="<?php echo esc_attr( wp_json_encode( $slk_sold_out_map ) ); ?>"
		data-slk-fit-hint="<?php echo esc_attr( $slk_fit_hint ); ?>">
		<?php
		/**
		 * Hook: woocommerce_single_product_summary.
		 *
		 * @hooked woocommerce_template_single_title   - 5
		 * @hooked woocommerce_template_single_rating   - 10 (visually hidden, see inc/pdp.php — not part of this design)
		 * @hooked woocommerce_template_single_price    - 10
		 * @hooked woocommerce_template_single_excerpt  - 20 (the one-line fit description)
		 * @hooked woocommerce_template_single_add_to_cart - 30
		 * @hooked slk_pdp_whatsapp_button              - 31 (inc/pdp.php)
		 * @hooked woocommerce_template_single_meta     - 40 (visually hidden, see inc/pdp.php)
		 * @hooked slk_pdp_trust_rows                   - 45 (inc/pdp.php)
		 * @hooked woocommerce_template_single_sharing  - 50
		 * @hooked WC_Structured_Data::generate_product_data() - 60
		 */
		do_action( 'woocommerce_single_product_summary' );
		?>
	</div>

	<?php
	/**
	 * Hook: woocommerce_after_single_product_summary.
	 *
	 * @hooked woocommerce_output_product_data_tabs - 10
	 * @hooked woocommerce_upsell_display - 15
	 * @hooked woocommerce_output_related_products - 20
	 */
	do_action( 'woocommerce_after_single_product_summary' );
	?>
</div>

<?php do_action( 'woocommerce_after_single_product' ); ?>
