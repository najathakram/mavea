<?php
/**
 * Reset-password form — restyle of WooCommerce's own form-reset-password.php.
 *
 * Same glass-panel language as form-lost-password.php: a .slk-auth-head
 * intro above a single 420px card (.slk-auth-single — see inc/account.php
 * for the CSS). Both password fields render full width (form-row-wide)
 * rather than core's floated form-row-first/form-row-last pair, so they
 * never pick up Blocksy's unscoped 48%-width float rule for that class.
 * Every core hook keeps its original position relative to the form; only
 * the intro copy moves out of the form and into the head, and the submit
 * button borrows form-login's pill class (woocommerce-form-login__submit)
 * so the existing pill rule styles it instead of Blocksy's default blue
 * button.
 *
 * Based on: design/_reference/woocommerce-templates/myaccount/form-reset-password.php
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="slk-auth-head">
	<h1><?php esc_html_e( 'Choose a new password.', 'slk' ); ?></h1>
	<p><?php esc_html_e( 'Enter a new password to get back into your account.', 'slk' ); ?></p>
</div>

<div class="slk-auth-single">

	<?php do_action( 'woocommerce_before_reset_password_form' ); ?>

	<form method="post" class="woocommerce-form woocommerce-ResetPassword lost_reset_password">

		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="password_1"><?php esc_html_e( 'New password', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
			<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_1" id="password_1" autocomplete="new-password" required aria-required="true" />
		</p>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="password_2"><?php esc_html_e( 'Re-enter new password', 'woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
			<input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_2" id="password_2" autocomplete="new-password" required aria-required="true" />
		</p>

		<input type="hidden" name="reset_key" value="<?php echo esc_attr( $args['key'] ); ?>" />
		<input type="hidden" name="reset_login" value="<?php echo esc_attr( $args['login'] ); ?>" />

		<div class="clear"></div>

		<?php do_action( 'woocommerce_resetpassword_form' ); ?>

		<p class="woocommerce-form-row form-row">
			<input type="hidden" name="wc_reset_password" value="true" />
			<button type="submit" class="woocommerce-Button button woocommerce-form-login__submit<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>" value="<?php esc_attr_e( 'Save', 'woocommerce' ); ?>"><?php esc_html_e( 'Save', 'woocommerce' ); ?></button>
		</p>

		<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>

	</form>

</div>
<?php
do_action( 'woocommerce_after_reset_password_form' );
