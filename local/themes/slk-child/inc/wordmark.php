<?php
/**
 * The wordmark system.
 *
 * =============================== THE NAME =================================
 * The brand is "MAVÉA". Gate G1 closed on 2026-08-15; this is no longer a
 * placeholder. "AESHAL" still appears in design/_tools/crawl_site.py as a
 * FORBIDDEN-name guard — it belongs to the US sister label and this store must
 * never carry it, so that check stays exactly as it is.
 *
 * ⚠ The É is not decorative. Two rules follow from it:
 *   1. This file — and any file that writes the name — must stay UTF-8. The
 *      É is two bytes (C3 89); a CP1252 save silently corrupts it to "MAVÃ‰A".
 *   2. Never apply PHP's strtoupper() to the wordmark. It is byte-based and
 *      would mangle the É. Uppercasing is done in CSS (text-transform), which
 *      is Unicode-aware — see the .slk-wordmark rule in style.css. The theme
 *      currently contains no strtoupper() at all; keep it that way.
 * The ASCII fallback for domains, slugs, handles and file names is "mavea"
 * (mavea.lk) — the accent lives in the wordmark and in prose, nowhere else.
 *
 * The name lives in exactly ONE place: the 'slk_wordmark' filter default
 * below. Changing it is still a one-line change, in a plugin or a single inc/
 * file, should it ever move again:
 *
 *     add_filter( 'slk_wordmark', fn() => 'MAVÉA' );
 *
 * That one line is genuinely everything, because the filter is bound to the
 * `blogname` OPTION (not just to get_bloginfo()), which is what every other
 * reader of the site name goes through: the document <title>, feeds, Blocksy's
 * header, and — the part that used to leak — WooCommerce's transactional mail.
 * WC_Email::get_from_name(), the {site_title} placeholder in every order email
 * subject and heading, the email footer, invoices and packing slips all call
 * wp_specialchars_decode( get_option( 'blogname' ) ) directly, and many of them
 * run inside wp-admin (an order marked Complete from the orders screen) or in
 * cron, so the filter must NOT be front-end-only. See slk_wordmark_is_editing_
 * blogname() below for the one narrow context that still sees the raw value.
 *
 * Nothing else needs to move: the brief's §6 system (Newsreader 300, all caps,
 * 0.26em tracking, trailing indent) holds for any name of 4-8 letters, and
 * MAVÉA is 5. The one setting change the accent forced is line-height on
 * .slk-wordmark — see the note on that rule in style.css.
 * ==========================================================================
 *
 * Spec: design/docs/brand-guidelines.md §6 and the "Wordmark system" screen in
 * design/sections/01-gaps.html.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * The wordmark text. The single source of truth for the brand name.
 *
 * @return string Uppercase brand name, unescaped.
 */
function slk_wordmark_text() {
	/**
	 * Filters the wordmark text.
	 *
	 * The brand name, settled at gate G1 — see the file header. Already in
	 * caps; the É must survive byte-for-byte, so never strtoupper() this.
	 *
	 * @param string $text The wordmark, set in caps.
	 */
	return (string) apply_filters( 'slk_wordmark', 'MAVÉA' );
}

/**
 * Is this request a screen where the STORED blogname must stay visible?
 *
 * The approach, stated plainly: the wordmark filter is applied to the blogname
 * option everywhere — front end, admin, cron, REST, WP-CLI — with exactly one
 * carve-out, the handful of places where a human edits that option. If the
 * carve-out were the whole of wp-admin (the old `is_admin()` guard), then every
 * WooCommerce email fired from wp-admin — order status changes, resent invoices,
 * refund notices — would go out under the stale stored name, and the "rename is
 * one line" promise would be false. Scoping the carve-out to the editing screens
 * instead keeps Settings > General showing the real value so it stays editable,
 * while mail, cron and every other reader see the wordmark.
 *
 * - options-general.php : the Site Title field itself.
 * - options.php         : that form's save target (so update_option() compares
 *                         against the true old value, not the filtered one).
 * - customize.php       : the Customizer's own blogname control.
 * - REST /wp/v2/settings: the block editor / Site Editor reading the same field.
 *
 * @return bool True when the raw stored value should be returned unfiltered.
 */
function slk_wordmark_is_editing_blogname() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

		if ( false !== strpos( $route, '/wp/v2/settings' ) ) {
			return true;
		}
	}

	if ( ! is_admin() ) {
		return false;
	}

	$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

	return in_array( $pagenow, array( 'options-general.php', 'options.php', 'customize.php' ), true );
}

/**
 * Size keys -> a design token. Tokens only; no raw values here.
 *
 * @return array<string,string>
 */
function slk_wordmark_sizes() {
	return array(
		'sm' => 'var(--slk-text-base)',
		'md' => 'var(--slk-text-lg)',    // header default
		'lg' => 'var(--slk-text-xl)',
		'xl' => 'var(--slk-display-s)',
	);
}

/**
 * Render the wordmark.
 *
 * @param array $args {
 *     @type string $size    One of sm|md|lg|xl. Default 'md'.
 *     @type bool   $link    Wrap in a link to the home page. Default false.
 *     @type bool   $initial Render the initial alone — the §6 fallback for
 *                           anything narrower than the 96px minimum.
 *     @type string $class   Extra classes.
 *     @type string $tag     Element when not linking. Default 'span'.
 * }
 * @return string Escaped markup.
 */
