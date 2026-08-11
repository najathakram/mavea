## DIVERGENCES — most severe first

---

### 1. The checkout's numbered panels are wired to the wrong field groups — "2 · Where" renders empty of address fields
**Design** (`06-mobile.html` lines 287–327): panel `1 · You` = mobile number, full name, email. Panel `2 · Where` = address, landmark, city/district, postal code.
**Built**: `woocommerce/checkout/form-checkout.php:52` puts `do_action('woocommerce_checkout_billing')` in panel 1 and `woocommerce_checkout_shipping` in panel 2. But `plugins/slk-checkout/includes/class-slk-checkout-fields.php` defines **every** field — phone, name, email, country, address_1, landmark, city, state, postcode (priorities 10–80) — inside the `billing` group. Nothing anywhere sets `woocommerce_ship_to_destination` to `billing_only` or unhooks the shipping form (grep for `ship_to_different` / `needs_shipping_address` across the plugin returns nothing).
**Result**: panel "1 · You" contains the entire address form; panel "2 · Where" contains the "Ship to a different address?" checkbox, a duplicate address form, and the Delivery-notes textarea. The design's three-step story is destroyed.
**Fix**: in `form-checkout.php`, stop splitting on billing/shipping. Render the fields yourself by group — e.g. call `woocommerce_form_field()` for the `1 · You` keys (`billing_phone`, `billing_first_name`, `billing_last_name`, `billing_email`) in panel 1 and the `2 · Where` keys (`billing_country`, `billing_address_1`, `billing_landmark`, `billing_city`, `billing_state`, `billing_postcode`) in panel 2, then keep `woocommerce_checkout_shipping` only for the notes field. And add `add_filter('woocommerce_ship_to_different_address_checked','__return_false')` + force `woocommerce_ship_to_destination` to `billing_only`.

---

### 2. Desktop checkout collapses into 60% of the page, right-hand 40% empty
**Design** (`07-desktop.html`, Desktop checkout): fields left, sticky summary right, filling the 1140px column.
**Built**: `style.css:2002` makes `form.checkout` a `1.5fr / 1fr` grid and at `:2011` places `> #customer_details` into `grid-column: 1`. In the override template, `#customer_details` is the *only* direct child of the form (`form-checkout.php:46`) and it already contains both columns (`.slk-checkout__grid`, laid out at `min-width:960px` in `checkout-view.php:158`). So the whole checkout is packed into the 1.5fr track and column 2 renders blank. `style.css`'s rules for `> #order_review` and `> #order_review_heading` (lines 2012–2024) never match — `#order_review` is now nested inside `.slk-checkout__aside`.
**Also**: `style.css:1828` `.woocommerce-checkout #customer_details { gap: var(--slk-space-3) }` is specificity (1,1,0) and beats `checkout-view.php`'s `.slk-checkout__grid{gap:var(--slk-space-8)}` (0,1,0) — the desktop gutter is 12px, not the 32px the design shows.
**Fix**: delete `style.css` §3.11's desktop grid block (lines 1992–2032, keeping only the `form-row-first/last` and payment-card rules) and let `checkout-view.php` own the checkout layout — or vice versa. One file, one layout.

---

### 3. The floating glass header pill, the nav and the footer were never built
**Design**: the glass pill header is on **every** screen in `06-mobile.html` and `07-desktop.html`; the 4-column footer with the wordmark, Shop/Help columns and the WhatsApp card is `07-desktop.html:97–119`.
**Built**: `grep -rl "slk-header\|slk-footer\|slk-nav"` across `local/` matches **only `style.css`**. There is no `header.php`, no `footer.php`, no partial — the theme is `functions.php`, `inc/*.php`, and five Woo templates. `style.css` §3.2 and §3.3 (lines 531–701, ~170 lines of pill, wordmark placement, nav, icon buttons, bag badge, footer grid) are dead code. The site renders Blocksy's stock header/footer with only the `#header [data-row="middle"] > .ct-container` fallback pill at `style.css:646`.
**Fix**: build `header.php`/`footer.php` (or Blocksy header/footer hooks) emitting the documented `.slk-header > .slk-header__inner > .slk-wordmark + .slk-nav + .slk-header__actions` structure and the `.slk-footer__inner` 4-column grid, plus `.slk-header--over` on home/PDP.

