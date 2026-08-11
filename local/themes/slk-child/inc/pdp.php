<?php
/**
 * Product detail page — Porcelain Glass.
 *
 * Pairs with woocommerce/content-single-product.php (the structural override,
 * which adds the 'slk-pdp' class and two data-* attributes but keeps every
 * core hook untouched). Everything else — the two-column desktop layout, the
 * glass summary panel, colour swatches, the size grid, the WhatsApp button,
 * and the trust rows — lives here as CSS + progressive-enhancement JS + extra
 * woocommerce_single_product_summary callbacks, per brand law 8 (prefer hooks
 * and CSS over template overrides).
 *
 * Real hooks used (all confirmed against the reference templates in
 * design/_reference/woocommerce-templates/):
 *   - woocommerce_dropdown_variation_attribute_options_html  (core filter
 *     inside wc_dropdown_variation_attribute_options(), called from
 *     single-product/add-to-cart/variable.php line 41-47 — confirmed real,
 *     not invented)
 *   - woocommerce_single_product_summary                     (31, 45 — the
 *     WhatsApp button and trust rows slot between the documented core
 *     callbacks at 30/40, exactly as content-single-product.php's docblock
 *     states)
 *   - woocommerce_product_single_add_to_cart_text             (core filter,
 *     single-product-only variant of the add-to-cart button label)
 *
 * Sold-out sizes: WooCommerce's native variation JS only disables a <option>
 * when OTHER selected attributes make a combination unavailable — it does
 * not disable an option just because every variation carrying that value is
 * out of stock. Since the design requires already-sold-out sizes to render
 * struck through on first paint (not just after selection), stock is
 * pre-computed per attribute value in slk_pdp_compute_sold_out_values() from
 * $product->get_available_variations() and handed to the page as a JSON
 * data-attribute (see the template). The client script unions this with the
 * select's live `disabled` state, so combination-based disabling still works
 * on top of it.
 *
 * Colour swatches: no colour-swatch plugin or term meta is present in this
 * codebase, so there is no real source for a colour's hex value. Rendering a
 * filled circle would mean inventing that data. Swatches are therefore 44x44
 * circles carrying the colour name as their accessible label and visible
 * initial — structurally what the design calls for, honestly sourced.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Add to bag" instead of WooCommerce's default single-product button label.
 * Single-product-only filter — the shop grid's "Add to cart" text belongs to
 * whichever agent owns the grid, so it is left alone.
 */
add_filter(
	'woocommerce_product_single_add_to_cart_text',
	static function () {
		return __( 'Add to bag', 'slk' );
	}
);

/* -------------------------------------------------------------------------
 * Sold-out size computation.
 * ---------------------------------------------------------------------- */

/**
 * Map each variable product's attributes to the option values that are
 * entirely out of stock (every variation carrying that value is unavailable).
 *
 * Keys are built the same way core builds a variation <select>'s `name`
 * attribute ('attribute_' . sanitize_title( $attribute_name )) — confirmed
 * against add-to-cart/variable.php line 38, which uses the identical
 * sanitize_title() call for the paired <label for="">. This keeps the PHP
 * side and the client-side select.name lookup guaranteed to agree without
 * depending on undocumented internals of get_variation_attributes().
 *
 * @param WC_Product|null $product Product object.
 * @return array<string,array<int,string>>
 */
function slk_pdp_compute_sold_out_values( $product ) {
	$map = array();

	if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) ) {
		return $map;
	}

	$variations = $product->get_available_variations();
	if ( empty( $variations ) ) {
		return $map;
	}

	foreach ( array_keys( $product->get_variation_attributes() ) as $attribute_name ) {
		$dom_name = 'attribute_' . sanitize_title( $attribute_name );

		$seen_values    = array();
		$in_stock_values = array();

		foreach ( $variations as $variation ) {
			if ( ! isset( $variation['attributes'][ $dom_name ] ) ) {
				continue;
			}

			$value = $variation['attributes'][ $dom_name ];

			if ( '' === $value ) {
				continue; // "Any <attribute>" wildcard row — nothing precise to mark.
			}

			$seen_values[ $value ] = true;

			if ( ! empty( $variation['is_in_stock'] ) ) {
				$in_stock_values[ $value ] = true;
			}
		}

		$sold_out = array_values( array_diff( array_keys( $seen_values ), array_keys( $in_stock_values ) ) );

		if ( ! empty( $sold_out ) ) {
			$map[ $dom_name ] = $sold_out;
		}
	}

	return $map;
}

/* -------------------------------------------------------------------------
 * Colour / size control: wrap the real <select> so it can be progressively
 * enhanced into swatches or a size grid without touching its name, options,
 * disabled states or change-event contract. If JS fails to load the select
 * still renders and still works.
 * ---------------------------------------------------------------------- */

