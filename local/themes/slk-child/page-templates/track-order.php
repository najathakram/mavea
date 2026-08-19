<?php
/**
 * Template Name: Track order
 *
 * "Track your order" — design/sections/04-pages.html "TRACK ORDER (GUEST)".
 * No account needed: real Order ID + email-or-mobile lookup, real
 * order-notes timeline.
 *
 * When SLK_Track (local/plugins/slk-order-flow) is active and the tracking
 * form was POSTed with a valid woocommerce-order_tracking nonce, the lookup
 * runs through SLK_Track::resolve() — order number plus either the billing
 * email or the billing mobile number both work, which is what lets a
 * phone-only cash-on-delivery customer track an order at all. A match
 * renders the Porcelain result via woocommerce/order/tracking.php; a miss
 * prints a neutral notice and the form again, same as core's own
 * track_order_form()-after-error behaviour. Without SLK_Track this falls
 * back to the stock [woocommerce_order_tracking] shortcode verbatim
 * (email-only lookup, as core ships it) — see
 * design/_reference/woocommerce-templates/order/tracking.php and
 * order/form-tracking.php, which order/form-tracking.php overrides and
 * order/tracking.php (new) overrides respectively.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div id="primary" class="slk-page slk-track">
	<div class="slk-page__head">
		<h1><?php esc_html_e( 'Track your order', 'slk' ); ?></h1>
		<p class="slk-page__intro"><?php esc_html_e( 'Enter your order number and the email or mobile number you used when you ordered.', 'slk' ); ?></p>
	</div>

	<div class="slk-track__panel">
		<?php
		if ( class_exists( 'SLK_Track' ) ) {
			// The form always posts orderid, so its presence — not its value —
			// is what separates a submitted lookup from a first visit here.
			$slk_track_posted   = isset( $_POST['orderid'] );
			$slk_track_nonce_ok = isset( $_POST['woocommerce-order-tracking-nonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-order-tracking-nonce'] ) ), 'woocommerce-order_tracking' );
			$slk_track_order_id = isset( $_POST['orderid'] ) ? sanitize_text_field( wp_unslash( $_POST['orderid'] ) ) : '';
			$slk_track_order    = null;

			if ( $slk_track_nonce_ok && '' !== $slk_track_order_id ) {
				$slk_track_contact = isset( $_POST['order_email'] ) ? sanitize_text_field( wp_unslash( $_POST['order_email'] ) ) : '';

				// Same per-IP budget the assist widget's ajax lookup spends
				// from, so guessing order ids against a known mobile number
				// is no cheaper here than there. Over the limit reads as an
				// ordinary miss — the notice below never says which it was.
				$slk_track_limited = method_exists( 'SLK_Track', 'rate_limited' ) && SLK_Track::rate_limited();
				$slk_track_order   = $slk_track_limited ? null : SLK_Track::resolve( $slk_track_order_id, $slk_track_contact );
			}

			if ( $slk_track_order ) {
				wc_get_template( 'order/tracking.php', array( 'order' => $slk_track_order ) );
			} else {
				// Every failed submission reads the same: a blank order
				// number, a contact that matches nothing, and a nonce that
				// expired inside the page cache all print this one neutral
				// notice, so pressing Track is never a silent no-op.
				if ( $slk_track_posted ) {
					wc_print_notice( esc_html__( 'We could not find that order. Check the number and the email or mobile you used.', 'slk' ), 'error' );
				}

				wc_get_template( 'order/form-tracking.php' );
			}
		} else {
			echo do_shortcode( '[woocommerce_order_tracking]' );
		}
		?>
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
</div>

<?php
get_footer();
