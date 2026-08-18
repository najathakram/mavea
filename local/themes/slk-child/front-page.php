<?php
/**
 * The Home page.
 *
 * Reproduced from design/sections/06-mobile.html ("Home") and
 * design/sections/07-desktop.html ("Desktop home"): a full-bleed hero with a
 * glass panel over it, the category chips, the "Ready to wear" rail, the
 * paying panel beside the atelier frame, the "Everyday hijabs" colour rail and
 * the trust strip.
 *
 * Markup only. The queries, the counts, the card renderer, the page
 * provisioning and every line of CSS live in inc/home.php (brand law 10).
 *
 * The page renders inside Blocksy's <main>, between inc/chrome.php's header
 * pill and its footer; no second <main> and no second <h1> are opened here.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

/*
 * Portrait frame on the phone, landscape from 1000px — the same breakpoint
 * inc/home.php flips .slk-hero__media from 4:5 to 16:9 at.
 *
 * The alt has to hold for both frames at once (see slk_editorial_picture), so
 * it says the one thing both of them show and stops. The colour-by-colour
 * descriptions the other slots use live on their attachments, where exactly one
 * photograph can ever answer to them.
 */
$slk_hero = slk_editorial_picture(
	'hero_alt',
	'hero_group',
	__( 'Three abayas from the collection, worn full length.', 'slk' ),
	'full',
	array(
		'loading'       => 'eager',
		'fetchpriority' => 'high',
	)
);

$slk_atelier = slk_editorial_image( 'room_wide', 'large' );

$slk_new      = slk_home_new_count();
$slk_shop_url = slk_home_new_in_url();
$slk_story    = slk_home_page_link( 'story' );
$slk_delivery = slk_home_page_link( 'delivery' );
$slk_garments = slk_home_new_in_products( 4 );
$slk_hijabs   = slk_home_hijab_products( 6 );
$slk_from     = slk_home_from_price( $slk_hijabs );

/*
 * Two separate questions, two separate sentences below: is there a fee at all
 * (the amount, as /delivery/ and /faq/ ask it), and can we print it (the text,
 * which is '' when wc_price() is unavailable).
 */
$slk_cod_amount = slk_delivery_cod_fee();
$slk_cod_fee    = slk_home_cod_fee_text();

/*
 * Cash on delivery is the only gateway installed today. The rest of this
 * sentence is read from WooCommerce's own registered gateways, never typed,
 * so it can never again name a payment method that is not actually live —
 * the same discipline slk_delivery_cod_fee() already applies to the fee.
 *
 * Enabled, not available: get_available_payment_gateways() answers for the
 * current cart, and the home page's cart is empty — where cash on delivery
 * declares itself unavailable, so the available set is empty here rather than
 * ['cod']. Reading the enabled set instead means the sentence turns itself on
 * the moment a second gateway is switched on; with COD alone the list stays
 * empty and the sentence is dropped rather than printed false.
 */
$slk_other_payment_methods = array();

if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
	foreach ( WC()->payment_gateways()->payment_gateways() as $slk_gateway_id => $slk_gateway ) {
		if ( 'cod' === $slk_gateway_id || 'yes' !== $slk_gateway->enabled ) {
			continue;
		}

		$slk_other_payment_methods[] = wp_strip_all_tags( $slk_gateway->get_title() );
	}
}

$slk_days_metro  = function_exists( 'slk_delivery_days' ) ? slk_delivery_days( 0 ) : '';
$slk_days_island = function_exists( 'slk_delivery_days' ) ? slk_delivery_days( 2 ) : '';

/*
 * The zone names, not just their day ranges, are read from the same source
 * slk_delivery_days() already reads — typing "Colombo" and "island-wide"
 * here let them drift from slk_delivery_zones() the moment a tier is
 * renamed. Tier 0 is the fastest zone, tier 2 the slowest, the order
 * slk_delivery_zones() returns.
 */
$slk_zones       = function_exists( 'slk_delivery_zones' ) ? array_values( (array) slk_delivery_zones() ) : array();
$slk_zone_metro  = isset( $slk_zones[0]['label'] ) ? (string) $slk_zones[0]['label'] : '';
$slk_zone_island = isset( $slk_zones[2]['label'] ) ? (string) $slk_zones[2]['label'] : '';
?>

