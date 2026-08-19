<?php
/**
 * Account area — sign in, dashboard, orders, addresses, order tracking.
 *
 * Real WooCommerce My Account, not a parallel system: every screen here is
 * either a genuine template override (form-login.php, my-account.php,
 * dashboard.php — the three files WooCommerce loads for those routes) or a
 * restyle of the native orders / view-order / address / edit-account markup
 * via hooks and injected CSS from THIS file, because this file owns the
 * account area's behaviour and those two templates are not in this agent's
 * file list. Nothing here forks WooCommerce's data layer — order state,
 * addresses and account details still come straight out of Woo.
 *
 * design/sections/05-account.html shows a phone one-time-code sign-in. This
 * store has no SMS/OTP delivery wired up (no plugin, no gateway) — building
 * that screen would ship a form that cannot ever complete. The real
 * WordPress + WooCommerce login (username/email + password) is restyled to
 * the same glass-panel language instead; see woocommerce/myaccount/form-login.php.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Custom order-flow statuses — friendly labels, never a blank status.
 *
 * slk-order-flow (local/plugins/slk-order-flow) owns registering these as
 * real post statuses; it is a scaffold today. Whether or not it has run,
 * wc_get_order_status_name() falls back to wc_get_order_statuses(), and this
 * filter guarantees an entry exists for each one so the Orders list and the
 * order-tracking screen never fall through to a blank or raw-slug label.
 * ---------------------------------------------------------------------- */

/**
 * @param array $statuses wc- prefixed status => label.
 * @return array
 */
function slk_account_order_statuses( $statuses ) {
	$defaults = array(
		'wc-pending-confirm' => __( 'Awaiting confirmation', 'slk' ),
		'wc-confirmed'       => __( 'Confirmed', 'slk' ),
		'wc-dispatched'      => __( 'With the courier', 'slk' ),
		'wc-rto'             => __( 'Returned to origin', 'slk' ),
	);

	foreach ( $defaults as $key => $label ) {
		if ( ! isset( $statuses[ $key ] ) ) {
			$statuses[ $key ] = $label;
		}
	}

	return $statuses;
}
add_filter( 'wc_order_statuses', 'slk_account_order_statuses', 20 );

/*
 * Remove Blocksy's hero band from the account area at the source — the login
 * and dashboard templates title themselves, so the band's "My account" was a
 * second h1 in the served HTML even while display:none'd. Verify, D3.
 */
add_filter(
	'blocksy:single:has-default-hero',
	static function ( $has ) {
		return ( function_exists( 'is_account_page' ) && is_account_page() ) ? false : $has;
	}
);

/**
 * The bare status slug (no wc- prefix) for an order — the piece every helper
 * below keys off.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function slk_account_status_slug( $order ) {
	return (string) $order->get_status();
}

/**
 * Where a status sits on the four-step COD timeline: placed, confirmed,
 * dispatched, delivered. Terminal/exception statuses (rto, cancelled,
 * refunded, failed) return -1 — they get their own messaging, not a step
 * index, because forcing them onto the happy-path timeline would misstate
 * what actually happened to the order.
 *
 * @param string $slug Bare status slug.
 * @return int -1..3
 */
function slk_account_status_step( $slug ) {
	$map = array(
		'pending'         => 0,
		'pending-confirm' => 0,
		'on-hold'         => 0,
		'confirmed'       => 1,
		'processing'      => 1,
		'dispatched'      => 2,
		'completed'       => 3,
	);

	return $map[ $slug ] ?? -1;
}

/**
 * True for a status that should still show a "keep cash ready" reminder.
 *
 * @param string $slug Bare status slug.
 * @return bool
 */
function slk_account_status_awaiting_cod( $slug ) {
	return in_array( $slug, array( 'pending', 'pending-confirm', 'on-hold', 'confirmed', 'processing', 'dispatched' ), true );
}

/**
 * Pill tone for a status chip: 'solid' (ink, currently active/attention) or
 * 'quiet' (translucent, resolved/informational) — the same two tones the
 * design uses across the Orders list and the account card.
 *
 * @param string $slug Bare status slug.
 * @return string
 */
function slk_account_status_tone( $slug ) {
	$solid = array( 'pending', 'pending-confirm', 'on-hold', 'confirmed', 'processing', 'dispatched', 'rto' );

	return in_array( $slug, $solid, true ) ? 'solid' : 'quiet';
}

/**
 * Render a status pill matching .slk-step / order-card chip styling.
 *
 * @param WC_Order $order Order.
 * @return string Escaped HTML.
 */
function slk_account_status_pill( $order ) {
	$slug  = slk_account_status_slug( $order );
	$label = wc_get_order_status_name( $order->get_status() );
	$tone  = slk_account_status_tone( $slug );

	return sprintf(
		'<span class="slk-status-pill slk-status-pill--%1$s">%2$s</span>',
		esc_attr( $tone ),
		esc_html( $label )
	);
}

/* -------------------------------------------------------------------------
 * 2. Account nav — only routes that actually go somewhere.
 *
 * Downloads and saved payment methods are dropped: this is a COD-first store
 * with no digital products and no stored-card flow, so both endpoints would
 * be technically reachable but permanently empty. "Dashboard" is dropped from
 * the *list* only — the endpoint stays registered — because the dashboard is
 * where the rail already lives, not a destination inside it.
 * ---------------------------------------------------------------------- */

/**
 * @param array $items Endpoint => label.
 * @return array
 */
function slk_account_menu_items( $items ) {
	unset( $items['dashboard'], $items['downloads'], $items['payment-methods'] );

	if ( isset( $items['orders'] ) ) {
		$items['orders'] = __( 'Orders', 'slk' );
	}
	if ( isset( $items['edit-address'] ) ) {
		$items['edit-address'] = __( 'Addresses', 'slk' );
	}
	if ( isset( $items['edit-account'] ) ) {
		$items['edit-account'] = __( 'Account details', 'slk' );
	}
	if ( isset( $items['customer-logout'] ) ) {
		$items['customer-logout'] = __( 'Sign out', 'slk' );
	}

	return $items;
}
add_filter( 'woocommerce_account_menu_items', 'slk_account_menu_items', 20 );

/* -------------------------------------------------------------------------
 * 3. Shared account helpers used by dashboard.php.
 * ---------------------------------------------------------------------- */

/**
 * A first-name-first greeting name for the signed-in customer.
 *
 * @param WP_User $user Current user.
 * @return string
 */
function slk_account_first_name( $user ) {
	if ( $user->first_name ) {
		return $user->first_name;
	}

	$display = trim( (string) $user->display_name );

	if ( '' === $display ) {
		return __( 'there', 'slk' );
	}

	$parts = explode( ' ', $display );

	return $parts[0];
}

