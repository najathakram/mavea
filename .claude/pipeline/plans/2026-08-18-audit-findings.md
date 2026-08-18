# MAVEA live-site audit — 13 pages, 215 raw findings, 27 root causes

> Produced by a 14-agent parallel audit on 2026-08-18. Status: OPEN

HEADLINE: 62 raw findings collapse to 27 root causes; 5 blockers, and the cart/checkout funnel does not run the theme at all


## BLOCKER

### Footer ships placeholder copy promising a business address on every page
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php

**Root cause:** The default value of the slk_footer_blurb filter at inc/chrome.php:559 is `__( 'Modest ready-to-wear, made in Sri Lanka to export standard. Address and business numbers will sit here.', 'slk' )`. Verified: no add_filter for slk_footer_blurb exists anywhere in the theme or the three slk-* plugins, so the default is what renders. Because the footer is chrome, this single string is the same defect reported separately on home, shop, PDP and cart.

**Fix:** Cut the second sentence at inc/chrome.php:559 so the default reads `__( 'Modest ready-to-wear, made in Sri Lanka to export standard.', 'slk' )`. Do not substitute an address or a phone number — the fourth footer column already carries Contact us, and the WhatsApp card is the intended contact route.

### Cart and checkout render WooCommerce blocks — the entire Porcelain Glass funnel is dead code
**Files:** WordPress page id 6 and id 7 post_content (WP-CLI / block editor — no repo file), C:/ClaudeCode/mavea/local/themes/slk-child/woocommerce/cart/cart.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/cart.php

**Root cause:** WordPress page id 6 (/cart/) and id 7 (/checkout/) carry block markup (`data-block-name="woocommerce/cart"`), so WooCommerce never routes through the theme's template overrides. Verified in the repo: woocommerce/cart/cart.php exists (588 lines) and inc/cart.php:458 ships a large inline stylesheet, none of which can execute against block markup. Everything downstream is the same root cause: the exclamation-mark headline "Your cart is currently empty!", the blank pre-hydration cart region, the missing <h1>, the missing back-to-shop CTA, square thumbnails, no glass treatment, and the invisibility of every slk-checkout/slk-order-flow/slk-exchanges fulfilment feature (ready dates, ship-mode fieldset, free-delivery shortfall, district shipping label at inc/cart.php:143) — none of those plugins register a Blocks IntegrationInterface or extend the Store API.

**Fix:** `wp post update 6 --post_content='[woocommerce_cart]'` and `wp post update 7 --post_content='[woocommerce_checkout]'`, then re-audit both pages — roughly ten separate findings resolve at once. If the block funnel is instead deliberate, the honest alternative is to delete woocommerce/cart/cart.php, the EMPTY CART section of inc/cart.php and the .slk-cart* inline CSS, and rebuild against .wc-block-* selectors plus a Store API integration — a far larger job. See needsOwner.

### Front page names four payment methods; only cash on delivery is installed
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/front-page.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** All three branches of the Paying panel at front-page.php:185/189/193 end with the hardcoded sentence "Card, eZ Cash, helaPay and bank transfer also work." The live Store API cart reports payment_methods = ['cod'] and the block cart's wcSettings payload reports paymentMethodSortOrder ['cod'] — one gateway. The COD fee in the same sentence is correctly read from slk_delivery_cod_fee(); the method list is not, so the copy outran the install. The cart's empty accepted-payment-methods block and the PDP's lack of payment marks are the same gap seen from two other pages.

**Fix:** Delete the trailing sentence from all three branches at front-page.php:185/189/193 now. Then make it self-correcting: build the list from `WC()->payment_gateways()->get_available_payment_gateways()` and print it only when count > 1, the same discipline the fee already uses. Add the payment marks (inline SVG, ink, ~24px) under slk_pdp_trust_rows() at inc/pdp.php:407-449 from the same source, so a mark can never advertise a gateway that is not live.

### Every product photo is a square crop stretched into a portrait tile, and blurry on retina
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php, C:/ClaudeCode/mavea/local/themes/slk-child/functions.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** slk_template_loop_product_thumbnail() at inc/shop.php:152 requests the 'woocommerce_thumbnail' size, which is a hard square crop, and the srcset therefore tops out at 300w. style.css:270 then pours that square into `aspect-ratio: var(--slk-ratio-portrait)` with object-fit:cover — the original 1153x1537 (exact 3:4) file is cropped to square, cropped again on the sides, then upscaled ~1.2x on a 270px card. The same root cause hits the PDP gallery pills: Blocksy requests the 100x100 thumbnail while inc/pdp.php:625-641 lays them out at roughly 196x261. The token comment at style.css:106 says the ratio exists so cards "never crop head or hem".

**Fix:** In functions.php register a real card size: `add_image_size( 'slk_card', 600, 800, true );` and `add_image_size( 'slk_thumb', 300, 400, true );`. Change inc/shop.php:152/154 to request 'slk_card'. Add `add_filter( 'woocommerce_gallery_thumbnail_size', fn() => 'slk_thumb' );` so the PDP pills stop requesting 100x100, and correct the emitted `sizes` to the real ~196px slot. Then regenerate thumbnails (`wp media regenerate --yes`).

### The whole live store is noindex, nofollow
**Files:** WordPress Settings → Reading / update_option('blog_public', 1) — no repo file

**Root cause:** `<meta name='robots' content='noindex, nofollow' />` is emitted on the home page, the shop archive and product pages. No theme or plugin file emits it — grep for `noindex` across local/themes/slk-child and local/plugins returns nothing — so it is WordPress core's blog_public = 0, the "Discourage search engines" checkbox.

**Fix:** Confirm the store is meant to be publicly live (see needsOwner), then `wp option update blog_public 1`. Verify the meta tag is gone and that /robots.txt no longer disallows crawling. Do this in the same pass as the Open Graph work below — indexing without share cards leaves half the value on the table.


## MAJOR

### No WhatsApp entry point exists, and the footer announces one is coming
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/functions.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php

**Root cause:** slk_whatsapp_number() at inc/cart.php:49 returns `apply_filters( 'slk_whatsapp_number', '' )`. Verified: the filter is read at inc/cart.php:49, inc/checkout-view.php:56, inc/pages-support.php:91 and inc/pdp.php:189, and is never registered anywhere in the theme or the three plugins — the only occurrences of add_filter are inside docblock examples. Every guarded WhatsApp surface therefore falls back: the drawer card (chrome.php:514), the footer card (chrome.php:615), the PDP button, the buy-dock circle, the cart prompt and the contact page. What ships instead is chrome.php:623, "A WhatsApp line opens with the relaunch" — a live storefront telling customers it is mid-rebuild.

