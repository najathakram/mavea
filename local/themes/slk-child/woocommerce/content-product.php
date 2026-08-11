<?php
/**
 * The template for displaying product content within loops
 *
 * Porcelain Glass restyle, based on the real WooCommerce 11.0.1 template
 * (design/_reference/woocommerce-templates/content-product.php).
 *
 * WHY THE THREE INNER HOOKS ARE NOT FIRED HERE
 * --------------------------------------------
 * Blocksy renders its *entire* product card — image, title, price, meta and the
 * add-to-cart actions — from a single anonymous closure attached to
 * `woocommerce_before_shop_loop_item_title`, driven by its own `woo_card_layout`
 * theme mod (blocksy/inc/components/woocommerce/archive/product-card.php).
 *
 * Because that callback is a closure, `remove_action()` cannot address it by
 * name. Firing the hook therefore renders Blocksy's whole card *in addition to*
 * ours, which produced: two images, two titles, two prices — and, fatally, an
 * `<a class="ct-media-container">` nested inside our `<a class="slk-card">`.
 * Nested anchors are invalid HTML, so the browser closed our card link
 * immediately and every card rendered as an empty tile.
 *
 * So the three inner hooks are replaced by direct calls to the same renderers.
 * The two OUTER hooks still fire, because that is where third-party plugins
 * normally add card wrappers and badges, and nothing hijacks them.
 *
 * Consequence to know: a future plugin that adds card content via
 * `woocommerce_before/after_shop_loop_item_title` will not render here until it
 * is wired in deliberately. That is the intended trade — this card's markup is
 * owned by the design, not negotiated at runtime.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/content-product.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 9.4.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

// Check if the product is a valid WooCommerce product and ensure its visibility before proceeding.
if ( ! is_a( $product, WC_Product::class ) || ! $product->is_visible() ) {
	return;
}
?>
<li <?php wc_product_class( 'slk-grid__item', $product ); ?>>
	<?php
	/**
	 * Hook: woocommerce_before_shop_loop_item.
	 *
	 * @hooked slk_template_loop_product_link_open - 10 (replaces woocommerce_template_loop_product_link_open)
	 */
	do_action( 'woocommerce_before_shop_loop_item' );

	/*
	 * The card body. Called directly rather than via
	 * woocommerce_before_shop_loop_item_title / woocommerce_shop_loop_item_title /
	 * woocommerce_after_shop_loop_item_title — see the note at the top of this file.
	 *
	 * No sale badge and no star rating: neither appears on any product card in
	 * the Shop, Desktop shop or Hijabs browse mockups, and sale badges are
	 * forbidden brand-wide.
	 */
	slk_template_loop_product_thumbnail();
	slk_template_loop_product_title();
	slk_template_loop_price();
	slk_template_loop_swatches();

	/**
	 * Hook: woocommerce_after_shop_loop_item.
	 *
	 * @hooked slk_template_loop_product_link_close - 5 (replaces woocommerce_template_loop_product_link_close)
	 *
	 * The default quick "Add to cart" button (woocommerce_template_loop_add_to_cart,
	 * priority 10) is unhooked in inc/shop.php: no grid card in the design carries
	 * an add-to-cart control — "Add to bag" only appears on the PDP action dock.
	 */
	do_action( 'woocommerce_after_shop_loop_item' );
	?>
</li>
