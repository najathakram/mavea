<?php
/**
 * Replay local/deploy/catalog.json onto the live Hostinger install. Twin of
 * local/deploy/export-catalog.php: that one reads the local Docker database,
 * this one writes the live one. Runs on Hostinger via `wp eval-file`:
 *
 *   wp eval-file local/deploy/seed-catalog-live.php local/deploy/catalog.json
 *
 * (argument optional — defaults to catalog.json next to this file.)
 *
 * Idempotent by product SLUG: re-running updates the same twenty products
 * instead of duplicating them, and creates only what is missing. Images are
 * NOT handled here — local/import-product-images.php and
 * local/deploy/seed-editorial-live.php own imagery, run separately.
 *
 * Touches no `woocommerce_*` option, anywhere. Every write here goes through
 * post meta, terms and WooCommerce's own attribute API — never
 * `update_option( 'woocommerce_...' )` — so none of the protected store
 * settings (currency, shipping zones, tax) are at risk from a re-run.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

// Mirrors the whitelist in export-catalog.php. Re-checked here too: a key in
// catalog.json that is NOT in this list is skipped rather than written, so a
// hand-edited or stale JSON file can never smuggle _thumbnail_id,
// _product_image_gallery or anything else onto a live product.
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

$json_path = isset( $args[0] ) ? (string) $args[0] : __DIR__ . '/catalog.json';

if ( ! file_exists( $json_path ) ) {
	WP_CLI::error( sprintf( 'catalog file not found: %s', $json_path ) );
}

$catalog = json_decode( file_get_contents( $json_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions

if ( ! is_array( $catalog ) || empty( $catalog['products'] ) ) {
	WP_CLI::error( sprintf( '%s is empty or malformed.', $json_path ) );
}

/**
 * Make sure a pa_* attribute taxonomy exists and is registered in THIS
 * process. WooCommerce reads its custom attributes from
 * wp_woocommerce_attribute_taxonomies and registers each one as a real
 * taxonomy on `init` — which has already fired by the time an eval-file's own
 * code runs. An attribute that already existed before this run is therefore
 * already registered (WooCommerce's own init hook did it); an attribute
 * created just now by wc_create_attribute() below is NOT, and every
 * wp_insert_term()/wp_set_object_terms() against it would silently do
 * nothing without the explicit register_taxonomy() call.
 */
function mavea_ensure_attribute_taxonomy( string $slug ): string {
	$taxonomy = wc_attribute_taxonomy_name( $slug );
	$known    = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_name' );

	if ( ! in_array( $slug, $known, true ) ) {
		$attribute_id = wc_create_attribute(
			array(
				'name'         => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);

		if ( is_wp_error( $attribute_id ) ) {
			WP_CLI::error( sprintf( 'could not create attribute pa_%s: %s', $slug, $attribute_id->get_error_message() ) );
		}

		WP_CLI::log( sprintf( '  created attribute pa_%s', $slug ) );
	}

	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy(
			$taxonomy,
			'product',
			array(
				'hierarchical' => false,
				'show_ui'      => false,
				'query_var'    => true,
				'rewrite'      => false,
			)
		);
	}

	return $taxonomy;
}

// 1. Global attribute taxonomies — must land before step 2 touches any pa_*
// term, and before step 3 assigns one to a product.
foreach ( array_keys( $catalog['terms'] ) as $taxonomy ) {
	if ( str_starts_with( $taxonomy, 'pa_' ) && ! empty( $catalog['terms'][ $taxonomy ] ) ) {
		mavea_ensure_attribute_taxonomy( substr( $taxonomy, 3 ) );
	}
}

// 2. Terms, with descriptions — the hand-beaded shelf's intro copy lives
// here, on the product_cat term, not on any product.
foreach ( $catalog['terms'] as $taxonomy => $terms ) {
	foreach ( $terms as $term ) {
		$slug = (string) $term['slug'];
		$name = (string) $term['name'];
		$desc = (string) $term['description'];

		$existing = get_term_by( 'slug', $slug, $taxonomy );

		if ( ! $existing ) {
			$inserted = wp_insert_term(
				$name,
				$taxonomy,
				array(
					'slug'        => $slug,
					'description' => $desc,
				)
			);

			if ( is_wp_error( $inserted ) ) {
				WP_CLI::warning( sprintf( '%s/%s: %s', $taxonomy, $slug, $inserted->get_error_message() ) );
			}

			continue;
		}

		if ( $existing->name !== $name || $existing->description !== $desc ) {
			wp_update_term(
				$existing->term_id,
				$taxonomy,
				array(
					'name'        => $name,
					'description' => $desc,
				)
			);
		}
	}
}

// 3. Products, idempotent by slug.
$created = 0;
$updated = 0;

foreach ( $catalog['products'] as $product ) {
	$slug = (string) $product['slug'];

	$existing = get_page_by_path( $slug, OBJECT, 'product' );

	$postarr = array(
		'post_type'    => 'product',
		'post_status'  => isset( $product['status'] ) ? (string) $product['status'] : 'publish',
		'post_title'   => (string) $product['title'],
		'post_name'    => $slug,
		'post_excerpt' => (string) ( $product['excerpt'] ?? '' ),
		'post_content' => (string) ( $product['content'] ?? '' ),
	);

	if ( $existing instanceof WP_Post ) {
		$postarr['ID'] = $existing->ID;
		$product_id    = wp_update_post( $postarr, true );
		$updated++;
	} else {
		$product_id = wp_insert_post( $postarr, true );
		$created++;
	}

	if ( is_wp_error( $product_id ) ) {
		WP_CLI::warning( sprintf( '%s: %s', $slug, $product_id->get_error_message() ) );
		continue;
	}

	foreach ( (array) ( $product['meta'] ?? array() ) as $key => $value ) {
		if ( ! in_array( $key, $meta_whitelist, true ) ) {
			WP_CLI::warning( sprintf( '%s: skipped non-whitelisted meta key %s', $slug, $key ) );
			continue;
		}

		update_post_meta( $product_id, $key, $value );
	}

	// Brand law: this store never carries a sale price. Deleted defensively
	// even though export-catalog.php never writes one, in case a future
	// export — or a hand-edited catalog.json — ever picks one up.
	delete_post_meta( $product_id, '_sale_price' );

	// A true replay of the export, removals included. A taxonomy the export
	// lists with an EMPTY slug list means the product was taken off that shelf
	// locally, so the live assignment is cleared rather than skipped —
	// otherwise a re-seed could only ever add terms, and a product pulled from
	// e.g. hand-beaded would keep showing there forever. A taxonomy absent from
	// the JSON is still left alone: the export says nothing about it, so
	// neither does this.
	foreach ( (array) ( $product['terms'] ?? array() ) as $taxonomy => $slugs ) {
		wp_set_object_terms( $product_id, array_map( 'strval', (array) $slugs ), $taxonomy );
	}

	wp_set_object_terms( $product_id, 'simple', 'product_type' );

	WP_CLI::log( sprintf( '  %-10s %s (#%d)', $slug, $existing instanceof WP_Post ? 'updated' : 'created', $product_id ) );
}

WP_CLI::success( sprintf( '%d created, %d updated.', $created, $updated ) );
WP_CLI::log( 'Next: wp wc tool run regenerate_product_lookup_tables --user=1' );