---

### 4. Mobile PDP sticky buy dock missing
**Design** (`06-mobile.html:207–212`): sticky bottom dock — glass pill, `blur(22px) saturate(1.4)`, WhatsApp circle + full-width **"Add to bag — Rs. 12,500"** (price in the label).
**Built**: `.slk-buy-dock` / `.slk-buy-dock__inner` are fully styled at `style.css:1550–1568` and released at `:2152`, but nothing renders them — grep for `slk-buy-dock` in PHP returns nothing. `inc/pdp.php` leaves the add-to-cart button in normal document flow, and the label is a bare `"Add to bag"` (`pdp.php:56`) with no price.
**Fix**: render the dock from `woocommerce_after_single_product_summary` (or `woocommerce_single_product_summary` 32) as a sibling of `form.cart`, moving the submit button into it; append `wc_price()` to the button text via `woocommerce_product_single_add_to_cart_text`.

---

### 5. Cart has two competing layout systems; both are half-dead
**Built**: `woocommerce/cart/cart.php:58` emits `<ul class="slk-cart-list">` with `.slk-cart-item` cards — so **all ~200 lines of `style.css` §3.10** (`table.shop_table.cart` de-tabling, `grid-template-areas: "thumb name total"`, the remove-button 44px circle, the `td.product-price{display:none}` unit-price rule) never match anything.
Meanwhile `inc/cart.php:255` lays the cart out with `display:inline-block; width:63%` / `37%` at `min-width:900px`, *inside* the grid `style.css:1785` establishes on `.woocommerce-cart .site-main .woocommerce` (`1.6fr / 1fr`, at 1000px). Grid blockifies the children but the 63%/37% widths survive → at ≥1000px each column renders at ~63%/37% of its own track, leaving two dead gutters.
**Fix**: delete `style.css` §3.10 entirely (lines 1610–1812) and let `inc/cart.php` + the template own the cart; convert its 63/37 inline-block to `grid-template-columns: minmax(0,1.6fr) minmax(0,1fr)` on the wrapper at the single breakpoint.

---

### 6. Five different breakpoints where the file says there is one
`style.css:379` states: *"Desktop is a single breakpoint at 1000px… 1000px is the only breakpoint in the file."* In play:

| File | Breakpoint | What flips |
|---|---|---|
| `inc/shop.php:317` | **768px** | product grid 2→3 |
| `inc/cart.php:255` | **900px** | cart columns |
| `inc/checkout-view.php:158` | **960px** | checkout columns |
| `inc/pdp.php:248` | **992px** | PDP two-column |
| `style.css` (×8 queries) | **1000px** | everything else |

Between 768 and 1000 the page changes shape four separate times. **Fix**: one `1000px` everywhere; the PDP one is the worst offender because `style.css:1578` *also* grids `.woocommerce div.product` at 1000px, so 992–999px runs a second, different grid.

---

### 7. Shop grid is 3-up on desktop; the design is 4-up
**Design**: `07-desktop.html:40` `repeat(4,1fr)` for the home product rail; `:176` `repeat(3,1fr)` only for the *filtered* archive beside the sidebar.
**Built**: `style.css:1240` correctly sets `repeat(4, minmax(0,1fr))` at 1000px and `repeat(3,…)` inside `.slk-shop-layout`. But `inc/shop.php:317` re-declares the **identical selector** `.woocommerce ul.products` at `min-width:768px` with `repeat(3,1fr)`, and its `wp_add_inline_style` output is printed *after* `style.css` at equal specificity — so 3-up wins at every width ≥768px. The 4-up rail and the 3-up-in-sidebar distinction are both lost.
**Fix**: delete the grid block in `inc/shop.php:301–324`; `style.css` §3.7 already covers it.