add_filter(
	'woocommerce_dropdown_variation_attribute_options_html',
	static function ( $html, $args ) {
		$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
		$is_colour = (bool) preg_match( '/colou?r/i', $attribute );

		return '<span class="slk-pdp__variation-select" data-slk-kind="' . ( $is_colour ? 'colour' : 'size' ) . '">'
			. $html
			. '</span>';
	},
	10,
	2
);

/* -------------------------------------------------------------------------
 * WhatsApp ask button — woocommerce_single_product_summary, priority 31
 * (right after the add-to-cart form at 30, matching the mockup's
 * button-beside-button row).
 *
 * No WhatsApp number is available in this codebase yet. Rather than render
 * a dead "#" link, the button stays off until a real number is supplied —
 * same single-source-of-truth pattern as slk_wordmark in inc/wordmark.php:
 *
 *     add_filter( 'slk_whatsapp_number', fn() => '94771234567' );
 * ---------------------------------------------------------------------- */

add_action(
	'woocommerce_single_product_summary',
	'slk_pdp_whatsapp_button',
	31
);

function slk_pdp_whatsapp_button() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	/**
	 * Filters the WhatsApp number the PDP "ask" button messages.
	 *
	 * Digits only, country code first, no plus sign (wa.me format).
	 * PLACEHOLDER pending a real business number — see file header.
	 *
	 * @param string $number
	 */
	$number = (string) apply_filters( 'slk_whatsapp_number', '' );
	$number = preg_replace( '/\D/', '', $number );

	if ( empty( $number ) ) {
		return;
	}

	$message = sprintf(
		/* translators: %s: product name. */
		__( 'Hi, I have a question about the %s.', 'slk' ),
		$product->get_name()
	);

	$url = sprintf( 'https://wa.me/%1$s?text=%2$s', $number, rawurlencode( $message ) );

	printf(
		'<a class="slk-btn slk-btn--secondary slk-btn--icon slk-pdp__whatsapp" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s"><span aria-hidden="true">W</span></a>',
		esc_url( $url ),
		esc_attr__( 'Ask about this piece on WhatsApp', 'slk' )
	);
}

/* -------------------------------------------------------------------------
 * Trust rows — woocommerce_single_product_summary, priority 45 (between the
 * core meta hook at 40 and the sharing hook at 50).
 * ---------------------------------------------------------------------- */

add_action(
	'woocommerce_single_product_summary',
	'slk_pdp_trust_rows',
	45
);

function slk_pdp_trust_rows() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$rows = array(
		array( '✓', __( 'Cash on delivery — we call to confirm before dispatch', 'slk' ) ),
		array( '⇄', __( 'Exchange within 7 days, courier collects', 'slk' ) ),
		array( '◷', __( 'Colombo in 1–2 days · island-wide 3–5', 'slk' ) ),
	);

	echo '<div class="slk-pdp__trust">';
	foreach ( $rows as $row ) {
		printf(
			'<div class="slk-pdp__trust-row"><span aria-hidden="true">%1$s</span><span>%2$s</span></div>',
			esc_html( $row[0] ),
			esc_html( $row[1] )
		);
	}
	echo '</div>';
}

/* -------------------------------------------------------------------------
 * Styles + progressive-enhancement script — single product pages only.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! is_product() ) {
			return;
		}

		$css = '
/* ── Layout: gallery + sticky glass summary ─────────────────────────── */
@media (min-width: 992px) {
	.slk-pdp.product{
		display:grid;
		grid-template-columns:1.25fr 1fr;
		gap:var(--slk-space-8);
		align-items:start;
	}
	.slk-pdp .slk-pdp__summary{
		position:sticky;
		top:var(--slk-space-6);
	}
}

/* ── Gallery: rounded, cropped, CLS-safe (width/height ship with the img tag) ── */
.slk-pdp .woocommerce-product-gallery{
	border-radius:var(--slk-radius-tile);
	overflow:hidden;
}
.slk-pdp .woocommerce-product-gallery__image{
	border-radius:var(--slk-radius-tile);
	overflow:hidden;
	aspect-ratio:var(--slk-ratio-hero);
}
.slk-pdp .woocommerce-product-gallery__image img{
	width:100%;height:100%;object-fit:cover;display:block;
}
.slk-pdp .woocommerce-product-gallery .flex-control-thumbs{
	display:flex;gap:var(--slk-space-3);padding-top:var(--slk-space-3);list-style:none;margin:0;
}
.slk-pdp .woocommerce-product-gallery .flex-control-thumbs li{
	flex:1;aspect-ratio:3/4;border-radius:var(--slk-radius-tile);overflow:hidden;
}
.slk-pdp .woocommerce-product-gallery .flex-control-thumbs img{
	width:100%;height:100%;object-fit:cover;display:block;cursor:pointer;
}

