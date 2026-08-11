<?php
/**
 * Checkout billing information form — Porcelain Glass presentation.
 *
 * Based on the real WooCommerce template (see
 * design/_reference/woocommerce-templates/checkout/form-billing.php, v3.6.0).
 *
 * WHY THIS OVERRIDE EXISTS
 * ------------------------
 * The design (design/sections/06-mobile.html) tells the address story in two
 * numbered glass panels: "1 · You" (mobile number, full name, email) and
 * "2 · Where" (address, landmark, city / district, postal code). WooCommerce
 * has no separate field group for that split — the slk-checkout plugin defines
 * *every* one of those keys inside the single `billing` group
 * (class-slk-checkout-fields.php), ordered by priority 10–80. The stock
 * template loops the whole group into one container, so panel 1 got the entire
 * form and panel 2 got nothing.
 *
 * So this template renders BOTH numbered panels and cuts the single billing
 * loop between them at the priority boundary (see slk_checkout_billing_split()
 * in inc/checkout-view.php — identity < 40, address >= 40, which is exactly
 * where billing_country sits). Nothing is added, removed, reordered or
 * validated here: it is the same `get_checkout_fields( 'billing' )` array, in
 * the same priority order, rendered through the same woocommerce_form_field()
 * calls, with `woocommerce_before_checkout_billing_form` and
 * `woocommerce_after_checkout_billing_form` still firing before and after the
 * loop as a whole.
 *
 * Two structural notes for the next maintainer:
 *
 *  1. Each panel keeps its own `.woocommerce-billing-fields` >
 *     `.woocommerce-billing-fields__field-wrapper` pair. That is not
 *     decoration — WooCommerce's own country-select.js resolves the district
 *     dropdown with `$( '#billing_country' ).closest( '.woocommerce-billing-fields, … )`
 *     and then `.find( '#billing_state' )`, and address-i18n.js re-sorts
 *     `.form-row`s by priority inside each `…__field-wrapper`. billing_country
 *     (40) and billing_state (70) are both in the "2 · Where" panel, so they
 *     share one wrapper and both scripts keep working.
 *
 *  2. `woocommerce_checkout_shipping` is fired at the end of the "2 · Where"
 *     panel instead of from form-checkout.php, so the Delivery-notes field
 *     lands inside the panel it belongs to rather than in a fourth glass box
 *     the design does not have. It still fires exactly once, still after the
 *     billing fields, and `.woocommerce-shipping-fields` is still a sibling of
 *     `.woocommerce-billing-fields`. Shipping *address* fields never render:
 *     inc/checkout-view.php forces `woocommerce_ship_to_destination` to
 *     `billing_only`, so `WC_Cart::needs_shipping_address()` is false and
 *     form-shipping.php emits only the notes.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/checkout/form-billing.php.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.6.0
 * @global WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

$slk_billing_fields = $checkout->get_checkout_fields( 'billing' );
$slk_billing_split  = function_exists( 'slk_checkout_billing_split' )
	? slk_checkout_billing_split( $slk_billing_fields )
	: array(
		'you'   => $slk_billing_fields,
		'where' => array(),
	);
?>

<div class="slk-panel slk-panel--lifted slk-checkout__panel col-1" id="slk-panel-you">

	<div class="slk-eyebrow slk-checkout__panel-label"><?php esc_html_e( '1 · You', 'slk' ); ?></div>

	<div class="woocommerce-billing-fields" id="slk-billing-you">

		<?php do_action( 'woocommerce_before_checkout_billing_form', $checkout ); ?>

		<div class="woocommerce-billing-fields__field-wrapper">
			<?php
			foreach ( $slk_billing_split['you'] as $key => $field ) {
				woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
			}
			?>
		</div>

	</div>

</div>

<div class="slk-panel slk-panel--lifted slk-checkout__panel col-2" id="slk-panel-where">

	<div class="slk-eyebrow slk-checkout__panel-label"><?php esc_html_e( '2 · Where', 'slk' ); ?></div>

	<div class="woocommerce-billing-fields" id="slk-billing-where">

		<div class="woocommerce-billing-fields__field-wrapper">
			<?php
			foreach ( $slk_billing_split['where'] as $key => $field ) {
				woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
			}
			?>
		</div>

		<?php do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>

	</div>

	<?php
	/**
	 * Shipping group + order notes. See note 2 in the file header: with
	 * billing-only shipping this contributes the Delivery-notes field and
	 * nothing else, so it belongs in "2 · Where".
	 */
	do_action( 'woocommerce_checkout_shipping' );
	?>

</div>

<?php if ( ! is_user_logged_in() && $checkout->is_registration_enabled() ) : ?>
	<div class="woocommerce-account-fields">
		<?php if ( ! $checkout->is_registration_required() ) : ?>

			<p class="form-row form-row-wide create-account">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
					<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ); ?> type="checkbox" name="createaccount" value="1" /> <span><?php esc_html_e( 'Create an account?', 'woocommerce' ); ?></span>
				</label>
			</p>

		<?php endif; ?>

		<?php do_action( 'woocommerce_before_checkout_registration_form', $checkout ); ?>

		<?php if ( $checkout->get_checkout_fields( 'account' ) ) : ?>

			<div class="create-account">
				<?php foreach ( $checkout->get_checkout_fields( 'account' ) as $key => $field ) : ?>
					<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>
				<?php endforeach; ?>
				<div class="clear"></div>
			</div>

		<?php endif; ?>

		<?php do_action( 'woocommerce_after_checkout_registration_form', $checkout ); ?>
	</div>
<?php endif; ?>