---

### 8. The round colour-led hijab card is overridden back into a rectangle
**Design** (`06-mobile.html:58–61`, `07-desktop.html:88–93`): hijabs are 86/110px **circles**.
**Built**: `inc/shop.php:124` correctly adds `.slk-card--colour`, and `components.css` (copied to `style.css:276`) sets `.slk-card--colour .slk-card__media { aspect-ratio:1; border-radius:50% }`. But `style.css:1115` `.woocommerce ul.products li.product a img` — specificity (0,3,4) vs `.slk-card__media img` (0,1,1) — forces the inner `<img>` to `aspect-ratio: var(--slk-ratio-portrait)`, `height:auto`, `border-radius:20px`, `margin:0 0 10px` and its own `box-shadow`. A 3:4 image inside a 1:1 circle with `overflow:hidden`: top-anchored, bottom cropped, second shadow clipped, stray 10px margin.
**Fix**: scope `style.css:1115` to `li.product:not(.slk-card--colour) a img`, or better — drop the img-level ratio/radius/shadow entirely and let `.slk-card__media` carry the box (the card markup now always provides it, `inc/shop.php:149`).

---

### 9. PDP: the WhatsApp button is not beside "Add to bag" — it can't be
**Design** (`06-mobile.html:209–210`): WhatsApp circle and Add-to-bag share one row.
**Built**: `inc/pdp.php:294` gives `.slk-pdp__summary` `grid-template-areas: "title price" / "desc desc" / "cart cart" / "trust trust"`. `slk_pdp_whatsapp_button` (priority 31) prints its `<a>` as a **direct child of the summary**, a sibling of `form.cart` — it has no `grid-area`, so it is auto-placed into a new implicit row in column 1, below the cart. The rule that was supposed to pair them (`pdp.php:366`, `form.cart > .single_variation_wrap`) targets a descendant of the form the button is not inside, and `.slk-pdp__whatsapp{flex:none}` is inert on a grid item.
**Fix**: hook the button into the form instead (`woocommerce_after_add_to_cart_button`) so it lands in the same flex row.

---

### 10. Checkout renders nested double-glass panels
**Built**: `form-checkout.php:50` wraps each group in `.slk-panel.slk-panel--lifted.slk-checkout__panel` (glass fill + 1px edge + 24px radius + 24px padding). Inside it WooCommerce core emits `<div class="woocommerce-billing-fields">` / `woocommerce-shipping-fields`, which `style.css:1843` *also* paints as glass (`--slk-glass-solid`, `--slk-glass-edge`, `--slk-radius-card`, `padding: 24px 18px`). Two stacked translucent fills, two 1px near-white borders, ~48px of inset padding. The design has exactly one panel per step.
**Fix**: strip `.woocommerce-billing-fields`/`.woocommerce-shipping-fields`/`.woocommerce-additional-fields` out of `style.css:1843` and `:2025` — the template now supplies the panel.

---

### 11. Order-received: step 1 renders two markers at once
**Design** (`06-mobile.html:387`): step 1 is a solid ink circle with a white "1"; steps 2–3 are `rgba(35,34,32,.1)` fills with ink numerals.
**Built**: `thankyou.php:94` uses `.slk-step--now` for step 1. `components.css` (→ `style.css:354–359`) defines `--now` as a *white* dot with a 2px ink ring **plus a generated `::after` 8px filled circle** — designed for an unnumbered timeline. With the numeral "1" also inside the `display:grid; place-items:center` dot, the number and the generated dot stack in the same cell. Steps 2–3 use `--todo` → `background:none` + border, not the design's `.1` fill.
**Fix**: use the base `.slk-step` for step 1 (already solid ink + `--slk-color-on-ink`), and either drop `--todo`'s `background:none` for `rgba(35,34,32,.1)` or add a `--step-numbered` modifier that suppresses `::after`.

---

