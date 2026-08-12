<?php
/**
 * Contact, Track order, 404 and Search — provisioning, mailer and view CSS.
 *
 * Owns: the two provisioned pages (Contact, Track order — see
 * page-templates/contact.php and page-templates/track-order.php), the
 * contact-form mail handler, restricting site search to products (so
 * search.php's product-card grid and the header search field agree on what
 * "search" means here), and all Porcelain Glass CSS these four screens need
 * beyond what components.css / style.css already carry.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Provision the two custom pages.
 *
 * 404.php and search.php are WordPress template-hierarchy files (theme root)
 * and need no page/provisioning — WordPress selects them by request type
 * automatically. Contact and Track order are ordinary pages so the URL, the
 * footer "Help" column (inc/chrome.php) and the editor all work normally.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		slk_ensure_page( 'contact', __( 'Contact', 'slk' ), 'page-templates/contact.php' );
		slk_ensure_page( 'track-order', __( 'Track order', 'slk' ), 'page-templates/track-order.php' );
	},
	20
);

/* -------------------------------------------------------------------------
 * 2. Site search is product search.
 *
 * The header search field (inc/chrome.php) already submits with a hidden
 * post_type=product field, but any other route into ?s= (a typed URL, a
 * widget, a bookmarked link) must land on the same product grid search.php
 * renders — the store has no editorial content worth searching, and a mixed
 * results page would break the "same card as the shop grid" contract.
 * ---------------------------------------------------------------------- */

add_action(
	'pre_get_posts',
	static function ( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$query->set( 'post_type', 'product' );
	}
);

/* -------------------------------------------------------------------------
 * 3. Contact page data — single-source-of-truth filters.
 *
 * The phone card reuses the WhatsApp business number (inc/cart.php's
 * slk_whatsapp_number()) rather than defining a second number: it is the
 * same line. Email and Instagram have no source anywhere in this codebase
 * yet, so both default to '' and their rows simply do not render — the same
 * "never a dead href" rule slk_whatsapp_url() already follows.
 * ---------------------------------------------------------------------- */

/**
 * The support email address shown on the Contact page, or '' to hide the row.
 *
 * @return string
 */
function slk_contact_email() {
	return (string) apply_filters( 'slk_contact_email', '' );
}

/**
 * The Instagram profile URL shown on the Contact page, or '' to hide the row.
 *
 * @return string
 */
function slk_contact_instagram_url() {
	return (string) apply_filters( 'slk_contact_instagram_url', '' );
}

/**
 * A tel: link built from the same business number as WhatsApp, or '' if no
 * number is configured.
 *
 * @return string
 */
function slk_contact_phone_url() {
	$number = preg_replace( '/\D+/', '', function_exists( 'slk_whatsapp_number' ) ? slk_whatsapp_number() : '' );

	return '' === $number ? '' : 'tel:+' . $number;
}

/* -------------------------------------------------------------------------
 * 4. Contact form handler.
 *
 * "Leave a message" posts to admin-post.php rather than to itself so the
 * mail send happens before any output starts (needed for wp_safe_redirect).
 * Success/error state travels back as a query var the template reads.
 * ---------------------------------------------------------------------- */

/**
 * Handle the Contact page's "leave a message" form.
 */
