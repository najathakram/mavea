<?php
/**
 * Template Name: Delivery & COD
 *
 * design/sections/06-mobile.html "Delivery and COD" /
 * design/sections/08-desktop2.html "Desktop delivery and COD".
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$zones    = slk_delivery_zones();
$free_over = slk_delivery_free_over();
$cod_fee   = slk_delivery_cod_fee();
$wa_url    = slk_whatsapp_url( __( 'Hi! I have a question about delivery to my area.', 'slk' ) );
?>

<main id="main" class="slk-help-main">
	<div class="slk-help-col">

		<div class="slk-help-hero">
			<span class="slk-eyebrow"><?php esc_html_e( 'Delivery & cash on delivery', 'slk' ); ?></span>
			<h1><?php esc_html_e( 'Delivery, plainly.', 'slk' ); ?></h1>
			<p>
				<?php
				/* translators: %s: free-delivery threshold, e.g. "Rs. 15,000". */
				printf(
					esc_html__( 'Every order, every district, one courier partner. Free delivery over %s.', 'slk' ),
					wp_kses_post( wc_price( $free_over ) )
				);
				?>
			</p>
		</div>

		<div class="slk-help-section">
			<div class="slk-panel">
				<table class="slk-zones">
					<caption class="slk-sr-only"><?php esc_html_e( 'Delivery zones, timing and fee', 'slk' ); ?></caption>
					<thead class="slk-sr-only">
						<tr>
							<th scope="col"><?php esc_html_e( 'Area', 'slk' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Delivery time and fee', 'slk' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $zones as $zone ) : ?>
							<tr>
								<td><?php echo esc_html( $zone['label'] ); ?></td>
								<td>
									<?php
									echo esc_html( $zone['days'] ) . ' · '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static separator.
									echo wp_kses_post( wc_price( $zone['fee'] ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="slk-help-section">
			<h2><?php esc_html_e( 'Cash on delivery, done properly.', 'slk' ); ?></h2>
			<p><?php esc_html_e( 'Most of our orders are cash on delivery. It is not a fallback. It is how we would want to buy from a store we had never met.', 'slk' ); ?></p>

			<ol class="slk-help-steps">
				<li class="slk-panel slk-help-step">
					<span class="slk-help-step__num" aria-hidden="true">1</span>
					<p><?php esc_html_e( 'You place the order. Nothing is charged, nothing is asked for.', 'slk' ); ?></p>
				</li>
				<li class="slk-panel slk-help-step">
					<span class="slk-help-step__num" aria-hidden="true">2</span>
					<p><?php esc_html_e( 'A real person calls or WhatsApps to confirm the piece, the size and the address. Nothing ships until you say yes.', 'slk' ); ?></p>
				</li>
				<li class="slk-panel slk-help-step">
					<span class="slk-help-step__num" aria-hidden="true">3</span>
					<p>
						<?php
						/* translators: %s: COD handling fee, e.g. "Rs. 150". */
						printf(
							esc_html__( 'The courier arrives and you pay in cash at your door. Cash on delivery adds %s handling, and we show it before you order rather than after.', 'slk' ),
							wp_kses_post( wc_price( $cod_fee ) )
						);
						?>
					</p>
				</li>
			</ol>
		</div>

		<div class="slk-help-section">
			<h2><?php esc_html_e( 'Other ways to pay', 'slk' ); ?></h2>
			<div class="slk-help-cards">
				<div class="slk-panel slk-help-card">
					<h3><?php esc_html_e( 'Card · eZ Cash · helaPay · LankaQR', 'slk' ); ?></h3>
					<p><?php esc_html_e( 'One secure PayHere screen covers all of them. No COD fee.', 'slk' ); ?></p>
				</div>
				<div class="slk-panel slk-help-card">
					<h3><?php esc_html_e( 'Bank transfer', 'slk' ); ?></h3>
					<p><?php esc_html_e( 'We hold your pieces for 24 hours. Send the slip on WhatsApp and they ship the same day.', 'slk' ); ?></p>
				</div>
			</div>
		</div>

		<?php if ( $wa_url ) : ?>
			<a class="slk-panel slk-panel--lifted slk-help-wa" href="<?php echo esc_url( $wa_url ); ?>">
				<span class="slk-help-wa__text">
					<?php esc_html_e( 'A question about your area?', 'slk' ); ?>
					<small><?php esc_html_e( 'WhatsApp · replies until 8pm', 'slk' ); ?></small>
				</span>
				<span class="slk-help-wa__mark" aria-hidden="true">W</span>
			</a>
		<?php endif; ?>

	</div>
</main>

<?php
get_footer();