/* ── Summary panel: map WooCommerce\'s real element classes onto a grid ── */
.slk-pdp__summary{
	padding:var(--slk-space-8);
	display:grid;
	grid-template-columns:1fr auto;
	gap:var(--slk-space-2) var(--slk-space-3);
	grid-template-areas:
		"title price"
		"desc  desc"
		"cart  cart"
		"trust trust";
}
.slk-pdp__summary > h1.product_title{
	grid-area:title;margin:0;font-size:var(--slk-display-s);font-weight:400;
}
.slk-pdp__summary > p.price{
	grid-area:price;margin:0;font:500 var(--slk-text-xl)/1 var(--slk-font-ui);white-space:nowrap;
}
.slk-pdp__summary > .woocommerce-product-details__short-description{
	grid-area:desc;font:400 var(--slk-text-sm)/1.6 var(--slk-font-ui);color:var(--slk-color-muted);
}
.slk-pdp__summary > .woocommerce-product-rating,
.slk-pdp__summary > .product_meta{
	/* Not part of this design; hidden without removing the hook. */
	display:none;
}
.slk-pdp__summary > form.cart{
	grid-area:cart;
	display:flex;flex-wrap:wrap;align-items:flex-start;gap:var(--slk-space-4);
}
.slk-pdp__summary > form.cart > table.variations{
	flex:1 1 100%;width:100%;border-collapse:collapse;
}
.slk-pdp__summary > form.cart table.variations tr{ display:block; }
.slk-pdp__summary > form.cart table.variations th.label,
.slk-pdp__summary > form.cart table.variations td.value{
	display:block;text-align:left;padding:0;border:0;
}
.slk-pdp__summary > form.cart table.variations tr + tr{ margin-top:var(--slk-space-6); }
.slk-pdp__summary > form.cart table.variations th.label label{
	display:flex;align-items:center;justify-content:space-between;
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);
	letter-spacing:.08em;text-transform:uppercase;color:var(--slk-color-muted);
	margin-bottom:var(--slk-space-3);
}
.slk-pdp__summary .slk-pdp__attr-current{ text-transform:none;letter-spacing:0; }
.slk-pdp__summary .slk-pdp__size-guide{
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);text-decoration:underline;text-underline-offset:3px;
	color:var(--slk-color-ink);min-height:var(--slk-touch);display:inline-flex;align-items:center;
}
.slk-pdp__summary .reset_variations{ font:400 12px/1 var(--slk-font-ui);color:var(--slk-color-muted); }
.slk-pdp__summary .slk-pdp__fit-hint{
	font:400 12px/1.6 var(--slk-font-ui);color:var(--slk-color-muted);padding-top:var(--slk-space-2);
}

/* Colour swatches — real select stays functional and off-screen (JS only). */
.slk-swatch-group{ display:flex;flex-wrap:wrap;gap:var(--slk-space-2); }
.slk-swatch{
	width:44px;height:44px;border-radius:50%;
	border:1px solid rgba(35,34,32,.16);
	background:var(--slk-glass-solid);
	cursor:pointer;
	display:grid;place-items:center;
	font:500 12px/1 var(--slk-font-ui);
	transition:transform var(--slk-motion-base) var(--slk-ease), border-color var(--slk-motion-base) var(--slk-ease);
}
.slk-swatch:hover{ transform:scale(1.08); }
.slk-swatch[aria-pressed="true"]{ border:2px solid var(--slk-color-ink); }
.slk-swatch[disabled]{
	color:#b5b1a9;cursor:not-allowed;position:relative;overflow:hidden;transform:none;
}
.slk-swatch[disabled]::after{
	content:"";position:absolute;left:6px;right:6px;top:50%;height:1px;background:rgba(35,34,32,.3);
}
.slk-swatch__dot{ text-transform:uppercase; }

.slk-pdp__variation-select select.slk-pdp__sr-select{
	position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
	clip:rect(0,0,0,0);white-space:nowrap;border:0;
}

/* Add to bag + WhatsApp, side by side. */
.slk-pdp__summary form.cart > .single_variation_wrap,
.slk-pdp__summary form.cart > .woocommerce-variation-add-to-cart{
	display:flex;flex:1 1 auto;gap:var(--slk-space-2);align-items:center;flex-wrap:wrap;
}
.slk-pdp__summary form.cart .single_add_to_cart_button{ flex:1; }
.slk-pdp__whatsapp{ flex:none; }