### 12. Dead `href="#"` on the order-confirmation WhatsApp button
`inc/checkout-view.php:60` returns `'#'` when no number is filtered in, and `thankyou.php:182` renders it unconditionally as the page's **primary** ink button. Every other place got this right and hides the control instead (`inc/pdp.php:181`, `inc/cart.php:61`).
**Fix**: `if ( slk_whatsapp_url(...) )` guard around the anchor, same as the PDP.

---

### 13. Designed checkout affordances that are simply absent
All verified by grep across `local/`:

| Design | Where | Status |
|---|---|---|
| `+94` prefix affix beside the phone input | `06-mobile.html:292` | **missing** — plain field |
| `+ Rs. 150` shown on the COD option row | `06-mobile.html:336` | **missing** — fee only appears in the totals; no `woocommerce_gateway_title` filter |
| "Pay in 3 with Koko is available on orders over Rs. 10,000. You are Rs. X away." | `01-gaps.html:93` | **missing** — `grep -i koko themes/` → 0 hits, though `SLK_Payments:113` says "that line is the theme's to render" |
| "Switch to cash on delivery" recovery button in the email-required error | `01-gaps.html:46` | **missing** |
| Bank-transfer expanded panel: bank/account/branch/reference rows + "Copy details" + "Send slip on WhatsApp" | `01-gaps.html:61–77` | **missing** — stock BACS `payment_box` |
| Per-field inline error text (red label, red 1.5px border, `!` glyph, message under the field) | `01-gaps.html:28,43` | **partially missing** — `checkout-view.php:172` styles `.woocommerce-invalid-message`, which classic-checkout WooCommerce never emits. Errors only appear in the top notice list |
| Round `!` badge on the error summary toast | `01-gaps.html:16` | **missing** — `style.css:860` kills `::before` and adds nothing back |
| `"Placing your order…"` spinner-in-button on submit | `01-gaps.html:99` | **missing** — `.slk-btn--loading` exists in `style.css:208` but nothing applies it; `#place_order` has no loading state |

---

### 14. Cart line shows two prices where the design shows one
**Design** (`06-mobile.html:230`): one figure per line, top-right.
**Built**: `cart.php:140` prints `get_product_price()` (unit) top-right **and** `:211` prints `get_product_subtotal()` (line total) in the footer row. `style.css:1680` had the correct rule (`td.product-price{display:none}`) but it targets the table markup this template no longer emits.
**Fix**: drop `.slk-cart-item__price` and keep `.slk-cart-item__subtotal`, or hide the unit price via `.slk-cart-item__price{display:none}` in `inc/cart.php`.

---

### 15. Price renders `Rs.12,500`, not `Rs. 12,500`
Brand guidelines §7 and every mockup: *"prefix, space, comma thousands, no decimals"*. `plugins/slk-checkout/slk-checkout.php:31` sets the symbol to `Rs.` but nothing sets the format — WooCommerce's default left position is `%1$s%2$s`, no space.
**Fix**: `add_filter('woocommerce_price_format', fn($f,$pos) => 'left' === $pos ? '%1$s&nbsp;%2$s' : $f, 10, 2);` (or set `woocommerce_currency_pos` to `left_space` at activation, alongside the existing decimals note in `class-slk-money.php`).

---

### 16. PDP long-form content is the wrong component
**Design** (`06-mobile.html:192–206`): three hairline accordions — Fabric & care / Measurements / Delivery & exchanges — then a "The drape, up close" 4:3 detail image.
**Built**: `.slk-accordion` is fully styled (`style.css:1492–1509`) but nothing renders it; `woocommerce_output_product_data_tabs` stays hooked, and `style.css:1512` restyles it as a **pill tab strip**. The detail-image block does not exist. Likewise `.slk-assurances`/`.slk-assurance` (`style.css:1478`) is dead — `inc/pdp.php:224` invented a parallel `.slk-pdp__trust` with different geometry (no glass fill, no 18px radius, no border) than the design's glass rows at `06-mobile.html:185–190`.
**Fix**: replace the tabs with `<details class="slk-accordion">` on `woocommerce_after_single_product_summary`, and either render `.slk-assurance` markup or delete the duplicate.