**Fix:** One line in functions.php once the number exists: `add_filter( 'slk_whatsapp_number', fn() => '<digits, country code first, no plus>' );` — that single filter lights up all six surfaces, whose markup and CSS are already written and correct. Until the number is supplied, delete the else branch at inc/chrome.php:623 entirely and let the Contact us link stand alone; an absent card reads as restraint, a coming-soon note reads as a building site. Do not invent a number.

### Hijabs is advertised in three places and the category is empty
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/front-page.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php

**Root cause:** get_terms() at front-page.php:112-119 passes `hide_empty => false`, so the Hijabs chip prints for a term with zero products; the rail at front-page.php:221 is guarded by `if ( $slk_hijabs )` so it silently never renders, leaving a designed section invisible; and the header search placeholder in inc/chrome.php still reads "Abaya, linen, hijab…". The catalogue is 20 pieces, 19 abayas plus one coat dress, no hijabs. The chip is a link to an archive with nothing in it.

**Fix:** Whichever way the owner decides (see needsOwner), the code change is the same shape: drop `hide_empty => false` at front-page.php:116 so a chip is never printed for an empty term — the same never-a-dead-link discipline slk_home_page_link() already applies. If hijabs are not launching, also remove 'hijabs' from the $slk_cats slug list at front-page.php:117, delete the rail section at front-page.php:221-234, and change the search placeholder in inc/chrome.php.

### An empty category archive accuses the shopper of a filter she never set
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/moments.php

**Root cause:** slk_moments_no_products_found() at inc/moments.php:718-760 has one headline for every zero-result case: "No pieces match those filters", followed by a "Clear filters" button pointing at slk_moments_listing_url(). Verified in source: the function reads the active price bucket and cats but never branches on "no filters active at all". On /product-category/hijabs/ that renders a Clear filters button that reloads the identical empty page, beside a submit reading "Show 0 pieces".

**Fix:** In slk_moments_no_products_found(), branch on `! $bucket && ! $cats` (no facet active): print a category-empty message and a primary link to wc_get_page_permalink('shop') instead of the Clear filters ghost button. Keep the existing filtered-out branch exactly as it is — its unfiltered-count sentence is good work.

### The zero-result shop page loses its <h1> and its sort control
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php

**Root cause:** inc/shop.php:341-363 hooks the head row onto `woocommerce_before_shop_loop`, which archive-product.php fires only when the loop has products, while inc/shop.php:329-336 permanently gates Blocksy's own hero band off via the theme_mod_{prefix}_hero_enabled filters whenever slk_is_product_listing() is true. On a zero-result page both are gone, so the only heading in the document is the filter sheet's <h2>Filters</h2>.

**Fix:** Move the head-row printer from `woocommerce_before_shop_loop` to `woocommerce_shop_loop_header` at priority < 999, the hook inc/moments.php:678 already uses for the layout wrapper — it fires on zero-result pages, which the surviving "Show 0 pieces" button proves. Keep the count sourced from wc_get_loop_prop('total') so it correctly reads "0 pieces". While there, ensure .woo-listing-top is not suppressed on the empty branch.

### The shop results grid is two columns at every viewport and mis-places three of its children
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php

**Root cause:** inc/shop.php:379 prints `.slk-shop-results{display:grid;grid-template-columns:1fr auto;...}` with no media query, though the comment above it describes the desktop one-baseline row. The `auto` track is sized by the sort pill, which inc/select.php:123 gives `min-width:210px` — on a 360px viewport that leaves the <h1> and Filters button about 95px. Compounding it, the placement rules at inc/shop.php:380-383 name only three children: the live child order is .slk-filterbar, header.slk-shop-head, an empty div.woocommerce-notices-wrapper, .woo-listing-top, ul.products, nav.ct-pagination. The unplaced empty notices div auto-fills row 2 column 2 — the sort pill's cell — and `nav.woocommerce-pagination` never matches because Blocksy emits `nav.ct-pagination`, so the pagination auto-places into the 1fr column, ~230px narrower than the grid it paginates.

**Fix:** In the inline CSS string at inc/shop.php:372: wrap the whole `.slk-shop-results` grid block in `@media (min-width:1000px){ … }` so mobile falls back to normal block flow. Inside it add `.slk-shop-results > .slk-filterbar, .slk-shop-results > .woocommerce-notices-wrapper{grid-column:1 / -1}` and widen the span selector to `.slk-shop-results > ul.products, .slk-shop-results > nav.woocommerce-pagination, .slk-shop-results > nav.ct-pagination{grid-column:1 / -1}`.

### Pagination is styled by Blocksy — 4px corners and a 40px touch target
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/style.css

**Root cause:** Verified by grep: the theme references `nav.woocommerce-pagination` at style.css:1328-1353 and inc/shop.php:383, and never references `.ct-pagination` anywhere. Blocksy emits `<nav class="ct-pagination" data-pagination="simple">` with bare `.page-numbers` children and no ul/li, so every house rule matches nothing and Blocksy's `height:40px;border-radius:var(--theme-border-radius,4px)` wins — and that variable is only ever defined scoped to a header sub-menu, so it falls back to 4px. The archive's only navigation control ends up sharp-cornered and below the 44px touch floor.

**Fix:** Add to style.css §3.7: `.ct-pagination{padding-block:var(--slk-space-8);text-align:center} .ct-pagination .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:var(--slk-touch);min-height:var(--slk-touch);height:auto;border-radius:var(--slk-radius-pill);background:var(--slk-glass-solid);border:1px solid rgba(35,34,32,.1);font:500 13px/1 var(--slk-font-ui);text-transform:none} .ct-pagination .page-numbers.current{background:var(--slk-color-ink);color:var(--slk-color-on-ink);border-color:transparent}`. Delete the dead nav.woocommerce-pagination block at style.css:1328-1353 rather than leaving two owners.

### Card names are h2 and the reset that styles them also deletes the 10px gap under the photo
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php, C:/ClaudeCode/mavea/local/themes/slk-child/style.css

