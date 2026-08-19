<?php
/**
 * The interaction moments — added to bag, filter sheet, image zoom.
 *
 * design/sections/02-moments.html shows three states the store had no markup
 * for at all: the confirmation drawer after an add-to-cart, the filter sheet
 * over the shop grid, and the full-screen fabric zoom. This file is those three
 * states and nothing else.
 *
 * PROGRESSIVE ENHANCEMENT IS THE RULE HERE, NOT A NICETY
 * -----------------------------------------------------
 * She is on patchy mobile data. Every one of these three has to leave a working
 * store behind when the JavaScript never arrives:
 *
 *   Added to bag  — the sheet is rendered by PHP on the page load that follows
 *                   a plain form POST (WooCommerce's own add-to-cart), driven by
 *                   a WC session flag. JS only shortens that to "no page load"
 *                   by fetching the same PHP through a wc-ajax endpoint when
 *                   the `added_to_cart` event fires. The add-to-cart mechanism
 *                   itself is untouched.
 *   Filter sheet  — a real <form method="get">. Submitting it without any JS
 *                   produces `?product_cat=…&min_price=…&max_price=…`, which are
 *                   WooCommerce's and WordPress's own query args (verified live:
 *                   WC_Query::price_filter_post_clauses reads min_price/max_price
 *                   and WP_Query reads product_cat), so the result set is genuine
 *                   and the URL is shareable. JS adds only sheet behaviour:
 *                   open/close, focus trap, scroll lock, Escape.
 *   Image zoom    — with JS off there is no overlay and no dead control, because
 *                   the trigger is injected by the same script that can open it.
 *
 * WHY THE ZOOM IS *NOT* A NEW LIGHTBOX
 * ------------------------------------
 * functions.php enables wc-product-gallery-zoom/lightbox/slider, so WooCommerce
 * enqueues its bundled PhotoSwipe 4.1.1 + PhotoSwipeUI_Default and prints its
 * `.pswp` dialog markup in the footer — all of that is live on the PDP today
 * (verified in the running container). What does *not* work is WooCommerce's own
 * click binding: Blocksy replaces the gallery with its "flexy" markup, so
 * `.woocommerce-product-gallery__image`, the selector WC's single-product.js
 * binds to, never matches and the lightbox can never open.
 *
 * So this file writes no lightbox. It reuses WooCommerce's PhotoSwipe instance,
 * WooCommerce's `.pswp` markup and WooCommerce's own options filter
 * (`woocommerce_single_product_photoswipe_options`, read here through
 * wc_single_product_params so the store stays the authority), and supplies only
 * the two things the parent theme broke: a trigger bound to Blocksy's figures,
 * and the Porcelain Glass skin from 02-moments.html.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * 0. Shared helpers
 * ====================================================================== */

/**
 * Is this a product listing the filter sheet belongs on?
 *
 * @return bool
 */
function slk_moments_is_listing() {
	if ( ! function_exists( 'is_shop' ) ) {
		return false;
	}

	return ( is_shop() || is_product_taxonomy() ) && ! is_admin();
}

/**
 * The URL the filter form posts to: the archive itself, with no filter args.
 *
 * @return string
 */
function slk_moments_listing_url() {
	if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			$link = get_term_link( $term );

			if ( ! is_wp_error( $link ) ) {
				return (string) $link;
			}
		}
	}

	return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
}

/**
 * The current URL, filters and all, for building "remove this filter" links.
 *
 * @return string
 */
function slk_moments_current_url() {
	$url = slk_moments_listing_url();
	$q   = array();

	foreach ( array( 'product_cat', 'min_price', 'max_price', 'orderby' ) as $key ) {
		if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$q[ $key ] = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security
		}
	}

	return $q ? add_query_arg( $q, $url ) : $url;
}

/* =========================================================================
 * 1. FILTER SHEET
 *
 * Two facets, matching the brief: category and price. Both are expressed in
 * query args WooCommerce/WordPress already understand, so no custom WP_Query
 * runs anywhere in this file — the archive's own main query does the work and
 * the URL is a shareable, bookmarkable description of the result set.
 * ====================================================================== */

/**
 * The real min/max published product price, cached.
 *
 * Reads wc_product_meta_lookup directly — the same table
 * slk_moments_price_clause() joins against for the archive's own price
 * filter — rather than loading every product through wc_get_products(), so
 * this stays a single indexed aggregate query. It runs at most once per
 * cache window: slk_moments_flush_price_bounds() below clears the transient
 * the moment a product is written, so a price edit is never left stale for
 * up to 12 hours.
 *
 * @return array{min:float,max:float}|null Null when there are fewer than two
 *                                          distinct prices to bucket (an
 *                                          empty catalogue, one product, or
 *                                          every product at the same price).
 */
function slk_moments_price_bounds() {
	$cached = get_transient( 'slk_price_bounds' );

	if ( false !== $cached ) {
		return $cached ? $cached : null;
	}

	global $wpdb;

	$bounds = null;
	$row    = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- no user input; result cached below.
		"SELECT MIN(wc_product_meta_lookup.min_price) AS lo, MAX(wc_product_meta_lookup.max_price) AS hi
		FROM {$wpdb->wc_product_meta_lookup} wc_product_meta_lookup
		INNER JOIN {$wpdb->posts} p ON p.ID = wc_product_meta_lookup.product_id
		WHERE p.post_status = 'publish' AND p.post_type = 'product'"
	);

	if ( $row && null !== $row->lo && null !== $row->hi && (float) $row->lo < (float) $row->hi ) {
		$bounds = array(
			'min' => (float) $row->lo,
			'max' => (float) $row->hi,
		);
	}

	// Cache the degenerate result too (as a plain 0, not null — a transient
	// cannot be told apart from "not set" by returning null itself), or a
	// catalogue with one price point would re-run this query every request.
	set_transient( 'slk_price_bounds', $bounds ? $bounds : 0, 12 * HOUR_IN_SECONDS );

	return $bounds;
}

/**
 * Flush the cached price bounds whenever a product is written.
 */
function slk_moments_flush_price_bounds() {
	delete_transient( 'slk_price_bounds' );
	delete_transient( 'slk_price_mid_band' );
}
add_action( 'woocommerce_update_product', 'slk_moments_flush_price_bounds' );
add_action( 'woocommerce_new_product', 'slk_moments_flush_price_bounds' );
add_action( 'save_post_product', 'slk_moments_flush_price_bounds' );

/**
 * The out-of-stock half of the archive's own visibility constraint.
 *
 * `'visibility' => 'catalog'` is a real WC_Product_Query argument and covers
 * the exclude-from-catalog term, but WC_Query::get_tax_query() also drops the
 * outofstock term when the store is set to hide sold-out items — and there is
 * no product-query argument for that one. Returned as tax_query clauses so
 * the filter endpoint and the price-band probe can both bolt it on and
 * measure against the same set the archive would render.
 *
 * @return array<int,array<string,mixed>>
 */
function slk_moments_stock_tax_query() {
	if ( 'yes' !== get_option( 'woocommerce_hide_out_of_stock_items' ) || ! function_exists( 'wc_get_product_visibility_term_ids' ) ) {
		return array();
	}

	$term_ids = wc_get_product_visibility_term_ids();

	if ( empty( $term_ids['outofstock'] ) ) {
		return array();
	}

	return array(
		array(
			'taxonomy' => 'product_visibility',
			'field'    => 'term_taxonomy_id',
			'terms'    => array( (int) $term_ids['outofstock'] ),
			'operator' => 'NOT IN',
		),
	);
}

/**
 * Does the middle band actually hold anything?
 *
 * The two outer bands are provable from the bounds alone — a product exists
 * at $min and one at $max — but nothing about [min, max] says the interval
 * between the boundaries is occupied. Two products at Rs. 1,000 and
 * Rs. 20,000 clear every arithmetic guard in slk_moments_price_buckets() and
 * still leave "Rs. 7,000 to Rs. 14,000" matching nothing, which is exactly
 * the dead band the whole exercise exists to remove. So this counts it for
 * real, through the same overlap clause the archive's own min_price/max_price
 * filter applies, and caches the answer against the boundary pair it was
 * measured for (12 hours, cleared by the same product-write flush above), so
 * it is one indexed query per cache window rather than one per request.
 *
 * @param int $under Lower boundary.
 * @param int $over  Upper boundary.
 * @return bool True when at least one product falls in [$under, $over].
 */
function slk_moments_price_band_populated( $under, $over ) {
	$cached = get_transient( 'slk_price_mid_band' );

	if ( is_array( $cached ) && isset( $cached['under'], $cached['over'], $cached['has'] )
		&& (int) $cached['under'] === (int) $under && (int) $cached['over'] === (int) $over ) {
		return (bool) $cached['has'];
	}

	if ( ! function_exists( 'wc_get_products' ) ) {
		return true; // Nothing to measure with; leave the facet as the arithmetic left it.
	}

	$clause = slk_moments_price_clause( $under, $over );
	add_filter( 'posts_clauses', $clause );

	$probe = array(
		'status'     => 'publish',
		'limit'      => 1,
		'return'     => 'ids',
		'type'       => array_keys( wc_get_product_types() ),
		// The archive's own constraint. Without it this can certify a band
		// whose one occupant is catalog-hidden (or sold out, with sold-out
		// items hidden) and ship exactly the dead band this function exists
		// to eliminate.
		'visibility' => 'catalog',
	);

	$stock_clauses = slk_moments_stock_tax_query();

	if ( $stock_clauses ) {
		$probe['tax_query'] = $stock_clauses; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the archive runs the same clause.
	}

	$found = wc_get_products( $probe );

	remove_filter( 'posts_clauses', $clause );

	$has = ! empty( $found );

	set_transient(
		'slk_price_mid_band',
		array(
			'under' => (int) $under,
			'over'  => (int) $over,
			'has'   => $has,
		),
		12 * HOUR_IN_SECONDS
	);

	return $has;
}

/**
 * The price buckets from 02-moments.html:19-23, sized to the live catalogue.
 *
 * The two interior boundaries are not the design's fixed 5,000/10,000 — that
 * pair came from a mockup catalogue and left "Under Rs. 5,000" permanently
 * empty once real stock (Rs. 9,900-17,900) went live. Instead the real
 * min/max price is split into three even thirds and each interior boundary
 * is rounded to the nearest Rs. 1,000, so a boundary is always a round human
 * number (never "Rs. 12,566") while still sitting inside the real range.
 * Rendering of the labels is still wc_price(), so the "Rs." prefix, the comma
 * thousands and the zero decimals all come from the store's own currency
 * settings and never from a typed symbol.
 *
 * Degenerate ranges — too narrow to round into two distinct boundaries that
 * both still fall strictly inside [min, max], a catalogue clustered at the
 * two extremes so that the middle band holds nothing, or no usable range at
 * all — return no buckets whatsoever, and slk_moments_render_filters() omits
 * the whole Price group in that case: a facet that cannot match anything is
 * worse than no facet.
 *
 * @return array<string,array{min:int|null,max:int|null,label:string}>
 */
