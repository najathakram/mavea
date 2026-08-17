<?php
/**
 * Export the live-bound slice of the local catalogue to JSON, so it can be
 * carried onto Hostinger and replayed by local/deploy/seed-catalog-live.php.
 *
 * The 20-product catalogue exists ONLY in the local Docker database — no
 * script recreates it — so this is the one-time bridge off that database.
 * Runs INSIDE the local container via `wp eval-file`:
 *
 *   MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' \
 *     docker cp local/deploy/export-catalog.php slk-wp:/var/www/html/temp/export-catalog.php
 *   docker exec slk-wp sh -c 'chown -R www-data:www-data /var/www/html/temp'
 *   docker compose run --rm -T wpcli eval-file temp/export-catalog.php < /dev/null
 *   MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' \
 *     docker cp slk-wp:/var/www/html/temp/catalog.json local/deploy/catalog.json
 *
 * Two details in that recipe are load-bearing. The chown, because `docker cp`
 * lands files as root while the wpcli service runs as uid 33 (www-data) — a
 * root-owned temp/ or a root-owned catalog.json left by an earlier run makes
 * the write below fail (seed-editorial.sh chowns for the same reason). And
 * the bare `eval-file`, because the wpcli service already sets
 * `entrypoint: ["wp"]` — spelling `wp` again resolves to `wp wp eval-file`,
 * which is not a registered command.
 *
 * Deliberately narrow. Images are NOT in here — local/import-product-images.php
 * and local/deploy/seed-editorial-live.php own imagery, keyed off the frames in
 * temp/, not this file; a local media attachment ID means nothing on a
 * Hostinger install that has never seen it. This file owns only what a
 * product post and its terms ARE: price, stock, attributes, categories — the
 * whitelist below, nothing else. No _thumbnail_id, no _product_image_gallery
 * (attachment IDs don't survive the move), no _sale_price (brand law: this
 * store never carries one — seed-catalog-live.php deletes it defensively on
 * import too, in case a future export ever picks one up).
 *
 * Term DESCRIPTIONS are exported alongside slug/name, not just the term
 * assignment on each product — the hand-beaded shelf's intro copy lives in
 * the product_cat term's description, nowhere else.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

// Everything a product post needs re-created from nothing on a site that has
// never seen it before.
$meta_whitelist = array(
	'_regular_price',
	'_price',
	'_manage_stock',
	'_stock',
	'_backorders',
	'_stock_status',
	'_sold_individually',
	'_tax_status',
	'_tax_class',
	'_product_attributes',
	'_low_stock_amount',
	'_slk_making_days',
	'_slk_retired',
	'_slk_custom_options',
	'_slk_custom_length',
);

// The five taxonomies the catalogue actually uses.
$taxonomies = array( 'product_cat', 'product_tag', 'pa_colour', 'pa_detail', 'pa_occasion' );

$terms = array();

foreach ( $taxonomies as $taxonomy ) {
	$terms[ $taxonomy ] = array();

	$found = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $found ) ) {
		continue;
	}

	foreach ( $found as $term ) {
		$terms[ $taxonomy ][] = array(
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => $term->description,
		);
	}
}

$products = array();

$posts = get_posts(
	array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);

foreach ( $posts as $post ) {
	$meta = array();

	foreach ( $meta_whitelist as $key ) {
		if ( metadata_exists( 'post', $post->ID, $key ) ) {
			$meta[ $key ] = get_post_meta( $post->ID, $key, true );
		}
	}

	$product_terms = array();

	foreach ( $taxonomies as $taxonomy ) {
		$assigned = get_the_terms( $post->ID, $taxonomy );

		$product_terms[ $taxonomy ] = ( $assigned && ! is_wp_error( $assigned ) )
			? wp_list_pluck( $assigned, 'slug' )
			: array();
	}

	$products[] = array(
		'slug'    => $post->post_name,
		'title'   => $post->post_title,
		'status'  => $post->post_status,
		'excerpt' => $post->post_excerpt,
		'content' => $post->post_content,
		'meta'    => $meta,
		'terms'   => $product_terms,
	);
}

$catalog = array(
	'generated' => gmdate( 'c' ),
	'terms'     => $terms,
	'products'  => $products,
);

$json = wp_json_encode( $catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

if ( false === $json ) {
	WP_CLI::error( 'catalog.json failed to encode.' );
}

$out = '/var/www/html/temp/catalog.json';

// Checked, not fire-and-forget: an unwritable temp/ (root-owned by an earlier
// `docker cp`) fails here while the operator's next step — copying catalog.json
// back out — succeeds against the STALE file, and the stale catalogue seeds
// onto live. A green success line on a failed write is the worst outcome this
// script can produce, so the write speaks up.
if ( false === file_put_contents( $out, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
	WP_CLI::error( sprintf( 'could not write %s (is /var/www/html/temp owned by www-data?)', $out ) );
}

WP_CLI::success( sprintf( '%d products exported to %s', count( $products ), $out ) );
