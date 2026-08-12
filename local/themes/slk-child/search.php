<?php
/**
 * Search results — design/sections/04-pages.html "SEARCH".
 *
 * inc/pages-support.php forces every search to post_type=product, so this
 * loop only ever meets products; each result renders through the exact same
 * card renderer as the shop grid (woocommerce/content-product.php's
 * slk_template_loop_*() callbacks, inc/shop.php) by using WooCommerce's own
 * loop-start/loop-end and wc_get_template_part(), not a hand-rolled result
 * row. A ".woocommerce" wrapper is added by hand because is_search() does not
 * get WooCommerce's own body class, and the shop grid's CSS is keyed to
 * ".woocommerce ul.products".
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$query_str = get_search_query();
$count     = (int) $GLOBALS['wp_query']->found_posts;
?>

<main id="primary" class="slk-page slk-search">

	<?php if ( have_posts() ) : ?>

		<div class="slk-page__head">
			<h1><?php esc_html_e( 'Search', 'slk' ); ?></h1>
			<?php if ( '' !== $query_str ) : ?>
				<p class="slk-page__intro">
					<?php
					printf(
						/* translators: %s: the search term. */
						esc_html__( 'Results for "%s"', 'slk' ),
						esc_html( $query_str )
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<div class="slk-eyebrow slk-search__count">
			<?php
			printf(
				/* translators: %s: number of matching products. */
				esc_html( _n( '%s piece', '%s pieces', $count, 'slk' ) ),
				esc_html( number_format_i18n( $count ) )
			);
			?>
		</div>

		<div class="woocommerce slk-search__list">
			<?php woocommerce_product_loop_start(); ?>
			<?php
			while ( have_posts() ) :
				the_post();
				wc_get_template_part( 'content', 'product' );
			endwhile;
			?>
			<?php woocommerce_product_loop_end(); ?>
		</div>

		<?php the_posts_pagination(); ?>

	<?php else : ?>

		<div class="slk-search__empty">
			<h1>
				<?php
				if ( '' !== $query_str ) {
					printf(
						/* translators: %s: the search term. */
						esc_html__( 'Nothing matched "%s".', 'slk' ),
						esc_html( $query_str )
					);
				} else {
					esc_html_e( 'Nothing matched that search.', 'slk' );
				}
				?>
			</h1>
			<p><?php esc_html_e( 'Try a shorter word, or browse instead. Everything we are making now is one tap away.', 'slk' ); ?></p>

			<div class="slk-search__suggestions">
				<a href="<?php echo esc_url( slk_chrome_shop_url() ); ?>"><?php esc_html_e( 'Shop everything', 'slk' ); ?></a>
				<?php foreach ( slk_chrome_primary_links() as $link ) : ?>
					<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

	<?php endif; ?>

</main>

<?php
get_footer();