function slk_handle_contact_message() {
	$redirect = wp_get_referer() ? wp_get_referer() : slk_page_url( 'contact' );

	if (
		! isset( $_POST['slk_contact_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['slk_contact_nonce'] ) ), 'slk_contact_message' )
	) {
		wp_safe_redirect( add_query_arg( 'slk_contact', 'error', $redirect ) );
		exit;
	}

	$phone   = isset( $_POST['cf_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['cf_phone'] ) ) : '';
	$message = isset( $_POST['cf_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cf_message'] ) ) : '';

	if ( '' === $phone || '' === $message ) {
		wp_safe_redirect( add_query_arg( 'slk_contact', 'error', $redirect ) );
		exit;
	}

	$to      = get_option( 'admin_email' );
	/* translators: %s: site name. */
	$subject = sprintf( __( 'New message from %s', 'slk' ), get_bloginfo( 'name' ) );
	$body    = sprintf(
		/* translators: 1: customer's mobile number, 2: their message. */
		__( "Mobile number: %1\$s\n\nMessage:\n%2\$s", 'slk' ),
		$phone,
		$message
	);

	wp_mail( $to, $subject, $body );

	wp_safe_redirect( add_query_arg( 'slk_contact', 'sent', remove_query_arg( 'slk_contact', $redirect ) ) );
	exit;
}
add_action( 'admin_post_slk_contact_message', 'slk_handle_contact_message' );
add_action( 'admin_post_nopriv_slk_contact_message', 'slk_handle_contact_message' );

/* -------------------------------------------------------------------------
 * 5. View CSS for the four screens — tokens only, one 1000px breakpoint.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		$css = <<<'CSS'
/* -- shared page shell (Contact / Track order / 404 / Search) ----------- */
.slk-page{max-width:600px;margin:0 auto;padding:0 var(--slk-space-4) var(--slk-space-16)}
.slk-page__head{padding-top:var(--slk-space-6)}
.slk-page__head h1{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-2)}
.slk-page__intro{margin:0;color:var(--slk-color-muted);font:400 var(--slk-text-sm)/1.65 var(--slk-font-ui)}

/* -- Contact: WhatsApp card ---------------------------------------------- */
.slk-wa-card{
	display:flex;align-items:center;gap:var(--slk-space-4);
	min-height:70px;margin-top:var(--slk-space-6);
	padding:14px 14px 14px 20px;
	background:var(--slk-color-ink);color:var(--slk-color-on-ink);
	border-radius:26px;text-decoration:none;
	box-shadow:var(--slk-shadow-float);
	transition:transform var(--slk-motion-base) var(--slk-ease);
}
.slk-wa-card:hover{transform:translateY(-2px)}
.slk-wa-card__text{flex:1;font:500 15px/1.35 var(--slk-font-ui)}
.slk-wa-card__text small{display:block;font-weight:300;opacity:.72;font-size:12px;padding-top:2px}
.slk-wa-card__mark{
	flex:none;width:44px;height:44px;border-radius:50%;
	background:var(--slk-color-on-ink);color:var(--slk-color-ink);
	display:grid;place-items:center;font:600 13px var(--slk-font-ui);
}

/* -- Contact: info rows --------------------------------------------------- */
.slk-contact-rows{margin-top:var(--slk-space-3);display:grid;gap:var(--slk-space-2)}
.slk-contact-row{
	display:flex;align-items:center;gap:var(--slk-space-4);min-height:56px;
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);padding:var(--slk-space-4) 18px;
	text-decoration:none;color:inherit;
}
.slk-contact-row__text{flex:1;font:500 13px/1.4 var(--slk-font-ui)}
.slk-contact-row__text small{display:block;font-weight:400;color:var(--slk-color-faint);font-size:11.5px;padding-top:2px}

/* -- Contact: the atelier -------------------------------------------------- */
.slk-atelier{margin-top:var(--slk-space-6)}
.slk-atelier__media{
	aspect-ratio:16/10;border-radius:var(--slk-radius-tile);overflow:hidden;
	margin-top:var(--slk-space-3);box-shadow:var(--slk-shadow-lift);
}
.slk-atelier__media img{width:100%;height:100%;object-fit:cover;display:block}
.slk-atelier__copy{margin:var(--slk-space-3) 0 0;color:var(--slk-color-muted);font:400 var(--slk-text-sm)/1.7 var(--slk-font-ui)}

/* -- Contact: leave a message --------------------------------------------- */
.slk-contact-form{margin-top:var(--slk-space-6);padding:var(--slk-space-6) 18px}
.slk-contact-form h2{font-size:var(--slk-text-lg);margin:0 0 var(--slk-space-4)}
.slk-contact-form .slk-toast{margin-bottom:var(--slk-space-4)}

/* -- Track order: real WooCommerce tracking form/result, restyled -------- */
.slk-track__panel{
	margin-top:var(--slk-space-6);
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);padding:var(--slk-space-6) 18px;
}
.slk-track__panel form.woocommerce-form-track-order p:first-child{
	margin:0 0 var(--slk-space-4);color:var(--slk-color-muted);
	font:400 var(--slk-text-sm)/1.65 var(--slk-font-ui);
}
.slk-track__panel .form-row{margin:0 0 var(--slk-space-3)}
.slk-track__panel .form-row label{display:block;font:500 12px/1 var(--slk-font-ui);margin-bottom:7px}
.slk-track__panel .form-row .input-text{
	width:100%;min-height:48px;border:1px solid var(--slk-field-border);
	background:var(--slk-color-white);border-radius:var(--slk-radius-field);padding:0 16px;
	font:400 14px var(--slk-font-ui);color:var(--slk-color-ink);
}
.slk-track__panel .form-row button[name="track"]{
	width:100%;min-height:50px;border:0;background:var(--slk-color-ink);
	color:var(--slk-color-on-ink);border-radius:var(--slk-radius-pill);
	font:500 13.5px/1 var(--slk-font-ui);cursor:pointer;margin-top:var(--slk-space-2);
	transition:transform var(--slk-motion-base) var(--slk-ease);
}
.slk-track__panel .form-row button[name="track"]:hover{transform:translateY(-2px)}
.slk-track__panel p.order-info{
	font:500 13.5px/1.5 var(--slk-font-ui);margin:0 0 var(--slk-space-4);
}
.slk-track__panel ol.notes{list-style:none;margin:0;padding:0;display:grid;gap:var(--slk-space-3)}
.slk-track__panel ol.notes li{
	background:rgba(255,255,255,.7);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-tile);padding:14px 16px;
}
.slk-track__panel ol.notes .meta{margin:0 0 4px;color:var(--slk-color-faint);font:400 11.5px/1.5 var(--slk-font-ui)}
.slk-track__panel ol.notes .description p{margin:0;font:400 12.5px/1.6 var(--slk-font-ui)}
.slk-track__panel table.order_details,
.slk-track__panel table.shop_table{width:100%;border-collapse:collapse;margin-top:var(--slk-space-4)}
.slk-track__panel table.order_details th,
.slk-track__panel table.order_details td,
.slk-track__panel table.shop_table th,
.slk-track__panel table.shop_table td{
	padding:var(--slk-space-2) 0;border-bottom:1px solid var(--slk-hairline);
	font:400 12.5px/1.5 var(--slk-font-ui);text-align:left;
}
.slk-track__help{margin-top:var(--slk-space-4);text-align:center;color:var(--slk-color-faint);font:400 11.5px/1.6 var(--slk-font-ui)}