**Root cause:** Two defects in one selector pair. slk_template_loop_product_title() at inc/shop.php:166 hard-codes `<h2 class="slk-card__name">`, so on the home rails, the archive and the PDP related rail the card names sit level-equal with the section heading that introduces them — the outline reads h2 Ready to wear, h2 Suhana, h2 Warda… as peers. Separately, style.css:276 gives `.slk-card__name{padding-top:10px}` at specificity (0,1,0) while style.css:1297-1306 declares `.woocommerce ul.products li.product h2{padding:0;margin:0}` at (0,3,3) later in the file — the card name IS an h2, so the breathing room under every image is zeroed. The desktop 14px bump at style.css:1419-1420 targets `.woocommerce-loop-product__title` and `.price`, which the house card never emits, so card type also never grows past 13.5px.

**Fix:** inc/shop.php:166 — make the level a filterable parameter defaulting to h3: `$tag = apply_filters( 'slk_loop_title_tag', 'h3' );` so cards nest under their rail heading. style.css:1298 — widen the type selector to `:is(h2,h3)` so typography is unaffected, and either scope the reset with `:not(.slk-card__name)` or restore `padding-top:10px` inside that block. style.css:1419-1420 — add `.slk-card__name, .slk-card__price` to the desktop 14px selector.

### Three text colours fail WCAG AA because --slk-color-faint is used for real content
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/style.css, C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/home.php

**Root cause:** The token's own comment restricts faint (#8a867e) to "meta/disabled only, never body copy", and brand-guidelines.md:61 asserts every listed pair clears 4.5:1. Three places break it: style.css:774 `.slk-footer__col > .slk-eyebrow{color:var(--slk-color-faint)}` — 11px uppercase navigation headings (Shop, Help, Talk to us) at 3.36:1 on the composited footer band; inc/shop.php:378 `.slk-shop-head__count{font-size:15px;color:var(--slk-color-faint)}` at 3.19:1 on the page ground; and the hero eyebrow, which inherits --slk-color-muted onto .slk-glass over a photograph and measures 3.67–4.04:1 across a large share of both hero crops, and shifts whenever the photo is swapped.

