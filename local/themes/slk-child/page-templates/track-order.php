<?php
/**
 * Template Name: Track order
 *
 * "Track your order" — design/sections/04-pages.html "TRACK ORDER (GUEST)".
 * No account needed: this renders WooCommerce's own order-tracking shortcode
 * (real Order ID + billing-email lookup, real order-notes timeline), restyled
 * to the Porcelain Glass panel via CSS in inc/pages-support.php rather than a
 * fake form — see design/_reference/woocommerce-templates/order/tracking.php
 * and order/form-tracking.php, which this reuses unmodified.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="primary" class="slk-page slk-track">
	<div class="slk-page__head">
		<h1><?php esc_html_e( 'Track your order', 'slk' ); ?></h1>
		<p class="slk-page__intro"><?php esc_html_e( 'Look it up with your order number and the email you checked out with.', 'slk' ); ?></p>
	</div>

	<div class="slk-track__panel">
		<?php echo do_shortcode( '[woocommerce_order_tracking]' ); ?>
	</div>

	<?php
	$wa_url = function_exists( 'slk_whatsapp_url' ) ? slk_whatsapp_url( __( "Hi, I can't find my order.", 'slk' ) ) : '';
	if ( $wa_url ) :
		?>
		<p class="slk-track__help">
			<?php
			printf(
				/* translators: %s: WhatsApp link. */
				wp_kses_post( __( "Can't find it? <a href=\"%s\">WhatsApp us</a> your order number and we'll look it up.", 'slk' ) ),
				esc_url( $wa_url )
			);
			?>
		</p>
	<?php endif; ?>
</main>

<?php
get_footer();