/* -- 404 -------------------------------------------------------------------- */
.slk-404__hero{padding-top:var(--slk-space-16);text-align:center}
.slk-404__num{font-family:var(--slk-font-display);font-weight:300;font-size:52px;line-height:1;color:var(--slk-color-faint);margin-bottom:var(--slk-space-3)}
.slk-404__hero h1{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-2)}
.slk-404__hero p{max-width:30ch;margin:0 auto var(--slk-space-6);color:var(--slk-color-muted);font:400 var(--slk-text-sm)/1.65 var(--slk-font-ui)}
.slk-404__actions{display:flex;gap:var(--slk-space-2);justify-content:center;flex-wrap:wrap}
.slk-cart-empty__related{margin-top:var(--slk-space-16)}

/* -- Search ------------------------------------------------------------------ */
.slk-search__count{margin-top:var(--slk-space-4)}
.slk-search__list{margin-top:var(--slk-space-4)}
.slk-search__empty{padding-top:var(--slk-space-12);text-align:center}
.slk-search__empty h1{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-2)}
.slk-search__empty p{margin:0 auto var(--slk-space-6);max-width:34ch;color:var(--slk-color-muted);font:400 var(--slk-text-sm)/1.65 var(--slk-font-ui)}
.slk-search__suggestions{display:flex;flex-wrap:wrap;gap:var(--slk-space-2);justify-content:center}
.slk-search__suggestions a{
	min-height:40px;display:inline-flex;align-items:center;padding:0 14px;
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-pill);font:400 12.5px/1 var(--slk-font-ui);
	text-decoration:none;color:inherit;
}

@media (min-width:1000px){
	.slk-page{max-width:760px}
	.slk-page__head h1{font-size:var(--slk-display-m)}
}
CSS;

		wp_add_inline_style( 'slk-child', $css );
	},
	31
);
