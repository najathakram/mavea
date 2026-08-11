<?php
/**
 * SLK child theme bootstrap.
 *
 * Scaffold only — brand CSS, PDP badges and Woo template overrides land here
 * after gates G1 (name) and G2 (brand look).
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'slk-child', get_stylesheet_uri(), array(), '0.1.0' );
	}
);