**Fix:** style.css:774 → `color: var(--slk-color-muted)` (#5f5c56, 6.17:1 on that band, still visibly quieter than the ink links beneath). inc/shop.php:378 → same swap to --slk-color-muted (5.85:1). inc/home.php, beside the hero rules around line 422 → add `.slk-hero__panel .slk-eyebrow{color:var(--slk-color-ink-soft)}` (#44413c, 6.6–7.0:1 on the same composite). Scope the hero fix to the eyebrow rather than raising --slk-glass alpha, which would change every glass surface in the store.

### No meta description, no canonical on archives, no Open Graph or Twitter tags anywhere
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/functions.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/ (new file: seo.php)

**Root cause:** The theme emits no head meta of its own — grep across the full home page HTML returns zero `og:` and zero `twitter:` matches, and there is no meta description on home, shop or PDP. WordPress emits rel_canonical for singular views only, so paginated archives and every filter permutation (?min_price=…&product_cat[]=…) are separately indexable with no canonical. For a store whose customers share links over WhatsApp and Instagram, every shared link renders as a bare URL with no image, title or price.

**Fix:** Add inc/seo.php on `wp_head` (priority 5) emitting, per context: meta description, og:type (website / product), og:title, og:description, og:url, og:locale, og:image (home → the 1672px editorial-hero-group.jpg; PDP → the featured image at 1200px+), and twitter:card=summary_large_image. Emit a canonical for listings — wc_get_page_permalink('shop') plus the paged segment, filter args stripped. Also correct <main itemtype>, currently schema.org/CreativeWork on a product archive. Ship this in the same deploy as lifting the noindex flag.

### No privacy policy, terms or returns page — all three URLs 404 and none are linked
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php, WordPress pages (content — see needsOwner)

**Root cause:** slk_chrome_help_links() at inc/chrome.php:205-245 lists Size guide, Delivery & COD, Exchanges, Track order, FAQ and Sign in / My account. There is no privacy, terms or refund entry and no copyright line in the footer. /privacy-policy/, /terms/ and /returns/ all 404. The store collects names, phone numbers and delivery addresses at checkout with nothing published about how they are handled — separate from, and unaffected by, the decision not to publish a business address.

**Fix:** Publish privacy and terms/refund pages (Exchanges documents the mechanics but is not a policy document), then add them to the $links array in slk_chrome_help_links() via slk_chrome_page_url() so they inherit the existing empty-URL filter and never render as dead links. Add a small copyright line to the footer inner in inc/chrome.php. The policy text itself is an owner deliverable.

### The first price filter can never return a product and a second returns two
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/moments.php

**Root cause:** slk_moments_price_buckets() at inc/moments.php:128-129 hardcodes `$under = 5000` and `$over = 10000`. The catalogue runs Rs. 9,900 to Rs. 17,900, so "Under Rs. 5,000" matches 0 of 20 — the first facet a shopper is offered leads straight to the empty panel — and "Rs. 5,000 to Rs. 10,000" matches 2. The comment above the function says the boundaries are the design's; the catalogue has moved and they did not.

**Fix:** Derive the boundaries from the live catalogue instead of typing them, the same way the COD fee and the delivery days are already read from their source: read MIN/MAX of _price (cached in a transient), split into thirds, and suppress any bucket whose count is zero. For today's catalogue that yields roughly under Rs. 12,500 / Rs. 12,500–15,900 / over Rs. 15,900.

### Out-of-stock products are indistinguishable from in-stock ones on the grid
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php, C:/ClaudeCode/mavea/local/themes/slk-child/woocommerce/content-product.php, C:/ClaudeCode/mavea/local/themes/slk-child/style.css

**Root cause:** woocommerce/content-product.php:71-74 calls thumbnail, title, price and swatches only, and inc/shop.php defines no availability renderer. Live page 1 card 9 carries `class="… outofstock …"` (Naila Crepe Abaya, Rs. 12,500) and renders identically to the eleven in-stock cards. The shopper only discovers it on the PDP. The house already has the precedent for showing rather than hiding unavailability — style.css:296-304 keeps sold-out sizes visible and struck.

**Fix:** Add slk_template_loop_soldout() beside slk_template_loop_price() in inc/shop.php emitting `<span class="slk-card__soldout">Sold out</span>` when `! $product->is_in_stock()`, call it from content-product.php after the title, and style it in style.css §3 as a quiet glass pill over the media plus `.outofstock .slk-card__media img{opacity:.72}`. Do not hide the card.

### Related products are hidden on every phone and tablet by a rule that cannot match
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** Blocksy stamps `ct-hidden-sm ct-hidden-md` on the rail and defines them as `display:none !important` under 1000px. The intended override at inc/pdp.php:616 is `.slk-pdp .related.products.ct-hidden-sm.ct-hidden-md{display:block}` and it fails twice: the section's parent is `article.post-46`, a SIBLING of `div#product-46.slk-pdp`, so the .slk-pdp ancestor never matches; and even matched, `display:block` cannot beat `!important`. The same dead ancestor also kills the width/margin rules at inc/pdp.php:617-621 and the heading rule at :622-624, so the rail is unstyled on desktop too.

**Fix:** Drop the .slk-pdp ancestor and match the specificity war: `.related.products.ct-hidden-sm.ct-hidden-md{display:block !important}`, and re-point the sibling rules at `.single-product article > .related.products` so the container width and heading size apply. Cleaner still: filter the Blocksy related-products visibility option so the classes are never stamped, then the plain rules work as written.

### The PDP summary paints a third glass panel around the two cards below 1000px
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php, C:/ClaudeCode/mavea/local/themes/slk-child/woocommerce/content-single-product.php

**Root cause:** content-single-product.php:71 gives the summary `class="summary entry-summary slk-panel slk-panel--lifted slk-pdp__summary"`, which style.css:163-167 paints with glass, a 24px radius and a lift shadow. Verified: inc/pdp.php strips that skin only inside `@media (min-width:1000px)` — the reset at inc/pdp.php:490-510 is entirely within the desktop block. Below 1000px the outer panel survives while style.css:1538 gives it `padding: 0 var(--slk-gutter)` (zero vertical), so on every phone and tablet a shadowed 24px panel wraps .slk-summary-card and .slk-pdp-about, whose own 28px corners sit flush against it. Three stacked glass surfaces where the design has two.

**Fix:** Simplest and safest: remove `slk-panel slk-panel--lifted` from the summary div at content-single-product.php:71, since the two inner cards now own the panel look — then the desktop reset at inc/pdp.php:490-510 has nothing left to undo and can shed its background/border/shadow lines, keeping only position:sticky and the grid. If the classes must stay, hoist the skin-strip out of the media query as an unconditional `.slk-pdp.slk-pdp .summary.entry-summary{background:none;border:0;box-shadow:none;backdrop-filter:none}`.

### The mobile buy dock hardcodes quantity 1 and silently discards the stepper value
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** inc/pdp.php:386 renders, for any product that is not variable and has no customisation, its own form with `<input type="hidden" name="quantity" value="1" />`. On a phone both controls are visible at once — the summary card's stepper and the sticky dock — so a shopper who sets 3 and taps the dock button (the one she actually reaches for) adds 1, with no indication anything was ignored. The correct pattern already exists three lines above: the $needs_summary_form branch at inc/pdp.php:379-383 emits `data-slk-dock-proxy` and lets initDock() forward the click to the real submit.

**Fix:** Delete the else branch at inc/pdp.php:385-391 and use the proxy path for every product type — set $needs_summary_form true unconditionally. That removes the duplicate form entirely, so the stepper, any customisation fields and the real add-to-cart button are the single source of truth. Verify initDock() finds the summary submit on simple products before shipping.

### No size information, and the size-guide link is a dead anchor
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/functions.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** Verified by grep: `slk_pdp_size_guide_url` is applied once, at inc/pdp.php:988, with the default `'#slk-size-guide'`, and is never registered — the live page ships `sizeGuideUrl:"#slk-size-guide"`, an anchor to nothing. The link is only injected inside a variation row's label (inc/pdp.php:955-962), so on a simple product it never renders at all. `slk_pdp_fit_hint` (applied at content-single-product.php:62) is likewise never registered, so data-slk-fit-hint ships empty. A Rs. 16,900 ankle-length abaya is sold with no length, no fit note and no route to /size-guide/, which exists and is linked only from the footer.

**Fix:** Two parts. (1) In functions.php: `add_filter( 'slk_pdp_size_guide_url', fn() => slk_chrome_page_url( 'size-guide' ) );` so the anchor is never dead and dies gracefully if the page is unpublished. (2) In inc/pdp.php, print the size-guide link from a `woocommerce_single_product_summary` callback at priority ~35 (inside the summary card) rather than only from the variation-row JS, so simple products get it too. Per-garment length/fit copy belongs in the About box Details list and is an owner deliverable.

### Desktop filter rail: false modal semantics, an h2 before the h1, native checkboxes, and a sticky rule that never fires
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/moments.php, C:/ClaudeCode/mavea/local/themes/slk-child/style.css

**Root cause:** Four defects in one region of inc/moments.php. (a) The form at inc/moments.php:352-353 emits `role="dialog" aria-modal="true"` unconditionally, while inc/moments.php:1232-1238 turns the container into a static in-flow sidebar above 1000px — the JS already draws this distinction (isModal() skips the focus trap on desktop) but the ARIA does not, so a screen reader is told the archive is inert. (b) The filter box renders before .slk-shop-results, so `<h2 class="slk-filters__title">Filters</h2>` at inc/moments.php:363 precedes the page's own h1. (c) inc/moments.php:1263-1271 reverses the pill treatment on desktop to `position:static;width:18px;opacity:1;accent-color:…` — accent-color tints a UA control but does not reshape it, so the facets render as browser-default square checkboxes in a glass panel, on 38px rows below the 44px floor. (d) style.css:1415 declares `.slk-shop-layout > .slk-filters{position:sticky}` but the real DOM nests `.slk-shop-layout > .slk-filterbox > form.slk-filters`, so the child combinator misses and inc/moments.php:1236 independently pins it static.

**Fix:** (a) Remove role/aria-modal from the markup at inc/moments.php:352-353 and set them from JS in the open() handler only when isModal(sheet), removing them in close() and in the matchMedia handler at inc/moments.php:1400. (b) Demote the heading to `<p class="slk-filters__title">` — the desktop sidebar hides it anyway (inc/moments.php:1246) and the sheet's aria-label already reads Filters. (c) In the desktop block draw the box in CSS: keep the input visually hidden, `appearance:none;width:18px;height:18px;border:1px solid rgba(35,34,32,.28);border-radius:6px;background:var(--slk-color-white)` with a :checked ink fill and a mask tick; raise .slk-facet min-height to var(--slk-touch). (d) Delete style.css:1415 and add `.slk-shop-layout > .slk-filterbox{position:sticky;top:var(--slk-space-6)}` inside the 1000px block of inc/moments.php, which already owns that element.

### Blocksy's mobile sort icon floats over the house sort pill below 690px
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/select.php

**Root cause:** The ordering form ships `<svg class="ct-sort-icon ct-hidden-lg ct-hidden-md">` — hidden on desktop and tablet, therefore visible on phones. Blocksy absolutely positions it assuming the native select has collapsed to a 34px icon button, but inc/select.php:83-87 has already clipped that select to 1px and drawn a 210px .slk-dd__button pill in its place, so the icon lands over the pill's label text. Verified by grep: `.ct-sort-icon` is referenced nowhere in the child theme.

**Fix:** Add `.woo-listing-top .woocommerce-ordering .ct-sort-icon{display:none}` to slk_select_css(), beside the existing `.woocommerce-ordering .slk-dd__button` block — the house pill already draws its own caret in select.js.


## MINOR

### Product cards lift twice on hover — 8px on mobile, 9px on desktop
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/style.css

**Root cause:** The markup is `<li class="…product…"><a class="slk-card">`, and hovering the anchor also hovers its ancestor li, so style.css:1251 `li.product:hover{transform:translateY(-4px)}` and style.css:262 `.slk-card:hover{transform:translateY(-4px)}` both fire and compose. style.css:1418 raises the li to -5px above 1000px, making it 9px. Intended lift is 4px, over two independent 320ms transitions. The reduced-motion block at style.css:2187 correctly zeroes both.

**Fix:** Delete style.css:1251 and the -5px line in the desktop block at style.css:1418, leaving `.slk-card:hover` as the sole owner of the gesture. The anchor is the component that owns the interaction, and that also keeps the behaviour identical where .slk-card renders outside ul.products (the empty-bag rail).

### Trust glyphs are bare Unicode characters where the design system uses drawn icons
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/front-page.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php

**Root cause:** front-page.php:239/243/255 and inc/pdp.php:418-437 both emit `<span aria-hidden="true">✓</span>`, `⇄` (U+21C4) and `◷` (U+25F7) as literal characters. style.css:711 states the rule outright — "Drawn icons, not glyphs" — and every icon in the header is inline SVG via slk_chrome_icon(). U+25F7 is carried by neither Archivo nor Newsreader and has thin coverage in common Android and Windows fallbacks, so it can render as a substituted face or tofu, at a weight and baseline unrelated to its two neighbours.

**Fix:** Add check, two-way-arrow and clock entries to the slk_chrome_icon() set in inc/chrome.php at the same 1.35 stroke weight as the header icons, then replace the six spans in front-page.php and inc/pdp.php with slk_chrome_icon() calls, keeping aria-hidden="true" since the adjacent text carries the meaning.

### About 43KB of inline CSS ships on pages that have none of its elements
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pages-help.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/pages-support.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/moments.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/select.php, C:/ClaudeCode/mavea/local/themes/slk-child/functions.php

**Root cause:** Verified: four wp_enqueue_scripts callbacks call wp_add_inline_style with no context gate — inc/pages-help.php:300 (adds at :367), inc/pages-support.php:150 (at :275), inc/moments.php:1053 (at :1285) and inc/select.php:39 (at :61). The correctly gated files are the model: inc/home.php:406 returns early unless is_front_page(), and inc/shop.php:372 unless slk_is_product_listing(). The home page consequently carries slk-track, slk-faq, slk-contact, slk-filters, slk-zoom, slk-dd and slk-added selectors for elements it does not have, inside 86KB of render-blocking head CSS on a document whose LCP is the hero photograph. Separately the hostinger-reach plugin enqueues its subscription block CSS and JS (plus a REST nonce) unconditionally on a page with no such block, and flexslider, zoom and comment-reply load on every PDP although Blocksy renders its own gallery, the zoom trigger is display:none and reviews are removed at inc/pdp.php:237.

**Fix:** Add the same early-return guard inc/home.php:406 uses: pages-help behind its page templates, pages-support behind the contact/track-order/404/search contexts, moments behind `slk_is_product_listing() || is_product()`, select behind the views that actually render a select. In functions.php dequeue 'flexslider', 'zoom' and 'comment-reply' on is_product(), and either deactivate hostinger-reach or dequeue its two handles when has_block() is false.

### Escape does not close the header search panel
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php

**Root cause:** In the inline chrome script (inc/chrome.php, slk-chrome-js-after), setState() assigns the module-level `open` variable only when `panel.classList.contains('slk-drawer')`, and the keydown handler guards on `if (e.key === 'Escape' && open)`. #slk-header-search is not a .slk-drawer, so `open` stays null while it is expanded and Escape is inert. On the home page the panel sits inside the absolutely-positioned .slk-header--over, overlaying the hero with no keyboard route out except tabbing back to the toggle.

**Fix:** Track the last-opened panel id regardless of modality and let Escape close whatever is open, keeping the scroll-lock and focus-trap branches gated on the .slk-drawer check exactly as they are. Focus already returns to the toggle in setState(), so nothing else changes.

### Two sort options are inert and sorting dies without JavaScript
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/select.php

**Root cause:** The orderby select offers popularity and average rating, but no product carries a review, inc/shop.php:246 unhooks woocommerce_template_loop_rating and style.css:1314 hides .star-rating outright — the design has no ratings anywhere, and ?orderby=rating returns the default order. Two of six options do nothing. Separately the ordering form ships only the select and two hidden inputs, relying on WooCommerce's jQuery change handler to submit, while inc/select.php:32-34 dequeues selectWoo — so sort is the one control on an otherwise deliberately no-JS-safe archive (inc/moments.php:10-28 documents the filter form as a plain GET) that silently fails without script.

**Fix:** In inc/shop.php add a `woocommerce_catalog_orderby` filter unsetting 'rating' and 'popularity', leaving Default / Latest / Price low-high / Price high-low. In inc/select.php print a `<noscript><button type="submit" class="slk-btn slk-btn--secondary">Sort</button></noscript>` inside the ordering form.

### Front page volunteers the catalogue size twice and hardcodes a delivery-zone label
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/front-page.php

**Root cause:** Two copy-accuracy defects in front-page.php. The catalogue count prints in the chip at front-page.php:129-137 and again in the section link at :162-169 — "All 20" and "All 20 →" — although the comment at front-page.php:79-88 already argues against exactly this for the new-arrivals count ("a small figure volunteered about yourself reads as the size of the operation, not the rarity of the piece"). Separately front-page.php:261 types the labels "Colombo" and "island-wide" while reading only the day ranges from slk_delivery_days(); slk_delivery_zones() (inc/pages-help.php:54-70) defines tier 0 as "Colombo & Gampaha" 1–2 days and tier 2 as "All other districts" 3–5, with an unrepresented tier 1 at 2–3. A Gampaha customer is told 3–5 when she will get 1–2, and the two sources will drift again.

**Fix:** Remove the `$slk_total > 0` conditional in both controls and let them read "All" and "All →" — the zero-case fallback branch already exists, so this deletes code rather than adding it. Keep the count in the shop archive's result line, where a shopper is orienting inside a list. At front-page.php:261 print `$zones[0]['label']` and `$zones[2]['label']` instead of typed strings, exactly as the COD fee is read rather than typed. Naming districts here is within the delivery exception to the provenance rule.

### PDP price renders at 16px because the rule that sets it is outranked
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** inc/pdp.php:688-690 declares `.slk-summary-card > p.price{font:500 var(--slk-text-xl)/1 …}` at specificity (0,2,1), while style.css:1551 declares `.woocommerce div.product p.price{font:500 16px/1 …}` at (0,3,2) and wins regardless of load order — its desktop override only reaches 18px, so the price never hits the intended 20px and keeps an 8px bottom margin inside a gap-based grid. The same collision hits the h1: `.woocommerce div.product .product_title.entry-title{margin:0 0 6px}` (0,4,2) survives `.slk-pdp.product .slk-summary-card > *{margin-block:0}` (0,3,0).

**Fix:** Raise the two inc/pdp.php selectors above style.css by prefixing them fully: `.slk-pdp.product .slk-summary-card > p.price` and `.slk-pdp.product .slk-summary-card > h1.product_title`, which takes them to (0,4,1). Do not bump style.css instead — PDP summary typography should have one owner, and that is inc/pdp.php.

### "20 in stock" is CSS-hidden but still served to assistive tech and scrapers
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** The PDP ships `<p class="stock in-stock">20 in stock</p>` and style.css:1485 hides it with display:none. The comment at style.css:1483 already states the correct fix — "The text itself should be filtered out in PHP (woocommerce_get_stock_html)" — and grep confirms that filter is nowhere registered. Publishing an inventory count also runs against the premium positioning.

**Fix:** Do what the comment says: `add_filter( 'woocommerce_get_stock_html', fn( $html, $product ) => $product->is_in_stock() ? '' : $html, 10, 2 );` in inc/pdp.php, keeping the CSS as a belt-and-braces fallback. Note the out-of-stock case must still render, and pairs with the sold-out card marker above.

### Gallery arrows and quantity steppers are unlabelled non-focusable spans
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php

**Root cause:** Blocksy emits `<span class="flexy-arrow-prev">` / `<span class="flexy-arrow-next">` — not focusable, no role, no name — sized 40x40 (below --slk-touch) and, because their opacity/visibility rules sit inside `@media(any-hover:hover)`, hidden on desktop and rendered as plain white circles over the photograph on touch. Verified: `flexy-arrow` is referenced nowhere in the child theme. The quantity controls are the same shape of problem: `<span class="ct-increase"></span><span class="ct-decrease"></span>` with glyphs supplied purely by CSS at inc/pdp.php:578-579, so assistive tech is told nothing, and the CSS pins decrease left and increase right, making the painted order the reverse of the DOM order.

**Fix:** Arrows: either hide them at all widths (`.flexy-arrow-prev,.flexy-arrow-next{display:none}` in inc/pdp.php) and rely on the thumbnail pills plus swipe, or filter them to real `<button aria-label="Previous image">` and style them onto the system at 44x44 with --slk-glass-solid, the 1px near-white edge and an ink glyph. Quantity: filter Blocksy's markup to emit `<button type="button" class="ct-decrease" aria-label="Decrease quantity">−</button>` with real text content, swap the source order to match the painted order, and keep the 44px sizing. The number input stays usable throughout, so this degrades rather than breaks.

### The header search input is the one control still on the browser's default skin
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/style.css

**Root cause:** The input group at style.css:1006-1029 gives `.slk-input` a 16px radius and 48px min-height but sets no appearance reset, and grep finds appearance resets only for select (style.css:1040) and for number spinners in inc/cart.php and inc/pdp.php. `type=search` carries `-webkit-appearance: searchfield` by default, so on WebKit the header field keeps the platform's own search chrome and clear control instead of the house field — the single element on the home page not resolving to a radius token.

**Fix:** Add `appearance:none;-webkit-appearance:none;` to the input group at style.css:1006-1029, plus `input[type="search"]::-webkit-search-decoration, input[type="search"]::-webkit-search-cancel-button{-webkit-appearance:none}`. Verify on iOS Safari, where the difference is most visible.

### Desktop drops the Hijabs and whole-catalogue routes, and the comment claims otherwise
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/home.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php

**Root cause:** inc/home.php:519 sets `.slk-home__chips{display:none}` above 1000px, justified by the comment at inc/home.php:520-522: "above 1000px the same four destinations are in the header pill". Verified they are not the same four — the chip row renders All / Abayas / Dresses / Hijabs while slk_chrome_primary_links() renders New in / Abayas / Dresses / Our story. On desktop the whole-catalogue link and Hijabs disappear and Our story takes their place. Hijabs being empty today masks it; the mismatch is structural.

**Fix:** Decide which set is canonical, then make the code and the comment agree: either extend slk_chrome_primary_links() so the desktop pill really carries the category set (plus an All entry), or stop hiding the chip row at inc/home.php:519. Either way rewrite the comment at inc/home.php:520-522 so it no longer asserts something the markup contradicts. Coordinate with the Hijabs decision so the same category set is used in both places.

### An empty products header ships in the DOM and takes desktop padding
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/style.css

**Root cause:** WooCommerce's archive-product.php renders `<header class="woocommerce-products-header">` with every hook inside it removed — inc/shop.php:339 unhooks the result count and inc/shop.php:329-336 gates Blocksy's hero off — so it contributes nothing but still takes Blocksy's padding-block at style.css:1387 on desktop, adding dead vertical space above the layout.

**Fix:** Add `.woocommerce-products-header:empty{display:none}` to style.css §3.7. Do this rather than unhooking the header, so the rule stays correct if a subtitle is ever hooked back in.

### The PDP gallery runs 3:4 where the written guidelines specify 2:3
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php, C:/ClaudeCode/mavea/design/docs/brand-guidelines.md

**Root cause:** inc/pdp.php:532 sets the main slide to `var(--slk-ratio-portrait)` (3/4), while brand-guidelines.md:82 states "Portrait crops only: 3:4 for grid cards, 2:3 for hero and PDP" and design-tokens.css:78 defines --slk-ratio-hero as 2/3 with the comment "PDP + hero, full silhouette". The inline comment at inc/pdp.php:519-528 documents the deviation as deliberate — the source frames are normalised to 3:4, so 2:3 would cover-crop — which is defensible, but the token, the guideline and the code now disagree and the next agent will 'fix' it back.

**Fix:** Pick one and record it. Either re-export the PDP hero frames at 2:3 and switch inc/pdp.php:532 to `var(--slk-ratio-hero)`, or amend brand-guidelines.md:82 and the --slk-ratio-hero comment to state that the PDP runs 3:4 in production and why. Do not leave the three sources contradicting each other.

### The only account route reads as returning-customer only, and desktop has none
**Files:** C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php

**Root cause:** slk_chrome_help_links() at inc/chrome.php:233 defines a single account entry labelled "Sign in / My account", and it is the only account route in either chrome region — the footer Help column and the mobile drawer pill rail carry the identical list. The desktop header actions are Menu, Search and Bag with no account control at all. Registration is in fact live: /my-account/ renders `woocommerce-form-register` with a Create an account heading, so the destination is fine and only the signposting is missing.

**Fix:** Relabel inc/chrome.php:233 to "Sign in or create an account", or split it into two entries pointing at the same page, and add an account icon-button to .slk-header__actions beside the bag so desktop shoppers are not sent to the footer. Confirm WooCommerce → Settings → Accounts still allows registration on the My account page before relying on the fragment.


## NEEDS OWNER DECISION

- **WhatsApp business number** — slk_whatsapp_number() returns '' and the filter is never registered, so six built surfaces stay dark and the footer advertises "A WhatsApp line opens with the relaunch" on every page. The markup and CSS are already written and correct — one filter turns them all on. I will not invent a number.
  - options: (a) Supply the number (digits, country code first, no plus) and it is a one-line change in functions.php. (b) Decide WhatsApp is not launching yet, in which case delete the placeholder sentence at inc/chrome.php:623 and let Contact us stand alone — an absent card reads as restraint; a coming-soon note reads as a building site.
- **Is the store publicly launched?** — Three findings point the same way: every page is noindex/nofollow (blog_public = 0), the footer says "with the relaunch", and one category is empty. Lifting the noindex flag on a store that still says it is relaunching would index the unfinished state, so the sequencing is a business call, not a code one.
  - options: (a) Live now — lift blog_public, ship the SEO/OG head, and remove all relaunch-tense copy in the same deploy. (b) Not yet — keep noindex deliberately, and record that decision somewhere in the repo so the next audit does not re-report it as a blocker.
- **Cart and checkout: classic templates or WooCommerce blocks** — Pages 6 and 7 render the block cart and block checkout, so woocommerce/cart/cart.php, the empty-cart screen in inc/cart.php, roughly 48KB of .slk-cart* CSS, and every fulfilment feature in slk-checkout / slk-order-flow / slk-exchanges (ready dates, ship-mode choice, free-delivery shortfall, district shipping label) are unreachable. Roughly ten separate findings are downstream of this one choice.
  - options: (a) Switch both pages to [woocommerce_cart] / [woocommerce_checkout] — two WP-CLI commands, restores everything already built, and is my recommendation. (b) Commit to blocks — then delete the dead templates and CSS and budget for a Blocks IntegrationInterface plus ExtendSchema Store API work to re-expose the fulfilment features. Do not leave both half-built.
- **Payment gateway merchant account** — Cash on delivery is the only registered gateway, confirmed from both the Store API and the block cart's own settings payload, while the front page names card, eZ Cash, helaPay and bank transfer. I am cutting the false claim now, but whether payment marks and a second rail appear at all depends on a merchant account I cannot open.
  - options: Name the intended provider for the LK market (PayHere, Koko, or a bank rail) and supply credentials, and the copy and the payment marks can both be derived from get_available_payment_gateways() so they can never outrun the install. If COD stays the only method, the panel and the PDP simply say so — which is honest and reads fine.
- **Hijabs: launching, or removed from the site** — The catalogue is 20 pieces — 19 abayas and a coat dress — with zero hijabs, yet a Hijabs chip, a designed "Everyday hijabs" rail and the header search placeholder all advertise the category. The chip currently leads to an empty archive.
  - options: (a) Publish hijab products into the hijabs product_cat and everything fills in with no code change beyond the hide_empty fix. (b) Not launching yet — remove the slug from front-page.php:117, drop the rail, and change the search placeholder. The empty-state and hide_empty fixes ship either way.
- **Privacy policy, terms and refund policy content** — /privacy-policy/, /terms/ and /returns/ all 404 and none is linked, while checkout collects names, phone numbers and delivery addresses. The Exchanges page documents mechanics but is not a policy document. Wiring the links into slk_chrome_help_links() is a five-minute change; the text is a legal deliverable I should not draft as fact.
  - options: Supply or approve the policy text (or a lawyer's template) and the footer links, the Help column entries and a copyright line follow immediately. Note this is entirely separate from the decision not to publish a business address — a policy page needs no street address.
- **Hero headline and primary CTA copy** — The h1 reads "Made for export. A small number stay here." and the button reads "Shop the drop". No hard rule is broken — no town, no headcount, no urgency — but "a small number" describes the label with the word the premium positioning avoids, and "drop" is streetwear release language sitting against "made to export standard" and the Newsreader display face. The button also points at ?orderby=date, which is New in, not a drop.
  - options: Approve a rewrite in the allocation register rather than the smallness register — something in the shape of "Made for export. A few stay here." — and retitle the button to match its actual destination. I will not ship brand copy without sign-off.
- **SKU scheme** — The Product JSON-LD publishes "sku":46 — the WordPress post ID, because no product has a SKU. Merchant feeds and Google Shopping treat sku as a stable merchant identifier, so an auto-incrementing post ID breaks the moment the catalogue is rebuilt or migrated.
  - options: (a) Define a garment code convention and set real SKUs on all 20 products. (b) Suppress sku from the schema via woocommerce_structured_data_product until real values exist. Option (b) is the safe interim; option (a) is required before any feed goes live.

## PROPOSED PACKAGES

- **WP-ADMIN WordPress admin and catalogue — no repo files**
  - files: WordPress page id 6 and id 7 post_content, WordPress Settings → Reading (blog_public), WooCommerce product catalogue and image settings, WooCommerce → Settings → Payments and Accounts, hostinger-reach plugin activation
  - Everything outside the repo, so it conflicts with nothing and can start immediately. Switch pages 6 and 7 to [woocommerce_cart] / [woocommerce_checkout] — this alone resolves about ten cart findings and must land before anyone re-audits the funnel. Lift blog_public once launch status is confirmed. Regenerate thumbnails after PKG-G registers the new image sizes (that is the one ordering dependency across packages). Set real SKUs, publish or withhold hijab products, confirm registration is enabled on the My account page, and deactivate hostinger-reach if the newsletter block is unused.
- **PKG-A Site chrome — footer, help links, header search, icons**
  - files: C:/ClaudeCode/mavea/local/themes/slk-child/inc/chrome.php
  - Sole owner of inc/chrome.php. Cut the address placeholder at line 559 (the single highest-value change in the whole plan — it is on every page). Delete or replace the relaunch sentence at line 623. Add privacy/terms/refund entries and a copyright line, and relabel the account link at line 233. Fix Escape on the header search panel in the inline chrome JS. Add check, two-way-arrow and clock icons to slk_chrome_icon() — PKG-C and PKG-F consume them, so land this package's icon additions first. Change the search placeholder if hijabs are dropped.
- **PKG-B style.css — contrast, hover, card type, pagination, dead rules**
  - files: C:/ClaudeCode/mavea/local/themes/slk-child/style.css
  - Sole owner of style.css so no other package touches it. Swap the footer eyebrow at line 774 to --slk-color-muted. Delete the duplicate hover lifts at lines 1251 and 1418. Widen the card-name reset at 1298 to :is(h2,h3), restore the 10px padding, and add .slk-card__name/.slk-card__price to the desktop size bump. Add the .ct-pagination pill block and delete the dead nav.woocommerce-pagination rules. Add the search-input appearance reset at 1006-1029, `.woocommerce-products-header:empty{display:none}`, the .slk-card__soldout style (markup comes from PKG-D), and delete the dead sticky rule at 1415 (PKG-E adds the working one).
- **PKG-C Home page — front-page.php and inc/home.php**
  - files: C:/ClaudeCode/mavea/local/themes/slk-child/front-page.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/home.php
  - Cut the false payment-methods sentence from all three branches at front-page.php:185/189/193. Drop hide_empty:false at :116 and, on the hijabs decision, the slug and the rail. Remove the catalogue count from both controls. Read the zone labels from slk_delivery_zones() at :261. Replace the three glyph spans with slk_chrome_icon() (needs PKG-A). In inc/home.php add the hero-eyebrow contrast rule beside line 422, and resolve the desktop chip-row mismatch plus its incorrect comment at 519-522 (coordinate the canonical category set with PKG-A).
- **PKG-D Shop archive — inc/shop.php and the loop card template**
  - files: C:/ClaudeCode/mavea/local/themes/slk-child/inc/shop.php, C:/ClaudeCode/mavea/local/themes/slk-child/woocommerce/content-product.php
  - Move the head row to woocommerce_shop_loop_header so zero-result pages keep their h1 and sort. Media-query the .slk-shop-results grid to 1000px and fix the three mis-placed children. Request the new 'slk_card' size at lines 152/154 (PKG-G registers it). Make the loop title tag filterable, defaulting to h3. Swap the count colour to --slk-color-muted at line 378. Add the woocommerce_catalog_orderby filter dropping rating and popularity. Add slk_template_loop_soldout() and call it from content-product.php (PKG-B ships the CSS).
- **PKG-E Filters and listing behaviour — inc/moments.php**
  - files: C:/ClaudeCode/mavea/local/themes/slk-child/inc/moments.php
  - Sole owner of inc/moments.php. Derive the price buckets from the live catalogue instead of the hardcoded 5000/10000 and suppress zero-count facets. Branch slk_moments_no_products_found() so a genuinely empty category gets a category-empty message and a link to /shop/ rather than a Clear filters button. Move role/aria-modal off the markup and into the open()/close() handlers behind isModal(). Demote the Filters h2 to a p. Replace the desktop native checkboxes with drawn boxes and raise the row to 44px. Add the working sticky rule on .slk-filterbox in the 1000px block. Gate the inline stylesheet at line 1053 behind slk_is_product_listing() || is_product().
- **PKG-F Product page — inc/pdp.php and its template**
  - files: C:/ClaudeCode/mavea/local/themes/slk-child/inc/pdp.php, C:/ClaudeCode/mavea/local/themes/slk-child/woocommerce/content-single-product.php, C:/ClaudeCode/mavea/design/docs/brand-guidelines.md
  - Fix the related-products rule at 616 (drop the .slk-pdp ancestor, add !important, re-point the sibling rules at .single-product article >). Remove slk-panel slk-panel--lifted from the summary div at content-single-product.php:71 and simplify the desktop reset accordingly. Route the buy dock through data-slk-dock-proxy for all product types, deleting the hardcoded quantity form at 386. Print the size-guide link from woocommerce_single_product_summary ~35. Add the woocommerce_get_stock_html filter. Raise the price/h1 selectors to .slk-pdp.product. Swap the trust glyphs for slk_chrome_icon() (needs PKG-A). Handle the flexy arrows and quantity steppers. Reconcile the 3:4 gallery ratio with brand-guidelines.md:82.
- **PKG-G Bootstrap, SEO head and asset gating**
  - files: C:/ClaudeCode/mavea/local/themes/slk-child/functions.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/seo.php (new), C:/ClaudeCode/mavea/local/themes/slk-child/inc/pages-help.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/pages-support.php, C:/ClaudeCode/mavea/local/themes/slk-child/inc/select.php
  - In functions.php register add_image_size('slk_card',600,800,true) and ('slk_thumb',300,400,true) plus the woocommerce_gallery_thumbnail_size filter — PKG-D and PKG-F depend on these names, so agree them first and land this package early. Register slk_pdp_size_guide_url, and slk_whatsapp_number once the number exists. Dequeue flexslider, zoom, comment-reply on is_product() and the hostinger-reach handles. Add inc/seo.php on wp_head for meta description, canonical and the full Open Graph/Twitter set. Add the missing context gates to pages-help.php:300, pages-support.php:150 and select.php:39, plus the .ct-sort-icon suppression and the noscript sort button in slk_select_css().