/**
 * The customer's most recent order, or null.
 *
 * @param int $user_id Customer user ID.
 * @return WC_Order|null
 */
function slk_account_latest_order( $user_id ) {
	$orders = wc_get_orders(
		array(
			'customer_id' => $user_id,
			'limit'       => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'objects',
		)
	);

	return $orders ? $orders[0] : null;
}

/**
 * A short "City" / "District" line for the saved address, or ''.
 *
 * @param int    $user_id Customer user ID.
 * @param string $type    'billing' or 'shipping'.
 * @return string
 */
function slk_account_address_summary( $user_id, $type = 'shipping' ) {
	$city = get_user_meta( $user_id, $type . '_city', true );

	if ( ! $city && 'shipping' === $type ) {
		$city = get_user_meta( $user_id, 'billing_city', true );
	}

	return (string) $city;
}

/**
 * First line-item's product thumbnail for an order, at a given size.
 *
 * @param WC_Order $order Order.
 * @param string   $size  Image size.
 * @return string HTML, or ''.
 */
function slk_account_order_thumbnail( $order, $size = 'thumbnail' ) {
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();

		if ( $product && $product->get_image_id() ) {
			return wp_get_attachment_image( $product->get_image_id(), $size, false, array( 'loading' => 'lazy', 'decoding' => 'async' ) );
		}
	}

	return '';
}

/**
 * "Nayana Abaya + 2 hijabs" style item summary line.
 *
 * @param WC_Order $order Order.
 * @return string Escaped plain text.
 */
function slk_account_order_item_summary( $order ) {
	$names = array();
	foreach ( $order->get_items() as $item ) {
		$names[] = $item->get_name();
	}

	if ( empty( $names ) ) {
		return '';
	}

	$first = array_shift( $names );
	$rest  = count( $names );

	if ( $rest > 0 ) {
		/* translators: 1: first item name, 2: number of additional items */
		return sprintf( _n( '%1$s + %2$s more', '%1$s + %2$s more', $rest, 'slk' ), $first, $rest );
	}

	return $first;
}

/* -------------------------------------------------------------------------
 * 4. Order tracking — the step timeline + COD reminder + WhatsApp CTA,
 * injected after WooCommerce's own order-details block on the view-order
 * screen (woocommerce_view_order fires at the bottom of view-order.php).
 * ---------------------------------------------------------------------- */

/**
 * @param int $order_id Order ID.
 */
function slk_account_render_tracking( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	$slug = slk_account_status_slug( $order );
	$step = slk_account_status_step( $slug );

	if ( $step >= 0 ) {
		$steps = array(
			array(
				'label' => __( 'Order placed', 'slk' ),
				'meta'  => wc_format_datetime( $order->get_date_created() ),
			),
			array(
				'label' => __( 'Confirmed with you by phone', 'slk' ),
				'meta'  => 1 <= $step ? __( 'Confirmed', 'slk' ) : __( 'We call to confirm cash-on-delivery orders before dispatch.', 'slk' ),
			),
			array(
				'label' => __( 'With the courier', 'slk' ),
				'meta'  => 2 <= $step ? __( 'On its way to you.', 'slk' ) : __( 'Packed and handed to the courier next.', 'slk' ),
			),
			array(
				'label' => __( 'Delivered', 'slk' ),
				'meta'  => 3 <= $step ? __( 'Delivered.', 'slk' ) : __( 'Pay at the door if cash on delivery.', 'slk' ),
			),
		);
		?>
		<div class="slk-panel slk-tracking">
			<?php foreach ( $steps as $i => $s ) : ?>
				<?php
				$state = 'todo';
				if ( $i < $step ) {
					$state = 'done';
				} elseif ( $i === $step ) {
					$state = 'now';
				}
				?>
				<div class="slk-step slk-step--<?php echo esc_attr( $state ); ?>">
					<span class="slk-step__rail">
						<span class="slk-step__dot"><?php echo 'done' === $state ? '&#10003;' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<?php if ( $i < count( $steps ) - 1 ) : ?><span class="slk-step__line"></span><?php endif; ?>
					</span>
					<div class="slk-step__body">
						<div class="slk-step__label"><?php echo esc_html( $s['label'] ); ?></div>
						<div class="slk-step__meta"><?php echo esc_html( $s['meta'] ); ?></div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	} elseif ( 'rto' === $slug ) {
		?>
		<div class="slk-panel slk-toast slk-toast--error">
			<?php esc_html_e( 'This order came back to us because the courier could not complete the delivery. Message us on WhatsApp and we will sort it out.', 'slk' ); ?>
		</div>
		<?php
	}

	if ( 'cod' === $order->get_payment_method() && slk_account_status_awaiting_cod( $slug ) ) {
		?>
		<p class="slk-tracking__cod">
			<?php
			printf(
				/* translators: %s: formatted order total */
				esc_html__( 'Keep %s ready in cash for the courier.', 'slk' ),
				wp_kses_post( $order->get_formatted_order_total() )
			);
			?>
		</p>
		<?php
	}

	$wa_message = sprintf(
		/* translators: %s: order number */
		__( 'Hi! Question about order #%s.', 'slk' ),
		$order->get_order_number()
	);
	$wa = function_exists( 'slk_whatsapp_url' ) ? slk_whatsapp_url( $wa_message ) : '';

	if ( $wa ) {
		?>
		<a class="slk-tracking__wa" href="<?php echo esc_url( $wa ); ?>">
			<span class="slk-tracking__wa-text">
				<?php esc_html_e( 'Anything wrong? Tell us on WhatsApp', 'slk' ); ?>
				<small><?php echo esc_html( sprintf( __( 'order #%s attached automatically', 'slk' ), $order->get_order_number() ) ); ?></small>
			</span>
			<span class="slk-tracking__wa-mark" aria-hidden="true">W</span>
		</a>
		<?php
	}

	if ( slk_account_status_awaiting_cod( $slug ) && 'cod' === $order->get_payment_method() ) {
		?>
		<p class="slk-tracking__note"><?php esc_html_e( 'Changed your mind? You can decline at the door by telling the courier.', 'slk' ); ?></p>
		<?php
	}
}
add_action( 'woocommerce_view_order', 'slk_account_render_tracking', 20 );

