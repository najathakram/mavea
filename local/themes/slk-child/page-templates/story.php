<?php
/**
 * Template Name: Our Story
 *
 * The brand origin page. STRUCTURE is still design/sections/06-mobile.html
 * ("Our story") and 08-desktop2.html ("Desktop our story"), but the COPY is a
 * deliberate departure from brand law 1, on Najath's instruction (2026-08-17).
 *
 * The mockup sold a small atelier: eight women, twenty of a cut, clothes we
 * sew for ourselves. That is a US-market story. To a Sri Lankan buyer a brand
 * that volunteers how small it is has told her what to pay, and she will read
 * "handmade in a small workshop" as homemade rather than as rare. The same
 * facts are now told the other way round: the work is made to the standard
 * export orders demand, and only part of each run is released here. Nothing
 * claimed is new — the runs really are limited and really do retire — but the
 * scarcity is framed as rarity instead of as a headcount.
 *
 * Layout, tokens and image keys owned here; page provisioning and the CSS
 * that styles this template live in inc/pages-brand.php (brand law 10 —
 * this file writes only its own template markup).
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="primary" class="slk-story">

	<div class="slk-story__hero">
		<?php
		/*
		 * Its own frame, not room_wide. This page used to borrow the Contact
		 * photograph, which put one picture on three surfaces — home, story and
		 * contact — and made the site look like it owned a single image.
		 * Alt text comes from the attachment (local/seed-editorial.sh).
		 */
		$hero = slk_editorial_image( 'story_wide', 'full', array( 'loading' => 'eager', 'fetchpriority' => 'high' ) );

		if ( $hero ) {
			echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside slk_editorial_image().
		}
		?>
	</div>

	<div class="slk-container">

		<div class="slk-story__intro">
			<span class="slk-eyebrow"><?php esc_html_e( 'Our story', 'slk' ); ?></span>
			<?php
			/*
			 * This said "Worn in London, Melbourne and New York" in draft. The
			 * cities were invented: the markets we were given are three
			 * countries, and naming a city claims a shop window we cannot point
			 * at. The countries are the fact, so the countries are what runs.
			 */
			?>
			<h1><?php esc_html_e( 'Made here. Exported to the US, the UK and Australia.', 'slk' ); ?></h1>
			<p class="slk-story__lede">
				<?php esc_html_e( 'Most of what leaves this studio is made for buyers in the United States, the United Kingdom and Australia, cut to the standard those orders demand. We keep part of every run back, and that part is what you are looking at.', 'slk' ); ?>
			</p>
		</div>

		<div class="slk-story__stats">
			<?php
			/*
			 * These three used to be 8 women / 20 of a cut / 25 districts. Two of
			 * them were the size of the business stated as a boast, and a small
			 * number volunteered about yourself sets the price the reader expects
			 * to pay. Reach and terms survive; headcount and batch size do not.
			 */
			?>
			<div class="slk-panel slk-story__stat">
				<div class="slk-story__stat-n">3</div>
				<div class="slk-story__stat-l"><?php esc_html_e( 'countries we export to', 'slk' ); ?></div>
			</div>
			<div class="slk-panel slk-story__stat">
				<div class="slk-story__stat-n">25</div>
				<div class="slk-story__stat-l"><?php esc_html_e( 'districts we deliver to', 'slk' ); ?></div>
			</div>
			<div class="slk-panel slk-story__stat">
				<?php // The window is a policy number, so it prints from the setting the checkout reads — never a literal. ?>
				<div class="slk-story__stat-n"><?php echo (int) ( function_exists( 'slk_exchange_window_days' ) ? slk_exchange_window_days() : 7 ); ?></div>
				<div class="slk-story__stat-l"><?php esc_html_e( 'days to exchange', 'slk' ); ?></div>
			</div>
		</div>

		<div class="slk-story__pair">
			<div class="slk-story__pair-img slk-story__pair-img--a">
				<?php
				$pair_a = slk_editorial_image( 'studio_pair', 'large', array( 'loading' => 'eager' ) );

				if ( $pair_a ) {
					echo $pair_a; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside slk_editorial_image().
				}
				?>
			</div>
			<div class="slk-story__pair-img slk-story__pair-img--b">
				<?php
				$pair_b = slk_editorial_image( 'portrait_warm', 'large', array( 'loading' => 'eager' ) );

				if ( $pair_b ) {
					echo $pair_b; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside slk_editorial_image().
				}
				?>
			</div>
		</div>

		<div class="slk-story__closing">
			<p>
				<?php esc_html_e( 'We cut and finish every piece by hand and make a limited run of each one. When a run is gone we move on rather than repeat it. That is also why you are unlikely to meet someone wearing yours.', 'slk' ); ?>
			</p>
			<h2 class="slk-story__find-h"><?php esc_html_e( 'Where to find us', 'slk' ); ?></h2>
			<p class="slk-story__find-p">
				<?php esc_html_e( 'The address and phone numbers will go here. Visits are by appointment, and it is better to see the work than to take our word for it.', 'slk' ); ?>
			</p>
		</div>

		<div class="slk-story__cta">
			<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( slk_chrome_shop_url() ); ?>">
				<?php esc_html_e( 'See what we are making now', 'slk' ); ?>
			</a>
		</div>

	</div>
</main>

<?php get_footer(); ?>
