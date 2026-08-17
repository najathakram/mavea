<?php
/**
 * Plugin Name: MAVÉA pre-launch guard
 * Description: While the pre-launch flag is on (option mavea_prelaunch, a MISSING option counts as ON), the front page always renders the holding page standalone and every other public URL redirects home. Lives in wp-content/mu-plugins/ so it cannot be deactivated from the dashboard by accident. Launch: wp option update mavea_prelaunch 0.
 *
 * WHY: the slk-child theme ships front-page.php, and WordPress's template
 * hierarchy lets front-page.php override the static-front-page setting —
 * activating the theme would silently replace the holding page with the
 * store home. This guard outranks the theme without editing it.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The pre-launch flag. Fail-safe: a MISSING option counts as ON, so the
 * guard holds from the moment this file lands, before anything is
 * configured. Emergency override: define( 'MAVEA_PRELAUNCH', false ).
 */
function mavea_prelaunch_on(): bool {
	if ( defined( 'MAVEA_PRELAUNCH' ) ) {
		return (bool) MAVEA_PRELAUNCH;
	}

	return '0' !== (string) get_option( 'mavea_prelaunch', '1' );
}

/**
 * Is this request the site's front page? is_front_page() alone is not enough:
 * it returns false when page_on_front is unset (0) and when the front page is
 * trashed — exactly the cases the holding template's emergency fallback was
 * written for, and exactly where redirecting home would point / at itself in
 * an endless 302 loop. So fall back to comparing the requested path with
 * home's, which keeps the homepage serving something either way.
 */
function mavea_prelaunch_is_home_request(): bool {
	if ( is_front_page() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against home_url(), never output or stored.
	$mavea_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

	$mavea_path = (string) wp_parse_url( $mavea_uri, PHP_URL_PATH );
	$mavea_home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	return untrailingslashit( $mavea_path ) === untrailingslashit( $mavea_home );
}

/**
 * Front page: bypass the active theme entirely. Hooked to BOTH
 * frontpage_template (the hierarchy's own slot — exactly where the theme's
 * front-page.php would win) and template_include at PHP_INT_MAX (so no
 * later filterer can put a theme template back).
 */
function mavea_prelaunch_template( $template ) {
	if ( mavea_prelaunch_on() && mavea_prelaunch_is_home_request() ) {
		return __DIR__ . '/mavea/holding-template.php';
	}

	return $template;
}
add_filter( 'frontpage_template', 'mavea_prelaunch_template', PHP_INT_MAX );
add_filter( 'template_include', 'mavea_prelaunch_template', PHP_INT_MAX );

/**
 * Everything else: the theme provisions its brand/help pages the moment it
 * activates (slk_ensure_page), and WooCommerce's coming-soon covers only
 * store pages — without this, /our-story/ and friends would be publicly
 * reachable before launch. Anonymous front-end requests for anything but
 * the front page go home. Staff (edit_posts) pass through, so pages can be
 * reviewed while hidden. Priority 9: ahead of WP's sitemap renderer (10).
 */
function mavea_prelaunch_redirect(): void {
	if ( ! mavea_prelaunch_on() || mavea_prelaunch_is_home_request() || is_admin() ) {
		return;
	}

	if ( is_robots() || is_favicon() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_safe_redirect( home_url( '/' ), 302 );
	exit;
}
add_action( 'template_redirect', 'mavea_prelaunch_redirect', 9 );
