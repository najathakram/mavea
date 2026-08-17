<?php
/**
 * Re-attach every product to the normalised studio photography.
 *
 *   python local/prepare-product-images.py     # writes temp/products/
 *   bash   local/seed-product-images.sh        # stages, then runs this
 *
 * Runs inside the container under `wp eval-file`. One process for all twenty
 * products, because the alternative — a shell loop calling `docker compose run`
 * per image — is 120 container starts to move 120 files.
 *
 * The old attachments are force-deleted rather than left orphaned. That is also
 * what fixes the naming: every folder in dresses/ calls its main shot front.png,
 * so importing them all left WordPress disambiguating by suffix — front.png,
 * front-1.png … front-19.png — and which abaya `front-14.png` belonged to was
 * knowable only from the import order. These land as <slug>-<role>.jpg, so the
 * filename says what it is and a re-run is idempotent instead of additive.
 *
 * @package slk-child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

$dir = '/var/www/html/temp/products';

// Featured first; the rest become the gallery in this order. The label is the
// alt text: it names the garment and the part of it in frame, never the model.
$roles = array(
	'front'       => 'front',
	'back'        => 'back',
	'sleeve'      => 'sleeve and cuff',
	'top'         => 'neckline and bodice',
	'bottom'      => 'hem',
	'waist-bands' => 'waist',
);

$products = get_posts(
	array(
		'post_type'   => 'product',
		'numberposts' => -1,
		'post_status' => 'any',
		'orderby'     => 'title',
		'order'       => 'ASC',
	)
);

$made = 0;
$gone = 0;

foreach ( $products as $product ) {
	$slug = $product->post_name;

	// Everything this product currently points at, thumbnail and gallery both.
	$old = array_merge(
		array( (int) get_post_thumbnail_id( $product->ID ) ),
		array_map( 'intval', array_filter( explode( ',', (string) get_post_meta( $product->ID, '_product_image_gallery', true ) ) ) )
	);

	foreach ( array_unique( array_filter( $old ) ) as $old_id ) {
		if ( wp_delete_attachment( $old_id, true ) ) {
			$gone++;
		}
	}

	$ids = array();

	foreach ( $roles as $role => $label ) {
		$file = sprintf( '%s/%s-%s.jpg', $dir, $slug, $role );

		if ( ! file_exists( $file ) ) {
			continue;
		}

		$upload = wp_upload_bits( basename( $file ), null, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! empty( $upload['error'] ) ) {
			WP_CLI::warning( sprintf( '%s %s: %s', $slug, $role, $upload['error'] ) );
			continue;
		}

		$att_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => sprintf( '%s — %s', $product->post_title, $label ),
				'post_name'      => sprintf( '%s-%s', $slug, $role ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$product->ID
		);

		if ( is_wp_error( $att_id ) || ! $att_id ) {
			WP_CLI::warning( sprintf( '%s %s: insert failed', $slug, $role ) );
			continue;
		}

		wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $upload['file'] ) );
		update_post_meta( $att_id, '_wp_attachment_image_alt', sprintf( '%s, %s.', $product->post_title, $label ) );

		$ids[ $role ] = (int) $att_id;
		$made++;
	}

	if ( ! $ids ) {
		WP_CLI::warning( sprintf( '%s: no files in %s', $slug, $dir ) );
		continue;
	}

	if ( isset( $ids['front'] ) ) {
		set_post_thumbnail( $product->ID, $ids['front'] );
		unset( $ids['front'] );
	} else {
		// No front frame: promote whatever came first rather than leave the
		// card showing WooCommerce's placeholder.
		$first = array_key_first( $ids );
		set_post_thumbnail( $product->ID, $ids[ $first ] );
		unset( $ids[ $first ] );
	}

	update_post_meta( $product->ID, '_product_image_gallery', implode( ',', array_values( $ids ) ) );

	WP_CLI::log( sprintf( '  %-10s featured + %d in gallery', $slug, count( $ids ) ) );
}

WP_CLI::success( sprintf( '%d images attached, %d old ones removed.', $made, $gone ) );
