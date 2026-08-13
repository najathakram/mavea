<?php
/**
 * Template Name: Exchange policy
 *
 * design/sections/04-pages.html "Exchange policy".
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$send_fee = slk_exchange_send_fee();
$wa_url   = slk_whatsapp_url( __( 'Hi! Before I order, could you check my size against a piece?', 'slk' ) );

$rules = array(
	array(
		'title' => __( 'You have 7 days', 'slk' ),
		'body'  => __( 'We count from the day it reaches you. Message us on WhatsApp and we start it there. There are no forms.', 'slk' ),
	),
	array(
		'title' => __( 'The courier collects', 'slk' ),
		/* translators: %s: exchange send fee, e.g. "Rs. 350". */
		'body'  => sprintf(
			__( 'From your door, at a time you choose. We pay to collect it. You pay %s to send the new size.', 'slk' ),
			wp_strip_all_tags( wc_price( $send_fee ) )
		),
	),
	array(
		'title' => __( 'Unworn, with the tag on', 'slk' ),
		'body'  => __( 'Try it on over your clothes. We cannot exchange a piece that has been worn outside, washed, altered or perfumed.', 'slk' ),
	),
	array(
		'title' => __( 'Hijabs and undercaps', 'slk' ),
		'body'  => __( 'Only if the seal is unopened. This is for hygiene.', 'slk' ),
	),
	array(
		'title' => __( 'If we got it wrong', 'slk' ),
		'body'  => __( 'If we sent the wrong piece, or it is faulty, or it was damaged on the way, we collect it and send the right one. You pay nothing.', 'slk' ),
	),
);

/**
 * Made-to-order pieces are exchange-only — see PROJECT LAW brief and
 * design/docs/brand-guidelines.md §7 ("Exchange policy is stated in plain
 * words wherever a size is chosen"). Stated once, plainly, alongside the
 * "we exchange, we do not refund" line the design already carries.
 */
?>

<main id="main" class="slk-help-main">
	<div class="slk-help-col">

		<div class="slk-help-hero">
			<span class="slk-eyebrow"><?php esc_html_e( 'Exchange policy', 'slk' ); ?></span>
			<h1><?php esc_html_e( 'Exchanges', 'slk' ); ?></h1>
			<p><?php esc_html_e( 'We exchange, we do not refund. Every piece is made to order.', 'slk' ); ?></p>
		</div>

		<div class="slk-help-section">
			<div class="slk-help-cards">
				<?php foreach ( $rules as $rule ) : ?>
					<div class="slk-panel slk-help-card">
						<h3><?php echo esc_html( $rule['title'] ); ?></h3>
						<p><?php echo wp_kses_post( $rule['body'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="slk-help-section">
			<div class="slk-help-cta">
				<h3><?php esc_html_e( 'Get the size right the first time', 'slk' ); ?></h3>
				<p><?php esc_html_e( 'Send us your bust and height before you order and we will measure the actual piece.', 'slk' ); ?></p>
				<?php if ( $wa_url ) : ?>
					<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( $wa_url ); ?>">
						<?php esc_html_e( 'W · Check my size first', 'slk' ); ?>
					</a>
				<?php else : ?>
					<?php /* No WhatsApp number configured yet — the card must still lead somewhere, not trail off. */ ?>
					<a class="slk-btn slk-btn--primary" href="<?php echo esc_url( function_exists( 'slk_page_url' ) ? slk_page_url( 'contact' ) : home_url( '/' ) ); ?>">
						<?php esc_html_e( 'Message us first', 'slk' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

	</div>
</main>

<?php
get_footer();
