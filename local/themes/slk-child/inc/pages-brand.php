<?php
/**
 * Brand editorial pages: Our Story and Size Guide.
 *
 * Provisions the two WordPress pages (via slk_ensure_page(), the shared
 * helper in inc/page-provision.php) and carries every line of CSS + the tiny
 * tab-switch script the two page-templates/*.php files need. Styling lives
 * here rather than inline in the templates so the templates stay markup-only,
 * matching the pattern in inc/chrome.php.
 *
 * @package slk-child
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * 1. Provision the pages.
 * ---------------------------------------------------------------------- */

add_action(
	'init',
	static function () {
		slk_ensure_page( 'story', __( 'Our Story', 'slk' ), 'page-templates/story.php' );
		slk_ensure_page( 'size-guide', __( 'Size Guide', 'slk' ), 'page-templates/size-guide.php' );
	},
	20
);

/* -------------------------------------------------------------------------
 * 2. Styles + the size-guide tab script.
 *
 * Scoped to the two templates with is_page_template() so nothing here leaks
 * onto the rest of the site. Tokens only, one 1000px breakpoint, per brand
 * laws 6 and 7.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_enqueue_scripts',
	static function () {
		$is_story = is_page_template( 'page-templates/story.php' );
		$is_sizes = is_page_template( 'page-templates/size-guide.php' );

		if ( ! $is_story && ! $is_sizes ) {
			return;
		}

		$css = '';

		if ( $is_story ) {
			$css .= <<<'CSS'
/* -- Our story ----------------------------------------------------------- */
.slk-story__hero{aspect-ratio:4/5;overflow:hidden}
.slk-story__hero img{width:100%;height:100%;object-fit:cover;display:block}
.slk-story__intro{padding-block:var(--slk-space-6) 0;max-width:70ch}
.slk-story__intro h1{font-size:var(--slk-display-s);margin:var(--slk-space-2) 0 var(--slk-space-4)}
.slk-story__lede{font:400 var(--slk-text-base)/var(--slk-leading-body) var(--slk-font-ui);color:var(--slk-color-ink-soft);margin:0}
.slk-story__stats{
	display:grid;grid-template-columns:repeat(3,1fr);gap:var(--slk-space-3);
	padding-top:var(--slk-space-6);
}
.slk-story__stat{padding:var(--slk-space-4) var(--slk-space-3);text-align:center}
.slk-story__stat-n{font-family:var(--slk-font-display);font-weight:300;font-size:var(--slk-display-s)}
.slk-story__stat-l{font:400 var(--slk-text-xs)/1.4 var(--slk-font-ui);color:var(--slk-color-muted);padding-top:2px}
.slk-story__pair{
	display:grid;grid-template-columns:1fr 1fr;gap:var(--slk-space-3);
	padding-top:var(--slk-space-6);
}
.slk-story__pair-img{aspect-ratio:3/4;border-radius:var(--slk-radius-tile);overflow:hidden}
.slk-story__pair-img img{width:100%;height:100%;object-fit:cover;display:block}
.slk-story__pair-img--b{margin-top:var(--slk-space-6)}
.slk-story__closing{padding-top:var(--slk-space-6);max-width:70ch}
.slk-story__closing p{font:400 var(--slk-text-base)/var(--slk-leading-body) var(--slk-font-ui);color:var(--slk-color-ink-soft)}
.slk-story__find-h{font-size:var(--slk-display-s);margin:var(--slk-space-6) 0 var(--slk-space-2)}
.slk-story__find-p{margin:0}
.slk-story__cta{padding-block:var(--slk-space-6) var(--slk-space-12);text-align:center}

@media (min-width:1000px){
	.slk-story__hero{aspect-ratio:21/9}
	.slk-story__intro{max-width:760px;margin-inline:auto;text-align:center;padding-block:var(--slk-space-12) 0}
	.slk-story__intro h1{font-size:var(--slk-display-l)}
	.slk-story__lede{text-align:left}
	.slk-story__stats{max-width:880px;margin-inline:auto}
	.slk-story__pair,.slk-story__closing{max-width:880px;margin-inline:auto}
	.slk-story__closing{max-width:760px;text-align:center}
	.slk-story__closing p{text-align:left}
	.slk-story__find-h{text-align:center}
}
CSS;
		}

		if ( $is_sizes ) {
			$css .= <<<'CSS'
/* -- Size guide ------------------------------------------------------------ */
.slk-sizeguide__intro{padding-top:var(--slk-space-6);max-width:60ch}
.slk-sizeguide__intro h1{font-size:var(--slk-display-s);margin:0 0 var(--slk-space-3)}
.slk-sizeguide__intro p{font:400 var(--slk-text-base)/var(--slk-leading-body) var(--slk-font-ui);color:var(--slk-color-muted);margin:0}

.slk-sizeguide__tabs{
	display:inline-flex;gap:var(--slk-space-1);
	background:rgba(35,34,32,.05);border-radius:var(--slk-radius-pill);
	padding:5px;margin-top:var(--slk-space-4);
}
.slk-sizeguide__tab{
	flex:1;min-height:40px;border:0;border-radius:20px;
	background:none;color:var(--slk-color-muted);
	font:400 var(--slk-text-sm)/1 var(--slk-font-ui);cursor:pointer;padding:0 20px;
	transition:background var(--slk-motion-base) var(--slk-ease),color var(--slk-motion-base) var(--slk-ease);
}
.slk-sizeguide__tab.is-active{
	background:#fff;color:var(--slk-color-ink);font-weight:500;
	box-shadow:0 3px 10px rgba(35,34,32,.1);
}

.slk-sizeguide__body{padding-top:var(--slk-space-4) 0 var(--slk-space-12)}
.slk-sizeguide__table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}

