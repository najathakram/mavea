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
 * The price buckets from 02-moments.html:19-23, as real min/max price args.
 *
 * The boundaries are the design's; the rendering of them is wc_price(), so the
 * "Rs." prefix, the comma thousands and the zero decimals all come from the
 * store's own currency settings and never from a typed symbol.
 *
 * @return array<string,array{min:int|null,max:int|null,label:string}>
 */
function slk_moments_price_buckets() {
	$under = 5000;
	$over  = 10000;

	return array(
		'under' => array(
			'min'   => null,
			'max'   => $under,
			/* translators: %s: a formatted price, e.g. Rs. 5,000. */
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
			/* translators: %s: a formatted price, e.g. Rs. 10,000. */
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
 * bottom sheet over a scrim; at 1000px it becomes the static 240px sidebar that
 * style.css §3.7 (`.slk-shop-layout > .slk-filters`) already has rules for.
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
					printf(
						/* translators: %s: number of products. */
						esc_html( _n( 'Show %s piece', 'Show %s pieces', $total, 'slk' ) ),
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
	<div class="slk-filterbar">
		<button type="button" class="slk-btn slk-btn--primary slk-filterbar__trigger"
			data-slk-sheet-open="slk-filters"
			aria-controls="slk-filters"
			aria-expanded="false">
			<?php esc_html_e( 'Filters', 'slk' ); ?>
			<?php if ( $count ) : ?>
				<span class="slk-filterbar__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
			<?php endif; ?>
		</button>

		<?php if ( $chips ) : ?>
			<div class="slk-chips">
				<?php foreach ( $chips as $chip ) : ?>
					<a class="slk-chip" href="<?php echo esc_url( $chip['url'] ); ?>">
						<?php echo esc_html( $chip['label'] ); ?>
						<span aria-hidden="true">✕</span>
						<span class="screen-reader-text"><?php esc_html_e( 'Remove this filter', 'slk' ); ?></span>
					</a>
				<?php endforeach; ?>
				<a class="slk-btn slk-btn--ghost" href="<?php echo esc_url( slk_moments_listing_url() ); ?>"><?php esc_html_e( 'Clear all', 'slk' ); ?></a>
			</div>
		<?php endif; ?>
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
 * AJAX: how many products a candidate filter selection matches, before the
 * form is submitted.
 *
 * The query is the archive's own: same status, same category args, the
 * archive's own term when the listing is a term archive, and the archive's own
 * price clause, so the number handed back can never disagree with what
 * submitting the form would actually produce. Registered for both wp_ajax_ and
 * wp_ajax_nopriv_ because most shoppers on the shop page are not logged in.
 */
function slk_moments_ajax_filter_count() {
	if ( ! function_exists( 'wc_get_products' ) ) {
		wp_send_json_error( array(), 500 );
	}

	// A read-only count of already-public product data; nothing here is
	// written or disclosed beyond the same number the archive itself shows.
	// The endpoint answers unauthenticated callers, so only scalars are handed
	// to sanitize_title() (an array element would raise a TypeError inside it)
	// and the list of slugs is capped.
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
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$ctx_tax = isset( $_POST['slk_ctx_tax'] ) ? sanitize_key( wp_unslash( $_POST['slk_ctx_tax'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	$raw_term = isset( $_POST['slk_ctx_term'] ) && is_scalar( $_POST['slk_ctx_term'] ) ? wp_unslash( $_POST['slk_ctx_term'] ) : '';
	$ctx_term = sanitize_title( $raw_term );

	$args = array(
		'status'   => 'publish',
		'limit'    => -1,
		'return'   => 'ids',
		// Pinned, because the default also counts variations.
		'type'     => array_keys( wc_get_product_types() ),
		'category' => $categories, // array of slugs, empty for all
	);

	if ( $ctx_tax && $ctx_term && in_array( $ctx_tax, get_object_taxonomies( 'product' ), true ) ) {
		$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the archive runs the same clause.
			array(
				'taxonomy' => $ctx_tax,
				'field'    => 'slug',
				'terms'    => array( $ctx_term ),
			),
		);
	}

	$clause = null;

	if ( $min > 0 || $max > 0 ) {
		$clause = slk_moments_price_clause( $min, $max > 0 ? $max : PHP_INT_MAX );
		add_filter( 'posts_clauses', $clause );
	}

	$count = count( (array) wc_get_products( $args ) );

	if ( $clause ) {
		remove_filter( 'posts_clauses', $clause );
	}

	wp_send_json_success( array( 'count' => $count ) );
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
		echo '<div class="slk-shop-results">';
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
 * Print the empty-result panel.
 */
function slk_moments_no_products_found() {
	if ( ! slk_moments_is_listing() ) {
		wc_get_template( 'loop/no-products-found.php' );
		return;
	}

	$bucket = slk_moments_active_price_bucket();
	$cats   = slk_moments_active_cats();

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
	?>
	<div class="slk-panel slk-moments-empty">
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
		<a class="slk-btn slk-btn--secondary" href="<?php echo esc_url( slk_moments_listing_url() ); ?>"><?php esc_html_e( 'Clear filters', 'slk' ); ?></a>
	</div>
	<?php
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
	$fees = array_filter( array_map( 'floatval', (array) $fees ) );

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

		wp_add_inline_script(
			'slk-moments',
			'window.slkMoments=' . wp_json_encode(
				array(
					'addedUrl'          => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'slk_added_sheet' ) : '',
					'filterCountUrl'    => admin_url( 'admin-ajax.php' ),
					'filterCountAction' => 'slk_filter_count',
					/* translators: %s: number of products, singular. */
					'showPiece'         => __( 'Show %s piece', 'slk' ),
					/* translators: %s: number of products, plural. */
					'showPieces'        => __( 'Show %s pieces', 'slk' ),
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

	function open(sheet, trigger) {
		if (!sheet) { return; }
		sheet.removeAttribute('hidden');

		if (trigger) {
			trigger.setAttribute('aria-expanded', 'true');
			lastTrigger = trigger;
		}

		if (!isModal(sheet)) { return; }

		openSheet = sheet;
		document.documentElement.style.overflow = 'hidden';

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
			document.documentElement.style.overflow = '';
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
				document.documentElement.style.overflow = '';
			}
		});
	}

	/* ── Filter form: turn the bucket radio into WooCommerce's price args ──
	   Without this the form still works (template_redirect resolves it), but
	   the very first URL the browser produces is already the canonical,
	   shareable ?min_price=&max_price= form. */
	var form = document.querySelector('.slk-filterbox .slk-filters');

	if (form) {
		var buckets = { under: ['', '5000'], mid: ['5000', '10000'], over: ['10000', ''] };

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

	/* ── Filter count: keep the submit button's number live ────────────────
	   Runs on every category checkbox or price radio change, debounced so a
	   fast run of clicks fires one request, not one per click. The count
	   comes from slk_moments_ajax_filter_count, which runs the same
	   wc_get_products query the archive itself runs, so the number never
	   promises a result the grid cannot deliver. On any failure the button
	   is left showing whatever count it already had, never a wrong one. */
	if (form && cfg.filterCountUrl && cfg.filterCountAction) {
		var countBtn = form.querySelector('.slk-filters__submit');
		var countTimer = null;
		var countAbort = null;
		/* Which request is the current one. An older request that settles late,
		   including one this code aborted, must not touch the button: it would
		   either write a stale number or report the newer request as finished. */
		var countSeq = 0;
		var ctxTax = form.getAttribute('data-slk-ctx-tax');
		var ctxTerm = form.getAttribute('data-slk-ctx-term');

		var setCount = function (n) {
			if (!countBtn) { return; }
			var tpl = n === 1 ? cfg.showPiece : cfg.showPieces;
			if (!tpl) { return; }
			countBtn.textContent = tpl.replace('%s', n.toLocaleString());
		};

		var requestCount = function () {
			if (!window.fetch) { return; }
			if (countAbort && countAbort.abort) { countAbort.abort(); }

			var body = new FormData();
			body.append('action', cfg.filterCountAction);

			Array.prototype.forEach.call(
				form.querySelectorAll('input[name="product_cat[]"]:checked'),
				function (el) { body.append('product_cat[]', el.value); }
			);

			var price = form.querySelector('input[name="slk_price"]:checked');
			if (price) { body.append('slk_price', price.value); }

			/* A term archive scopes the results without any input in the form,
			   so the term travels with the request or the count would be the
			   whole store while the form submits back into the term. */
			if (ctxTax && ctxTerm) {
				body.append('slk_ctx_tax', ctxTax);
				body.append('slk_ctx_term', ctxTerm);
			}

			var seq = ++countSeq;

			countAbort = window.AbortController ? new AbortController() : null;
			if (countBtn) { countBtn.setAttribute('aria-busy', 'true'); }

			window.fetch(cfg.filterCountUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
				signal: countAbort ? countAbort.signal : undefined
			})
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (seq !== countSeq) { return; }

					if (json && json.success && json.data && typeof json.data.count === 'number') {
						setCount(json.data.count);
					}
					// A malformed response is treated the same as a failed
					// one: the button keeps whatever count it already had.
				})
				.catch(function () {
					// Request failed, or was aborted by a newer request:
					// same rule, the last known good count stays put.
				})
				.then(function () {
					// Only the newest request may say the button is settled.
					// A superseded one leaves the flag to its replacement.
					if (countBtn && seq === countSeq) { countBtn.setAttribute('aria-busy', 'false'); }
				});
		};

		form.addEventListener('change', function (e) {
			if (!e.target.matches('input[name="product_cat[]"], input[name="slk_price"]')) { return; }

			if (countTimer) { window.clearTimeout(countTimer); }
			countTimer = window.setTimeout(requestCount, 250);
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
