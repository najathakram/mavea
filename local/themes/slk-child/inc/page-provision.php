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
 * An editorial image attachment id, set by local/seed-editorial.sh.
 *
 * Keys in use: hero_group (home hero, landscape), hero_alt (home hero on the
 * phone, portrait), room_wide (the making frame on Home and Contact),
 * story_wide (Our Story hero), studio_pair and portrait_warm (the Our Story
 * pair). pair_close and single_floral are seeded but nothing renders them.
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

/**
 * Render two editorial frames as one <picture>: the portrait one on a phone,
 * the landscape one from $bp up.
 *
 * The hero box is 4:5 on a phone and 16:9 on a desktop, so a single landscape
 * file has to be centre-cropped to 45% of its own width to fill the phone —
 * which throws away most of what was composed. A <picture> lets the phone have
 * a frame that was actually shot portrait, and because the browser picks ONE
 * source she downloads one file, not both. That last part is the whole reason
 * this is not two <img>s toggled with CSS.
 *
 * The <img> carries the PORTRAIT frame, so a browser too old for <picture>
 * gets the phone crop rather than the desktop one. Its width/height describe
 * that file and not the landscape branch, which is safe here only because the
 * callers size the box in CSS (aspect-ratio on the wrapper, height:100% on the
 * image) rather than letting the intrinsic ratio lay the page out.
 *
 * $alt IS REQUIRED, and it is required because <picture> has exactly one alt
 * for every branch. Letting each attachment supply its own — which is what the
 * rest of this file does — silently describes the phone photograph to everyone
 * on a desktop, who is looking at the other one. So the caller has to write a
 * sentence that is true of BOTH frames, and if it cannot, these two frames were
 * never an art-direction pair to begin with.
 *
 * @param string $narrow_key Image key below $bp.
 * @param string $wide_key   Image key from $bp up.
 * @param string $alt        Alt text true of both frames.
 * @param string $size       Registered image size, for both.
 * @param array  $attr       Extra attributes for the <img>.
 * @param string $bp         Breakpoint width, matching the template's CSS.
 * @return string
 */
function slk_editorial_picture( $narrow_key, $wide_key, $alt, $size = 'large', $attr = array(), $bp = '1000px' ) {
	$narrow = slk_editorial_image_id( $narrow_key );
	$wide   = slk_editorial_image_id( $wide_key );
	$attr   = array_merge( (array) $attr, array( 'alt' => $alt ) );

	// With only one of the pair there is nothing to art-direct. Render it
	// plainly rather than emit a <picture> that can only ever choose one way.
	if ( ! $narrow || ! $wide ) {
		return slk_editorial_image( $narrow ? $narrow_key : $wide_key, $size, $attr );
	}

	$img = slk_editorial_image( $narrow_key, $size, $attr );
	$src = wp_get_attachment_image_url( $wide, $size );

	if ( '' === $img || ! $src ) {
		return $img;
	}

	$srcset = wp_get_attachment_image_srcset( $wide, $size );
	$sizes  = wp_get_attachment_image_sizes( $wide, $size );

	return sprintf(
		'<picture><source media="(min-width:%1$s)" srcset="%2$s"%3$s>%4$s</picture>',
		esc_attr( $bp ),
		esc_attr( $srcset ? $srcset : $src ),
		$sizes ? sprintf( ' sizes="%s"', esc_attr( $sizes ) ) : '',
		$img
	);
}
