<?php
/**
 * Account dashboard — the "Account" screen in design/sections/05-account.html.
 *
 * A full rebuild rather than a restyle of WooCommerce's default dashboard.php:
 * the default is two sentences of prose links, the design is a greeting, a
 * latest-order card, quick links and a sign-out list. All data below is real
 * — current user, their latest order via wc_get_orders(), their saved
 * address city — nothing is placeholder copy.
 *
 * Not reproduced from the mockup: "My size" and "Exchanges" tiles. Neither
 * has a page, a saved-size field or an exchange-request flow behind it in
 * this build, and this theme's own chrome (inc/chrome.php) drops any nav
 * item whose URL would be empty rather than ship a dead link — the same
 * rule applies here.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

$current_user = wp_get_current_user();
$user_id      = $current_user->ID;
$first_name   = slk_account_first_name( $current_user );
$initial      = function_exists( 'mb_substr' ) ? mb_substr( $first_name, 0, 1 ) : substr( $first_name, 0, 1 );

$phone = get_user_meta( $user_id, 'billing_phone', true );
$city  = slk_account_address_summary( $user_id, 'shipping' );
$meta_bits = array_filter( array( $phone, $city ) );

$latest_order = slk_account_latest_order( $user_id );

$all_orders = wc_get_orders(
	array(
		'customer_id' => $user_id,
		'limit'       => -1,
		'return'      => 'ids',
	)
);
$order_count   = count( $all_orders );
$active_count  = 0;
foreach ( $all_orders as $oid ) {
	$o = wc_get_order( $oid );
	if ( $o && slk_account_status_awaiting_cod( slk_account_status_slug( $o ) ) ) {
		++$active_count;
	}
}

$wa_url = function_exists( 'slk_whatsapp_url' ) ? slk_whatsapp_url( __( 'Hi! I have a question.', 'slk' ) ) : '';
?>

<div class="slk-acct-hello">
	<span class="slk-acct-hello__avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
	<div>
		<h1><?php echo esc_html( sprintf( __( 'Hello, %s.', 'slk' ), $first_name ) ); ?></h1>
		<?php if ( $meta_bits ) : ?>
			<p><?php echo esc_html( implode( ' · ', $meta_bits ) ); ?></p>
		<?php endif; ?>
	</div>
</div>

<?php if ( $latest_order ) : ?>
	<?php
	$slug  = slk_account_status_slug( $latest_order );
	$step  = slk_account_status_step( $slug );
	$thumb = slk_account_order_thumbnail( $latest_order, 'thumbnail' );
	?>
	<a class="slk-panel slk-acct-order" href="<?php echo esc_url( $latest_order->get_view_order_url() ); ?>">
		<div class="slk-acct-order__head">
			<span class="slk-eyebrow"><?php echo esc_html( sprintf( __( 'Latest order · #%s', 'slk' ), $latest_order->get_order_number() ) ); ?></span>
			<?php echo slk_account_status_pill( $latest_order ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</div>
		<div class="slk-acct-order__row">
			<?php if ( $thumb ) : ?>
				<span class="slk-acct-order__thumb"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- from wp_get_attachment_image(). ?></span>
			<?php endif; ?>
			<span class="slk-acct-order__text">
				<?php echo esc_html( slk_account_order_item_summary( $latest_order ) ); ?>
				<?php if ( 'cod' === $latest_order->get_payment_method() && slk_account_status_awaiting_cod( $slug ) ) : ?>
					<small>
						<?php
						printf(
							/* translators: %s: formatted order total */
							esc_html__( 'Keep %s ready', 'slk' ),
							wp_kses_post( $latest_order->get_formatted_order_total() )
						);
						?>
					</small>
				<?php endif; ?>
			</span>
		</div>
		<?php if ( $step >= 0 ) : ?>
			<div class="slk-acct-order__bar" aria-hidden="true">
				<?php for ( $i = 0; $i < 4; $i++ ) : ?>
					<span class="<?php echo $i <= $step ? 'is-filled' : ''; ?>"></span>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</a>
<?php endif; ?>

<div class="slk-acct-grid">
	<a class="slk-acct-tile" href="<?php echo esc_url( wc_get_endpoint_url( 'orders' ) ); ?>">
		<div class="slk-acct-tile__t"><?php esc_html_e( 'Orders', 'slk' ); ?></div>
		<div class="slk-acct-tile__s">
			<?php
			if ( $order_count ) {
				echo esc_html(
					$active_count
						/* translators: 1: total order count, 2: orders currently in progress */
						? sprintf( _n( '%1$d order · %2$d on the way', '%1$d orders · %2$d on the way', $order_count, 'slk' ), $order_count, $active_count )
						/* translators: %d: total order count */
						: sprintf( _n( '%d order', '%d orders', $order_count, 'slk' ), $order_count )
				);
			} else {
				esc_html_e( 'No orders yet', 'slk' );
			}
			?>
		</div>
	</a>
	<a class="slk-acct-tile" href="<?php echo esc_url( wc_get_endpoint_url( 'edit-address' ) ); ?>">
		<div class="slk-acct-tile__t"><?php esc_html_e( 'Addresses', 'slk' ); ?></div>
		<div class="slk-acct-tile__s"><?php echo esc_html( $city ? $city : __( 'Add an address', 'slk' ) ); ?></div>
	</a>
</div>

<div class="slk-acct-list">
	<a href="<?php echo esc_url( wc_get_endpoint_url( 'edit-account' ) ); ?>">
		<?php esc_html_e( 'Profile details', 'slk' ); ?> <span aria-hidden="true">&rarr;</span>
	</a>
	<a href="<?php echo esc_url( wc_logout_url() ); ?>">
		<?php esc_html_e( 'Sign out', 'slk' ); ?> <span aria-hidden="true">&rarr;</span>
	</a>
</div>

<?php if ( $wa_url ) : ?>
	<a class="slk-acct-wa" href="<?php echo esc_url( $wa_url ); ?>">
		<span class="slk-acct-wa__text">
			<?php esc_html_e( 'Need a hand with anything?', 'slk' ); ?>
			<small><?php esc_html_e( 'WhatsApp · replies until 8pm', 'slk' ); ?></small>
		</span>
		<span class="slk-acct-wa__mark" aria-hidden="true">W</span>
	</a>
<?php endif; ?>

<?php
/**
 * My Account dashboard.
 *
 * @since 2.6.0
 */
do_action( 'woocommerce_account_dashboard' );