---

### 17. Shop filter rail is styled but never rendered
`.slk-shop-layout`, `.slk-filters`, `.slk-filters__group`, `.slk-chips`, `.slk-chip` (`style.css:1204–1232`, `:1244–1251`) have no producer. The design's Shop screen leads with a Filters pill + removable chips (`06-mobile.html:100–106`) and the desktop archive is 240px sidebar + 3-up grid (`07-desktop.html:176`). `inc/shop.php` only touches the grid.

---

### 18. Wordmark renders at 17px instead of 19/21px, and loses its 44px target
`style.css:585` sets `.slk-wordmark{font-size:19px}` (21px at 1000px) — matching `06-mobile.html:12` and `07-desktop.html:12`. `inc/wordmark.php:215` then emits `.slk-wordmark--md{font-size:var(--slk-text-lg)}` = **17px**, at equal specificity, in inline CSS printed after the stylesheet → 17px wins at every width. The same inline block re-declares `.slk-wordmark{display:inline-block}` over `style.css:593`'s `inline-flex; min-height: var(--slk-touch)`, so the wordmark link drops below the 44px floor except in the `--site` variant.
**Fix**: make `slk_wordmark_sizes()['md']` resolve to the header size (or delete the `--md` size rule and let §3.2 own the header wordmark), and drop `display:inline-block` from the inline CSS.

---

### 19. Product card media is double-boxed
`inc/shop.php:149` wraps the image in `.slk-card__media` (ratio 3/4, 20px radius, `--slk-shadow-lift`, `overflow:hidden`). `style.css:1115` then gives the inner `<img>` the *same* `aspect-ratio`, the *same* radius, a *second* `--slk-shadow-lift`, and `margin: 0 0 10px` that is clipped by the parent's `overflow:hidden`.
**Fix**: reduce `style.css:1115` to `{display:block;width:100%;height:100%;object-fit:cover}` now that the wrapper always exists.

---

### 20. Quantity stepper buttons are 36px — under the 44px floor
Guidelines §8 and `--slk-touch` say 44×44 minimum; the mockup uses 40px buttons inside a taller pill (`06-mobile.html:235`). `inc/cart.php:235` ships `width:36px;height:36px`.
**Fix**: 44px, or 40px with a 44px hit area via padding.

---

### 21. Raw hex re-introduced in the inline CSS, after §3 named tokens to prevent exactly this
`style.css:433` defines `--slk-color-white`, `--slk-color-disabled-ink`, `--slk-color-error-ink` specifically so nothing below §2 writes a literal. The `inc/` files ignore them:
- `inc/checkout-view.php:184` — `color:#7a1f19` → `var(--slk-color-error-ink)`
- `inc/checkout-view.php:200` — `background:#fff` → `var(--slk-color-white)`
- `inc/pdp.php:353` — `color:#b5b1a9` → `var(--slk-color-disabled-ink)`
- `inc/cart.php:236` — `background:#fff` → `var(--slk-color-white)`
- `inc/pdp.php:278` — `aspect-ratio:3/4` → `var(--slk-ratio-portrait)`

---

### Checks that came back clean
Canvas scaffolding (`.sec` / `.fr` / `.scr` / `.rail` / `image-slot`) — **not** ported; grep across `local/` returns zero. Tokens are verbatim-identical to `design/assets/design-tokens.css`. Glass recipe (`blur(22px) saturate(1.4)` + 1px `--slk-glass-edge` + 24px radius) is correct wherever it is written. Motion is `280ms cubic-bezier(.22,1,.36,1)` throughout with `translateY(-2px)` hover / `scale(.97)` press, and reduced-motion collapses durations and releases sticky panels. Everything is genuinely mobile-first — desktop is always behind `min-width`. No sale badge, no `<del>`, no "only N left" survives (CSS + `remove_action` + `woocommerce_get_price_html`). Display type is Newsreader 300 everywhere the design uses it; card names and prices are correctly Archivo.