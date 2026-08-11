<?php
/**
 * My Account wrapper — restyle of WooCommerce's own my-account.php.
 *
 * Adds the two-column rail layout (sticky sidebar >=1000px, horizontal pill
 * scroller below it) around the untouched `woocommerce_account_navigation`
 * and `woocommerce_account_content` hooks. No get_header()/get_footer() here
 * — this template renders inside the normal page content area, same as the
 * reference file it is based on.
 *
 * Based on: design/_reference/woocommerce-templates/myaccount/my-account.php
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="slk-container">
	<div class="slk-account-layout">
		<?php
		/**
		 * My Account navigation.
		 *
		 * @since 2.6.0
		 */
		do_action( 'woocommerce_account_navigation' );
		?>

		<div class="woocommerce-MyAccount-content">
			<?php
				/**
				 * My Account content.
				 *
				 * @since 2.6.0
				 */
				do_action( 'woocommerce_account_content' );
			?>
		</div>
	</div>
</div>