/* -------------------------------------------------------------------------
 * 5. Checkout — sign in, create an account, or continue as a guest.
 *
 * A logged-out shopper used to drop straight into the guest form with no
 * choice. This turns on WooCommerce's own account creation (a real,
 * editable setting, not a hard-coded filter, so a merchant can later switch
 * it off in WooCommerce > Settings > Accounts & Privacy without this file
 * fighting back) and requires the one thing an account cannot exist
 * without: an email, only when the shopper actually asks to create one.
 * Guest checkout itself is untouched and stays enabled.
 *
 * WooCommerce's own login-reminder notice ("Returning customer? Click here
 * to login") is turned back OFF: this checkout renders its own single
 * sign-in prompt instead (slk_checkout_signin_row() below), so WooCommerce's
 * copy of the same prompt would be a second one. Guarded by
 * `slk_checkout_account_options_bound_v2`, a new option key, rather than the
 * original `_bound` key, so this seeds the corrected default once even on a
 * store where the old (pre-fix) values were already bound.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		if ( get_option( 'slk_checkout_account_options_bound_v2' ) ) {
			return;
		}

		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );
		update_option( 'slk_checkout_account_options_bound_v2', 'yes', false );
	},
	15
);

/**
 * Email is optional at this checkout by design (see the slk-checkout
 * plugin's SLK_Checkout_Fields / SLK_Email_Policy), but an account cannot
 * exist without one. Require it only when "Create an account?" is actually
 * checked, and say why in the same sentence. Guests are never touched by
 * this: the check is gated on $data['createaccount'].
 *
 * @param array    $data   Posted checkout data.
 * @param WP_Error $errors Error bag.
 */
add_action(
	'woocommerce_after_checkout_validation',
	static function ( $data, $errors ) {
		if ( ! is_array( $data ) || ! is_wp_error( $errors ) ) {
			return;
		}

		$creating_account = ! empty( $data['createaccount'] );
		$email            = isset( $data['billing_email'] ) ? trim( (string) $data['billing_email'] ) : '';

		if ( $creating_account && '' === $email ) {
			$errors->add(
				'billing_email',
				__( 'An email is needed to create your account, so we can send your login link and order updates.', 'slk' ),
				array( 'id' => 'billing_email' )
			);
		}
	},
	10,
	2
);

/**
 * The one account prompt this checkout shows: a "Continue with Google"
 * button (SLK_Google, WP3) beside a plain "Already have an account? Sign
 * in" link, for a logged-out shopper only, at the very top of the "1 · You"
 * step — before the mobile number field, not above the page title.
 *
 * Hooked onto woocommerce_before_checkout_billing_form rather than called
 * from form-checkout.php: that hook is fired by
 * woocommerce/checkout/form-billing.php right after the "1 · You" panel's
 * heading and before its field wrapper, which is the only way to land
 * content at the top of that specific step without editing form-billing.php
 * (a file outside this package). It fires exactly once, inside "1 · You"
 * only — see the header note in form-billing.php.
 *
 * Every control here still operates on WooCommerce's own markup; nothing
 * here is a parallel sign-in system:
 *
 * - The Google button is real markup only when SLK_Google::available()
 *   finds the login-with-google plugin active with a client id configured;
 *   otherwise SLK_Google::button() returns '' and only the sign-in link
 *   shows, so a dead "Continue with Google" button never reaches a
 *   shopper. The class is checked for existence first because a checkout
 *   render in this codebase can happen before WP3's class file is present.
 * - "Already have an account? Sign in" is an <a class="showlogin">, the
 *   exact class WooCommerce's own checkout.js binds a delegated click
 *   handler to. Clicking it opens the same hidden login form
 *   woocommerce_checkout_login_form() already prints via the
 *   woocommerce_before_checkout_form hook. WooCommerce's own visible
 *   reminder notice (and its own .showlogin link) is turned off in section
 *   5 above, so this link is now the only trigger for that form.
 *
 * @param WC_Checkout|null $checkout Current checkout instance.
 */
function slk_checkout_signin_row( $checkout ) {
	if ( is_user_logged_in() || ! $checkout instanceof WC_Checkout ) {
		return;
	}

	// Mirrors the guard WooCommerce's own checkout/form-login.php uses to
	// decide whether a login form exists at all: if registration is
	// required but not enabled, no form is reachable and a sign-in link
	// here would be dead.
	if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() ) {
		return;
	}

	$google_button = '';
	if ( class_exists( 'SLK_Google' ) && is_callable( array( 'SLK_Google', 'available' ) ) && SLK_Google::available() ) {
		$google_button = (string) SLK_Google::button( wc_get_checkout_url() );
	}
	?>
	<div class="slk-checkout__signin">
		<div class="slk-checkout__signin-row">
			<?php if ( '' !== $google_button ) : ?>
				<?php echo wp_kses_post( $google_button ); ?>
			<?php endif; ?>
			<a href="#" class="slk-checkout__signin-link showlogin"><?php esc_html_e( 'Already have an account? Sign in', 'slk' ); ?></a>
		</div>
		<div class="slk-checkout__signin-divider" aria-hidden="true"><span><?php esc_html_e( 'or', 'slk' ); ?></span></div>
	</div>
	<?php
}
add_action( 'woocommerce_before_checkout_billing_form', 'slk_checkout_signin_row' );

/**
 * The loyalty-points banner SLK_Points prints on
 * woocommerce_before_checkout_form (plugins/slk-order-flow/includes/class-slk-points.php
 * — out of this package's file list, so unhooked here rather than edited
 * there) shouted above the page title and duplicated a fact that now sits
 * once, under the account checkbox in "1 · You" (see the slk-checkout
 * plugin's field labels). Removed from the markup entirely, not hidden:
 * matches the exact hook, callback and (default) priority
 * SLK_Points::init() registered with, so this removes only that one
 * callback and nothing else on the hook.
 */
remove_action( 'woocommerce_before_checkout_form', array( 'SLK_Points', 'render_guest_notice' ) );

/* -------------------------------------------------------------------------
 * 5A. Logged-out /my-account/ screen — Google sign-in row + reasons to have
 * an account.
 *
 * WP1 (2026-08-18): at 1568px the register card sat below the fold inside a
 * 420px ribbon, so "Create an account" was effectively invisible and sign-up
 * looked missing to a visitor who never scrolled. Section 7 below widens the
 * layout to a two-up grid at >=1000px so both cards are visible together;
 * this adds the two things the old page never said at all: a Google
 * sign-in option, and why an account is worth having.
 * ---------------------------------------------------------------------- */

/**
 * "Continue with Google" row above the sign-in / register cards, mirroring
 * slk_checkout_signin_row() (section 5) but standalone: no "Already have an
 * account? Sign in" link is needed here, the sign-in form is right below.
 *
 * Redirects to the my-account permalink, NOT wc_get_checkout_url() — unlike
 * slk_checkout_signin_row()'s call site, this one is the account screen
 * itself, so a visitor who authorises with Google should land back here.
 *
 * Prints nothing at all — no wrapper, no "or" divider — when Google sign-in
 * is not configured (SLK_Google::available() false, or button() still
 * returns '' for some other reason), so an empty shell or an orphaned
 * divider never reaches a visitor.
 */
