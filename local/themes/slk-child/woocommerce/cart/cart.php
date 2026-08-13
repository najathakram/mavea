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
 * Ready dates and split shipments (slk-checkout's fulfilment feature) are
 * layered on top of the same markup:
 *   - every line gains a "Ready <date>." note under the product name, from
 *     SLK_Shipments::line_ready_date();
 *   - when more than one ready date is in the cart and splitting is turned
 *     on, a choice of "send together" or "send each piece as soon as it is
 *     ready" appears above the list;
 *   - the item loop itself is pulled into a small closure, $slk_render_cart_item,
 *     so the exact same row markup can print either flat (one shipment) or
 *     grouped under shipment headings (more than one), without duplicating
 *     the row.
 * See inc/cart.php for the styling and the ship-mode toggle's behaviour.
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

/*
 * Ready dates and the shipment choice. SLK_Fulfilment / SLK_Calendar /
 * SLK_Shipments are slk-checkout's fulfilment API (see that plugin's
 * includes/class-slk-shipments.php and neighbours) — settings, working-day
 * maths, and grouping the cart into one or more shipments.
 *
 * $slk_shipments is built with whatever mode the shopper currently has
 * (defaulting to "together"), so it is what actually renders below: one
 * entry means one shipment, whatever the session mode says, because there
 * is nothing to split. $slk_split_candidates always asks what SPLIT mode
 * would produce, purely to decide whether the choice is worth showing at
 * all — offering a split that would not change anything reads as a broken
 * control, not a feature.
 *
 * $slk_district must be the district the delivery charge is actually rated
 * against, or the per-shipment fees printed below stop adding up to the
 * Delivery line in the same totals block. WC_Cart::get_shipping_packages()
 * fills the package destination from the SHIPPING address, and that package is
 * what SLK_Shipping_Method::calculate_shipping() prices, so read that same
 * field. It must not fall back to the billing state when it is empty: an empty
 * shipping state rates at the island band, so quoting the billing district
 * instead would print a fee nobody is being charged. WooCommerce already
 * copies the billing state into the shipping state for a shopper with no
 * shipping address of their own (WC_Customer_Data_Store_Session::set_defaults),
 * which is every shopper here, shipping being billing-only.
 */
$slk_settings         = SLK_Fulfilment::settings();
$slk_district         = WC()->customer ? (string) WC()->customer->get_shipping_state() : '';
$slk_cart_items       = WC()->cart->get_cart();
$slk_shipments        = SLK_Shipments::build();
$slk_split_candidates = SLK_Shipments::build( null, SLK_Shipments::MODE_SPLIT );
$slk_show_ship_choice = ! empty( $slk_settings['split_enabled'] ) && count( $slk_split_candidates ) > 1;
$slk_ship_mode        = SLK_Shipments::mode();

/**
 * Render one cart line. Pulled out of the main loop so the identical markup
 * can be printed flat (a single shipment) or nested under a shipment heading
 * (more than one), without keeping two copies of the row in sync.
 *
 * @param string $cart_item_key Cart item key.
 * @param array  $cart_item     Cart item data.
 */
$slk_render_cart_item = function ( $cart_item_key, $cart_item ) {
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

	if ( ! ( $_product instanceof WC_Product && $_product->exists() && $cart_item['quantity'] > 0 && $visible ) ) {
		return;
	}

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
					<?php
					$slk_ready = SLK_Shipments::line_ready_date( $_product, (int) $cart_item['quantity'] );
					if ( $slk_ready ) :
						?>
						<span class="slk-cart__ready">
							<?php
							printf(
								/* translators: %s: a date, or the words today or tomorrow. */
								esc_html__( 'Ready %s.', 'slk' ),
								esc_html( SLK_Calendar::label( $slk_ready, SLK_Fulfilment::settings() ) )
							);
							?>
						</span>
					<?php endif; ?>
					<div class="slk-cart-item__meta">
						<?php
						// Variation / meta data (e.g. colour, size).
						//
						// WooCommerce core's own "Available on backorder" notice
						// (woocommerce_cart_item_backorder_notification) is
						// deliberately not printed here: the ready-date line above
						// already tells the shopper what they need to know, as a
						// date rather than the word "backorder" the store's copy
						// rules forbid on customer-facing screens.
						echo wc_get_formatted_cart_item_data( $cart_item ); // PHPCS: XSS ok, escaped by WooCommerce.
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
};
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

	<?php if ( $slk_show_ship_choice ) : ?>
		<fieldset class="slk-cart__ship-mode">
			<legend class="slk-cart__ship-mode-legend"><?php esc_html_e( 'How should this order travel?', 'slk' ); ?></legend>

			<label class="slk-cart__ship-mode-option">
				<input
					type="radio"
					name="slk_ship_mode"
					value="<?php echo esc_attr( SLK_Shipments::MODE_TOGETHER ); ?>"
					data-slk-ship-mode
					<?php checked( SLK_Shipments::MODE_SPLIT !== $slk_ship_mode, true ); ?>
				/>
				<?php esc_html_e( 'Send everything together.', 'slk' ); ?>
			</label>

			<label class="slk-cart__ship-mode-option">
				<input
					type="radio"
					name="slk_ship_mode"
					value="<?php echo esc_attr( SLK_Shipments::MODE_SPLIT ); ?>"
					data-slk-ship-mode
					<?php checked( SLK_Shipments::MODE_SPLIT === $slk_ship_mode, true ); ?>
				/>
				<?php esc_html_e( 'Send each piece as soon as it is ready.', 'slk' ); ?>
			</label>
		</fieldset>
	<?php endif; ?>

	<ul class="slk-cart-list" role="list">

		<?php do_action( 'woocommerce_before_cart_contents' ); ?>

		<?php if ( count( $slk_shipments ) > 1 ) : ?>

			<?php foreach ( $slk_shipments as $slk_index => $slk_shipment ) : ?>
				<li class="slk-cart__shipment">
					<div class="slk-cart__shipment-head">
						<p class="slk-cart__shipment-title">
							<?php
							printf(
								/* translators: 1: this shipment's number, 2: total number of shipments, 3: when this shipment ships, already carrying its own preposition: "tomorrow" or "on 5 August". */
								esc_html__( 'Shipment %1$s of %2$s, ships %3$s.', 'slk' ),
								esc_html( number_format_i18n( $slk_index + 1 ) ),
								esc_html( number_format_i18n( count( $slk_shipments ) ) ),
								esc_html( SLK_Calendar::when( $slk_shipment['ready_date'], $slk_settings ) )
							);
							?>
						</p>
						<?php
						$slk_shipment_fee = SLK_Shipments::fee_for_shipment( (float) $slk_shipment['subtotal'], $slk_district, 0 === $slk_index );
						if ( $slk_shipment_fee > 0 ) :
							$slk_shipment_shortfall = SLK_Shipments::shortfall( (float) $slk_shipment['subtotal'] );
							?>
							<p class="slk-cart__shipment-fee">
								<?php
								printf(
									/* translators: %s: the delivery fee for this shipment. */
									esc_html__( 'Delivery for this shipment is %s.', 'slk' ),
									wp_kses_post( wc_price( $slk_shipment_fee ) )
								);
								?>
								<?php if ( $slk_shipment_shortfall > 0 ) : ?>
									<?php
									printf(
										/* translators: %s: how much more this shipment needs to travel free. */
										esc_html__( 'Add %s more to this shipment and it travels free.', 'slk' ),
										wp_kses_post( wc_price( $slk_shipment_shortfall ) )
									);
									?>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</div>

					<ul class="slk-cart__shipment-items" role="list">
						<?php
						foreach ( $slk_shipment['keys'] as $slk_key ) {
							if ( isset( $slk_cart_items[ $slk_key ] ) ) {
								$slk_render_cart_item( $slk_key, $slk_cart_items[ $slk_key ] );
							}
						}
						?>
					</ul>
				</li>
			<?php endforeach; ?>

		<?php else : ?>

			<?php
			foreach ( $slk_cart_items as $cart_item_key => $cart_item ) {
				$slk_render_cart_item( $cart_item_key, $cart_item );
			}
			?>

		<?php endif; ?>

		<?php do_action( 'woocommerce_cart_contents' ); ?>

	</ul>

	<?php if ( 1 === count( $slk_shipments ) ) : ?>
		<p class="slk-cart__ship-note">
			<?php
			printf(
				/* translators: %s: when the whole order ships, already carrying its own preposition: "tomorrow" or "on 5 August". */
				esc_html__( 'Everything ships %s.', 'slk' ),
				esc_html( SLK_Calendar::when( $slk_shipments[0]['ready_date'], $slk_settings ) )
			);
			?>
		</p>
	<?php endif; ?>

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
