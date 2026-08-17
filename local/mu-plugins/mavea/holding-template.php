<?php
/**
 * Standalone holding-page template, selected by ../mavea-prelaunch.php while
 * the pre-launch flag is on. In a subdirectory ON PURPOSE: WordPress
 * auto-loads every top-level mu-plugins/*.php on every request, and this
 * file must only ever run when the template loader includes it.
 *
 * It renders the CONTENT of the static front page (live: page 13, one
 * wp:html block whose source of truth is design/holding-page.html) in a bare
 * HTML shell. No get_header()/get_footer(), so there is no Twenty
 * Twenty-Five and no Blocksy chrome to suppress — the TT25-scoped :has()
 * rules inside the block simply never match, and design/holding-page.html
 * needs no edit for the theme switch.
 */

defined( 'ABSPATH' ) || exit;

$mavea_front = get_post( (int) get_option( 'page_on_front' ) );
$mavea_html  = $mavea_front instanceof WP_Post ? do_blocks( $mavea_front->post_content ) : '';

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( get_bloginfo( 'name', 'display' ) ); ?></title>
</head>
<body>
<?php
if ( '' !== trim( $mavea_html ) ) {
	echo $mavea_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored block content: the holding page itself.
} else {
	// Emergency fallback, reached only if the static front page is unset or
	// emptied. Deliberately plain: the real markup's single source of truth
	// stays design/holding-page.html, never a copy here.
	echo '<h1 style="font-family:Georgia,serif;text-align:center;margin-top:40vh;letter-spacing:.24em;">MAV&Eacute;A</h1>';
	echo '<p style="font-family:sans-serif;text-align:center;letter-spacing:.16em;">OPENING SOON</p>';
}
?>
</body>
</html>