function slk_account_google_row() {
	if ( ! class_exists( 'SLK_Google' ) || ! is_callable( array( 'SLK_Google', 'available' ) ) || ! SLK_Google::available() ) {
		return;
	}

	$button = (string) SLK_Google::button( wc_get_page_permalink( 'myaccount' ) );

	if ( '' === $button ) {
		return;
	}
	?>
	<div class="slk-auth-google">
		<div class="slk-auth-google-row"><?php echo wp_kses_post( $button ); ?></div>
		<div class="slk-auth-google-divider" aria-hidden="true"><span><?php esc_html_e( 'or', 'slk' ); ?></span></div>
	</div>
	<?php
}
add_action( 'woocommerce_before_customer_login_form', 'slk_account_google_row' );

/**
 * Three quiet tiles naming what an account is actually for, printed after
 * the sign-in / register cards. Each is gated on the class that ships the
 * feature it names being active, and dropped entirely — never printed with
 * placeholder copy — when that class is not there: order tracking
 * (SLK_Track), Saved pieces (SLK_Saved), points toward credit (SLK_Points).
 * No numbers, no rate, no urgency: the points rate is SLK_Points's own
 * dashboard disclosure (render_account_panel()), not this page's.
 */
function slk_account_reasons_to_join() {
	$reasons = array();

	if ( class_exists( 'SLK_Track' ) ) {
		$reasons[] = array(
			'title' => __( 'Track every order', 'slk' ),
			'text'  => __( 'Follow each order from confirmation to delivery.', 'slk' ),
		);
	}

	if ( class_exists( 'SLK_Saved' ) ) {
		$reasons[] = array(
			'title' => __( 'Saved pieces', 'slk' ),
			'text'  => __( 'Keep pieces you like somewhere you can find them again.', 'slk' ),
		);
	}

	if ( class_exists( 'SLK_Points' ) ) {
		$reasons[] = array(
			'title' => __( 'Points towards credit', 'slk' ),
			'text'  => __( 'Orders earn points that go towards credit on the next one.', 'slk' ),
		);
	}

	if ( empty( $reasons ) ) {
		return;
	}
	?>
	<div class="slk-auth-reasons">
		<?php foreach ( $reasons as $reason ) : ?>
			<div class="slk-auth-reason">
				<div class="slk-auth-reason__t"><?php echo esc_html( $reason['title'] ); ?></div>
				<div class="slk-auth-reason__s"><?php echo esc_html( $reason['text'] ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
add_action( 'woocommerce_after_customer_login_form', 'slk_account_reasons_to_join' );

/* -------------------------------------------------------------------------
 * 6. Registration — full name + mobile number.
 *
 * Registration is passwordless (a set-password link is emailed —
 * woocommerce_registration_generate_username and ..._generate_password stay
 * 'yes'; see woocommerce/myaccount/form-login.php). Email alone leaves no way
 * to reach a customer for a cash-on-delivery confirmation call, so this adds
 * a full name and a Sri Lankan mobile number to the same form via
 * woocommerce_register_form — no template edit needed. The fields use the
 * same .woocommerce-form-row.form-row-wide / .input-text markup the stock
 * email field uses, so section 7's CSS already styles them.
 * ---------------------------------------------------------------------- */

/**
 * Render the extra registration fields. Values are repopulated from $_POST
 * so a validation failure on the other fields does not blank these out.
 */
function slk_account_registration_fields() {
	$name  = ! empty( $_POST['reg_full_name'] ) && is_string( $_POST['reg_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reg_full_name'] ) ) : '';
	$phone = ! empty( $_POST['reg_phone'] ) && is_string( $_POST['reg_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['reg_phone'] ) ) : '';
	?>
	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
		<label for="reg_full_name"><?php esc_html_e( 'Full name', 'slk' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
		<input type="text" class="woocommerce-Input woocommerce-Input--text input-text" name="reg_full_name" id="reg_full_name" autocomplete="name" value="<?php echo esc_attr( $name ); ?>" required aria-required="true" />
	</p>
	<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
		<label for="reg_phone"><?php esc_html_e( 'Mobile number', 'slk' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span><span class="screen-reader-text"><?php esc_html_e( 'Required', 'woocommerce' ); ?></span></label>
		<input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="reg_phone" id="reg_phone" autocomplete="tel" aria-describedby="reg_phone-hint" value="<?php echo esc_attr( $phone ); ?>" required aria-required="true" />
		<span class="slk-field__hint" id="reg_phone-hint"><?php esc_html_e( 'The number we confirm orders on.', 'slk' ); ?></span>
	</p>
	<?php
}
add_action( 'woocommerce_register_form', 'slk_account_registration_fields' );

/**
 * Validate the extra registration fields, appended to WooCommerce's own
 * $errors bag from WC_Form_Handler::process_registration().
 *
 * @param string   $username Posted username (unused; part of the hook signature).
 * @param string   $email    Posted email (unused; part of the hook signature).
 * @param WP_Error $errors   Error bag.
 */
function slk_account_validate_registration_fields( $username, $email, $errors ) {
	// wc_create_new_customer() fires this hook for checkout's "Create an account"
	// tick and for programmatic customer creation too, neither of which posts
	// these fields. Only the my-account register form carries this nonce field,
	// so leave every other path alone.
	if ( ! isset( $_POST['woocommerce-register-nonce'] ) ) {
		return;
	}

	if ( ! $errors instanceof WP_Error ) {
		return;
	}

	$name  = ! empty( $_POST['reg_full_name'] ) && is_string( $_POST['reg_full_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reg_full_name'] ) ) : '';
	$phone = ! empty( $_POST['reg_phone'] ) && is_string( $_POST['reg_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['reg_phone'] ) ) : '';

	if ( '' === trim( $name ) ) {
		$errors->add( 'reg_full_name', __( 'Please enter your full name.', 'slk' ) );
	}

	// One phone contract for the whole store. SLK_Phone::check() is what the
	// checkout validates with (SLK_Checkout_Fields::validate()), and it accepts
	// dashes, dots, brackets and the 94… / 0094… prefixes as well as 07X…; a
	// second, narrower rule here would reject at registration the very numbers
	// checkout accepts. The inline fallback runs only if slk-checkout is off.
	if ( class_exists( 'SLK_Phone' ) ) {
		$check = SLK_Phone::check( $phone );

		if ( ! $check['ok'] ) {
			$errors->add( 'reg_phone', $check['message'] );
		}
	} else {
		$phone_compact = preg_replace( '/\s+/', '', $phone );

		if ( ! is_string( $phone_compact ) || ! preg_match( '/^(?:\+94|0)?7\d{8}$/', $phone_compact ) ) {
			$errors->add( 'reg_phone', __( 'Enter a Sri Lankan mobile number, like 07X XXX XXXX.', 'slk' ) );
		}
	}
}
add_action( 'woocommerce_register_post', 'slk_account_validate_registration_fields', 10, 3 );

/**
 * Persist the extra registration fields once WooCommerce has created the
 * account: split the full name into first/last, set the display name, and
 * store the mobile number as billing_phone.
 *
 * @param int   $customer_id        Newly created user ID.
 * @param array $new_customer_data  Unused; part of the hook signature.
 * @param bool  $password_generated Unused; part of the hook signature.
 */
function slk_account_save_registration_fields( $customer_id, $new_customer_data, $password_generated ) {
	$name  = ! empty( $_POST['reg_full_name'] ) && is_string( $_POST['reg_full_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['reg_full_name'] ) ) ) : '';
	$phone = ! empty( $_POST['reg_phone'] ) && is_string( $_POST['reg_phone'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['reg_phone'] ) ) ) : '';

	if ( '' !== $name ) {
		$parts = explode( ' ', $name, 2 );

		wp_update_user(
			array(
				'ID'           => $customer_id,
				'first_name'   => $parts[0],
				'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
				'display_name' => $name,
			)
		);
	}

	if ( '' !== $phone ) {
		// billing_phone is stored canonically as +94XXXXXXXXX everywhere else
		// (see class-slk-phone.php); store the same form here so the dashboard
		// header and the orders show one number, not two formats of it.
		$normalised = class_exists( 'SLK_Phone' ) ? SLK_Phone::normalise( $phone ) : '';

		update_user_meta( $customer_id, 'billing_phone', '' !== $normalised ? $normalised : $phone );
	}
}
add_action( 'woocommerce_created_customer', 'slk_account_save_registration_fields', 10, 3 );

/* -------------------------------------------------------------------------
 * 7. Styling — restyle the native login form, nav, dashboard, orders table
 * and order-view screen to the glass-panel / pill contract. Tokens only,
 * one 1000px breakpoint.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		$css = <<<'CSS'
/* -- shared page shell --------------------------------------------------- */
.woocommerce-account .slk-container{max-width:var(--slk-container);margin:0 auto;padding:0 var(--slk-space-4)}
.woocommerce-account main{padding:var(--slk-space-6) 0 var(--slk-space-16)}

/* -- sign in --------------------------------------------------------------
   #customer_login is WooCommerce's own login wrapper; targeted directly so
   no template markup has to be duplicated. 420px / stacked below 1000px
   (unchanged from before WP1); a 980px two-up grid at >=1000px — see the
   media query below. .slk-auth-head, .slk-auth-guest, .slk-auth-google and
   .slk-auth-reasons all default to the same 420px so nothing drifts out of
   alignment with the card(s) beneath them, at any width. */
.woocommerce-account #customer_login{
	max-width:420px;margin:0 auto;padding:var(--slk-space-6) var(--slk-space-4);
}
.woocommerce-account .slk-auth-head{max-width:420px;margin-inline:auto;padding-inline:var(--slk-space-4);padding-bottom:var(--slk-space-6)}
.woocommerce-account .slk-auth-head h1{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-2)}
.woocommerce-account .slk-auth-head p{margin:0;color:var(--slk-color-muted);font-size:var(--slk-text-sm)}
.woocommerce-account .u-columns{display:block}
/* Blocksy's .col2-set > * padding/border survives the display:block override
   above, adding a 40px inline offset and a dashed divider between the two
   stacked cards at >=690px (a dashed bottom-border variant applies below
   that). Zero both — the extra :first-child/:last-child raises specificity
   above the original rule, so this wins regardless of viewport, source
   order or the grid change below (this rule only ever touches padding and
   border, never display, so the two never fight over the same property). */
.ct-woo-unauthorized .col2-set > *:first-child,
.ct-woo-unauthorized .col2-set > *:last-child{padding:0;border:0}
.woocommerce-account .woocommerce-form-login,
.woocommerce-account .woocommerce-form-register{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);padding:var(--slk-space-6);
	box-shadow:var(--slk-shadow-lift);
}
.woocommerce-account .woocommerce-form-register{margin-top:var(--slk-space-4)}
.woocommerce-account .slk-auth-card__h{font:400 20px/1.2 var(--slk-font-display);margin:0 0 var(--slk-space-4);color:var(--slk-color-ink)}
.woocommerce-account .u-column2{margin-top:0}
/* With my-account registration turned off, form-login.php prints the bare
   sign-in form and no #customer_login wrapper at all (see
   woocommerce/myaccount/form-login.php:41), so nothing caps the card and it
   would stretch out from under the 420px head, Google row, guest note and
   tiles. Cap it to the width the card renders at when registration is on —
   #customer_login's 420px less that wrapper's own inline padding. */
.woocommerce-account:not(:has(#customer_login)) .woocommerce-form-login{
	max-width:calc(420px - var(--slk-space-4) * 2);margin-inline:auto;
}
.woocommerce-account .woocommerce-form-row{margin:0 0 var(--slk-space-3)}
.woocommerce-account .woocommerce-form-row label{display:block;font:500 12px/1 var(--slk-font-ui);margin-bottom:7px}
.woocommerce-account .woocommerce-form-row .input-text{
	width:100%;min-height:48px;border:1px solid var(--slk-field-border);background:var(--slk-color-white);
	border-radius:var(--slk-radius-field);padding:0 16px;font:400 14px var(--slk-font-ui);color:var(--slk-color-ink);
}
.woocommerce-account .woocommerce-form-login__rememberme{display:flex;align-items:center;gap:8px;font:400 12.5px var(--slk-font-ui);color:var(--slk-color-muted);margin-bottom:var(--slk-space-3)}
.woocommerce-account button.woocommerce-form-login__submit,
.woocommerce-account button.woocommerce-form-register__submit{
	width:100%;min-height:50px;border:0;background:var(--slk-color-ink);color:var(--slk-color-on-ink);
	border-radius:var(--slk-radius-pill);font:500 13.5px/1 var(--slk-font-ui);cursor:pointer;
	transition:transform var(--slk-motion-base) var(--slk-ease),box-shadow var(--slk-motion-base) var(--slk-ease);
}
.woocommerce-account button.woocommerce-form-login__submit:hover,
.woocommerce-account button.woocommerce-form-register__submit:hover{transform:translateY(-2px);box-shadow:var(--slk-shadow-press)}
.woocommerce-account .woocommerce-LostPassword{margin-top:var(--slk-space-3);text-align:center;font-size:12.5px}
.woocommerce-account .woocommerce-LostPassword a{color:var(--slk-color-ink);text-decoration:underline;text-underline-offset:3px}
.woocommerce-account .slk-auth-guest{max-width:420px;margin-inline:auto;padding-inline:var(--slk-space-4);padding-top:var(--slk-space-4);text-align:center;font-size:12px;color:var(--slk-color-faint)}
.woocommerce-account .slk-auth-note,
.woocommerce-account .woocommerce-privacy-policy-text{font:400 12.5px/1.55 var(--slk-font-ui);color:var(--slk-color-muted)}
.woocommerce-account .woocommerce-privacy-policy-text a{color:var(--slk-color-ink);text-decoration:underline;text-underline-offset:2px}

/* -- sign-in: Google row ----------------------------------------------------
   slk_account_google_row() (section 5A). Same visual language as the
   checkout's slk-checkout__signin row (see inc/checkout-view.php around
   :302/:329) so the two surfaces match; classed separately (slk-auth-*
   rather than slk-checkout__*) because this row is centred and standalone,
   not sat inside a billing-field column. */
.woocommerce-account .slk-auth-google{max-width:420px;margin:0 auto;padding:0 var(--slk-space-4) var(--slk-space-6)}
.woocommerce-account .slk-auth-google-row{display:flex;justify-content:center}
.woocommerce-account .slk-auth-google .slk-google-button{
	width:100%;min-height:var(--slk-touch);padding:0 20px;border-radius:var(--slk-radius-pill);
	border:1px solid var(--slk-field-border);background:var(--slk-color-white);
	color:var(--slk-color-ink);font:500 13px/1 var(--slk-font-ui);
	display:inline-flex;align-items:center;justify-content:center;gap:var(--slk-space-2);
	text-decoration:none;cursor:pointer;
	transition:border-color var(--slk-motion-base) var(--slk-ease),transform var(--slk-motion-base) var(--slk-ease);
}
.woocommerce-account .slk-auth-google .slk-google-button:hover{border-color:var(--slk-color-ink);transform:translateY(-1px)}
/* The icon span SLK_Google::button() emits is empty and aria-hidden, so the
   mark comes from CSS as a background image.

   This is Google's OFFICIAL four-colour G, and it is a deliberate, documented
   exception to two of this codebase's own rules — no accent colour, and no raw
   hex outside the token set. An earlier version drew a letter "G" in Archivo to
   stay inside those rules; that is not Google's mark, and Google's identity
   guidelines for Sign in with Google require their artwork, unaltered, in one of
   the permitted colourways. A trademark belonging to someone else is not ours to
   restyle, so the brand rule yields here rather than the other way round. Do not
   recolour, rotate, outline or single-colour this. The white button with the
   colour G is Google's own "light" treatment and is the closest of their
   permitted styles to Porcelain. Same artwork at inc/checkout-view.php. */
.woocommerce-account .slk-auth-google .slk-google-button__icon{
	width:18px;height:18px;flex:none;
	background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Cpath fill='%23EA4335' d='M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z'/%3E%3Cpath fill='%234285F4' d='M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z'/%3E%3Cpath fill='%23FBBC05' d='M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z'/%3E%3Cpath fill='%2334A853' d='M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z'/%3E%3C/svg%3E") center/contain no-repeat;
}
.woocommerce-account .slk-auth-google-divider{
	display:flex;align-items:center;gap:var(--slk-space-3);margin:var(--slk-space-4) 0 0;
	font:500 11px/1 var(--slk-font-ui);letter-spacing:.14em;text-transform:uppercase;color:var(--slk-color-muted);
}
.woocommerce-account .slk-auth-google-divider::before,
.woocommerce-account .slk-auth-google-divider::after{content:"";flex:1;height:1px;background:var(--slk-hairline)}

/* -- sign-in: reasons to join ------------------------------------------------
   slk_account_reasons_to_join() (section 5A) — quiet tiles under the cards,
   one column on mobile, three across at >=1000px (see the media query
   below). Any tile whose backing class is not active is simply absent from
   the loop, so this grid can render with fewer than three children. */
.woocommerce-account .slk-auth-reasons{
	max-width:420px;margin:var(--slk-space-2) auto 0;padding:0 var(--slk-space-4) var(--slk-space-6);
	display:grid;grid-template-columns:1fr;gap:10px;
}
.woocommerce-account .slk-auth-reason{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);border-radius:20px;
	padding:16px 18px;text-align:center;
}
.woocommerce-account .slk-auth-reason__t{font:500 13px/1.3 var(--slk-font-ui)}
/* Muted, not faint: this line is body copy on the glass tile, and
   --slk-color-faint is reserved for meta/disabled (style.css:45) — it lands
   near 3.4:1 there, under the 4.5:1 the redesign accepts on. */
.woocommerce-account .slk-auth-reason__s{font:400 11.5px/1.5 var(--slk-font-ui);color:var(--slk-color-muted);margin-top:3px}

/* -- sign-in: two-up at >=1000px --------------------------------------------
   WP1 (2026-08-18): the register card sat below the fold in the 420px
   single column above; here it moves level with sign-in instead. Scoped to
   :has(#customer_login) for .slk-auth-head/.slk-auth-guest so the lost- and
   reset-password screens below (same .slk-auth-head class, no #customer_login
   on those routes) are left at 420px untouched, per plan risk #2. */
@media (min-width:1000px){
	.woocommerce-account #customer_login{max-width:980px}
	.woocommerce-account:has(#customer_login) .slk-auth-head,
	.woocommerce-account:has(#customer_login) .slk-auth-guest,
	.woocommerce-account:has(#customer_login) .slk-auth-reasons{max-width:980px}
	.woocommerce-account .u-columns{
		display:grid;grid-template-columns:1fr 1fr;gap:var(--slk-space-6);align-items:start;
	}
	.woocommerce-account .u-columns > .u-column1,
	.woocommerce-account .u-columns > .u-column2{width:auto;min-width:0;float:none}
	/* The 16px above the register card spaces the two cards when they stack;
	   side by side the grid gap does that instead, and the margin would push
	   the register card 16px below sign-in. Grid items open their own
	   formatting context, so the child margin cannot collapse away — zero it
	   explicitly or align-items:start starts the two cards unevenly. */
	.woocommerce-account .u-columns > .u-column2 > .woocommerce-form-register{margin-top:0}
	.woocommerce-account:has(#customer_login) .slk-auth-reasons{grid-template-columns:repeat(3,1fr)}
}

/* -- lost / reset password -------------------------------------------------
   Single-card variant of the sign-in layout above: same .slk-auth-head,
   one glass card instead of two, no col2-set involved. See
   woocommerce/myaccount/form-lost-password.php and form-reset-password.php. */
.woocommerce-account .slk-auth-single{max-width:420px;margin:0 auto;padding:var(--slk-space-6) var(--slk-space-4)}
.woocommerce-account .slk-auth-single .woocommerce-ResetPassword{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);padding:var(--slk-space-6);
	box-shadow:var(--slk-shadow-lift);
}

/* -- nav rail -------------------------------------------------------------
   Mobile: horizontal pill scroller. Desktop (>=1000px): sticky sidebar,
   matching the design's rail. */
.woocommerce-account .woocommerce-MyAccount-navigation ul{
	list-style:none;margin:0 0 var(--slk-space-6);padding:0 0 var(--slk-space-2);
	display:flex;gap:8px;overflow-x:auto;-webkit-overflow-scrolling:touch;
}
.woocommerce-account .woocommerce-MyAccount-navigation li{flex:none}
.woocommerce-account .woocommerce-MyAccount-navigation a{
	display:flex;align-items:center;min-height:var(--slk-touch);padding:0 18px;
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-pill);text-decoration:none;color:var(--slk-color-ink);
	font:500 13px/1 var(--slk-font-ui);white-space:nowrap;
}
.woocommerce-account .woocommerce-MyAccount-navigation a[aria-current="page"]{
	background:var(--slk-color-ink);color:var(--slk-color-on-ink);border-color:transparent;
}
.woocommerce-account .woocommerce-MyAccount-content{max-width:100%}

@media (min-width:1000px){
	.woocommerce-account .slk-account-layout{
		display:grid;grid-template-columns:250px 1fr;gap:var(--slk-space-8);align-items:start;
	}
	.woocommerce-account .woocommerce-MyAccount-navigation{position:sticky;top:var(--slk-space-4)}
	.woocommerce-account .woocommerce-MyAccount-navigation ul{
		flex-direction:column;overflow:visible;padding:8px;margin:0;
		background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);border-radius:22px;gap:2px;
	}
	.woocommerce-account .woocommerce-MyAccount-navigation a{background:none;border:0;border-radius:16px;justify-content:flex-start}
	.woocommerce-account .woocommerce-MyAccount-navigation a[aria-current="page"]{background:var(--slk-color-ink)}
}

/* -- dashboard (see woocommerce/myaccount/dashboard.php for markup) ------ */
.slk-acct-hello{display:flex;align-items:center;gap:14px;margin-bottom:var(--slk-space-6)}
.slk-acct-hello__avatar{
	width:54px;height:54px;border-radius:50%;background:var(--slk-color-ink);color:var(--slk-color-on-ink);
	display:grid;place-items:center;font-family:var(--slk-font-display);font-size:22px;font-weight:300;flex:none;
}
.slk-acct-hello h1{font-size:var(--slk-display-s);margin:0 0 2px}
.slk-acct-hello p{margin:0;font-size:12px;color:var(--slk-color-muted)}

.slk-acct-order{
	display:block;text-decoration:none;color:inherit;padding:18px;margin-bottom:var(--slk-space-4);
	box-shadow:var(--slk-shadow-lift);transition:transform var(--slk-motion-base) var(--slk-ease);
}
.slk-acct-order:hover{transform:translateY(-2px)}
.slk-acct-order__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.slk-acct-order__row{display:flex;gap:12px;align-items:center}
.slk-acct-order__thumb{width:44px;aspect-ratio:3/4;border-radius:10px;overflow:hidden;flex:none}
.slk-acct-order__thumb img{width:100%;height:100%;object-fit:cover}
.slk-acct-order__text{flex:1;font:400 12.5px/1.5 var(--slk-font-ui)}
.slk-acct-order__text small{display:block;color:var(--slk-color-faint)}
.slk-acct-order__bar{display:flex;gap:5px;padding-top:14px}
.slk-acct-order__bar span{flex:1;height:4px;border-radius:2px;background:rgba(35,34,32,.14)}
.slk-acct-order__bar span.is-filled{background:var(--slk-color-ink)}

.slk-acct-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:var(--slk-space-4)}
.slk-acct-tile{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);border-radius:20px;
	padding:18px 16px;text-decoration:none;color:inherit;transition:transform var(--slk-motion-base) var(--slk-ease);
}
.slk-acct-tile:hover{transform:translateY(-2px)}
.slk-acct-tile__t{font:500 13.5px/1.3 var(--slk-font-ui);margin-bottom:3px}
.slk-acct-tile__s{font:400 11.5px/1.5 var(--slk-font-ui);color:var(--slk-color-faint)}

.slk-acct-list{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);border-radius:20px;
	padding:6px 8px;margin-bottom:var(--slk-space-4);
}
.slk-acct-list a{
	display:flex;align-items:center;justify-content:space-between;min-height:48px;padding:0 12px;
	text-decoration:none;color:inherit;font:400 13px var(--slk-font-ui);border-bottom:1px solid var(--slk-hairline);
}
.slk-acct-list a:last-child{border-bottom:0;color:var(--slk-color-faint)}

.slk-acct-wa{
	display:flex;align-items:center;gap:13px;min-height:56px;padding:8px 12px 8px 18px;
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);border-radius:28px;
	text-decoration:none;color:inherit;box-shadow:var(--slk-shadow-lift);
}
.slk-acct-wa__text{flex:1;font:500 13px/1.35 var(--slk-font-ui)}
.slk-acct-wa__text small{display:block;font-weight:400;color:var(--slk-color-muted);font-size:11.5px}
.slk-acct-wa__mark{
	width:38px;height:38px;border-radius:50%;background:var(--slk-color-ink);color:var(--slk-color-on-ink);
	display:grid;place-items:center;font:600 12px var(--slk-font-ui);flex:none;
}

@media (min-width:1000px){
	.slk-acct-grid{grid-template-columns:repeat(4,1fr)}
}

/* -- status pills ---------------------------------------------------------
   .woocommerce-orders-table__row--status-<slug> is native WC row markup;
   coloured here without touching the table template. */
.slk-status-pill{
	display:inline-flex;align-items:center;min-height:28px;padding:0 12px;
	border-radius:14px;font:500 11px/1 var(--slk-font-ui);white-space:nowrap;
}
.slk-status-pill--solid{background:var(--slk-color-ink);color:var(--slk-color-on-ink)}
.slk-status-pill--quiet{background:rgba(35,34,32,.08);color:var(--slk-color-ink)}

/* -- orders table ----------------------------------------------------------
   Kept as a real <table> (screen readers, sortability) but restyled: each
   row reads as a glass card on its own line. */
.woocommerce-orders-table{width:100%;border-collapse:separate;border-spacing:0 10px}
.woocommerce-orders-table thead{display:none}
.woocommerce-orders-table tbody tr{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
}
.woocommerce-orders-table tbody tr th,
.woocommerce-orders-table tbody tr td{
	display:block;border:0;padding:4px 18px;font:400 13px/1.6 var(--slk-font-ui);
}
.woocommerce-orders-table tbody tr th:first-child{padding-top:16px;font-weight:500}
.woocommerce-orders-table tbody tr td:last-child{padding-bottom:16px}
.woocommerce-orders-table__cell-order-number a{color:var(--slk-color-ink);text-decoration:none;font-weight:500}
.woocommerce-orders-table__cell-order-actions .button{
	display:inline-flex;align-items:center;min-height:var(--slk-touch);padding:0 18px;margin-top:8px;
	background:var(--slk-glass-solid);border:1px solid rgba(35,34,32,.16);border-radius:var(--slk-radius-pill);
	color:var(--slk-color-ink);text-decoration:none;font:500 12.5px var(--slk-font-ui);
}
@media (min-width:1000px){ /* was 640px — the theme's ONLY breakpoint is 1000 (verify, D6) */
	.woocommerce-orders-table tbody tr{display:flex;flex-wrap:wrap;align-items:center;border-radius:20px;padding:14px 18px}
	.woocommerce-orders-table tbody tr th,
	.woocommerce-orders-table tbody tr td{display:block;padding:4px 10px 4px 0;width:auto}
	.woocommerce-orders-table__cell-order-actions{margin-left:auto}
	.woocommerce-orders-table__cell-order-actions .button{margin-top:0}
}

/* -- order view / tracking ------------------------------------------------- */
.woocommerce-account .woocommerce-order-details,
.woocommerce-account .woocommerce-customer-details{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);padding:var(--slk-space-6);margin-top:var(--slk-space-4);
}
.woocommerce-account .shop_table.order_details{width:100%;border-collapse:collapse}
.woocommerce-account .shop_table.order_details th,
.woocommerce-account .shop_table.order_details td{padding:10px 0;border-bottom:1px solid var(--slk-hairline);font-size:13px}
.woocommerce-account mark.order-status{background:none;color:inherit;font-weight:500}