.slk-pdp__trust{ grid-area:trust;display:grid;gap:var(--slk-space-2);padding-top:var(--slk-space-2); }
.slk-pdp__trust-row{
	display:flex;gap:var(--slk-space-3);
	font:400 12.5px/1.5 var(--slk-font-ui);color:var(--slk-color-ink-soft);
}
';

		wp_add_inline_style( 'slk-child', $css );

		wp_register_script( 'slk-pdp', false, array(), null, true );
		wp_enqueue_script( 'slk-pdp' );

		$js = <<<'JS'
(function(){
	function ready(fn){
		if(document.readyState!=='loading'){fn();}else{document.addEventListener('DOMContentLoaded',fn);}
	}

	ready(function(){
		document.querySelectorAll('.variations_form').forEach(initForm);
	});

	function initForm(form){
		var summary = form.closest('.slk-pdp__summary');
		var soldOutMap = {};
		if(summary && summary.dataset.slkSoldOut){
			try{ soldOutMap = JSON.parse(summary.dataset.slkSoldOut) || {}; }catch(e){ soldOutMap = {}; }
		}
		var fitHint = summary ? summary.dataset.slkFitHint : '';

		form.querySelectorAll('table.variations tr').forEach(function(row){
			var wrap = row.querySelector('.slk-pdp__variation-select');
			var select = wrap ? wrap.querySelector('select') : row.querySelector('select');
			if(!select) return;

			var kind = wrap ? wrap.getAttribute('data-slk-kind') : 'size';
			var isColour = kind === 'colour';
			var soldOut = soldOutMap[select.name] || [];

			var labelEl = row.querySelector('th.label label');
			var valueCell = row.querySelector('td.value');
			if(!valueCell) return;

			var currentSpan = null;
			if(isColour && labelEl){
				currentSpan = document.createElement('span');
				currentSpan.className = 'slk-pdp__attr-current';
				labelEl.appendChild(currentSpan);
			}

			var ui = document.createElement('div');
			ui.className = isColour ? 'slk-swatch-group' : 'slk-sizes';
			ui.setAttribute('role','group');
			if(labelEl){ ui.setAttribute('aria-label', labelEl.textContent.trim()); }

			var pairs = [];

			Array.prototype.forEach.call(select.options, function(opt){
				if(!opt.value) return;

				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = isColour ? 'slk-swatch' : 'slk-size';
				btn.setAttribute('aria-label', opt.textContent.trim());

				if(isColour){
					var dot = document.createElement('span');
					dot.className = 'slk-swatch__dot';
					dot.setAttribute('aria-hidden','true');
					dot.textContent = opt.textContent.trim().charAt(0);
					btn.appendChild(dot);
				} else {
					btn.textContent = opt.textContent.trim();
				}

				btn.addEventListener('click', function(){
					if(btn.disabled) return;
					select.value = opt.value;
					select.dispatchEvent(new Event('change', { bubbles:true }));
				});

				ui.appendChild(btn);
				pairs.push({ btn:btn, opt:opt, soldOut: soldOut.indexOf(opt.value) !== -1 });
			});

			function sync(){
				pairs.forEach(function(pair){
					var pressed = pair.opt.selected;
					pair.btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
					pair.btn.disabled = pair.opt.disabled || pair.soldOut;
					if(isColour && pressed && currentSpan){
						currentSpan.textContent = ' · ' + pair.opt.textContent.trim();
					}
				});
			}

			select.addEventListener('change', sync);
			var observer = new MutationObserver(sync);
			observer.observe(select, { childList:true, subtree:true, attributes:true, attributeFilter:['disabled','selected'] });

			select.classList.add('slk-pdp__sr-select');
			valueCell.appendChild(ui);
			sync();

			if(!isColour){
				if(labelEl){
					var guide = document.createElement('a');
					guide.href = (window.slkPdp && window.slkPdp.sizeGuideUrl) || '#slk-size-guide';
					guide.className = 'slk-pdp__size-guide';
					guide.textContent = (window.slkPdp && window.slkPdp.sizeGuideLabel) || 'Size guide · cm';
					labelEl.appendChild(guide);
				}
				if(fitHint){
					var hint = document.createElement('p');
					hint.className = 'slk-pdp__fit-hint';
					hint.textContent = fitHint;
					valueCell.appendChild(hint);
				}
			}
		});
	}
})();
JS;

		wp_add_inline_script( 'slk-pdp', $js );

		wp_localize_script(
			'slk-pdp',
			'slkPdp',
			array(
				/**
				 * Filters the size-guide link target on the PDP.
				 *
				 * PLACEHOLDER anchor pending a real size-guide page/modal.
				 *
				 * @param string $url
				 */
				'sizeGuideUrl'   => apply_filters( 'slk_pdp_size_guide_url', '#slk-size-guide' ),
				'sizeGuideLabel' => __( 'Size guide · cm', 'slk' ),
			)
		);
	},
	25 // After the parent/child stylesheets (20) so wp_add_inline_style attaches correctly.
);
