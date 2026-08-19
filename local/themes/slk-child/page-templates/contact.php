<?php
/**
 * Template Name: Contact
 *
 * "Talk to us" — design/sections/04-pages.html "CONTACT". WhatsApp-first: the
 * big WhatsApp card leads, then a phone/email/Instagram row (each rendered
 * only when a real value is configured — never a dead href), the atelier
 * photo, and a short "leave a message" form that mails the site admin via
 * inc/pages-support.php's admin-post handler.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$wa_url        = function_exists( 'slk_whatsapp_url' ) ? slk_whatsapp_url( __( 'Hi! I have a question.', 'slk' ) ) : '';
$phone_url     = slk_contact_phone_url();
$email         = slk_contact_email();
$instagram_url = slk_contact_instagram_url();
$atelier_image = slk_editorial_image( 'room_wide' );

$sent = isset( $_GET['slk_contact'] ) ? sanitize_text_field( wp_unslash( $_GET['slk_contact'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change.
?>

<div id="primary" class="slk-page slk-contact">
	<div class="slk-page__head">
		<h1><?php esc_html_e( 'Talk to us', 'slk' ); ?></h1>
		<p class="slk-page__intro"><?php esc_html_e( 'A person reads every message. We usually reply within the hour, and always on the same day.', 'slk' ); ?></p>
	</div>

	<?php if ( $wa_url ) : ?>
		<a class="slk-wa-card" href="<?php echo esc_url( $wa_url ); ?>">
			<span class="slk-wa-card__text">
				<?php esc_html_e( 'WhatsApp', 'slk' ); ?>
				<small><?php esc_html_e( 'Business number · fastest way to reach us', 'slk' ); ?></small>
			</span>
			<span class="slk-wa-card__mark" aria-hidden="true">W</span>
		</a>
	<?php endif; ?>

	<div class="slk-contact-rows">
		<?php if ( $phone_url ) : ?>
			<a class="slk-contact-row" href="<?php echo esc_url( $phone_url ); ?>">
				<span class="slk-contact-row__text">
					<?php esc_html_e( 'Call us', 'slk' ); ?>
					<small><?php esc_html_e( 'Business number · 9am to 6pm, Mon to Sat', 'slk' ); ?></small>
				</span>
			</a>
		<?php endif; ?>

		<?php if ( $email ) : ?>
			<a class="slk-contact-row" href="<?php echo esc_url( 'mailto:' . $email ); ?>">
				<span class="slk-contact-row__text">
					<?php esc_html_e( 'Email', 'slk' ); ?>
					<small><?php esc_html_e( 'Replies within a day', 'slk' ); ?></small>
				</span>
			</a>
		<?php endif; ?>

		<?php if ( $instagram_url ) : ?>
			<a class="slk-contact-row" href="<?php echo esc_url( $instagram_url ); ?>">
				<span class="slk-contact-row__text">
					<?php esc_html_e( 'Instagram', 'slk' ); ?>
					<small><?php esc_html_e( 'DMs work too, but WhatsApp is faster', 'slk' ); ?></small>
				</span>
			</a>
		<?php endif; ?>
	</div>

	<?php if ( $atelier_image ) : ?>
		<div class="slk-atelier">
			<span class="slk-eyebrow"><?php esc_html_e( 'The studio', 'slk' ); ?></span>
			<div class="slk-atelier__media"><?php echo $atelier_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside slk_editorial_image(). ?></div>
			<p class="slk-atelier__copy"><?php esc_html_e( 'Visits to the studio are by appointment. Message us first so we know you are coming.', 'slk' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="slk-panel slk-panel--lifted slk-contact-form" id="leave-a-message">
		<h2><?php esc_html_e( 'Or leave a message', 'slk' ); ?></h2>

		<?php if ( 'sent' === $sent ) : ?>
			<div class="slk-toast"><?php esc_html_e( 'Thank you. We have your message and will reply soon.', 'slk' ); ?></div>
		<?php elseif ( 'error' === $sent ) : ?>
			<div class="slk-toast slk-toast--error"><?php esc_html_e( "That didn't go through. Please fill in both fields and try again.", 'slk' ); ?></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>#leave-a-message">
			<input type="hidden" name="action" value="slk_contact_message">
			<?php wp_nonce_field( 'slk_contact_message', 'slk_contact_nonce' ); ?>

			<div class="slk-field">
				<label for="cf-phone"><?php esc_html_e( 'Mobile number', 'slk' ); ?></label>
				<input class="slk-input" id="cf-phone" name="cf_phone" type="tel" required placeholder="+94 77 123 4567">
			</div>

			<div class="slk-field">
				<label for="cf-msg"><?php esc_html_e( 'What can we help with?', 'slk' ); ?></label>
				<textarea class="slk-textarea" id="cf-msg" name="cf_message" rows="3" required></textarea>
			</div>

			<button class="slk-btn slk-btn--primary" type="submit"><?php esc_html_e( 'Send', 'slk' ); ?></button>
		</form>
	</div>
</div>

<?php
get_footer();
