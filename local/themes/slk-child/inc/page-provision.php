<?php
/**
 * Page provisioning.
 *
 * Every designed page is a THEME TEMPLATE plus a thin WordPress page that
 * points at it. The template is version-controlled and carries the design; the
 * page exists so the URL, the menu and the editor all behave normally, and so a
 * human can still add a paragraph without touching PHP.
 *
 * Each feature file calls slk_ensure_page() for the page it owns, so no shared
 * provisioning script exists to collide over. Creation is idempotent and runs
 * once per template change, not on every request.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ensure a page exists for a template, exactly once.
 *
 * @param string $slug     Page slug, also the option key suffix.
 * @param string $title    Page title.
 * @param string $template Template filename relative to the theme, or '' for none.
 * @param string $content  Optional initial content.
 * @return int Page ID, or 0 on failure.
 */
function slk_ensure_page( $slug, $title, $template = '', $content = '' ) {
	$option = 'slk_page_' . str_replace( '-', '_', $slug );
	$known  = (int) get_option( $option, 0 );

	if ( $known && 'page' === get_post_type( $known ) && 'trash' !== get_post_status( $known ) ) {
		// Keep the template pointer honest if the template was renamed.
		if ( $template && get_page_template_slug( $known ) !== $template ) {
			update_post_meta( $known, '_wp_page_template', $template );
		}
		return $known;
	}

	$existing = get_page_by_path( $slug );
	$id       = $existing ? (int) $existing->ID : 0;

	if ( ! $id ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
			)
		);
	}

	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}

	if ( $template ) {
		update_post_meta( $id, '_wp_page_template', $template );
	}

	update_option( $option, $id, false );

	return (int) $id;
}

/**
 * The page ID for a provisioned slug, or 0.
 *
 * @param string $slug Page slug.
 * @return int
 */
function slk_page_id( $slug ) {
	return (int) get_option( 'slk_page_' . str_replace( '-', '_', $slug ), 0 );
}

/**
 * Permalink for a provisioned page, falling back to home so a link is never
 * rendered as "#".
 *
 * @param string $slug Page slug.
 * @return string
 */
function slk_page_url( $slug ) {
	$id = slk_page_id( $slug );

	return $id ? (string) get_permalink( $id ) : home_url( '/' );
}

/**
 * An editorial image attachment id, set by local/seed-catalog.sh.
 *
 * Keys: hero_group, hero_alt, portrait_warm, pair_close, single_floral,
 * room_wide, studio_pair.
 *
 * @param string $key Image key.
 * @return int Attachment ID, 0 when the shoot has not supplied one.
 */
function slk_editorial_image_id( $key ) {
	return (int) get_option( 'slk_img_' . str_replace( '-', '_', $key ), 0 );
}

/**
 * Render an editorial image, or nothing at all.
 *
 * Deliberately renders NOTHING when the image is missing rather than a grey
 * placeholder box: the shoot is incomplete by design, and an empty space reads
 * as "not yet" while a broken frame reads as "broken".
 *
 * @param string $key   Image key.
 * @param string $size  Image size.
 * @param array  $attr  Extra attributes.
 * @return string
 */
function slk_editorial_image( $key, $size = 'large', $attr = array() ) {
	$id = slk_editorial_image_id( $key );

	if ( ! $id ) {
		return '';
	}

	$attr = wp_parse_args( $attr, array( 'loading' => 'lazy', 'decoding' => 'async' ) );

	return (string) wp_get_attachment_image( $id, $size, false, $attr );
}
