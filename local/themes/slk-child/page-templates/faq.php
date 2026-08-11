<?php
/**
 * Template Name: FAQ
 *
 * design/sections/04-pages.html "FAQ". Category chips filter which group of
 * <details>-style items is visible; every answer is rendered in the initial
 * HTML regardless of which chip is active or whether JS runs at all, so
 * search engines and no-JS visitors see the full FAQ.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$groups = slk_faq_groups();
$wa_url = slk_whatsapp_url( __( 'Hi! I have a question that wasn\'t answered on the FAQ page.', 'slk' ) );
$first  = true;
?>

<main id="main" class="slk-help-main">
	<div class="slk-help-col">

		<div class="slk-help-hero">
			<span class="slk-eyebrow"><?php esc_html_e( 'FAQ', 'slk' ); ?></span>
			<h1><?php esc_html_e( 'Questions', 'slk' ); ?></h1>
		</div>

		<ul class="slk-faq-tabs" role="list">
			<li>
				<button type="button" class="slk-faq-tab" aria-pressed="true" data-faq-target="all"><?php esc_html_e( 'All', 'slk' ); ?></button>
			</li>
			<?php foreach ( $groups as $key => $group ) : ?>
				<li>
					<button type="button" class="slk-faq-tab" aria-pressed="false" data-faq-target="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['label'] ); ?></button>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php foreach ( $groups as $key => $group ) : ?>
			<div class="slk-panel slk-faq-group" data-faq-group="<?php echo esc_attr( $key ); ?>">
				<h2 class="slk-sr-only"><?php echo esc_html( $group['label'] ); ?></h2>
				<?php foreach ( $group['items'] as $i => $item ) : ?>
					<?php
					$id_base = 'slk-faq-' . $key . '-' . $i;
					$open    = $first;
					$first   = false;
					?>
					<div class="slk-faq-item">
						<h3 style="margin:0">
							<button type="button"
								class="slk-faq-item__trigger"
								id="<?php echo esc_attr( $id_base . '-trigger' ); ?>"
								aria-expanded="<?php echo $open ? 'true' : 'false'; ?>"
								aria-controls="<?php echo esc_attr( $id_base . '-panel' ); ?>">
								<span><?php echo esc_html( $item['q'] ); ?></span>
								<span class="slk-faq-item__icon" aria-hidden="true"><?php echo $open ? '−' : '＋'; ?></span>
							</button>
						</h3>
						<p class="slk-faq-item__panel"
							id="<?php echo esc_attr( $id_base . '-panel' ); ?>"
							role="region"
							aria-labelledby="<?php echo esc_attr( $id_base . '-trigger' ); ?>"
							<?php echo $open ? '' : 'hidden'; ?>>
							<?php echo wp_kses_post( $item['a'] ); ?>
						</p>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( $wa_url ) : ?>
			<div class="slk-help-section" style="margin-top:var(--slk-space-6)">
				<a class="slk-panel slk-panel--lifted slk-help-wa" href="<?php echo esc_url( $wa_url ); ?>">
					<span class="slk-help-wa__text">
						<?php esc_html_e( 'Not answered here?', 'slk' ); ?>
						<small><?php esc_html_e( 'WhatsApp · a person, until 8pm', 'slk' ); ?></small>
					</span>
					<span class="slk-help-wa__mark" aria-hidden="true">W</span>
				</a>
			</div>
		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
