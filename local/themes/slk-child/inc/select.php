<?php
/**
 * The house dropdown.
 *
 * Two things used to draw dropdowns on this site and neither matched the
 * design: the operating system's own <select> popup (square, system font, blue
 * highlight) and select2, which WooCommerce loads for the district field.
 *
 * This file retires both in favour of one component — assets/js/select.js
 * plus the glass panel below. select2 is dequeued rather than restyled;
 * WooCommerce guards every call with `if ( $().selectWoo )`, so the country and
 * district fields quietly fall back to plain <select>s, which the enhancer then
 * picks up like any other. One dropdown, one behaviour, one look.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drop select2/selectWoo on the front end.
 *
 * Admin keeps it — the product and order screens genuinely need its search.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( is_admin() ) {
			return;
		}

		wp_dequeue_script( 'selectWoo' );
		wp_dequeue_script( 'select2' );
		wp_dequeue_style( 'select2' );
	},
	99
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( is_admin() ) {
			return;
		}

		$rel  = '/assets/js/select.js';
		$path = get_stylesheet_directory() . $rel;

		if ( ! file_exists( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'slk-select',
			get_stylesheet_directory_uri() . $rel,
			array(),
			slk_asset_version( $path ),
			true
		);

		wp_add_inline_style( 'slk-child', slk_select_css() );
	},
	25
);

/**
 * Panel styling.
 *
 * The trigger deliberately inherits the field skin already defined in
 * style.css §3 so a closed dropdown looks exactly as it did; only the open
 * state is new.
 *
 * @return string
 */
function slk_select_css() {
	return '
/* ── The house dropdown ────────────────────────────────────────────────── */
.slk-dd{position:relative;display:block;width:100%}

/* The native control stays in the form and keeps submitting. It is hidden
   without display:none so a required field is still focusable when the browser
   reports a validation error against it. */
.slk-dd__native{
	position:absolute;width:1px;height:1px;min-height:0;padding:0;margin:-1px;
	border:0;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap;
	opacity:0;pointer-events:none;
}

.slk-dd__button{
	display:flex;align-items:center;gap:var(--slk-space-3);
	width:100%;min-height:48px;box-sizing:border-box;
	padding:0 var(--slk-space-4);
	border:1px solid var(--slk-field-border);
	border-radius:var(--slk-radius-field);
	background:var(--slk-color-white,#fff);
	font:400 14px/1.4 var(--slk-font-ui);
	color:var(--slk-color-ink);
	text-align:left;cursor:pointer;
	transition:border-color var(--slk-motion-base) var(--slk-ease),
	           box-shadow var(--slk-motion-base) var(--slk-ease);
}
.slk-dd__button:hover{border-color:rgba(35,34,32,.28)}
.slk-dd__button:disabled{opacity:.55;cursor:not-allowed}
.slk-dd__button.is-placeholder .slk-dd__value{color:var(--slk-color-faint)}
.slk-dd__value{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.slk-dd__caret{
	flex:none;color:var(--slk-color-muted);
	transition:transform var(--slk-motion-base) var(--slk-ease);
}
.slk-dd.is-open .slk-dd__caret{transform:rotate(180deg)}
.slk-dd.is-open .slk-dd__button{border-color:rgba(35,34,32,.34)}

/* The sort control is a pill, not a field. */
.woocommerce-ordering .slk-dd__button{
	min-height:var(--slk-touch);
	border-radius:var(--slk-radius-pill);
	padding-inline:20px var(--slk-space-4);
	background:var(--slk-glass-solid);
	backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	-webkit-backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	border-color:var(--slk-glass-edge);
}
.woocommerce-ordering .slk-dd{width:auto;min-width:210px}

/* ── The panel ─────────────────────────────────────────────────────────── */
.slk-dd__panel{
	position:absolute;z-index:1200;
	box-sizing:border-box;
	padding:var(--slk-space-2);
	background:var(--slk-glass);
	backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	-webkit-backdrop-filter:blur(var(--slk-blur)) saturate(1.4);
	border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-tile);
	box-shadow:var(--slk-shadow-float);
	overflow-y:auto;overscroll-behavior:contain;
	scrollbar-width:thin;scrollbar-color:rgba(35,34,32,.20) transparent;
	opacity:0;transform:translateY(-6px) scale(.985);transform-origin:top center;
	transition:opacity var(--slk-motion-fast) var(--slk-ease),
	           transform var(--slk-motion-base) var(--slk-ease);
}
.slk-dd__panel--up{transform-origin:bottom center;transform:translateY(6px) scale(.985)}
.slk-dd__panel.is-shown{opacity:1;transform:translateY(0) scale(1)}
.slk-dd__panel[hidden]{display:none}

/* Chrome/Safari draw a full-width grey trough otherwise, which is the one
   square-edged thing left on the panel. */
.slk-dd__panel::-webkit-scrollbar{width:10px}
.slk-dd__panel::-webkit-scrollbar-track{background:transparent}
.slk-dd__panel::-webkit-scrollbar-thumb{
	background:rgba(35,34,32,.20);border-radius:var(--slk-radius-pill);
	border:3px solid transparent;background-clip:content-box;
}
.slk-dd__panel::-webkit-scrollbar-thumb:hover{background:rgba(35,34,32,.32);background-clip:content-box}

.slk-dd__list{list-style:none;margin:0;padding:0}
.slk-dd__option{
	display:flex;align-items:center;
	min-height:var(--slk-touch);
	padding:0 var(--slk-space-3);
	border-radius:12px;
	font:400 14px/1.35 var(--slk-font-ui);
	color:var(--slk-color-ink-soft);
	cursor:pointer;
	transition:background var(--slk-motion-fast) var(--slk-ease),
	           color var(--slk-motion-fast) var(--slk-ease);
}
/* Keyboard and pointer share one highlight, so there is never a second
   "where am I" marker on screen. */
.slk-dd__option.is-active{background:rgba(255,255,255,.72);color:var(--slk-color-ink)}
.slk-dd__option[aria-selected="true"]{color:var(--slk-color-ink);font-weight:500}
.slk-dd__option[aria-selected="true"].is-active{background:rgba(255,255,255,.9)}
.slk-dd__option[aria-disabled="true"]{color:var(--slk-color-faint);cursor:not-allowed}

.slk-dd__button:focus-visible{
	outline:2px solid var(--slk-color-ink);outline-offset:2px;
}

@media (prefers-reduced-motion:reduce){
	.slk-dd__panel{transition:none;transform:none}
	.slk-dd__panel--up{transform:none}
	.slk-dd__caret{transition:none}
}
';
}
