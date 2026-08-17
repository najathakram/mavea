<?php
/**
 * Import the editorial stills the designed pages ask for, and point the
 * slk_img_* options at them. Live twin of local/seed-editorial.sh: that
 * script is docker-bound (stages temp/ into the container), this one runs
 * directly on the host via `wp eval-file`.
 *
 *   wp eval-file local/deploy/seed-editorial-live.php
 *
 * These are the CAMPAIGN frames (reference/website-campaign, converted into
 * temp/editorial-*.jpg), not product photography. Six frames carry three pages:
 * the home hero, the home "made by eight women" frame, the Our Story hero and
 * its pair, and the workshop frame on Contact.
 *
 * Idempotent, and idempotent about CONTENT: the frame is re-imported and the
 * previous attachment for the key dropped every run, so editing
 * temp/editorial-<key>.jpg and re-running actually changes what the site serves.
 * (Importing over an existing slug would not — WordPress would keep the old file
 * and just hand back the old ID.) The seed bundle survives every run: the import
 * is handed a throwaway copy, never the staged frame itself.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

// Where the editorial frames are. Defaults to the repo's own temp/ staging
// directory, which only resolves while this file sits in local/deploy/; on
// Hostinger pass the bundle directory as the eval-file argument:
//   wp eval-file seed-editorial-live.php /home/uXXXXXXXXX/mavea-seed
$dir = isset( $args[0] ) ? rtrim( (string) $args[0], '/' ) : dirname( __DIR__, 2 ) . '/temp';

// key|alt text
//
// Alt describes the GARMENTS in frame, never the model, and never the place:
// these are campaign frames shot on location, so an alt claiming "the workshop
// in Galle" would be a lie told only to the people who cannot see the picture.
$stills = array(
	'hero-group'    => 'A blush abaya with embroidered sleeves, a lilac open abaya over a matching dress, and a black abaya with silver leaf embroidery.',
	'hero-alt'      => 'A sage abaya with beaded sleeve detail, a lilac coat dress with covered buttons, and a camel abaya with cutwork cuffs.',
	'room-wide'     => 'A taupe open abaya worn over cream, a grey abaya with woven sleeve trim, and a soft blue abaya, in a daylit room.',
	'story-wide'    => 'A taupe open abaya with lace panels worn over cream, beside a pale blue abaya with a draped hood.',
	'studio-pair'   => 'A lilac coat dress with covered buttons, a black abaya with beaded sleeves, and a camel abaya with cutwork cuffs.',
	'portrait-warm' => 'A blush abaya with embroidered sleeves and a piped front seam, cut full length.',
);

$failed = 0;

foreach ( $stills as $name => $alt ) {
	$slug = sprintf( 'editorial-%s', $name );
	$file = sprintf( '%s/%s.jpg', $dir, $slug );
	$opt  = sprintf( 'slk_img_%s', str_replace( '-', '_', $name ) );

	if ( ! file_exists( $file ) ) {
		WP_CLI::warning( sprintf( 'could not import %s (looked for %s)', $slug, $file ) );
		$failed++;
		continue;
	}

	// Sideload a THROWAWAY COPY, never the staged frame. WordPress's sideload
	// path is copy-then-unlink, so handing it $file would eat the seed bundle:
	// the next run would find nothing to import and the site would be left
	// pointing at attachments that no longer exist.
	$tmp = wp_tempnam( basename( $file ) );

	if ( ! $tmp || ! copy( $file, $tmp ) ) {
		WP_CLI::warning( sprintf( '%s: could not stage a copy of %s', $slug, $file ) );
		$failed++;
		continue;
	}

	$att_id = media_handle_sideload(
		array(
			'name'     => basename( $file ),
			'tmp_name' => $tmp,
		),
		0,
		sprintf( 'Editorial — %s', str_replace( '-', ' ', $name ) )
	);

	if ( is_wp_error( $att_id ) ) {
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		WP_CLI::warning( sprintf( '%s: %s', $slug, $att_id->get_error_message() ) );
		$failed++;
		continue;
	}

	// Only now drop the previous import: until the replacement exists the old
	// still is what the site is serving, so nothing is deleted on a run that
	// could not import. The exclude keeps this from matching the attachment
	// just created, which takes the slug outright when there is no old one.
	$old = get_posts(
		array(
			'post_type'      => 'attachment',
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'exclude'        => array( $att_id ),
		)
	);

	if ( $old ) {
		wp_delete_attachment( $old[0], true );
	}

	wp_update_post(
		array(
			'ID'        => $att_id,
			'post_name' => $slug,
		)
	);

	update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
	update_option( $opt, $att_id );

	WP_CLI::log( sprintf( '    %s -> #%d', $slug, $att_id ) );
}

if ( $failed ) {
	WP_CLI::error(
		sprintf(
			'%d of %d editorial stills did not import — the pages that use them will render nothing. Fix the frames in %s and re-run.',
			$failed,
			count( $stills ),
			$dir
		)
	);
}

WP_CLI::success( 'editorial stills in place.' );