<div class="slk-home">

	<section class="slk-hero<?php echo $slk_hero ? '' : ' slk-hero--plain'; ?>" aria-labelledby="slk-hero-title">
		<?php if ( $slk_hero ) : ?>
			<div class="slk-hero__media">
				<?php echo $slk_hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in slk_editorial_image(). ?>
			</div>
		<?php endif; ?>

		<div class="slk-hero__inner slk-container">
			<div class="slk-hero__panel <?php echo $slk_hero ? 'slk-glass' : 'slk-panel slk-panel--lifted'; ?>">
				<?php if ( $slk_new > 0 ) : ?>
					<?php
					/*
					 * The count is deliberately not printed. It is a real number
					 * and it was accurate, but "New this week · 3 pieces" prices
					 * the brand as a stall rather than a label — a small figure
					 * volunteered about yourself reads as the size of the
					 * operation, not the rarity of the piece. The condition still
					 * uses the count, so the line only appears when there really
					 * is something new.
					 */
					?>
					<span class="slk-eyebrow"><?php esc_html_e( 'New this week', 'slk' ); ?></span>
				<?php endif; ?>

				<h1 class="slk-hero__title" id="slk-hero-title">
					<?php esc_html_e( 'Made for export. A small number stay here.', 'slk' ); ?>
				</h1>

				<div class="slk-hero__actions">
					<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( $slk_shop_url ); ?>">
						<?php esc_html_e( 'Shop the drop', 'slk' ); ?>
					</a>
					<?php if ( $slk_story ) : ?>
						<a class="slk-btn slk-btn--secondary" href="<?php echo esc_url( $slk_story ); ?>">
							<?php esc_html_e( 'Our story', 'slk' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</section>

	<?php
	/*
	 * hide_empty is left at its default of true on purpose: a chip is a link,
	 * and a term with no products is a link to an empty archive. Letting the
	 * chip disappear on its own is the same never-a-dead-link discipline
	 * slk_home_page_link() applies to the story and delivery buttons above,
	 * and it fills back in by itself the day the category is stocked.
	 */
	$slk_cats = get_terms(
		array(
			'taxonomy' => 'product_cat',
			'exclude'  => array( (int) get_option( 'default_product_cat', 0 ) ),
			'slug'     => array( 'abayas', 'dresses', 'hijabs' ),
		)
	);

	$slk_cats = ( is_array( $slk_cats ) && ! is_wp_error( $slk_cats ) ) ? $slk_cats : array();
	?>
	<?php if ( $slk_cats ) : ?>
		<div class="slk-container">
			<nav class="slk-home__chips" aria-label="<?php esc_attr_e( 'Shop by category', 'slk' ); ?>">
				<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( $slk_shop_url ); ?>">
					<?php esc_html_e( 'All', 'slk' ); ?>
				</a>
				<?php foreach ( $slk_cats as $slk_cat ) : ?>
					<?php
					$slk_cat_url = get_term_link( $slk_cat );

					if ( is_wp_error( $slk_cat_url ) ) {
						continue;
					}
					?>
					<a class="slk-btn slk-btn--secondary" href="<?php echo esc_url( $slk_cat_url ); ?>">
						<?php echo esc_html( $slk_cat->name ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>
	<?php endif; ?>

	<?php if ( $slk_garments ) : ?>
		<section class="slk-section slk-container" aria-labelledby="slk-ready">
			<div class="slk-section__head">
				<h2 id="slk-ready"><?php esc_html_e( 'Ready to wear', 'slk' ); ?></h2>
				<a class="slk-home__all" href="<?php echo esc_url( $slk_shop_url ); ?>">
					<?php esc_html_e( 'All →', 'slk' ); ?>
				</a>
			</div>

			<?php slk_home_render_cards( $slk_garments ); ?>
		</section>
	<?php endif; ?>

	<section class="slk-section slk-container slk-home__care" aria-labelledby="slk-paying">
		<div class="slk-panel slk-panel--lifted slk-home__pay">
			<span class="slk-eyebrow"><?php esc_html_e( 'Paying', 'slk' ); ?></span>
			<h2 id="slk-paying"><?php esc_html_e( 'Pay when it reaches your door.', 'slk' ); ?></h2>
			<p>
				<?php
				if ( $slk_cod_amount <= 0 ) {
					esc_html_e( 'We deliver cash on delivery to all 25 districts. We call to confirm before we ship, and there is no handling fee to add.', 'slk' );
				} elseif ( $slk_cod_fee ) {
					printf(
						/* translators: %s: cash-on-delivery handling fee, e.g. "Rs. 150". */
						esc_html__( 'We deliver cash on delivery to all 25 districts. We call to confirm before we ship, and the %s handling fee is shown before you order.', 'slk' ),
						esc_html( $slk_cod_fee )
					);
				} else {
					esc_html_e( 'We deliver cash on delivery to all 25 districts. We call to confirm before we ship, and the handling fee is shown before you order.', 'slk' );
				}

				if ( $slk_other_payment_methods ) {
					$slk_payment_count = count( $slk_other_payment_methods );
					$slk_last_method   = array_pop( $slk_other_payment_methods );

					$slk_payment_list = $slk_other_payment_methods
						? sprintf(
							/* translators: 1: all other payment methods but the last, comma-separated. 2: the last payment method in the list. */
							__( '%1$s and %2$s', 'slk' ),
							implode( ', ', $slk_other_payment_methods ),
							$slk_last_method
						)
						: $slk_last_method;

					echo ' ';
					printf(
						/* translators: %s: other payment methods installed besides cash on delivery, e.g. "Card and Koko". */
						esc_html( _n( '%s also works.', '%s also work.', $slk_payment_count, 'slk' ) ),
						esc_html( $slk_payment_list )
					);
				}
				?>
			</p>
			<?php if ( $slk_delivery ) : ?>
				<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( $slk_delivery ); ?>">
					<?php esc_html_e( 'How delivery works', 'slk' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<figure class="slk-home__atelier<?php echo $slk_atelier ? ' slk-home__atelier--framed' : ''; ?>">
			<?php echo $slk_atelier; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in slk_editorial_image(). ?>
			<figcaption class="slk-home__caption <?php echo $slk_atelier ? 'slk-glass' : 'slk-panel'; ?>">
				<h2><?php esc_html_e( 'Made to export standard', 'slk' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %d: number of days to start an exchange. */
						esc_html__( 'Cut and finished by hand · exchange within %d days', 'slk' ),
						function_exists( 'slk_exchange_window_days' ) ? (int) slk_exchange_window_days() : 7
					);
					?>
				</p>
			</figcaption>
		</figure>
	</section>

	<?php if ( $slk_hijabs ) : ?>
		<section class="slk-section slk-container" aria-labelledby="slk-hijabs">
			<div class="slk-section__head">
				<h2 id="slk-hijabs"><?php esc_html_e( 'Everyday hijabs', 'slk' ); ?></h2>
				<?php if ( null !== $slk_from && function_exists( 'wc_price' ) ) : ?>
					<span class="slk-home__meta">
						<?php echo wp_kses_post( wc_price( $slk_from ) ); ?>
					</span>
				<?php endif; ?>
			</div>

			<?php slk_home_render_cards( $slk_hijabs, 'slk-home__rail' ); ?>
		</section>
	<?php endif; ?>

	<section class="slk-section slk-container" aria-label="<?php esc_attr_e( 'What to expect', 'slk' ); ?>">
		<ul class="slk-assurances slk-home__assurances">
			<li class="slk-assurance">
				<?php echo slk_chrome_icon( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
				<span><?php esc_html_e( 'Cash on delivery, with a call to confirm first', 'slk' ); ?></span>
			</li>
			<li class="slk-assurance">
				<?php echo slk_chrome_icon( 'exchange', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
				<span>
					<?php
					printf(
						/* translators: %d: number of days to start an exchange. */
						esc_html__( 'Exchange within %d days, courier collects', 'slk' ),
						function_exists( 'slk_exchange_window_days' ) ? (int) slk_exchange_window_days() : 7
					);
					?>
				</span>
			</li>
			<li class="slk-assurance">
				<?php echo slk_chrome_icon( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup. ?>
				<span>
					<?php
					if ( $slk_zone_metro && $slk_days_metro && $slk_zone_island && $slk_days_island ) {
						printf(
							/* translators: 1: fastest delivery zone's label. 2: its day range. 3: slowest delivery zone's label. 4: its day range. */
							esc_html__( '%1$s in %2$s · %3$s in %4$s', 'slk' ),
							esc_html( $slk_zone_metro ),
							esc_html( $slk_days_metro ),
							esc_html( $slk_zone_island ),
							esc_html( $slk_days_island )
						);
					} else {
						esc_html_e( 'One courier, all 25 districts', 'slk' );
					}
					?>
				</span>
			</li>
		</ul>
	</section>

</div>

<?php
get_footer();
