<?php
/**
 * 404 — "This page isn't here."
 *
 * design/sections/04-pages.html "Not found". Never a dead end: two routes
 * back into the shop (browse everything, track an order) plus a small rail
 * of products, reusing the same "you looked at these" rail component
 * inc/cart.php's empty-cart screen already defines (.slk-cart-empty__rail /
 * __card), so this ships no parallel rail styling.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$related = function_exists( 'slk_cart_empty_related_products' ) ? slk_cart_empty_related_products( 2 ) : array();
?>

<main id="primary" class="slk-page slk-404">
	<div class="slk-404__hero">
		<div class="slk-404__num" aria-hidden="true">404</div>
		<h1><?php esc_html_e( "This page isn't here.", 'slk' ); ?></h1>
		<p><?php esc_html_e( 'It may have sold out and been taken down. Everything we are making now is one tap away.', 'slk' ); ?></p>
		<div class="slk-404__actions">
			<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( slk_chrome_shop_url() ); ?>"><?php esc_html_e( 'Shop everything', 'slk' ); ?></a>
			<a class="slk-btn slk-btn--secondary" href="<?php echo esc_url( slk_page_url( 'track-order' ) ); ?>"><?php esc_html_e( 'Track an order', 'slk' ); ?></a>
		</div>
	</div>

	<?php if ( ! empty( $related ) ) : ?>
		<div class="slk-cart-empty__related">
			<div class="slk-eyebrow slk-cart-empty__related-label"><?php esc_html_e( 'You might like', 'slk' ); ?></div>
			<div class="slk-cart-empty__rail">
				<?php foreach ( $related as $product ) : ?>
					<a class="slk-card slk-cart-empty__card" href="<?php echo esc_url( $product->get_permalink() ); ?>">
						<span class="slk-card__media">
							<?php echo $product->get_image( 'woocommerce_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce-escaped. ?>
						</span>
						<span class="slk-card__name"><?php echo esc_html( $product->get_name() ); ?></span>
						<span class="slk-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
</main>

<?php
get_footer();