function slk_moments_price_buckets() {
	$bounds = slk_moments_price_bounds();

	if ( ! $bounds ) {
		return array();
	}

	$min  = $bounds['min'];
	$max  = $bounds['max'];
	$step = 1000;

	$under = (int) ( round( ( $min + ( $max - $min ) / 3 ) / $step ) * $step );
	$over  = (int) ( round( ( $min + ( $max - $min ) * 2 / 3 ) / $step ) * $step );

	// Rounding a narrow range can push the two boundaries together or past
	// each other (e.g. a few-hundred-rupee spread rounds both to the same
	// thousand). Widen them apart by one step rather than render an inverted
	// or empty middle band.
	if ( $under >= $over ) {
		$over = $under + $step;
	}

	// Each boundary must still leave the outer bands non-empty: "under" needs
	// a product at or below it, "over" needs one at or above it. Rounding can
	// walk a boundary outside [min, max] on a narrow or lopsided range, at
	// which point that outer band can never match — omit the facet entirely
	// rather than ship a band with zero results.
	if ( $under < $min || $over > $max || $under >= $over ) {
		return array();
	}

	// The middle band is the one the arithmetic cannot vouch for: a catalogue
	// clustered at both extremes leaves it empty with every guard above
	// satisfied. Same treatment as the outer bands — no facet at all beats a
	// band that cannot match.
	if ( ! slk_moments_price_band_populated( $under, $over ) ) {
		return array();
	}

	return array(
		'under' => array(
			'min'   => null,
			'max'   => $under,
			/* translators: %s: a formatted price, e.g. Rs. 13,000. */
			'label' => sprintf( __( 'Under %s', 'slk' ), wp_strip_all_tags( wc_price( $under ) ) ),
		),
		'mid'   => array(
			'min'   => $under,
			'max'   => $over,
			/* translators: 1: lower price, 2: upper price. */
			'label' => sprintf( __( '%1$s to %2$s', 'slk' ), wp_strip_all_tags( wc_price( $under ) ), wp_strip_all_tags( wc_price( $over ) ) ),
		),
		'over'  => array(
			'min'   => $over,
			'max'   => null,
			/* translators: %s: a formatted price, e.g. Rs. 15,000. */
			'label' => sprintf( __( 'Over %s', 'slk' ), wp_strip_all_tags( wc_price( $over ) ) ),
		),
	);
}

/**
 * Which price bucket the current URL describes, or '' for none.
 *
 * @return string
 */
function slk_moments_active_price_bucket() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$min = isset( $_GET['min_price'] ) ? (int) $_GET['min_price'] : null;
	$max = isset( $_GET['max_price'] ) ? (int) $_GET['max_price'] : null;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( null === $min && null === $max ) {
		return '';
	}

	foreach ( slk_moments_price_buckets() as $key => $bucket ) {
		if ( (int) $bucket['min'] === (int) $min && (int) $bucket['max'] === (int) $max ) {
			return $key;
		}
	}

	return '';
}

/**
 * Category slugs currently selected.
 *
 * @return string[]
 */
function slk_moments_active_cats() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw = isset( $_GET['product_cat'] ) ? wp_unslash( $_GET['product_cat'] ) : '';

	if ( is_array( $raw ) ) {
		$slugs = $raw;
	} else {
		$slugs = '' === $raw ? array() : explode( ',', (string) $raw );
	}

	return array_values( array_filter( array_map( 'sanitize_title', $slugs ) ) );
}

/**
 * Categories offered in the sheet: real product_cat terms that hold products.
 *
 * @return WP_Term[]
 */
function slk_moments_cat_terms() {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return array();
	}

	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'exclude'    => array( (int) get_option( 'default_product_cat', 0 ) ),
		)
	);

	return ( is_array( $terms ) && ! is_wp_error( $terms ) ) ? $terms : array();
}

/**
 * A checkbox form submits `product_cat[]`; WP_Query wants one comma string.
 *
 * Joining here — on `request`, before WP_Query is built — means the resulting
 * query is WordPress's own native taxonomy query rather than a bolt-on
 * pre_get_posts rewrite, and `?product_cat=abayas,dresses` keeps working when
 * someone shares the joined form of the URL.
 */
add_filter(
	'request',
	static function ( $vars ) {
		if ( ! isset( $vars['product_cat'] ) || ! is_array( $vars['product_cat'] ) ) {
			return $vars;
		}

		$slugs = array_values( array_unique( array_filter( array_map( 'sanitize_title', $vars['product_cat'] ) ) ) );

		if ( $slugs ) {
			$vars['product_cat'] = implode( ',', $slugs );
		} else {
			unset( $vars['product_cat'] ); // An empty taxonomy var 404s the archive.
		}

		return $vars;
	}
);

/**
 * The active-filter chips: label plus the URL that removes just that filter.
 *
 * @return array<int,array{label:string,url:string}>
 */
function slk_moments_active_chips() {
	$chips  = array();
	$cats   = slk_moments_active_cats();
	$bucket = slk_moments_active_price_bucket();

	foreach ( $cats as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );

		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}

		$rest = array_values( array_diff( $cats, array( $slug ) ) );
		$url  = remove_query_arg( 'product_cat', slk_moments_current_url() );

		if ( $rest ) {
			$url = add_query_arg( 'product_cat', implode( ',', $rest ), $url );
		}

		$chips[] = array(
			'label' => $term->name,
			'url'   => $url,
		);
	}

	if ( $bucket ) {
		$buckets = slk_moments_price_buckets();

		$chips[] = array(
			'label' => $buckets[ $bucket ]['label'],
			'url'   => remove_query_arg( array( 'min_price', 'max_price' ), slk_moments_current_url() ),
		);
	}

	return $chips;
}

/**
 * The listing's own base URL (no filter args) for an explicit taxonomy/term
 * pair, rather than the current front-end query.
 *
 * slk_moments_listing_url() reads is_product_taxonomy()/get_queried_object(),
 * which describe the page WordPress is currently rendering — inside an
 * admin-ajax.php request that is never this listing (is_admin() is true
 * there, so slk_moments_is_listing() itself is false), so the AJAX filter
 * response resolves its base URL through this instead, given the taxonomy
 * and slug the client already carries in data-slk-ctx-tax/-term.
 *
 * @param string $taxonomy  Term-archive taxonomy, '' for the shop root.
 * @param string $term_slug Term-archive slug, '' for the shop root.
 * @return string
 */
function slk_moments_base_url_for( $taxonomy, $term_slug ) {
	if ( $taxonomy && $term_slug && taxonomy_exists( $taxonomy ) ) {
		$term = get_term_by( 'slug', $term_slug, $taxonomy );

		if ( $term && ! is_wp_error( $term ) ) {
			$link = get_term_link( $term );

			if ( ! is_wp_error( $link ) ) {
				return (string) $link;
			}
		}
	}

	return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
}

/**
 * The shareable URL for an explicit filter selection — the same query args a
 * real form submit would produce — built against a base URL rather than
 * $_GET. slk_moments_current_url() (used by the page-load chips above) stays
 * exactly as it was; this is the AJAX-side equivalent, for a candidate
 * selection that has not been submitted and so has no "current URL" of its
 * own yet.
 *
 * @param string   $base_url Listing URL with no filter args.
 * @param string[] $cats     Category slugs.
 * @param int|null $min      Price floor, or null for none.
 * @param int|null $max      Price ceiling, or null for none.
 * @param string   $orderby  Sort key to carry through, '' for none.
 * @return string
 */
function slk_moments_filtered_url( $base_url, array $cats, $min, $max, $orderby = '' ) {
	$q = array();

	if ( $cats ) {
		$q['product_cat'] = implode( ',', $cats );
	}
	if ( null !== $min ) {
		$q['min_price'] = (int) $min;
	}
	if ( null !== $max ) {
		$q['max_price'] = (int) $max;
	}
	if ( $orderby ) {
		$q['orderby'] = $orderby;
	}

	return $q ? add_query_arg( $q, $base_url ) : $base_url;
}

/**
 * The active-filter chips for an explicit selection (category slugs + price
 * bucket key), rather than $_GET. The AJAX filter response has a candidate
 * selection that has not been submitted, so it cannot read
 * slk_moments_active_chips()'s $_GET-sourced state — but it resolves to the
 * exact same shape {label,url}, from the exact same bucket labels
 * (slk_moments_price_buckets() is the one source for those either way), so
 * slk_moments_render_chips() below renders either without caring which one
 * produced them.
 *
 * @param string[] $cats       Active category slugs.
 * @param string   $bucket_key Active price bucket key, '' for none.
 * @param string   $base_url   Listing URL with no filter args.
 * @param string   $orderby    Sort key to carry through, '' for none.
 * @return array<int,array{label:string,url:string}>
 */
function slk_moments_build_filter_chips( array $cats, $bucket_key, $base_url, $orderby = '' ) {
	$chips   = array();
	$buckets = slk_moments_price_buckets();

	foreach ( $cats as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );

		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}

		$rest = array_values( array_diff( $cats, array( $slug ) ) );
		$min  = ( $bucket_key && isset( $buckets[ $bucket_key ] ) ) ? $buckets[ $bucket_key ]['min'] : null;
		$max  = ( $bucket_key && isset( $buckets[ $bucket_key ] ) ) ? $buckets[ $bucket_key ]['max'] : null;

		$chips[] = array(
			'label' => $term->name,
			'url'   => slk_moments_filtered_url( $base_url, $rest, $min, $max, $orderby ),
		);
	}

	if ( $bucket_key && isset( $buckets[ $bucket_key ] ) ) {
		$chips[] = array(
			'label' => $buckets[ $bucket_key ]['label'],
			'url'   => slk_moments_filtered_url( $base_url, $cats, null, null, $orderby ),
		);
	}

	return $chips;
}

/**
 * The chips markup: one removable pill per active filter, plus "Clear all".
 * Shared by the page-load filterbar and the AJAX filter response, so a
 * chip's markup can only ever come from one place and the two can never draw
 * it differently.
 *
 * @param array<int,array{label:string,url:string}> $chips     From slk_moments_active_chips() or slk_moments_build_filter_chips().
 * @param string                                     $clear_url The no-filters URL.
 * @return string
 */
function slk_moments_render_chips( array $chips, $clear_url ) {
	if ( ! $chips ) {
		return '';
	}

	ob_start();
	?>
	<div class="slk-chips">
		<?php foreach ( $chips as $chip ) : ?>
			<a class="slk-chip" href="<?php echo esc_url( $chip['url'] ); ?>">
				<?php echo esc_html( $chip['label'] ); ?>
				<span aria-hidden="true">✕</span>
				<span class="screen-reader-text"><?php esc_html_e( 'Remove this filter', 'slk' ); ?></span>
			</a>
		<?php endforeach; ?>
		<a class="slk-btn slk-btn--ghost" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear all', 'slk' ); ?></a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * How many products the current, filtered query found.
 *
 * @return int
 */
function slk_moments_result_total() {
	$total = (int) ( isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query']->found_posts : 0 );

	return max( 0, $total );
}

/**
 * One facet control: a real input with a visible <label>.
 *
 * The input is the field; the label is the pill (mobile) or the checkbox row
 * (desktop). Both states are the design — 02-moments.html:19-23 draws pills,
 * 07-desktop.html:148-158 draws checkbox rows — and the same DOM serves both,
 * which is why the field is here rather than a button with aria-pressed: a
 * button cannot be submitted by a form, and this form must work with no JS.
 *
 * @param array $args type/name/value/label/checked/id.
 * @return string
 */
function slk_moments_facet( $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'type'    => 'checkbox',
			'name'    => '',
			'value'   => '',
			'label'   => '',
			'checked' => false,
			'id'      => '',
		)
	);

	return sprintf(
		'<input class="slk-facet__input" type="%1$s" name="%2$s" value="%3$s" id="%4$s"%5$s><label class="slk-size slk-facet" for="%4$s">%6$s</label>',
		esc_attr( $args['type'] ),
		esc_attr( $args['name'] ),
		esc_attr( $args['value'] ),
		esc_attr( $args['id'] ),
		$args['checked'] ? ' checked' : '',
		esc_html( $args['label'] )
	);
}

/**
 * Print the sheet/sidebar form plus the mobile trigger bar and active chips.
 *
 * One form serves both breakpoints: below 1000px `.slk-filterbox` is a fixed
 * bottom sheet over a scrim; at 1000px it becomes the 240px sidebar that
 * style.css §3.7's `.slk-shop-layout` grid places, made sticky by the
 * 1000px block at the foot of this file.
 */