.slk-tracking{padding:22px 20px;margin-top:var(--slk-space-4)}
.slk-tracking .slk-step{display:flex;gap:var(--slk-space-4)}
.slk-tracking .slk-step__body{padding-bottom:20px}
.slk-tracking .slk-step__label{font:500 13.5px/1.3 var(--slk-font-ui)}
.slk-tracking .slk-step__meta{font:400 12px/1.55 var(--slk-font-ui);color:var(--slk-color-faint);padding-top:2px}
.slk-tracking .slk-step--now .slk-step__meta{color:var(--slk-color-muted)}
.slk-tracking .slk-step:last-child .slk-step__body{padding-bottom:0}

.slk-tracking__cod{
	margin:var(--slk-space-3) 0 0;padding:14px 18px;background:var(--slk-glass-solid);
	border:1px solid var(--slk-glass-edge);border-radius:16px;font:400 12.5px/1.5 var(--slk-font-ui);
}
.slk-tracking__wa{
	display:flex;align-items:center;gap:13px;min-height:56px;padding:8px 12px 8px 18px;margin-top:var(--slk-space-4);
	background:var(--slk-color-ink);color:var(--slk-color-on-ink);border-radius:28px;text-decoration:none;
	transition:transform var(--slk-motion-base) var(--slk-ease);
}
.slk-tracking__wa:hover{transform:translateY(-2px)}
.slk-tracking__wa-text{flex:1;font:500 13px/1.35 var(--slk-font-ui)}
.slk-tracking__wa-text small{display:block;font-weight:300;opacity:.7;font-size:11.5px}
.slk-tracking__wa-mark{
	width:38px;height:38px;border-radius:50%;background:var(--slk-color-on-ink);color:var(--slk-color-ink);
	display:grid;place-items:center;font:600 12px var(--slk-font-ui);flex:none;
}
.slk-tracking__note{font:400 11.5px/1.6 var(--slk-font-ui);color:var(--slk-color-faint);text-align:center;margin-top:var(--slk-space-3)}

