<?php
/**
 * Cart Page — Porcelain Glass
 *
 * Based on the real WooCommerce 11.0.0 `cart/cart.php` (see
 * design/_reference/woocommerce-templates/cart/cart.php). All the original
 * hooks/filters are preserved in the same relative order so other plugins
 * keep working; only the markup around them is restyled from a <table> into
 * glass line-item cards per design/sections/06-mobile.html (#s-cart) and
 * design/sections/07-desktop.html (#d-cart).
 *
 * WooCommerce only routes to this template when the cart is non-empty
 * (WC_Shortcode_Cart branches to cart/cart-empty.php itself); the guard
 * below is a defensive fallback for any code path that calls this file
 * directly. The empty-cart screen from design/sections/04-pages.html is
 * implemented by reshaping the default cart-empty.php output via hooks in
 * inc/cart.php (see "EMPTY CART" section there) rather than by overriding
 * cart-empty.php, per file ownership.
 *
 * Text domain: slk.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package slk-child
 * @version 11.0.0 (base)
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );

if ( WC()->cart->is_empty() ) {
	wc_get_template( 'cart/cart-empty.php' );
	do_action( 'woocommerce_after_cart' );
	return;
}

$slk_cart_count = WC()->cart->get_cart_contents_count();
?>

<form class="woocommerce-cart-form slk-cart" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
	<?php do_action( 'woocommerce_before_cart_table' ); ?>

	<div class="slk-cart__head">
		<h1 class="slk-cart__title">
			<?php esc_html_e( 'Your bag', 'slk' ); ?>
			<span class="slk-cart__count">
				<?php
				printf(
					/* translators: %s: number of items in the cart. */
					esc_html( _n( '%s piece', '%s pieces', $slk_cart_count, 'slk' ) ),
					esc_html( number_format_i18n( $slk_cart_count ) )
				);
				?>
			</span>
		</h1>
	</div>

	<ul class="slk-cart-list" role="list">

		<?php do_action( 'woocommerce_before_cart_contents' ); ?>

		<?php
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

			/**
			 * Filter whether this cart item is visible in the cart.
			 *
			 * @since 2.1.0
			 * @param bool   $visible       Whether the cart item is visible. Default true.
			 * @param array  $cart_item     The cart item data.
			 * @param string $cart_item_key The cart item key.
			 */
			$visible = apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key );

			if ( $_product instanceof WC_Product && $_product->exists() && $cart_item['quantity'] > 0 && $visible ) {
				/**
				 * This filter is documented in the base WooCommerce template.
				 *
				 * @since 2.1.0
				 */
				$product_name      = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );
				$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
				?>
				<li class="woocommerce-cart-form__cart-item slk-cart-item <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">

					<div class="slk-cart-item__media">
						<?php
						/**
						 * This filter is documented in the base WooCommerce template.
						 *
						 * @since 2.1.0
						 */
						$thumbnail = apply_filters(
							'woocommerce_cart_item_thumbnail',
							$_product->get_image( 'woocommerce_thumbnail', array( 'class' => 'slk-cart-item__img' ) ),
							$cart_item,
							$cart_item_key
						);

						if ( ! $product_permalink ) {
							echo $thumbnail; // PHPCS: XSS ok, filtered.
						} else {
							printf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail ); // PHPCS: XSS ok, filtered.
						}
						?>
					</div>

					<div class="slk-cart-item__body">
						<div class="slk-cart-item__top">
							<div class="slk-cart-item__id" data-title="<?php esc_attr_e( 'Product', 'slk' ); ?>">
								<div class="slk-cart-item__name">
									<?php
									if ( ! $product_permalink ) {
										echo wp_kses_post( $product_name );
									} else {
										/**
										 * This filter is documented in the base WooCommerce template.
										 *
										 * @since 2.1.0
										 */
										echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $_product->get_name() ), $cart_item, $cart_item_key ) );
									}

									do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );
									?>
								</div>
								<div class="slk-cart-item__meta">
									<?php
									// Variation / meta data (e.g. colour, size).
									echo wc_get_formatted_cart_item_data( $cart_item ); // PHPCS: XSS ok, escaped by WooCommerce.

									if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
										echo wp_kses_post( apply_filters( 'woocommerce_cart_item_backorder_notification', '<p class="backorder_notification">' . esc_html__( 'Available on backorder', 'woocommerce' ) . '</p>', $product_id ) );
									}
									?>
								</div>
							</div>
							<div class="slk-cart-item__price" data-title="<?php esc_attr_e( 'Price', 'slk' ); ?>">
								<?php
									echo apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key ); // PHPCS: XSS ok, filtered.
								?>
							</div>
						</div>

						<div class="slk-cart-item__footer">
							<div class="slk-qty" data-title="<?php esc_attr_e( 'Quantity', 'slk' ); ?>">
								<?php
								if ( $_product->is_sold_individually() ) {
									$min_quantity = 1;
									$max_quantity = 1;
								} else {
									$min_quantity = 0;
									$max_quantity = $_product->get_max_purchase_quantity();
								}

								$slk_can_step_down = ( 0 === $min_quantity ) || ( $cart_item['quantity'] > $min_quantity );
								$slk_can_step_up   = ( $max_quantity < 0 ) || ( $cart_item['quantity'] < $max_quantity );
								?>
								<button
									type="button"
									class="slk-qty__btn slk-qty__btn--down"
									data-slk-qty-step="-1"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'One fewer %s', 'slk' ), wp_strip_all_tags( $product_name ) ) ); ?>"
									<?php disabled( ! $slk_can_step_down ); ?>
								>&minus;</button>
								<?php
								$product_quantity = woocommerce_quantity_input(
									array(
										'input_name'   => "cart[{$cart_item_key}][qty]",
										'input_value'  => $cart_item['quantity'],
										'max_value'    => $max_quantity,
										'min_value'    => $min_quantity,
										'product_name' => $product_name,
										'classes'      => array( 'input-text', 'qty', 'text', 'slk-qty__input' ),
									),
									$_product,
									false
								);

								echo apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item ); // PHPCS: XSS ok, filtered.
								?>
								<button
									type="button"
									class="slk-qty__btn slk-qty__btn--up"
									data-slk-qty-step="1"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'One more %s', 'slk' ), wp_strip_all_tags( $product_name ) ) ); ?>"
									<?php disabled( ! $slk_can_step_up ); ?>
								>&#65291;</button>
							</div>

							<div class="slk-cart-item__remove-cell" data-title="<?php esc_attr_e( 'Remove', 'slk' ); ?>">
								<?php
									echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										'woocommerce_cart_item_remove_link',
										sprintf(
											'<a role="button" href="%s" class="remove slk-cart-item__remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">%s</a>',
											esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
											/* translators: %s is the product name */
											esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), wp_strip_all_tags( $product_name ) ) ),
											esc_attr( $product_id ),
											esc_attr( $_product->get_sku() ),
											esc_html__( 'Remove', 'slk' )
										),
										$cart_item_key
									);
								?>
							</div>

							<div class="slk-cart-item__subtotal" data-title="<?php esc_attr_e( 'Subtotal', 'slk' ); ?>">
								<?php
									echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // PHPCS: XSS ok, filtered.
								?>
							</div>
						</div>
					</div>
				</li>
				<?php
			}
		}
		?>

		<?php do_action( 'woocommerce_cart_contents' ); ?>

	</ul>

	<a class="slk-cart-continue" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
		<?php esc_html_e( '← Keep browsing', 'slk' ); ?>
	</a>

	<div class="slk-cart-actions">

		<?php if ( wc_coupons_enabled() ) : ?>
			<details class="slk-cart-coupon">
				<summary class="slk-cart-coupon__toggle"><?php esc_html_e( 'Have a coupon?', 'slk' ); ?></summary>
				<div class="slk-field slk-cart-coupon__row">
					<label for="coupon_code"><?php esc_html_e( 'Coupon code', 'slk' ); ?></label>
					<div class="slk-cart-coupon__inline">
						<input
							type="text"
							name="coupon_code"
							class="slk-input"
							id="coupon_code"
							value=""
							placeholder="<?php esc_attr_e( 'Enter code', 'slk' ); ?>"
						/>
						<button
							type="submit"
							class="slk-btn slk-btn--secondary"
							name="apply_coupon"
							value="<?php esc_attr_e( 'Apply coupon', 'woocommerce' ); ?>"
						><?php esc_html_e( 'Apply', 'slk' ); ?></button>
					</div>
					<?php do_action( 'woocommerce_cart_coupon' ); ?>
				</div>
			</details>
		<?php endif; ?>

		<button
			type="submit"
			class="slk-btn slk-btn--ghost slk-cart-update"
			name="update_cart"
			value="<?php esc_attr_e( 'Update cart', 'woocommerce' ); ?>"
		><?php esc_html_e( 'Update cart', 'slk' ); ?></button>

		<?php do_action( 'woocommerce_cart_actions' ); ?>

		<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
	</div>

	<?php do_action( 'woocommerce_after_cart_contents' ); ?>

	<?php do_action( 'woocommerce_after_cart_table' ); ?>
</form>

<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

<div class="cart-collaterals slk-cart-collaterals">
	<?php
		/**
		 * Cart collaterals hook.
		 *
		 * @hooked woocommerce_cross_sell_display
		 * @hooked woocommerce_cart_totals - 10
		 */
		do_action( 'woocommerce_cart_collaterals' );
	?>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>