function slk_moments_render_filters() {
	$cats     = slk_moments_active_cats();
	$bucket   = slk_moments_active_price_bucket();
	$buckets  = slk_moments_price_buckets();
	$terms    = is_shop() ? slk_moments_cat_terms() : array();
	$total    = slk_moments_result_total();
	$context  = slk_moments_listing_context();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$orderby  = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
	?>
	<div class="slk-filterbox" id="slk-filters" data-slk-sheet hidden>
		<div class="slk-scrim" data-slk-sheet-close></div>

		<form class="slk-filters" method="get" action="<?php echo esc_url( slk_moments_listing_url() ); ?>"
			role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Filters', 'slk' ); ?>"
			<?php if ( $context ) : ?>data-slk-ctx-tax="<?php echo esc_attr( $context['taxonomy'] ); ?>" data-slk-ctx-term="<?php echo esc_attr( $context['term'] ); ?>"<?php endif; ?>>

			<?php if ( $orderby ) : ?>
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
			<?php endif; ?>

			<span class="slk-sheet__grabber" aria-hidden="true"></span>

			<div class="slk-filters__head">
				<h2 class="slk-filters__title"><?php esc_html_e( 'Filters', 'slk' ); ?></h2>
				<a class="slk-btn slk-btn--ghost" href="<?php echo esc_url( slk_moments_listing_url() ); ?>"><?php esc_html_e( 'Clear all', 'slk' ); ?></a>
			</div>

			<div class="slk-filters__body">
				<?php if ( $buckets ) : ?>
					<div class="slk-filters__group">
						<span class="slk-eyebrow"><?php esc_html_e( 'Price', 'slk' ); ?></span>
						<div class="slk-facets">
							<?php
							foreach ( $buckets as $key => $b ) {
								// Radios, not checkboxes: the buckets do not overlap,
								// and min_price/max_price are single-valued args.
								echo slk_moments_facet( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
									array(
										'type'    => 'radio',
										'name'    => 'slk_price',
										'value'   => $key,
										'label'   => $b['label'],
										'checked' => $key === $bucket,
										'id'      => 'slk-price-' . $key,
									)
								);
							}
							?>
						</div>
						<?php
						/*
						 * The form posts the bucket key; these two carry the real
						 * WooCommerce args. JS rewrites their values on change; with
						 * no JS the submit handler below has nothing to rewrite, so
						 * they ship pre-filled with whatever is already applied and
						 * a bucket change is resolved server-side (see the
						 * slk_price -> min/max redirect on `template_redirect`).
						 */
						?>
						<input type="hidden" name="min_price" value="<?php echo esc_attr( $bucket && null !== $buckets[ $bucket ]['min'] ? $buckets[ $bucket ]['min'] : '' ); ?>" data-slk-min>
						<input type="hidden" name="max_price" value="<?php echo esc_attr( $bucket && null !== $buckets[ $bucket ]['max'] ? $buckets[ $bucket ]['max'] : '' ); ?>" data-slk-max>
					</div>
				<?php endif; ?>

				<?php if ( $terms ) : ?>
					<div class="slk-filters__group">
						<span class="slk-eyebrow"><?php esc_html_e( 'Category', 'slk' ); ?></span>
						<div class="slk-facets">
							<?php
							foreach ( $terms as $term ) {
								echo slk_moments_facet( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
									array(
										'type'    => 'checkbox',
										'name'    => 'product_cat[]',
										'value'   => $term->slug,
										'label'   => $term->name,
										'checked' => in_array( $term->slug, $cats, true ),
										'id'      => 'slk-cat-' . $term->slug,
									)
								);
							}
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div class="slk-filters__foot">
				<button type="submit" class="slk-btn slk-btn--primary slk-filters__submit">
					<?php
					/*
					 * "Showing", not "Show": this count is already on screen,
					 * in the <h1> beside the button, on every page load — a
					 * shared or bookmarked filtered URL, every chip click
					 * (chips are real links), every use of the button itself,
					 * and permanently at 1000px where the sheet is the
					 * always-visible sidebar. The same two strings the live
					 * client swaps in (showingPiece/showingPieces below), so
					 * the label reads correctly at first paint and on the
					 * no-JS path, not only after an AJAX round trip.
					 */
					printf(
						/* translators: %s: number of products. */
						esc_html( _n( 'Showing %s piece', 'Showing %s pieces', $total, 'slk' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</button>
			</div>
		</form>
	</div>
	<?php
}

/**
 * The trigger + active-filter chips that sit above the grid.
 *
 * Rendered separately from the sheet because it belongs in the results column:
 * `.slk-shop-layout` is a two-column grid, and a third child would push the
 * grid out of alignment at 1000px.
 */
function slk_moments_render_filterbar() {
	$chips = slk_moments_active_chips();
	$count = count( $chips );
	?>
	<div class="slk-filterbar" id="slk-filterbar">
		<button type="button" class="slk-btn slk-btn--primary slk-filterbar__trigger"
			data-slk-sheet-open="slk-filters"
			aria-controls="slk-filters"
			aria-expanded="false">
			<?php esc_html_e( 'Filters', 'slk' ); ?>
			<?php if ( $count ) : ?>
				<span class="slk-filterbar__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
			<?php endif; ?>
		</button>

		<?php
		/*
		 * Always present, even with zero chips right now: the AJAX filter
		 * response swaps this element's content directly (id="slk-filterbar-chips"),
		 * and a container that only exists once a filter is active would give
		 * that swap nothing to target on the very first tick.
		 */
		?>
		<div id="slk-filterbar-chips" data-slk-chips>
			<?php echo slk_moments_render_chips( $chips, slk_moments_listing_url() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</div>
	</div>
	<?php
}

/**
 * Resolve the no-JS bucket key into WooCommerce's own price args.
 *
 * With JS the hidden min_price/max_price inputs are rewritten before submit and
 * `slk_price` is stripped, so this never fires. Without JS the browser sends
 * both the bucket key and the stale hidden values, and this one redirect turns
 * that into the canonical, shareable URL — which is also what makes the no-JS
 * result set identical to the JS one rather than merely similar.
 */
add_action(
	'template_redirect',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['slk_price'] ) || ! slk_moments_is_listing() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key     = sanitize_key( wp_unslash( $_GET['slk_price'] ) );
		$buckets = slk_moments_price_buckets();
		$url     = remove_query_arg( array( 'slk_price', 'min_price', 'max_price' ), slk_moments_current_url() );

		if ( isset( $buckets[ $key ] ) ) {
			if ( null !== $buckets[ $key ]['min'] ) {
				$url = add_query_arg( 'min_price', (int) $buckets[ $key ]['min'], $url );
			}
			if ( null !== $buckets[ $key ]['max'] ) {
				$url = add_query_arg( 'max_price', (int) $buckets[ $key ]['max'], $url );
			}
		}

		wp_safe_redirect( $url, 302 );
		exit;
	},
	5
);

/**
 * The taxonomy term the current listing is already scoped to, if any.
 *
 * On /product-category/abayas/ the sheet renders no category boxes and the form
 * submits back into the term, so the term itself is part of the result set the
 * button is counting. It is carried to the count endpoint as data attributes
 * rather than a hidden field, because a hidden field would also be submitted
 * and would put a redundant filter arg on an already scoped URL.
 *
 * @return array{taxonomy:string,term:string}|array{}
 */
function slk_moments_listing_context() {
	if ( ! function_exists( 'is_product_taxonomy' ) || ! is_product_taxonomy() ) {
		return array();
	}

	$term = get_queried_object();

	if ( ! $term instanceof WP_Term ) {
		return array();
	}

	return array(
		'taxonomy' => $term->taxonomy,
		'term'     => $term->slug,
	);
}

/**
 * The price condition the shop archive itself applies, as a posts_clauses filter.
 *
 * WooCommerce resolves ?min_price/?max_price in
 * WC_Query::price_filter_post_clauses(), which joins wc_product_meta_lookup and
 * tests the shopper's range and the product's range for overlap. That method
 * only runs on the main query, so the count query borrows its exact join and
 * its exact condition. It cannot use a product-query argument instead: there is
 * no price argument in WC_Product_Query, and an unknown one is passed through
 * to WP_Query and dropped in silence, which leaves the button promising a
 * result the archive will not deliver.
 *
 * @param float $min Lowest price to include.
 * @param float $max Highest price to include.
 * @return callable A posts_clauses callback.
 */
function slk_moments_price_clause( $min, $max ) {
	// The archive shifts the bounds when prices are stored one way and shown
	// another. The same shift is applied here, or the two would disagree the
	// day tax is switched on.
	if ( wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' ) && ! wc_prices_include_tax() ) {
		$rates = WC_Tax::get_rates( apply_filters( 'woocommerce_price_filter_widget_tax_class', '' ) );

		if ( $rates ) {
			$min -= WC_Tax::get_tax_total( WC_Tax::calc_inclusive_tax( $min, $rates ) );
			$max -= WC_Tax::get_tax_total( WC_Tax::calc_inclusive_tax( $max, $rates ) );
		}
	}

	return static function ( $clauses ) use ( $min, $max ) {
		global $wpdb;

		if ( ! strstr( $clauses['join'], 'wc_product_meta_lookup' ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} wc_product_meta_lookup ON {$wpdb->posts}.ID = wc_product_meta_lookup.product_id ";
		}

		$clauses['where'] .= $wpdb->prepare(
			' AND NOT (%f<wc_product_meta_lookup.min_price OR %f>wc_product_meta_lookup.max_price ) ',
			$max,
			$min
		);

		return $clauses;
	};
}

/**
 * "N piece"/"N pieces" — the same two translatable strings inc/shop.php's
 * woocommerce_before_shop_loop closure renders inside the archive's <h1>.
 * That closure is anonymous (hooked directly with add_action, not a named
 * function) and lives in a file this package cannot touch, so it cannot be
 * called from here directly; kept to the identical _n() call instead, so a
 * translator sees one msgid rather than a second, divergent string, and the
 * AJAX response's heading text can never read differently from a reload of
 * the same URL.
 *
 * @param int $count Result count.
 * @return string
 */
function slk_moments_shop_head_count_text( $count ) {
	return sprintf(
		/* translators: %d: number of products in the listing. */
		_n( '%d piece', '%d pieces', $count, 'slk' ),
		$count
	);
}

/**
 * The products grid markup for an explicit list of product IDs, via
 * WooCommerce's own loop-start/loop-end and content-product.php — the exact
 * mechanism inc/shop.php's slk_template_loop_*() callbacks already restyle
 * for the page-load grid, and the one search.php uses for the identical
 * reason (its own doc comment: "each result renders through the exact same
 * card renderer as the shop grid... by using WooCommerce's own
 * loop-start/loop-end and wc_get_template_part(), not a hand-rolled result
 * row"). A card can never look different depending on which path drew it,
 * because no path draws its own copy.
 *
 * setup_postdata()/global $product are set by hand rather than run through a
 * second WP_Query: the id list already comes from the exact wc_get_products()
 * call the archive's own filtering runs (see slk_moments_ajax_filter_count()
 * below), so this is purely a fetch-and-render pass, not a second,
 * independently-written filter that could disagree with it. The same
 * pattern WooCommerce core itself uses to render a product list outside the
 * main loop (single-product/related.php).
 *
 * @param int[] $product_ids Product IDs, in display order.
 * @return string
 */
function slk_moments_render_products_grid( array $product_ids ) {
	if ( ! $product_ids ) {
		return '';
	}

	global $product;

	ob_start();

	/*
	 * The page-load grid gets [data-slk-grid] from the
	 * woocommerce_product_loop_start filter below, which deliberately does
	 * nothing unless slk_moments_is_listing() — and that is false inside
	 * admin-ajax.php, where is_admin() is true. Left to the filter alone the
	 * AJAX response would hand back a grid with no attribute for
	 * applyResult() to find, so live filtering would swap the grid exactly
	 * once (off the page-load markup) and then silently stop. Stamped here
	 * instead, guarded by strpos so the two paths can never double it up.
	 */
	$loop_start = woocommerce_product_loop_start( false );

	if ( false === strpos( $loop_start, 'data-slk-grid' ) ) {
		$loop_start = preg_replace( '/<ul /', '<ul data-slk-grid ', $loop_start, 1 );
	}

	echo $loop_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own loop-start template output.

	foreach ( $product_ids as $product_id ) {
		$product_obj = wc_get_product( $product_id );

		if ( ! $product_obj || ! $product_obj->is_visible() ) {
			continue;
		}

		$post_object = get_post( $product_id );

		if ( ! $post_object ) {
			continue;
		}

		$GLOBALS['post'] = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- drives content-product.php outside the main loop, same as WC core's related.php.
		setup_postdata( $post_object );
		$product = $product_obj;

		wc_get_template_part( 'content', 'product' );
	}

	woocommerce_product_loop_end();
	wp_reset_postdata();

	return (string) ob_get_clean();
}

/**
 * Mark the grid's own top-level element — ul.products on a normal result,
 * .slk-moments-empty on a zero-result one (slk_moments_render_empty_panel()
 * carries the attribute itself) — with one stable, unambiguous selector. The
 * AJAX filter response swaps whichever of the two is currently in the DOM
 * for whichever of the two the new selection produces, and an outerHTML
 * replacement needs a target that survives the element's own tag changing
 * from <ul> to <div> and back.
 */
add_filter(
	'woocommerce_product_loop_start',
	static function ( $html ) {
		if ( ! slk_moments_is_listing() ) {
			return $html;
		}

		return preg_replace( '/<ul /', '<ul data-slk-grid ', $html, 1 );
	}
);

/**
 * The archive's own pager, for a result set rendered outside the archive.
 *
 * Goes through wc_get_template( 'loop/pagination.php' ) with explicit
 * total/current/base/format rather than calling paginate_links() directly,
 * because that template part is the one hook point the parent theme listens
 * on (woocommerce_before_template_part) to swap WooCommerce's markup for its
 * own nav.ct-pagination — so the pager the AJAX response returns is drawn by
 * exactly the code that draws the pager on a reload of the same URL, never a
 * second lookalike that could drift from it.
 *
 * The base cannot come from get_pagenum_link(): inside admin-ajax.php that
 * resolves against wp-admin. It is built from the filtered listing URL the
 * client is about to push instead, so page 2 of a filtered result still
 * carries the filter — the stale page-load pager this replaces pointed at the
 * unfiltered query and silently dropped it.
 *
 * @param int    $pages Total pages in the filtered result.
 * @param string $url   The filtered listing URL, page 1.
 * @return string Empty string when there is nothing to page through.
 */
function slk_moments_render_pagination( $pages, $url ) {
	if ( (int) $pages < 2 ) {
		return '';
	}

	$parts  = wp_parse_url( $url );
	$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
	$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
	$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' )
		. ( isset( $parts['host'] ) ? $parts['host'] : '' )
		. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

	if ( get_option( 'permalink_structure' ) ) {
		global $wp_rewrite;

		$page_base = ( isset( $wp_rewrite ) && $wp_rewrite && $wp_rewrite->pagination_base ) ? $wp_rewrite->pagination_base : 'page';
		$base      = $origin . trailingslashit( $path ) . $page_base . '/%#%/' . $query;
	} else {
		$base = $origin . $path . ( $query ? $query . '&' : '?' ) . 'paged=%#%';
	}

	ob_start();
	wc_get_template(
		'loop/pagination.php',
		array(
			'total'   => (int) $pages,
			'current' => 1,
			'base'    => $base,
			'format'  => '',
		)
	);

	return (string) ob_get_clean();
}

/**
 * AJAX: the live result of a candidate filter selection, before the form is
 * submitted — the rendered grid, the heading count and the active-filter
 * chips, not only a number, so the real-time client and a page reload can
 * never disagree about what a selection matches.
 *
 * The query is the archive's own: same status, same category args, the
 * archive's own term when the listing is a term archive, and the archive's own
 * price clause, so the result handed back can never disagree with what
 * submitting the form would actually produce. Registered for both wp_ajax_ and
 * wp_ajax_nopriv_ because most shoppers on the shop page are not logged in.
 */
function slk_moments_ajax_filter_count() {
	// The response is candidate, already-public product-listing HTML — the
	// same markup any visitor's next page load would see for this selection
	// — so this stays unauthenticated and unnonced, same as the count-only
	// version it replaces (see that rationale below). What changes is that a
	// cache sitting in front of PHP must never be allowed to serve one
	// shopper's filter selection back to the next one.
	nocache_headers();
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	// LiteSpeed Cache's own opt-out for a single request; a harmless no-op
	// when that plugin is not active.
	do_action( 'litespeed_control_set_nocache', 'slk_filter_count' );

	if ( ! function_exists( 'wc_get_products' ) ) {
		wp_send_json_error( array(), 500 );
	}

	// A read-only query of already-public product data; nothing here is
	// written or disclosed beyond what the archive itself would show for the
	// same selection. The endpoint answers unauthenticated callers, so only
	// scalars are handed to sanitize_title() (an array element would raise a
	// TypeError inside it) and the list of slugs is capped.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$raw_cats   = isset( $_POST['product_cat'] ) ? wp_unslash( $_POST['product_cat'] ) : array();
	$raw_cats   = array_slice( array_filter( (array) $raw_cats, 'is_scalar' ), 0, 50 );
	$categories = array_values( array_unique( array_filter( array_map( 'sanitize_title', $raw_cats ) ) ) );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$bucket_key = isset( $_POST['slk_price'] ) ? sanitize_key( wp_unslash( $_POST['slk_price'] ) ) : '';
	$buckets    = slk_moments_price_buckets();
	$min        = 0;
	$max        = 0;

	if ( isset( $buckets[ $bucket_key ] ) ) {
		$min = null !== $buckets[ $bucket_key ]['min'] ? (int) $buckets[ $bucket_key ]['min'] : 0;
		$max = null !== $buckets[ $bucket_key ]['max'] ? (int) $buckets[ $bucket_key ]['max'] : 0;
	} else {
		$bucket_key = ''; // Never carry forward a key that does not resolve to a real bucket.
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$ctx_tax = isset( $_POST['slk_ctx_tax'] ) ? sanitize_key( wp_unslash( $_POST['slk_ctx_tax'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$raw_term = isset( $_POST['slk_ctx_term'] ) && is_scalar( $_POST['slk_ctx_term'] ) ? wp_unslash( $_POST['slk_ctx_term'] ) : '';
	$ctx_term = sanitize_title( $raw_term );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$orderby = ( isset( $_POST['orderby'] ) && is_scalar( $_POST['orderby'] ) ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : '';

	// The archive's own page size: functions.php filters loop_shop_per_page to
	// 12, so a request for every match would hand the client a grid that a
	// reload of the very URL it is about to push does not reproduce.
	$per_page = (int) apply_filters( 'loop_shop_per_page', wc_get_default_products_per_row() * wc_get_default_product_rows_per_page() );
	$per_page = $per_page > 0 ? $per_page : 12;

	/*
	 * The archive's own sort. WC only splits "price-desc" into key and
	 * direction on the branch where it reads $_GET itself, which an
	 * admin-ajax POST is not — so the split happens here and the two halves
	 * go in. An empty $orderby is passed straight through, which is what
	 * makes WC fall back to the store's own default catalog ordering (here
	 * menu_order + title) rather than WC_Product_Query's date DESC, so the
	 * live grid is not reshuffled by a facet change for reasons unrelated to
	 * the filter.
	 */
	$sort_key   = '';
	$sort_order = '';

	if ( $orderby ) {
		$sort_parts = explode( '-', $orderby );
		$sort_key   = $sort_parts[0];
		$sort_order = isset( $sort_parts[1] ) ? $sort_parts[1] : '';
	}

	$ordering = ( function_exists( 'WC' ) && WC()->query ) ? WC()->query->get_catalog_ordering_args( $sort_key, $sort_order ) : array();

	$args = array(
		'status'     => 'publish',
		'limit'      => $per_page,
		'page'       => 1,
		'paginate'   => true,
		'return'     => 'ids',
		// Pinned, because the default also counts variations.
		'type'       => array_keys( wc_get_product_types() ),
		'category'   => $categories, // array of slugs, empty for all
		/*
		 * The archive's own WC_Query::get_tax_query() drops anything flagged
		 * exclude-from-catalog, and so does the grid renderer below (it skips
		 * any product failing is_visible()). Without the same constraint on
		 * the query, the count is measured against a wider set than the one
		 * drawn, and the heading, the live announcement and the button label
		 * can all claim more pieces than there are cards.
		 */
		'visibility' => 'catalog',
	);

	foreach ( array( 'orderby', 'order', 'meta_key' ) as $ordering_key ) {
		if ( ! empty( $ordering[ $ordering_key ] ) ) {
			$args[ $ordering_key ] = $ordering[ $ordering_key ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the archive sets the same key.
		}
	}

	$tax_query = slk_moments_stock_tax_query();

	if ( $ctx_tax && $ctx_term && in_array( $ctx_tax, get_object_taxonomies( 'product' ), true ) ) {
		$tax_query[] = array(
			'taxonomy' => $ctx_tax,
			'field'    => 'slug',
			'terms'    => array( $ctx_term ),
		);
	}

	if ( $tax_query ) {
		$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the archive runs the same clauses.
	}

	$clause = null;

	if ( $min > 0 || $max > 0 ) {
		$clause = slk_moments_price_clause( $min, $max > 0 ? $max : PHP_INT_MAX );
		add_filter( 'posts_clauses', $clause );
	}

	$result = wc_get_products( $args );

	// Unhooked before anything else queries: a price/popularity/rating sort
	// hooks its own posts_clauses callback, and leaving it in place would
	// reorder — and, for price, re-join — slk_moments_render_empty_panel()'s
	// own wc_get_products() call further down.
	if ( function_exists( 'WC' ) && WC()->query ) {
		WC()->query->remove_ordering_args();
	}

	if ( $clause ) {
		remove_filter( 'posts_clauses', $clause );
	}

	$ids   = ( is_object( $result ) && isset( $result->products ) && is_array( $result->products ) ) ? array_map( 'absint', $result->products ) : array();
	// The unpaginated total, so the heading and the announcement still say how
	// many the selection matches, not how many fit on the first page.
	$count = ( is_object( $result ) && isset( $result->total ) ) ? (int) $result->total : count( $ids );
	$pages = ( is_object( $result ) && isset( $result->max_num_pages ) ) ? (int) $result->max_num_pages : 1;

	$base_url = slk_moments_base_url_for( $ctx_tax, $ctx_term );
	$chips    = slk_moments_build_filter_chips( $categories, $bucket_key, $base_url, $orderby );

	wp_send_json_success(
		array(
			'count'      => $count,
			'heading'    => slk_moments_shop_head_count_text( $count ),
			'grid'       => $ids ? slk_moments_render_products_grid( $ids ) : slk_moments_render_empty_panel( $bucket_key, $categories, $base_url ),
			'chips'      => slk_moments_render_chips( $chips, $base_url ),
			'chipCount'  => count( $chips ),
			'pagination' => slk_moments_render_pagination(
				$pages,
				slk_moments_filtered_url( $base_url, $categories, $min > 0 ? $min : null, $max > 0 ? $max : null, $orderby )
			),
		)
	);
}
add_action( 'wp_ajax_slk_filter_count', 'slk_moments_ajax_filter_count' );
add_action( 'wp_ajax_nopriv_slk_filter_count', 'slk_moments_ajax_filter_count' );

/*
 * Layout wrapper.
 *
 * Opened on `woocommerce_shop_loop_header` (after the archive title) and closed
 * on `woocommerce_after_main_content` rather than around the loop, because
 * archive-product.php only fires woocommerce_before_shop_loop when there ARE
 * products — and a zero-result page is exactly when the filters must still be
 * reachable.
 */
add_action(
	'woocommerce_shop_loop_header',
	static function () {
		if ( ! slk_moments_is_listing() ) {
			return;
		}

		echo '<div class="slk-shop-layout">';
		slk_moments_render_filters();
		echo '<div class="slk-shop-results" id="slk-shop-results" aria-busy="false">';
		// Announces the new count once a facet changes the result set — never
		// moves focus, so a screen reader hears "12 pieces" without losing
		// its place in the form.
		echo '<div class="screen-reader-text" id="slk-shop-live" aria-live="polite" aria-atomic="true"></div>';
		slk_moments_render_filterbar();
	},
	999
);

add_action(
	'woocommerce_after_main_content',
	static function () {
		if ( ! slk_moments_is_listing() ) {
			return;
		}

		echo '</div></div>';
	},
	1
);

/**
 * The empty result (03-components.html:90-95), with the real numbers in it.
 */
add_action(
	'init',
	static function () {
		remove_action( 'woocommerce_no_products_found', 'wc_no_products_found', 10 );
		add_action( 'woocommerce_no_products_found', 'slk_moments_no_products_found', 10 );
	},
	20
);

/**
 * The empty-result panel (03-components.html:90-95), for an explicit filter
 * selection. Shared by the woocommerce_no_products_found hook below (page
 * load, selection read from $_GET) and the AJAX filter response (candidate
 * selection read from the POST body), so a zero-result state renders
 * identically from either path — never as a bare, message-less blank grid.
 *
 * @param string      $bucket   Active price bucket key, '' for none.
 * @param string[]    $cats     Active category slugs.
 * @param string|null $base_url The no-filters URL; defaults to
 *                              slk_moments_listing_url() (correct for the
 *                              page-load path). The AJAX path passes its own,
 *                              because that helper reads the current
 *                              front-end query, which an admin-ajax.php
 *                              request never has.
 * @return string
 */
function slk_moments_render_empty_panel( $bucket, array $cats, $base_url = null ) {
	if ( null === $base_url ) {
		$base_url = slk_moments_listing_url();
	}

	// How many there would be with the price filter lifted — a real count, not
	// a guess, so the sentence is never a promise the grid cannot keep.
	$unfiltered = 0;

	if ( function_exists( 'wc_get_products' ) ) {
		$args = array(
			'limit'  => -1,
			'return' => 'ids',
			'status' => 'publish',
		);

		if ( $cats ) {
			$args['category'] = $cats;
		}

		$unfiltered = count( (array) wc_get_products( $args ) );
	}

	ob_start();
	?>
	<div class="slk-panel slk-moments-empty" data-slk-grid>
		<p class="slk-moments-empty__head"><?php esc_html_e( 'No pieces match those filters', 'slk' ); ?></p>
		<?php if ( $bucket && $unfiltered ) : ?>
			<p class="slk-moments-empty__body">
				<?php
				printf(
					/* translators: %s: number of products without the price filter. */
					esc_html( _n( 'Clear the price filter and there is %s.', 'Clear the price filter and there are %s.', $unfiltered, 'slk' ) ),
					esc_html( number_format_i18n( $unfiltered ) )
				);
				?>
			</p>
		<?php endif; ?>
		<a class="slk-btn slk-btn--secondary" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Clear filters', 'slk' ); ?></a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * Print the empty-result panel.
 */
function slk_moments_no_products_found() {
	if ( ! slk_moments_is_listing() ) {
		wc_get_template( 'loop/no-products-found.php' );
		return;
	}

	echo slk_moments_render_empty_panel( slk_moments_active_price_bucket(), slk_moments_active_cats() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
}

/* =========================================================================
 * 2. ADDED TO BAG
 *
 * The add-to-cart mechanism is WooCommerce's, untouched. All this does is
 * remember which line the last add produced, and render the design's drawer
 * for it once.
 * ====================================================================== */

const SLK_MOMENTS_ADDED_KEY = 'slk_moments_added';

/**
 * Remember the line item the add produced. Fires for both the plain POST and
 * any AJAX add, because it is WooCommerce's server-side action either way.
 *
 * @param string $cart_item_key Cart line key.
 */
function slk_moments_note_add( $cart_item_key ) {
	if ( function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( SLK_MOMENTS_ADDED_KEY, $cart_item_key );
	}
}
add_action( 'woocommerce_add_to_cart', 'slk_moments_note_add', 20, 1 );

/**
 * Read and clear the pending add.
 *
 * @return array|null The cart item, or null.
 */
function slk_moments_take_added() {
	if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart ) {
		return null;
	}

	$key = (string) WC()->session->get( SLK_MOMENTS_ADDED_KEY, '' );

	if ( '' === $key ) {
		return null;
	}

	WC()->session->set( SLK_MOMENTS_ADDED_KEY, '' );

	$item = WC()->cart->get_cart_item( $key );

	return $item ? $item : null;
}

/**
 * "delivery from Rs. 350" — the lowest real zone fee, or '' when the delivery
 * page has not been built yet. Never a typed number standing in for data.
 *
 * @return string
 */
function slk_moments_delivery_from() {
	if ( ! function_exists( 'slk_delivery_zones' ) ) {
		return '';
	}

	$fees = wp_list_pluck( (array) slk_delivery_zones(), 'fee' );

	// Keep a zero fee: free metro delivery is a real dashboard setting, and
	// truthiness would drop it and advertise the next zone's price instead.
	$fees = array_filter( array_map( 'floatval', (array) $fees ), static fn( $f ) => $f >= 0 );

	return $fees ? wp_strip_all_tags( wc_price( min( $fees ) ) ) : '';
}

/**
 * The drawer's inner markup for one cart line.
 *
 * @param array $item Cart item.
 * @return string
 */
function slk_moments_added_body( $item ) {
	$product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;

	if ( ! $product ) {
		return '';
	}

	$qty      = (int) $item['quantity'];
	$parent   = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
	$name     = $parent ? $parent->get_name() : $product->get_name();
	$line     = WC()->cart->get_product_subtotal( $product, $qty );
	$image    = $product->get_image( 'woocommerce_thumbnail', array( 'alt' => '' ) );
	$subtotal = wc_price( (float) WC()->cart->get_subtotal() );
	$count    = (int) WC()->cart->get_cart_contents_count();
	$delivery = slk_moments_delivery_from();

	// "Ink · M · ×1" — the chosen variation, then the quantity.
	$bits = array();

	foreach ( (array) ( isset( $item['variation'] ) ? $item['variation'] : array() ) as $attr => $value ) {
		if ( '' === $value ) {
			continue;
		}

		$taxonomy = str_replace( 'attribute_', '', $attr );
		$term     = taxonomy_exists( $taxonomy ) ? get_term_by( 'slug', $value, $taxonomy ) : null;
		$bits[]   = $term && ! is_wp_error( $term ) ? $term->name : ucfirst( str_replace( '-', ' ', (string) $value ) );
	}

	/* translators: %s: quantity. */
	$bits[] = sprintf( __( '×%s', 'slk' ), number_format_i18n( $qty ) );

	ob_start();
	?>
	<span class="slk-sheet__grabber" aria-hidden="true"></span>

	<p class="slk-added__head">
		<span class="slk-added__tick" aria-hidden="true">✓</span>
		<span><?php esc_html_e( 'Added to your bag', 'slk' ); ?></span>
	</p>

	<div class="slk-added__line">
		<span class="slk-added__media"><?php echo wp_kses_post( $image ); ?></span>
		<span class="slk-added__detail">
			<span class="slk-added__row">
				<span class="slk-added__name"><?php echo esc_html( $name ); ?></span>
				<span class="slk-added__price"><?php echo wp_kses_post( $line ); ?></span>
			</span>
			<span class="slk-added__meta"><?php echo esc_html( implode( ' · ', $bits ) ); ?></span>
			<span class="slk-added__totals">
				<?php
				/* translators: %s: bag subtotal. */
				printf( esc_html__( 'Bag total %s', 'slk' ), wp_kses_post( $subtotal ) );

				if ( $delivery ) {
					/* translators: %s: lowest delivery fee. */
					printf( ' · ' . esc_html__( 'delivery from %s', 'slk' ), esc_html( $delivery ) );
				}
				?>
			</span>
		</span>
	</div>

	<?php
	$related = function_exists( 'wc_get_related_products' ) && $parent
		? wc_get_related_products( $parent->get_id(), 3 )
		: array();

	if ( $related ) :
		?>
		<div class="slk-added__with">
			<span class="slk-eyebrow"><?php esc_html_e( 'Wears well with', 'slk' ); ?></span>
			<div class="slk-added__rail">
				<?php
				foreach ( $related as $related_id ) :
					$rel = wc_get_product( $related_id );

					if ( ! $rel || ! $rel->is_visible() ) {
						continue;
					}
					?>
					<a class="slk-card slk-card--colour" href="<?php echo esc_url( get_permalink( $related_id ) ); ?>">
						<span class="slk-card__media"><?php echo wp_kses_post( $rel->get_image( 'woocommerce_thumbnail', array( 'alt' => '' ) ) ); ?></span>
						<span class="slk-card__name"><?php echo esc_html( $rel->get_name() ); ?></span>
						<span class="slk-card__price"><?php echo wp_kses_post( $rel->get_price_html() ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>

	<div class="slk-added__actions">
		<button type="button" class="slk-btn slk-btn--secondary" data-slk-sheet-close><?php esc_html_e( 'Keep browsing', 'slk' ); ?></button>
		<a class="slk-btn slk-btn--primary slk-added__view" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
			<?php
			printf(
				/* translators: %s: number of items in the bag. */
				esc_html__( 'View bag · %s', 'slk' ),
				esc_html( number_format_i18n( $count ) )
			);
			?>
		</a>
	</div>
	<?php
	return (string) ob_get_clean();
}

/**
 * The drawer shell, printed on every front-end page so JS has somewhere to
 * inject into and so a plain POST add can arrive with it already filled.
 */
add_action(
	'wp_footer',
	static function () {
		if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$item = slk_moments_take_added();
		$body = $item ? slk_moments_added_body( $item ) : '';
		?>
		<div class="slk-added" id="slk-added" data-slk-sheet <?php echo $body ? '' : 'hidden'; ?>>
			<div class="slk-scrim" data-slk-sheet-close></div>
			<div class="slk-sheet slk-added__sheet" role="dialog" aria-modal="true"
				aria-label="<?php esc_attr_e( 'Added to your bag', 'slk' ); ?>" data-slk-added-body>
				<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the builder. ?>
			</div>
		</div>
		<?php
	},
	5 // Before wp_print_footer_scripts (wp_footer, 20) so the script can see it.
);

/**
 * The same markup over wc-ajax, for the AJAX add path.
 *
 * No product id is passed: the server already knows which line the add just
 * produced, from the session flag set by woocommerce_add_to_cart during that
 * very request. One source of truth for both paths.
 */
add_action(
	'wc_ajax_slk_added_sheet',
	static function () {
		$item = slk_moments_take_added();

		wp_send_json(
			array(
				'html'  => $item ? slk_moments_added_body( $item ) : '',
				'count' => ( function_exists( 'WC' ) && WC()->cart ) ? (int) WC()->cart->get_cart_contents_count() : 0,
			)
		);
	}
);

/* =========================================================================
 * 3. IMAGE ZOOM
 *
 * See the file header for why this configures WooCommerce's PhotoSwipe rather
 * than shipping a second lightbox.
 * ====================================================================== */

/**
 * The line under the thumbnails in 02-moments.html:107, as PhotoSwipe's caption
 * for any gallery image that has no caption of its own.
 */
add_filter(
	'woocommerce_gallery_image_html_attachment_image_params',
	static function ( $params ) {
		if ( empty( $params['data-caption'] ) ) {
			$params['data-caption'] = esc_attr__( 'Pinch or double-tap to see the weave up close. You cannot feel a fabric through a screen, so this is the next best thing.', 'slk' );
		}

		return $params;
	}
);

/**
 * WooCommerce's own PhotoSwipe options filter is the place the skin is
 * declared, so anything else reading wc_single_product_params sees it too.
 */
add_filter(
	'woocommerce_single_product_photoswipe_options',
	static function ( $options ) {
		return array_merge(
			(array) $options,
			array(
				'mainClass'         => 'pswp--slk',
				'bgOpacity'         => 1,
				'shareEl'           => false,
				'fullscreenEl'      => false,
				'counterEl'         => true,
				'captionEl'         => true,
				'indexIndicatorSep' => ' of ',
				'closeOnScroll'     => false,
				/*
				 * The zoom needs more than one way out. Escape only helps on a
				 * desktop, and tapping the picture zooms rather than closes
				 * because the image is zoomable, so on a phone the close button
				 * was the only exit. history:true puts the gallery in the
				 * browser history, which makes the back button and the Android
				 * back gesture close it, the thing anyone reaches for first.
				 */
				'history'           => true,
				'timeToIdle'        => 0,
			)
		);
	}
);

/* =========================================================================
 * 4. Styles + behaviour
 *
 * Tokens only. One breakpoint at 1000px, mobile first.
 * ====================================================================== */

add_action(
	'wp_enqueue_scripts',
	static function () {
		$css = <<<'CSS'
/* ── Filter sheet ─────────────────────────────────────────────────────────
   Below 1000px the form is the bottom sheet from 02-moments.html:11; at 1000px
   it is the sidebar style.css §3.7 already sizes inside .slk-shop-layout. */
.slk-filterbox{position:fixed;inset:0;z-index:70}
.slk-filterbox[hidden]{display:none}
.slk-filterbox > .slk-filters{
	position:absolute;left:0;right:0;bottom:0;
	max-height:76%;
	display:flex;flex-direction:column;gap:0;
	background:rgba(250,249,246,.90);
	backdrop-filter:blur(30px) saturate(1.4);
	-webkit-backdrop-filter:blur(30px) saturate(1.4);
	border-top:1px solid var(--slk-glass-edge);
	border-radius:28px 28px 0 0;
	box-shadow:0 -20px 50px rgba(35,34,32,.24);
	transition:transform var(--slk-motion-base) var(--slk-ease);
}
.slk-filters__head{
	display:flex;align-items:center;justify-content:space-between;
	padding:var(--slk-space-1) 10px var(--slk-space-1) var(--slk-space-6);
}
.slk-filters__title{
	margin:0;font-family:var(--slk-font-display);font-weight:300;font-size:23px;
}
.slk-filters__body{padding:var(--slk-space-1) var(--slk-space-6) 0;overflow:auto;display:grid;gap:var(--slk-space-3)}
.slk-filterbox .slk-filters__group{background:none;border:0;border-radius:0;padding:var(--slk-space-2) 0 0}
.slk-filters__group > .slk-eyebrow{display:block;margin-bottom:10px}
.slk-facets{display:flex;flex-wrap:wrap;gap:var(--slk-space-2)}
/* The field stays focusable — only its box is traded for the pill it labels. */
.slk-facet__input{position:absolute;width:1px;height:1px;opacity:0;margin:0}
.slk-facet{display:inline-flex;align-items:center;min-height:var(--slk-touch);padding:0 16px;cursor:pointer}
.slk-facet__input:checked + .slk-facet{
	background:var(--slk-color-ink);color:var(--slk-color-on-ink);border-color:transparent;
}
.slk-facet__input:focus-visible + .slk-facet{outline:2px solid var(--slk-color-ink);outline-offset:2px}
.slk-filters__foot{
	padding:var(--slk-space-3) var(--slk-space-4) 18px;
	background:linear-gradient(to top,rgba(250,249,246,.98) 70%,rgba(250,249,246,0));
}
.slk-filters__submit{width:100%;min-height:52px}

/* The trigger bar over the grid (06-mobile.html:100-105). */
.slk-filterbar{display:flex;flex-wrap:wrap;gap:var(--slk-space-2);align-items:center;padding-block:var(--slk-space-3)}
.slk-filterbar__count{
	display:inline-grid;place-items:center;min-width:20px;padding:2px 6px;
	background:var(--slk-color-on-ink);color:var(--slk-color-ink);
	border-radius:10px;font:600 10px/1 var(--slk-font-ui);
}
.slk-filterbar .slk-chips{padding-bottom:0}
a.slk-chip{text-decoration:none}

/* Real-time filtering: the pending state while a facet change is in flight.
   Reduced opacity only — no layout shift, nothing pointer-events:none, so the
   grid stays fully usable while a request settles (~250ms debounce + a
   normal-connection round trip). */
.slk-shop-results{transition:opacity var(--slk-motion-base) var(--slk-ease)}
.slk-shop-results[aria-busy="true"]{opacity:.5}

/* Empty result (03-components.html:90-95). */
.slk-moments-empty{padding:var(--slk-space-6) 18px;text-align:center;display:grid;justify-items:center;gap:6px}
.slk-moments-empty__head{margin:0;font:500 13.5px/1.4 var(--slk-font-ui)}
.slk-moments-empty__body{margin:0 0 var(--slk-space-3);font:400 var(--slk-text-sm)/1.6 var(--slk-font-ui);color:var(--slk-color-muted)}

/* ── Added to bag ─────────────────────────────────────────────────────── */
.slk-added{position:fixed;inset:0;z-index:75}
.slk-added[hidden]{display:none}
.slk-added__sheet{max-height:88%;overflow:auto;padding-bottom:var(--slk-space-2)}
.slk-added__head{
	display:flex;align-items:center;gap:11px;margin:0;
	padding:10px var(--slk-space-6) 0;font:500 14px/1.3 var(--slk-font-ui);
}
.slk-added__tick{
	flex:none;width:26px;height:26px;border-radius:50%;
	display:grid;place-items:center;font-size:11px;
	background:var(--slk-color-ink);color:var(--slk-color-on-ink);
}
.slk-added__line{display:flex;gap:14px;padding:var(--slk-space-4) var(--slk-space-6) 0}
.slk-added__media{flex:none;width:74px;aspect-ratio:var(--slk-ratio-portrait);border-radius:14px;overflow:hidden;display:block}
.slk-added__media img{width:100%;height:100%;object-fit:cover;display:block}
.slk-added__detail{flex:1;display:block;min-width:0}
.slk-added__row{display:flex;justify-content:space-between;gap:10px}
.slk-added__name{font:500 13.5px/1.35 var(--slk-font-ui)}
.slk-added__price{font:500 13.5px/1.35 var(--slk-font-ui);white-space:nowrap}
.slk-added__meta{display:block;padding-top:3px;font:400 12px/1.5 var(--slk-font-ui);color:var(--slk-color-muted)}
.slk-added__totals{display:block;padding-top:var(--slk-space-2);font:400 11.5px/1.5 var(--slk-font-ui);color:var(--slk-color-faint)}
.slk-added__with{padding:18px var(--slk-space-6) 0}
.slk-added__with > .slk-eyebrow{display:block;margin-bottom:10px}
.slk-added__rail{display:flex;gap:10px;overflow-x:auto;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
.slk-added__rail .slk-card{flex:none;width:100px;scroll-snap-align:start;text-align:center}
.slk-added__rail .slk-card__media{display:block;aspect-ratio:1}
.slk-added__rail .slk-card__name{display:block;font-size:11.5px;padding-top:7px}
.slk-added__rail .slk-card__price{display:block;font-size:11.5px}
.slk-added__actions{display:flex;gap:9px;padding:18px var(--slk-space-4) 20px}
.slk-added__actions .slk-btn{min-height:50px}
.slk-added__view{flex:1}

/* ── Image zoom — the skin over WooCommerce's PhotoSwipe ──────────────────
   The overlay is a dark room, which the token set does not otherwise contain;
   rather than sprinkle literals, the two values it needs are declared once here
   as local custom properties derived from the ink/on-ink tokens. */
.pswp--slk{--slk-zoom-ui:rgba(250,249,246,.16);--slk-zoom-edge:rgba(255,255,255,.20)}
.pswp--slk .pswp__bg{background:var(--slk-color-ink)}
/* WooCommerce ships `.woocommerce .pswp__bg{opacity:.7!important}`, so the
   bgOpacity:1 above was ignored and the storefront stayed legible behind the
   zoom, competing with the garment. Beaten on specificity plus important.
   No transition is lost: the open and close animation durations are 0. */
.pswp--slk.pswp .pswp__bg{opacity:1 !important}
.pswp--slk .pswp__top-bar{
	background:none;height:auto;padding:12px;
	display:flex;align-items:center;gap:var(--slk-space-2);
}
.pswp--slk .pswp__counter{
	order:2;flex:1;position:static;height:auto;padding:0 var(--slk-space-3);
	text-align:center;opacity:1;
	font:400 12px/1 var(--slk-font-ui);color:var(--slk-color-on-ink);
}
.pswp--slk .pswp__counter::after{content:attr(data-slk-title)}
.pswp--slk .pswp__button--close{order:1}
.pswp--slk .pswp__button--zoom{order:3;display:block}
/* `!important` is not decoration here. Something upstream ships
   `button.pswp__button{background-color:transparent !important}`, which no
   amount of specificity beats, so the glass disc never painted and the close
   control was a hairline ring around a sprite. Important-vs-important is
   settled by specificity, which is why this is also scoped through
   .pswp__ui. */
.pswp--slk .pswp__ui .pswp__button{
	position:static;width:var(--slk-touch);height:var(--slk-touch);flex:none;
	margin:0;border-radius:50%;opacity:1;
	background-color:var(--slk-zoom-ui) !important;
	border:1px solid var(--slk-zoom-edge);
	backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
}
/* Same scope as the rule above, or `position:static` from it outranks these
   and both arrows collapse into the top-left corner, on top of the close
   button. */
.pswp--slk .pswp__ui .pswp__button--arrow--left,
.pswp--slk .pswp__ui .pswp__button--arrow--right{position:absolute;top:50%;margin-top:-22px;background-image:none}
.pswp--slk .pswp__ui .pswp__button--arrow--left{left:12px}
.pswp--slk .pswp__ui .pswp__button--arrow--right{right:12px}
.pswp--slk .pswp__caption{background:none}
.pswp--slk .pswp__caption__center{
	max-width:none;padding:0 var(--slk-space-4) 14px;text-align:center;
	font:400 11.5px/1.6 var(--slk-font-ui);color:var(--slk-color-on-ink);opacity:.6;
}
/* The thumbnail strip from 02-moments.html:101-106.

   The strip is sized to its thumbnails and centred, NOT stretched edge to
   edge. It used to be pinned left:12/right:12 with flex:1 thumbs, which is
   correct on the 390px screen it was drawn on and catastrophic anywhere
   wider: at 1905px each thumb took a third of the viewport, aspect-ratio 3/4
   then made it 821px tall, and the strip covered the entire lightbox at
   z-index 1550. Measured 1881x839 starting at y=-6, over the close button.
   The zoom looked like it had opened every image at once and trapped you
   there, because the only visible exit was underneath our own filmstrip. */
.slk-zoom__thumbs{
	position:absolute;left:50%;transform:translateX(-50%);bottom:56px;z-index:1550;
	max-width:calc(100% - 24px);
	display:flex;gap:var(--slk-space-2);padding:var(--slk-space-2);
	background:var(--slk-zoom-ui);
	backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);
	border:1px solid var(--slk-zoom-edge);border-radius:22px;
}
.slk-zoom__thumb{
	flex:none;width:48px;aspect-ratio:var(--slk-ratio-portrait);min-height:0;
	padding:0;border:0;background:none;border-radius:14px;overflow:hidden;
	opacity:.55;cursor:pointer;
	transition:opacity var(--slk-motion-base) var(--slk-ease);
}
.slk-zoom__thumb img{width:100%;height:100%;object-fit:cover;display:block}
.slk-zoom__thumb[aria-current="true"]{opacity:1;outline:2px solid var(--slk-color-on-ink);outline-offset:-2px}
/* The trigger over the gallery. Injected by script, so it never exists as a
   dead control on a page whose JavaScript did not arrive. */
.slk-zoom__open{
	position:absolute;top:12px;right:12px;z-index:2;
	width:var(--slk-touch);height:var(--slk-touch);border-radius:50%;
	display:grid;place-items:center;padding:0;
	background:var(--slk-glass);border:1px solid var(--slk-glass-edge);
	backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);
	box-shadow:var(--slk-shadow-lift);color:var(--slk-color-ink);
	font:400 17px/1 var(--slk-font-ui);cursor:pointer;
}
.woocommerce-product-gallery{position:relative}

@media (min-width:1000px){
	/* The sheet becomes the sidebar: no scrim, no fixed shell, always open. */
	.slk-filterbox{position:static;z-index:auto}
	.slk-filterbox[hidden]{display:block}
	/* The wrapper sticks, not the form: style.css's old
	   `.slk-shop-layout > .slk-filters` never matched this nesting. */
	.slk-shop-layout > .slk-filterbox{position:sticky;top:var(--slk-space-6)}
	.slk-filterbox > .slk-scrim{display:none}
	.slk-filterbox > .slk-filters{
		position:static;max-height:none;display:grid;gap:var(--slk-space-3);
		background:none;backdrop-filter:none;-webkit-backdrop-filter:none;
		border:0;border-radius:0;box-shadow:none;
	}
	.slk-filterbox .slk-sheet__grabber{display:none}
	/* The design's desktop sidebar has NO "Filters / Clear all" header — the
	   Price panel just starts (07-desktop.html:146). The header belongs to the
	   mobile sheet only; Clear lives in the empty state. */
	.slk-filters__head{display:none}
	.slk-filterbox .slk-filters__group{
		background:var(--slk-glass-solid);
		border:1px solid var(--slk-glass-edge);
		border-radius:22px;padding:var(--slk-space-6);
	}
	.slk-filters__body{padding:0;overflow:visible}
	.slk-filters__foot{padding:0;background:none}
	/* Checkbox rows, as 07-desktop.html:148-158 draws them.
	   MEASURED BUG this replaces: grid-template-columns:1fr stacked the input
	   and its sibling label on separate rows — circle above text — because
	   each facet is TWO grid children. Two columns pair them: inputs in the
	   18px track, labels beside, every row 38px and centred. */
	.slk-facets{
		display:grid;grid-template-columns:18px 1fr;
		align-items:center;column-gap:10px;row-gap:0;
	}
	.slk-facet__input{
		position:static;width:18px;height:18px;opacity:1;
		accent-color:var(--slk-color-ink);margin:0;justify-self:center;
	}
	.slk-facet{
		min-height:38px;padding:0;
		background:none;border:0;border-radius:0;
		font:400 13px/1.4 var(--slk-font-ui);
	}
	.slk-facet__input:checked + .slk-facet{background:none;color:var(--slk-color-ink);font-weight:500}
	.slk-filterbar__trigger{display:none}
	.slk-added__sheet{
		left:auto;right:var(--slk-space-6);bottom:var(--slk-space-6);
		width:420px;border-radius:var(--slk-radius-card);
	}
}

@media (prefers-reduced-motion:reduce){
	.slk-filterbox > .slk-filters{transition-duration:1ms}
	.slk-shop-results{transition-duration:1ms}
}
CSS;

		wp_add_inline_style( 'slk-child', $css );

		/*
		 * PhotoSwipe is a dependency, not a hope: WooCommerce enqueues it as a
		 * dependency of wc-single-product, which would otherwise print AFTER
		 * this inline block and leave `PhotoSwipe` undefined at the moment the
		 * zoom wires itself up. Declared only when it is actually registered,
		 * so this script still loads on the shop archive where it is not.
		 */
		$deps = array( 'jquery' );

		foreach ( array( 'photoswipe', 'photoswipe-ui-default', 'wc-single-product' ) as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) ) {
				$deps[] = $handle;
			}
		}

		wp_register_script( 'slk-moments', '', $deps, null, true );
		wp_enqueue_script( 'slk-moments' );

		// The same [min,max] pairs slk_moments_price_buckets() just rendered as
		// radios, keyed the same way, as strings ('' standing in for the null
		// open end) — so the client-side submit handler below rewrites
		// min_price/max_price from the live catalogue's own boundaries rather
		// than a second, independently hardcoded 5000/10000 that would drift
		// from the PHP the moment the catalogue's price range changed.
		$slk_price_buckets_js = array();

		foreach ( slk_moments_price_buckets() as $bucket_key => $bucket_bounds ) {
			$slk_price_buckets_js[ $bucket_key ] = array(
				null !== $bucket_bounds['min'] ? (string) $bucket_bounds['min'] : '',
				null !== $bucket_bounds['max'] ? (string) $bucket_bounds['max'] : '',
			);
		}

		wp_add_inline_script(
			'slk-moments',
			'window.slkMoments=' . wp_json_encode(
				array(
					'addedUrl'          => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'slk_added_sheet' ) : '',
					'filterCountUrl'    => admin_url( 'admin-ajax.php' ),
					'filterCountAction' => 'slk_filter_count',
					'priceBuckets'      => $slk_price_buckets_js,
					// The base URL a real form submit resolves against — pushState
					// needs the same base the archive itself would land on, and
					// slk_moments_listing_url() (term-archive aware) is only
					// callable server-side, at this one page-load moment.
					'listingUrl'        => slk_moments_listing_url(),
					/* translators: %s: number of products, singular — the count is already displayed, not a promise of what clicking will reveal. */
					'showingPiece'      => __( 'Showing %s piece', 'slk' ),
					/* translators: %s: number of products, plural — the count is already displayed, not a promise of what clicking will reveal. */
					'showingPieces'     => __( 'Showing %s pieces', 'slk' ),
					'zoomOpen' => __( 'Zoom', 'slk' ),
					'zoomOf'   => __( 'Image %1$s of %2$s', 'slk' ),
					/*
					 * Blocksy renders its own gallery figures, so WooCommerce's
					 * data-caption attribute (filtered above for the stock
					 * gallery) is not present in this DOM. Same sentence, same
					 * source, carried the one other way it can be.
					 */
					'zoomNote' => __( 'Pinch or double-tap to see the weave up close. You cannot feel a fabric through a screen, so this is the next best thing.', 'slk' ),
				)
			) . ';',
			'before'
		);

		$js = <<<'JS'
(function () {
	'use strict';

	var cfg = window.slkMoments || {};
	var desktop = window.matchMedia('(min-width:1000px)');

	/* ── Sheets: open/close, focus trap, scroll lock, Escape ─────────────
	   Modal only while the sheet is actually a sheet. At 1000px the filter
	   panel is a static sidebar, and trapping focus in a sidebar would be a
	   bug, not an accommodation. */
	var openSheet = null;
	var lastTrigger = null;

	function focusables(root) {
		return Array.prototype.filter.call(
			root.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"])'),
			function (el) { return el.offsetWidth || el.offsetHeight || el === document.activeElement; }
		);
	}

	function isModal(sheet) {
		return !(sheet.classList.contains('slk-filterbox') && desktop.matches);
	}

	/* The drawer's position-based lock from inc/chrome.php, borrowed rather
	   than repeated. `overflow:hidden` on <html> stops it being the scroll
	   container, so the browser clamps scrollTop to 0 and the page behind the
	   sheet snaps to the top the instant it opens — the same bug the drawer
	   had. Sharing the pair also keeps one stored offset, so the drawer and
	   this sheet cannot stack two locks. */
	function lockScroll() {
		if (window.slkScrollLock) { window.slkScrollLock.lock(); }
	}

	function unlockScroll() {
		if (window.slkScrollLock) { window.slkScrollLock.unlock(); }
	}

	function open(sheet, trigger) {
		if (!sheet) { return; }
		sheet.removeAttribute('hidden');

		if (trigger) {
			trigger.setAttribute('aria-expanded', 'true');
			lastTrigger = trigger;
		}

		if (!isModal(sheet)) { return; }

		openSheet = sheet;
		lockScroll();

		var first = focusables(sheet)[0];
		if (first) { first.focus(); }
	}

	function close(sheet) {
		if (!sheet) { return; }
		sheet.setAttribute('hidden', '');

		Array.prototype.forEach.call(
			document.querySelectorAll('[data-slk-sheet-open="' + sheet.id + '"]'),
			function (t) { t.setAttribute('aria-expanded', 'false'); }
		);

		if (openSheet === sheet) {
			openSheet = null;
			unlockScroll();
		}

		if (lastTrigger) { lastTrigger.focus(); lastTrigger = null; }
	}

	document.addEventListener('click', function (e) {
		var opener = e.target.closest('[data-slk-sheet-open]');
		if (opener) {
			e.preventDefault();
			open(document.getElementById(opener.getAttribute('data-slk-sheet-open')), opener);
			return;
		}

		var closer = e.target.closest('[data-slk-sheet-close]');
		if (closer) {
			e.preventDefault();
			close(closer.closest('[data-slk-sheet]'));
		}
	});

	document.addEventListener('keydown', function (e) {
		if (!openSheet) { return; }

		if (e.key === 'Escape') { close(openSheet); return; }
		if (e.key !== 'Tab') { return; }

		var items = focusables(openSheet);
		if (!items.length) { return; }

		var first = items[0];
		var last = items[items.length - 1];

		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	});

	/* A sheet left open while the viewport grows past 1000px must stop being
	   modal, or the scroll lock outlives the sheet. */
	if (desktop.addEventListener) {
		desktop.addEventListener('change', function () {
			if (openSheet && !isModal(openSheet)) {
				openSheet = null;
				unlockScroll();
			}
		});
	}

	/* ── Filter form: turn the bucket radio into WooCommerce's price args ──
	   Without this the form still works (template_redirect resolves it), but
	   the very first URL the browser produces is already the canonical,
	   shareable ?min_price=&max_price= form. */
	var form = document.querySelector('.slk-filterbox .slk-filters');

	if (form) {
		// Sourced from PHP (cfg.priceBuckets), not hardcoded here: the buckets
		// are derived from the live catalogue's price range and can change
		// whenever stock does, so a second literal copy in this file would be
		// exactly the bug this fixes, one script tag later.
		var buckets = cfg.priceBuckets || {};

		form.addEventListener('submit', function () {
			var picked = form.querySelector('input[name="slk_price"]:checked');
			var min = form.querySelector('[data-slk-min]');
			var max = form.querySelector('[data-slk-max]');
			var pair = picked ? buckets[picked.value] : ['', ''];

			if (min) { min.value = pair ? pair[0] : ''; min.disabled = !min.value; }
			if (max) { max.value = pair ? pair[1] : ''; max.disabled = !max.value; }

			Array.prototype.forEach.call(
				form.querySelectorAll('input[name="slk_price"]'),
				function (el) { el.disabled = true; }
			);
		});
	}

	/* ── Real-time filtering ────────────────────────────────────────────
	   Runs on every category checkbox or price radio change, debounced so a
	   fast run of clicks fires one request, not one per click. The response
	   comes from slk_moments_ajax_filter_count, which now renders the exact
	   grid/heading/chips the archive's own query would produce for the
	   selection — through the same renderers the PHP template uses — so the
	   count, the cards and the chips can never disagree with a reload of the
	   URL this then pushes. This only ever listens for `change`, never
	   `submit`: the form's own GET submit (wired above) is completely
	   untouched, so a page with no JavaScript — or a request that fails —
	   still filters correctly by a plain page reload, exactly as before. */
	if (form && cfg.filterCountUrl && cfg.filterCountAction && window.fetch && window.URLSearchParams) {
		var submitBtn = form.querySelector('.slk-filters__submit');
		var resultsEl = document.getElementById('slk-shop-results');
		var liveRegion = document.getElementById('slk-shop-live');
		var chipsEl = document.getElementById('slk-filterbar-chips');
		var triggerBtn = document.querySelector('.slk-filterbar__trigger');
		var updateTimer = null;
		var updateAbort = null;
		/* Which request is the current one. An older request that settles
		   late, including one this code aborted, must not touch the page:
		   it would either overwrite a newer result with a stale one, or
		   report the newer request as finished. */
		var updateSeq = 0;
		var ctxTax = form.getAttribute('data-slk-ctx-tax') || '';
		var ctxTerm = form.getAttribute('data-slk-ctx-term') || '';

		var currentSelection = function () {
			var orderbyInput = form.querySelector('input[name="orderby"]');
			var priceInput = form.querySelector('input[name="slk_price"]:checked');

			return {
				cats: Array.prototype.map.call(
					form.querySelectorAll('input[name="product_cat[]"]:checked'),
					function (el) { return el.value; }
				),
				price: priceInput ? priceInput.value : '',
				orderby: orderbyInput ? orderbyInput.value : ''
			};
		};

		/* The same query args a real GET submit would produce: categories as
		   repeated product_cat[] entries (a native checkbox submit never
		   joins them into one value), the bucket resolved to min_price/max_price
		   through cfg.priceBuckets exactly as the submit handler above
		   resolves them, and the current sort carried through unchanged. */
		var buildUrl = function (sel) {
			var params = new URLSearchParams();

			sel.cats.forEach(function (slug) { params.append('product_cat[]', slug); });

			var pair = sel.price ? buckets[sel.price] : null;
			if (pair && pair[0]) { params.set('min_price', pair[0]); }
			if (pair && pair[1]) { params.set('max_price', pair[1]); }
			if (sel.orderby) { params.set('orderby', sel.orderby); }

			var qs = params.toString();
			if (!qs) { return cfg.listingUrl; }

			return cfg.listingUrl + (cfg.listingUrl.indexOf('?') === -1 ? '?' : '&') + qs;
		};

		var setChipBadge = function (n) {
			if (!triggerBtn) { return; }
			var badge = triggerBtn.querySelector('.slk-filterbar__count');

			if (n > 0) {
				if (!badge) {
					badge = document.createElement('span');
					badge.className = 'slk-filterbar__count';
					triggerBtn.appendChild(badge);
				}
				badge.textContent = n.toLocaleString();
			} else if (badge) {
				badge.parentNode.removeChild(badge);
			}
		};

		var setPending = function (pending) {
			if (resultsEl) { resultsEl.setAttribute('aria-busy', pending ? 'true' : 'false'); }
		};

		/* The grid/empty-panel swap is by outerHTML, not innerHTML into a
		   fixed wrapper: a zero-result selection swaps <ul class="products">
		   for a <div class="slk-moments-empty"> panel (never a blank grid —
		   see slk_moments_render_empty_panel()), and [data-slk-grid] is the
		   one attribute both shapes carry. The submit button's label is
		   rewritten to the new count from the same "Showing" strings the PHP
		   already renders it with, so it stays a statement of what is on
		   screen rather than a promise of something the click has not
		   delivered yet (issue C) — the button itself is never disabled or
		   unhooked, so it keeps working as the fallback submit exactly as
		   before. */
		var applyResult = function (data) {
			var gridEl = resultsEl ? resultsEl.querySelector('[data-slk-grid]') : null;
			if (gridEl && typeof data.grid === 'string') { gridEl.outerHTML = data.grid; }

			/* The pager belongs to the query the grid was drawn from. Left
			   alone it would sit a "1 2 Next" from the unfiltered query under
			   a filtered grid, and clicking it would drop the filter. Swapped
			   when the new selection still has pages, removed when it does
			   not, appended when the previous one had none. */
			if (resultsEl && typeof data.pagination === 'string') {
				var pagerEl = resultsEl.querySelector('nav.ct-pagination, nav.woocommerce-pagination');

				if (pagerEl && data.pagination) {
					pagerEl.outerHTML = data.pagination;
				} else if (pagerEl) {
					pagerEl.parentNode.removeChild(pagerEl);
				} else if (data.pagination) {
					resultsEl.insertAdjacentHTML('beforeend', data.pagination);
				}
			}

			var headCount = resultsEl ? resultsEl.querySelector('.slk-shop-head__count') : null;
			if (headCount && typeof data.heading === 'string') { headCount.textContent = data.heading; }

			if (chipsEl && typeof data.chips === 'string') { chipsEl.innerHTML = data.chips; }
			if (typeof data.chipCount === 'number') { setChipBadge(data.chipCount); }

			if (submitBtn && cfg.showingPiece && cfg.showingPieces && typeof data.count === 'number') {
				var tpl = data.count === 1 ? cfg.showingPiece : cfg.showingPieces;
				submitBtn.textContent = tpl.replace('%s', data.count.toLocaleString());
			}

			if (liveRegion && typeof data.heading === 'string') { liveRegion.textContent = data.heading; }
		};

		var requestUpdate = function (pushHistory) {
			if (updateAbort && updateAbort.abort) { updateAbort.abort(); }

			var sel = currentSelection();
			var body = new FormData();
			body.append('action', cfg.filterCountAction);

			sel.cats.forEach(function (slug) { body.append('product_cat[]', slug); });
			if (sel.price) { body.append('slk_price', sel.price); }
			if (sel.orderby) { body.append('orderby', sel.orderby); }

			/* A term archive scopes the results without any input in the form,
			   so the term travels with the request or the response would
			   describe the whole store while the form submits back into the
			   term. */
			if (ctxTax && ctxTerm) {
				body.append('slk_ctx_tax', ctxTax);
				body.append('slk_ctx_term', ctxTerm);
			}

			var seq = ++updateSeq;

			updateAbort = window.AbortController ? new AbortController() : null;
			setPending(true);

			window.fetch(cfg.filterCountUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
				signal: updateAbort ? updateAbort.signal : undefined
			})
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (seq !== updateSeq) { return; }

					if (json && json.success && json.data) {
						applyResult(json.data);

						if (pushHistory) {
							/* One history entry per settled selection, not per
							   click: the 250ms debounce below already coalesces
							   a fast run of clicks into a single request, so a
							   fast run of clicks also produces a single entry —
							   which is what keeps Back from taking several
							   presses to undo one interaction. */
							window.history.pushState({ slkFilter: true }, '', buildUrl(sel));
						}
					}
					// A malformed response is treated the same as a failed
					// one below: the grid, heading and chips keep whatever
					// they already showed.
				})
				.catch(function () {
					// Request failed, or was aborted by a newer request: the
					// previous results stay exactly as they were, and the
					// submit button — never touched by any of this — still
					// submits the form normally.
				})
				.then(function () {
					// Only the newest request may say the page has settled.
					// A superseded one leaves the flag to its replacement.
					if (seq === updateSeq) { setPending(false); }
				});
		};

		form.addEventListener('change', function (e) {
			if (!e.target.matches('input[name="product_cat[]"], input[name="slk_price"]')) { return; }

			if (updateTimer) { window.clearTimeout(updateTimer); }
			updateTimer = window.setTimeout(function () { requestUpdate(true); }, 250);
		});

		/* Back/Forward: restore the form controls to match the URL the
		   browser just navigated to, then re-run the same request — without
		   pushing a new entry, since the browser already moved to this one —
		   so the grid, heading and chips catch up with wherever history
		   landed. */
		window.addEventListener('popstate', function () {
			var params = new URLSearchParams(window.location.search);
			var cats = params.getAll('product_cat[]');

			if (!cats.length && params.has('product_cat')) {
				cats = String(params.get('product_cat')).split(',').filter(function (s) { return s !== ''; });
			}

			Array.prototype.forEach.call(
				form.querySelectorAll('input[name="product_cat[]"]'),
				function (el) { el.checked = cats.indexOf(el.value) !== -1; }
			);

			var min = params.get('min_price') || '';
			var max = params.get('max_price') || '';
			var matchedBucket = '';

			Object.keys(buckets).forEach(function (key) {
				var pair = buckets[key];
				if (pair && pair[0] === min && pair[1] === max) { matchedBucket = key; }
			});

			Array.prototype.forEach.call(
				form.querySelectorAll('input[name="slk_price"]'),
				function (el) { el.checked = (el.value === matchedBucket); }
			);

			if (updateTimer) { window.clearTimeout(updateTimer); }
			requestUpdate(false);
		});
	}

	/* ── Added to bag ────────────────────────────────────────────────────
	   The sheet may already be open and filled by PHP (the no-JS path). This
	   only covers the AJAX add, and asks the server for the same markup. */
	if (cfg.addedUrl && window.jQuery && window.fetch) {
		window.jQuery(document.body).on('added_to_cart', function () {
			// Looked up per event, not once: the drawer is printed after this
			// script on some templates, and a null captured here would be null
			// forever.
			var added = document.getElementById('slk-added');
			if (!added) { return; }

			window.fetch(cfg.addedUrl, { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (!data || !data.html) { return; }

					var body = added.querySelector('[data-slk-added-body]');
					if (!body) { return; }

					body.innerHTML = data.html;
					open(added, null);
				})
				.catch(function () { /* Offline: the bag count fragment still updated. */ });
		});
	}

	/* ── Image zoom ──────────────────────────────────────────────────────
	   WooCommerce's PhotoSwipe, WooCommerce's .pswp markup, WooCommerce's
	   options. Supplied here: the trigger (because Blocksy's gallery markup
	   is not the markup WooCommerce binds to) and the thumbnail strip. */
	var pswpEl = document.querySelector('.pswp');

	function galleryEl() { return document.querySelector('.woocommerce-product-gallery'); }

	if (!galleryEl() || !pswpEl) { return; }

	function ready() {
		return typeof window.PhotoSwipe === 'function' && typeof window.PhotoSwipeUI_Default === 'function';
	}

	function figures() {
		var g = galleryEl();
		return g ? Array.prototype.slice.call(g.querySelectorAll('[data-src]')) : [];
	}

	function items() {
		return figures().map(function (fig) {
			var img = fig.querySelector('img');

			return {
				src: fig.getAttribute('data-src'),
				w: parseInt(fig.getAttribute('data-width'), 10) || 0,
				h: parseInt(fig.getAttribute('data-height'), 10) || 0,
				msrc: img ? img.getAttribute('src') : '',
				alt: img ? img.getAttribute('alt') : '',
				title: (img && img.getAttribute('data-caption')) || cfg.zoomNote || ''
			};
		}).filter(function (i) { return i.src; });
	}

	var title = (document.querySelector('.product_title') || {}).textContent || '';
	var strip = null;

	function buildStrip(pswp, list) {
		if (list.length < 2) { return; }

		var ui = pswpEl.querySelector('.pswp__ui') || pswpEl;
		strip = document.createElement('div');
		strip.className = 'slk-zoom__thumbs';

		list.forEach(function (item, i) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'slk-zoom__thumb';
			b.setAttribute('aria-label', (cfg.zoomOf || '%1$s / %2$s').replace('%1$s', i + 1).replace('%2$s', list.length));
			b.innerHTML = '<img src="' + (item.msrc || item.src) + '" alt="">';
			b.addEventListener('click', function () { pswp.goTo(i); });
			strip.appendChild(b);
		});

		ui.appendChild(strip);
	}

	function markStrip(index) {
		// "2 of 4 · Nayana Linen Abaya": PhotoSwipe writes the "2 of 4",
		// the product name is appended from the element's own attribute.
		var counter = pswpEl.querySelector('.pswp__counter');

		if (counter && title) {
			counter.setAttribute('data-slk-title', ' · ' + title);
		}

		if (!strip) { return; }

		Array.prototype.forEach.call(strip.children, function (el, i) {
			el.setAttribute('aria-current', i === index ? 'true' : 'false');
		});
	}

	function openZoom(index) {
		var list = items();

		if (!list.length || !ready()) { return; }

		var options = window.jQuery
			? window.jQuery.extend({}, (window.wc_single_product_params || {}).photoswipe_options || {}, { index: index || 0 })
			: { index: index || 0 };

		var pswp = new window.PhotoSwipe(pswpEl, window.PhotoSwipeUI_Default, list, options);

		pswp.listen('afterInit', function () {
			buildStrip(pswp, list);
			markStrip(pswp.getCurrentIndex());
			pswpEl.focus();
		});
		pswp.listen('afterChange', function () { markStrip(pswp.getCurrentIndex()); });
		pswp.listen('destroy', function () {
			if (strip && strip.parentNode) { strip.parentNode.removeChild(strip); }
			strip = null;
			if (trigger) { trigger.focus(); }
		});

		pswp.init();
	}

	/* Delegated, because Blocksy re-renders the gallery node after load and a
	   listener bound to the original element would be thrown away with it. */
	var trigger = document.createElement('button');
	trigger.type = 'button';
	trigger.className = 'slk-zoom__open';
	trigger.setAttribute('aria-label', cfg.zoomOpen || 'Zoom');
	trigger.innerHTML = '<span aria-hidden="true">＋</span>';

	document.addEventListener('click', function (e) {
		if (e.target.closest('.slk-zoom__open')) {
			e.preventDefault();
			openZoom(0);
			return;
		}

		var g = galleryEl();
		var fig = e.target.closest('[data-src]');

		if (!g || !fig || !g.contains(fig)) { return; }

		e.preventDefault();
		openZoom(figures().indexOf(fig));
	});

	// The trigger is (re)placed once the parent theme has finished with the
	// gallery, and put back if a later re-render drops it.
	function placeTrigger() {
		var g = galleryEl();

		if (g && trigger.parentNode !== g) { g.appendChild(trigger); }
	}

	window.addEventListener('load', function () {
		placeTrigger();

		var host = galleryEl() && galleryEl().parentNode;

		if (host && window.MutationObserver) {
			new window.MutationObserver(placeTrigger).observe(host, { childList: true, subtree: true });
		}
	});
})();
JS;

		wp_add_inline_script( 'slk-moments', $js );
	},
	30
);