function slk_wordmark_markup( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'size'    => 'md',
			'link'    => false,
			'initial' => false,
			'class'   => '',
			'tag'     => 'span',
		)
	);

	$size = array_key_exists( $args['size'], slk_wordmark_sizes() ) ? $args['size'] : 'md';

	$text = slk_wordmark_text();

	if ( $args['initial'] ) {
		// Multibyte-safe first character, same tracking (§6 minimum-width rule).
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 1 ) : substr( $text, 0, 1 );
	}

	$classes = array_filter(
		array_merge(
			array( 'slk-wordmark', 'slk-wordmark--' . $size ),
			$args['initial'] ? array( 'slk-wordmark--initial' ) : array(),
			preg_split( '/\s+/', (string) $args['class'], -1, PREG_SPLIT_NO_EMPTY )
		)
	);

	$class_attr = esc_attr( implode( ' ', $classes ) );

	if ( $args['link'] ) {
		return sprintf(
			'<a class="%1$s" href="%2$s" rel="home"><span class="slk-wordmark__text">%3$s</span></a>',
			$class_attr,
			esc_url( home_url( '/' ) ),
			esc_html( $text )
		);
	}

	$tag = in_array( $args['tag'], array( 'span', 'div', 'p', 'h1', 'h2' ), true ) ? $args['tag'] : 'span';

	return sprintf(
		'<%1$s class="%2$s"><span class="slk-wordmark__text">%3$s</span></%1$s>',
		$tag,
		$class_attr,
		esc_html( $text )
	);
}

/**
 * Echo the wordmark.
 *
 * @param array $args See slk_wordmark_markup().
 */
function slk_the_wordmark( $args = array() ) {
	echo slk_wordmark_markup( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in slk_wordmark_markup().
}

/* -------------------------------------------------------------------------
 * Shortcode: [slk_wordmark size="md" link="1" initial="0" class=""]
 * ---------------------------------------------------------------------- */

add_shortcode(
	'slk_wordmark',
	static function ( $atts ) {
		$atts = shortcode_atts(
			array(
				'size'    => 'md',
				'link'    => '0',
				'initial' => '0',
				'class'   => '',
			),
			$atts,
			'slk_wordmark'
		);

		return slk_wordmark_markup(
			array(
				'size'    => $atts['size'],
				'link'    => filter_var( $atts['link'], FILTER_VALIDATE_BOOLEAN ),
				'initial' => filter_var( $atts['initial'], FILTER_VALIDATE_BOOLEAN ),
				'class'   => $atts['class'],
			)
		);
	}
);

/* -------------------------------------------------------------------------
 * Header: the wordmark replaces the logo / site title.
 *
 * Blocksy renders the site identity through core: get_custom_logo() when a
 * logo image is set, get_bloginfo( 'name' ) otherwise. Both paths are covered
 * so the header shows the wordmark whether or not a logo image exists.
 * ---------------------------------------------------------------------- */

add_filter(
	'get_custom_logo',
	static function ( $html ) {
		// Markup, not text: only the front end wants a wordmark element here.
		if ( is_admin() ) {
			return $html;
		}

		return slk_wordmark_markup(
			array(
				'size'  => 'md',
				'link'  => true,
				'class' => 'slk-wordmark--site',
			)
		);
	},
	20
);

/**
 * Site title text falls back to the wordmark, so the header, the document
 * title, feeds and email templates all read the same name. Plain text — never
 * markup here.
 */
add_filter(
	'bloginfo',
	static function ( $output, $show ) {
		if ( slk_wordmark_is_editing_blogname() ) {
			return $output;
		}

		return ( 'name' === $show ) ? slk_wordmark_text() : $output;
	},
	20,
	2
);

/**
 * ...and the option itself. This is the filter that makes the rename one line.
 *
 * Measured, not assumed: with only the `bloginfo` filter above, the document
 * <title> read the wordmark while Blocksy's header still painted the raw stored
 * blogname. Blocksy reaches the site name by a path that does not run through
 * get_bloginfo(), so the display filter never saw it. Filtering the option
 * covers every reader — core, parent theme, WooCommerce mail and plugins alike,
 * including the direct get_option( 'blogname' ) calls behind {site_title},
 * WC_Email::get_from_name(), the email footer, invoices and packing slips.
 *
 * NOT front-end-only, on purpose: order emails are routinely dispatched from
 * wp-admin and from cron, and an is_admin() guard would have sent every one of
 * them under the old stored name. The single carve-out is the set of screens
 * where the option is edited — see slk_wordmark_is_editing_blogname() — so
 * Settings > General keeps showing (and saving) the true stored value.
 */
add_filter(
	'option_blogname',
	static function ( $value ) {
		return slk_wordmark_is_editing_blogname() ? $value : slk_wordmark_text();
	},
	20
);

/* -------------------------------------------------------------------------
 * The setting (§6): Newsreader, weight 300, all caps, 0.26em tracking, with a
 * trailing indent equal to the tracking to correct the optical centre.
 * Tokens only. Attached to the child stylesheet so it always loads after it.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		$sizes = '';

		foreach ( slk_wordmark_sizes() as $key => $token ) {
			$sizes .= sprintf( '.slk-wordmark--%1$s{font-size:%2$s}', $key, $token );
		}

		$css = '
.slk-wordmark{
	display:inline-block;
	min-width:96px;
	font-family:var(--slk-wordmark-font);
	font-weight:300;
	font-optical-sizing:auto;
	line-height:var(--slk-leading-tight);
	text-transform:uppercase;
	letter-spacing:var(--slk-wordmark-tracking);
	text-indent:var(--slk-wordmark-tracking);
	text-align:center;
	white-space:nowrap;
	color:var(--slk-color-ink);
	text-decoration:none;
}
.slk-wordmark--initial{min-width:0}
.slk-wordmark--site{
	display:inline-flex;
	align-items:center;
	justify-content:center;
	min-height:var(--slk-touch);
}
.slk-wordmark:focus-visible{
	outline:2px solid var(--slk-color-ink);
	outline-offset:2px;
}
' . $sizes;

		wp_add_inline_style( 'slk-child', $css );
	},
	30
);
