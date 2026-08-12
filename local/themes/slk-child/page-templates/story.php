<?php
/**
 * Template Name: Our Story
 *
 * "A small atelier in Galle, sewing clothes we actually wear." — the brand
 * origin page. Copy and structure reproduced exactly from
 * design/sections/06-mobile.html ("Our story") and
 * design/sections/08-desktop2.html ("Desktop our story"); see brand law 1.
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
		$hero = slk_editorial_image( 'room_wide', 'full', array( 'alt' => __( 'The atelier in Galle, in daylight.', 'slk' ), 'loading' => 'eager', 'fetchpriority' => 'high' ) );

		if ( $hero ) {
			echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside slk_editorial_image().
		}
		?>
	</div>

	<div class="slk-container">

		<div class="slk-story__intro">
			<span class="slk-eyebrow"><?php esc_html_e( 'Our story', 'slk' ); ?></span>
			<h1><?php esc_html_e( 'A small atelier in Galle, sewing clothes we actually wear.', 'slk' ); ?></h1>
			<p class="slk-story__lede">
				<?php esc_html_e( 'We started with a simple irritation: dressing modestly here meant choosing between imported abayas cut for another climate, or fast fashion that treats modesty as an afterthought. So we cut our own — for heat, for monsoon humidity, for buses and weddings and work.', 'slk' ); ?>
			</p>
		</div>

		<div class="slk-story__stats">
			<div class="slk-panel slk-story__stat">
				<div class="slk-story__stat-n">8</div>
				<div class="slk-story__stat-l"><?php esc_html_e( 'women in the atelier', 'slk' ); ?></div>
			</div>
			<div class="slk-panel slk-story__stat">
				<div class="slk-story__stat-n">20</div>
				<div class="slk-story__stat-l"><?php esc_html_e( 'of a cut, at most', 'slk' ); ?></div>
			</div>
			<div class="slk-panel slk-story__stat">
				<div class="slk-story__stat-n">25</div>
				<div class="slk-story__stat-l"><?php esc_html_e( 'districts delivered', 'slk' ); ?></div>
			</div>
		</div>

		<div class="slk-story__pair">
			<div class="slk-story__pair-img slk-story__pair-img--a">
				<?php
				$pair_a = slk_editorial_image( 'studio_pair', 'large', array( 'alt' => __( 'The cutting table at the atelier.', 'slk' ) ) );

				if ( $pair_a ) {
					echo $pair_a; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside slk_editorial_image().
				}
				?>
			</div>
			<div class="slk-story__pair-img slk-story__pair-img--b">
				<?php
				$pair_b = slk_editorial_image( 'portrait_warm', 'large', array( 'alt' => __( 'Hands, cloth and a needle, mid-stitch.', 'slk' ) ) );

				if ( $pair_b ) {
					echo $pair_b; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside slk_editorial_image().
				}
				?>
			</div>
		</div>

		<div class="slk-story__closing">
			<p>
				<?php esc_html_e( 'Every piece is cut and finished by hand, in small numbers, and never restocked in bulk. When a run sells, we make the next thing. The fabric offcuts become our hijab undercaps — nothing useful leaves the room.', 'slk' ); ?>
			</p>
			<h2 class="slk-story__find-h"><?php esc_html_e( 'Where to find us', 'slk' ); ?></h2>
			<p class="slk-story__find-p">
				<?php esc_html_e( 'The atelier is in Galle — the address and business numbers will sit here. Come by appointment; we would rather you see the work than take our word for it.', 'slk' ); ?>
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