.slk-sizeguide__table{
	width:100%;min-width:420px;border-collapse:collapse;
	background:var(--slk-glass-solid);border:1px solid var(--slk-glass-edge);
	border-radius:var(--slk-radius-card);overflow:hidden;
}
.slk-sizeguide__table th,.slk-sizeguide__table td{
	padding:13px 16px;text-align:center;
	font:400 var(--slk-text-sm)/1 var(--slk-font-ui);
	border-bottom:1px solid rgba(35,34,32,.06);
}
.slk-sizeguide__table thead th{
	font:500 var(--slk-text-xs)/1 var(--slk-font-ui);color:var(--slk-color-muted);
	border-bottom:1px solid rgba(35,34,32,.08);
}
.slk-sizeguide__table th[scope="row"]{text-align:left;color:var(--slk-color-muted);font-weight:400}
.slk-sizeguide__table tbody tr:last-child th,
.slk-sizeguide__table tbody tr:last-child td{border-bottom:0}
.slk-sizeguide__table .is-mid{font-weight:500;color:var(--slk-color-ink)}
.slk-sizeguide__table thead th.is-mid{color:var(--slk-color-ink);font-weight:500}

.slk-sizeguide__side{padding-top:var(--slk-space-6)}
.slk-sizeguide__howto-h{display:block;margin-bottom:var(--slk-space-3)}
.slk-sizeguide__howto{display:grid;gap:var(--slk-space-2)}
.slk-sizeguide__howto-card{display:flex;gap:var(--slk-space-3);padding:14px 16px}
.slk-sizeguide__howto-n{font:500 12px/1 var(--slk-font-ui);color:var(--slk-color-faint);flex:none}
.slk-sizeguide__howto-t{font:400 var(--slk-text-sm)/1.55 var(--slk-font-ui)}

.slk-sizeguide__wa-card{
	background:var(--slk-color-ink);color:var(--slk-color-on-ink);
	border-radius:var(--slk-radius-card);padding:var(--slk-space-5, 20px);
	margin-top:var(--slk-space-3);
}
.slk-sizeguide__wa-h{font:500 var(--slk-text-base)/1.4 var(--slk-font-ui);margin-bottom:6px}
.slk-sizeguide__wa-p{font:300 var(--slk-text-sm)/1.6 var(--slk-font-ui);opacity:.75;margin:0 0 var(--slk-space-4)}
.slk-sizeguide__wa-btn{background:var(--slk-color-on-ink);color:var(--slk-color-ink)}
.slk-sizeguide__wa-btn:hover{background:#fff}

@media (min-width:1000px){
	.slk-sizeguide__intro{max-width:880px;margin-inline:auto;padding-top:var(--slk-space-12)}
	.slk-sizeguide__intro h1{font-size:var(--slk-display-m)}
	.slk-sizeguide__body{max-width:880px;margin-inline:auto;display:grid;grid-template-columns:1.3fr 1fr;gap:var(--slk-space-6);align-items:start}
	.slk-sizeguide__tabs{margin-top:var(--slk-space-5, 22px)}
	.slk-sizeguide__side{padding-top:0}
}
CSS;
		}

		wp_add_inline_style( 'slk-child', $css );

		if ( ! $is_sizes ) {
			return;
		}

		/*
		 * Registered with an empty src so the inline block prints in the footer
		 * without a second HTTP request — same idiom as inc/chrome.php.
		 */
		wp_register_script( 'slk-pages-brand', '', array(), null, true );
		wp_enqueue_script( 'slk-pages-brand' );

		$js = <<<'JS'
(function () {
	var tabs = document.querySelectorAll('[data-slk-sizetab]');
	if (!tabs.length) { return; }

	Array.prototype.forEach.call(tabs, function (tab) {
		tab.addEventListener('click', function () {
			var target = tab.getAttribute('data-slk-sizetab');

			Array.prototype.forEach.call(tabs, function (t) {
				var active = t === tab;
				t.classList.toggle('is-active', active);
				t.setAttribute('aria-selected', active ? 'true' : 'false');
			});

			Array.prototype.forEach.call(document.querySelectorAll('.slk-sizeguide__panel'), function (panel) {
				var show = panel.id === 'slk-tabpanel-' + target;
				panel.classList.toggle('slk-sr-only', !show);
				if (show) { panel.removeAttribute('hidden'); }
				else { panel.setAttribute('hidden', ''); }
			});
		});
	});
})();
JS;

		wp_add_inline_script( 'slk-pages-brand', $js );
	},
	30
);