/* -- address + account-details forms ---------------------------------------
   Native WooCommerce form markup (.woocommerce-Input, .form-row), mapped to
   the field contract without duplicating the templates. */
.woocommerce-account .woocommerce-address-fields,
.woocommerce-account .woocommerce-EditAccountForm{
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);padding:var(--slk-space-6);
}
.woocommerce-account .form-row{margin:0 0 var(--slk-space-3)}
.woocommerce-account .form-row label:not(.woocommerce-form-login__rememberme){display:block;font:500 12px/1 var(--slk-font-ui);margin-bottom:7px}
.woocommerce-account .woocommerce-Input,
.woocommerce-account select.woocommerce-Input{
	width:100%;min-height:48px;border:1px solid var(--slk-field-border);background:var(--slk-color-white);
	border-radius:var(--slk-radius-field);padding:0 16px;font:400 14px var(--slk-font-ui);color:var(--slk-color-ink);
}
.woocommerce-account .woocommerce-Button,
.woocommerce-account button[name="save_address"],
.woocommerce-account button[name="save_account_details"]{
	min-height:50px;padding:0 24px;border:0;background:var(--slk-color-ink);color:var(--slk-color-on-ink);
	border-radius:var(--slk-radius-pill);font:500 13.5px/1 var(--slk-font-ui);cursor:pointer;
}
.woocommerce-account .woocommerce-address-fields h2,
.woocommerce-account .woocommerce-address-fields h3{font-size:var(--slk-display-s);margin-top:0}

.woocommerce-account .woocommerce-message,
.woocommerce-account .woocommerce-error,
.woocommerce-account .woocommerce-info{
	list-style:none;margin:0 0 var(--slk-space-4);padding:14px 16px;
	background:var(--slk-glass);border:1px solid var(--slk-glass-edge);border-radius:20px;
	font:400 12.5px/1.45 var(--slk-font-ui);
}
.woocommerce-account .woocommerce-error{background:var(--slk-color-error-tint);border-color:color-mix(in srgb, var(--slk-color-error) 20%, transparent);color:var(--slk-color-error-ink)}
CSS;

		wp_add_inline_style( 'slk-child', $css );
	},
	31
);
