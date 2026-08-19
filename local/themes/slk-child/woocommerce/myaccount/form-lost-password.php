<?php
/**
 * Lost password form — restyle of WooCommerce's own form-lost-password.php.
 *
 * Same glass-panel language as woocommerce/myaccount/form-login.php: a
 * .slk-auth-head intro above a single 420px card (.slk-auth-single — see
 * inc/account.php for the CSS). Every core hook keeps its original position
 * relative to the form; only the intro copy moves out of the form and into
 * the head. The single field renders full width (form-row-wide) rather than
 * core's form-row-first, and the submit button borrows form-login's pill
 * class (woocommerce-form-login__submit) so the existing pill rule styles
 * it instead of Blocksy's default blue button.
 *
 * Based on: design/_reference/woocommerce-templates/myaccount/form-lost-password.php
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="slk-auth-head">
	<h1><?php esc_html_e( 'Reset your password.', 'slk' ); ?></h1>
	<p><?php esc_html_e( 'Enter your email or username and we will send a link to set a new one.', 'slk' ); ?></p>
</div>

<div class="slk-auth-single">

	<?php do_action( 'woocommerce_before_lost_password_form' ); ?>

	<form method="post" class="woocommerce-form woocommerce-ResetPassword lost_reset_password">

		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="user_login"><?php esc_html_e( 'Username or email', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
			<input class="woocommerce-Input woocommerce-Input--text input-text" type="text" name="user_login" id="user_login" autocomplete="username" required aria-required="true" />
		</p>

		<div class="clear"></div>

		<?php do_action( 'woocommerce_lostpassword_form' ); ?>

		<p class="woocommerce-form-row form-row">
			<input type="hidden" name="wc_reset_password" value="true" />
			<button type="submit" class="woocommerce-Button button woocommerce-form-login__submit<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" value="<?php esc_attr_e( 'Reset password', 'woocommerce' ); ?>"><?php esc_html_e( 'Reset password', 'woocommerce' ); ?></button>
		</p>

		<?php wp_nonce_field( 'lost_password', 'woocommerce-lost-password-nonce' ); ?>

	</form>

</div>
<?php
do_action( 'woocommerce_after_lost_password_form' );
