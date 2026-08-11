<?php
/**
 * The wordmark system.
 *
 * ============================ PLACEHOLDER NAME ============================
 * "SL DRESS" is a PLACEHOLDER. The brand name is not chosen — it is pending
 * gate G1. The mockups in design/sections/*.html carry "AESHAL" as the
 * designer's 6-letter tracking demo; that name belongs to the US sister label
 * and this store must never carry it. It is therefore never written into any
 * template, string or class name in this theme.
 *
 * The name lives in exactly ONE place: the 'slk_wordmark' filter. Renaming the
 * brand after G1 is a one-line change, in a plugin or in a single inc/ file:
 *
 *     add_filter( 'slk_wordmark', fn() => 'NILARA' );
 *
 * Nothing else needs to move: the brief's §6 system (Newsreader 300, all caps,
 * 0.26em tracking, trailing indent) holds for any name of 4-8 letters.
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
	 * PLACEHOLDER default pending gate G1 — see the file header.
	 *
	 * @param string $text The wordmark, set in caps.
	 */
	return (string) apply_filters( 'slk_wordmark', 'SL DRESS' );
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
 * title and feeds all read the same name. Plain text — never markup here.
 */
add_filter(
	'bloginfo',
	static function ( $output, $show ) {
		if ( is_admin() ) {
			return $output;
		}

		return ( 'name' === $show ) ? slk_wordmark_text() : $output;
	},
	20,
	2
);

/**
 * ...and the option itself.
 *
 * Measured, not assumed: with only the `bloginfo` filter above, the document
 * <title> read "SL DRESS" while Blocksy's header still painted the raw stored
 * blogname. Blocksy reaches the site name by a path that does not run through
 * get_bloginfo(), so the display filter never saw it. Filtering the option
 * covers every reader — core, parent theme and plugins alike.
 *
 * Front end only: the admin must keep showing the real stored value, or the
 * Settings screen would display a name nobody can edit.
 */
add_filter(
	'option_blogname',
	static function ( $value ) {
		return is_admin() ? $value : slk_wordmark_text();
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
