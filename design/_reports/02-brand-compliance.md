## 1. THE NAME — clean, with one hole in the "one-line rename" claim

No occurrence of `AESHAL`/`Aeshal` reaches any rendered string, class name, attribute or CSS selector. The single hit is a PHP comment:

- `C:/ClaudeCode/sldress/local/themes/slk-child/inc/wordmark.php:7` — explanatory comment only, never output. **Not a violation.**

The wordmark genuinely comes from one filter (`slk_wordmark`, `wordmark.php:42`), and `get_custom_logo` (:168) + `bloginfo` (:190) both route through `slk_wordmark_text()`.

**VIOLATION — the rename is not actually one line.**
`C:/ClaudeCode/sldress/local/themes/slk-child/inc/wordmark.php:190-201`
The `bloginfo` filter only covers `get_bloginfo()`. `WC_Email::get_from_name()`, the `{site_title}` placeholder in every order email subject/heading, and the email footer all read `wp_specialchars_decode( get_option( 'blogname' ) )` **directly**, bypassing the filter. After G1, `add_filter( 'slk_wordmark', … )` renames the header and `<title>` but leaves the old name on every transactional email, invoice and packing slip. The `is_admin()` early return (:171, :193) means wp-admin also keeps the stale name.

Fix — add to `wordmark.php` beside the existing filters:
```php
add_filter( 'pre_option_blogname', 'slk_wordmark_text' );
```
and delete the `is_admin()` guards, or the rename is a two-place change and the docblock at :12-15 is wrong.

---

## 2. SALE THEATRE — suppressed on the classic paths; two real gaps

Confirmed working: `functions.php:145-154` removes both `sale_flash` actions and empties the `woocommerce_sale_flash` filter; `shop.php:272-283` collapses `<del>/<ins>` at `woocommerce_get_price_html`; `style.css:1277-1291` hides `.onsale` and `del`. No countdown, no "only N left", no percentage badge exists anywhere.

**VIOLATION — `del` suppression is scoped to `.woocommerce`, `.onsale` is not.**
`C:/ClaudeCode/sldress/local/themes/slk-child/style.css:1285-1291`
`span.onsale` is written unscoped (:1277) precisely so it wins everywhere, but the struck-price block underneath requires a `.woocommerce` ancestor. WooCommerce block markup (Product Collection / Product Price block) on the home page or any non-Woo page carries **no** `.woocommerce` wrapper and no `body.woocommerce` class, so `<del>` renders. The PHP filter is currently the only thing standing between the brand and a strikethrough there.

Fix — drop the prefix, matching how `.onsale` is handled two rules above:
```css
del,
.price del,
.wc-block-components-product-price del { display: none !important; }
```

**VIOLATION — the sale filter corrupts variable-product prices.**
`C:/ClaudeCode/sldress/local/themes/slk-child/inc/shop.php:279`
```php
return wc_price( wc_get_price_to_display( $product ) );
```
`wc_get_price_to_display()` falls back to `$product->get_price()`, which for a variable product is the **minimum** variation price. The moment any single variation is put on sale, a `Rs. 8,000 – Rs. 12,000` range collapses to a bare `Rs. 8,000` on the card and the PDP — a price most sizes cannot be bought at. That is worse than the theatre it replaces.

Fix:
```php
if ( $product->is_type( 'variable' ) ) {
    $min = wc_get_price_to_display( $product, array( 'price' => $product->get_variation_price( 'min', true ) ) );
    $max = wc_get_price_to_display( $product, array( 'price' => $product->get_variation_price( 'max', true ) ) );
    return $min === $max ? wc_price( $min ) : wc_format_price_range( $min, $max );
}
return wc_price( wc_get_price_to_display( $product ) );
```

**VIOLATION — the promised PHP half of the stock suppression was never written.**
`C:/ClaudeCode/sldress/local/themes/slk-child/style.css:1299-1306` states *"The text itself should be filtered out in PHP (`woocommerce_get_stock_html`)"*. Grep of the whole tree: no `woocommerce_get_stock_html`, no `woocommerce_stock_format`, no `get_availability` filter exists. `"Only 2 left in stock"` is generated and shipped in the HTML on every PDP, hidden by a CSS rule scoped to `.woocommerce div.product`. One parent-theme wrapper change and scarcity copy is on screen.

Fix — add to `functions.php` §5:
```php
add_filter( 'woocommerce_get_stock_html', static function ( $html, $product ) {
    return $product->is_in_stock() ? '' : $html;
}, 20, 2 );
```

---

## 3. CURRENCY

No hardcoded symbol reaches customer output. `Rs.` appears exactly once as behaviour — `slk-checkout.php:33`, the `woocommerce_currency_symbol` filter, which is the correct single place and feeds `wc_price()`. Everything else is docblocks. `wc_price()` is used correctly at `shop.php:279`; every other amount comes from `WC()->cart->get_product_price()`, `get_price_html()` or `get_formatted_order_total()`.

**VIOLATION — nothing in the shipped code guarantees zero decimals.**
`C:/ClaudeCode/sldress/local/plugins/slk-checkout/includes/class-slk-money.php:10-14` deliberately refuses to filter `wc_get_price_decimals()` and defers to a manual WooCommerce setting. The only thing that sets it is `C:/ClaudeCode/sldress/local/bootstrap.sh:64` (`woocommerce_price_num_decimals 0`), which is the **local Docker dev bootstrap** and never runs on the production install. On a fresh host, every price on the site renders `Rs. 12,500.00`, breaking §7 outright. The plugin's own reasoning (line-rounding, gateway hashing) is sound but the mitigation is missing.

Fix — either enforce the option on plugin activation (safe, one-time, does not change runtime maths):
```php
register_activation_hook( __FILE__, static function () {
    update_option( 'woocommerce_price_num_decimals', 0 );
    update_option( 'woocommerce_currency_pos', 'left_space' );
    update_option( 'woocommerce_price_thousand_sep', ',' );
} );
```
or add it to the production deploy checklist as a hard gate. It cannot stay only in `bootstrap.sh`.

**Minor — hardcoded currency in admin labels.**
`class-slk-shipping-method.php:50, 59, 68, 77` — `'Colombo & Gampaha (Rs.)'` etc. Admin-only, but should read `sprintf( __( 'Colombo & Gampaha (%s)', 'slk' ), get_woocommerce_currency_symbol() )`.

---

## 4. ACCESSIBILITY — the worst category

### 4a. Colour contrast — `--slk-color-faint` fails 4.5:1 in every text use

Token: `--slk-color-faint: #8a867e` (`style.css:45`).

Working (WCAG 2.x relative luminance):
- `#8a867e` → R 138/255=0.5412 → ((0.5412+0.055)/1.055)^2.4 = 0.25409; G 134 → 0.23837; B 126 → 0.20863. **L = 0.2126(0.25409)+0.7152(0.23837)+0.0722(0.20863) = 0.23957**
- `#f2f0ec` (ground) → 0.8880 / 0.8715 / 0.8389 → **L = 0.87266**
- **Ratio on ground = (0.87266+0.05)/(0.23957+0.05) = 0.92266 / 0.28957 = 3.19:1** ✗
- On `#ffffff` (input/field surfaces): 1.05 / 0.28957 = **3.63:1** ✗
- On `.slk-panel` glass-solid over ground (≈`#faf9f7`, L=0.94881): 0.99881 / 0.28957 = **3.45:1** ✗
- On footer `rgba(255,255,255,.4)` over ground (≈`#f7f6f3`, L=0.92257): **3.36:1** ✗

`brand-guidelines.md:52` claims *"Every text pair listed clears 4.5:1"*. That statement is false for this token, and "meta only" is not a WCAG exemption. Every one of these is a violation:

| File:Line | Element |
|---|---|
| `style.css:255` | `.slk-field__hint` — 11.5px form hints |
| `style.css:691` | `.slk-footer__col > .slk-eyebrow` |
| `style.css:914` | `.form-row .optional`, `.form-row .description` |
| `style.css:1056` | `.woocommerce-result-count` |
| `style.css:1070,1072` | `.woocommerce-breadcrumb` and its links |
| `style.css:1702` | cart `a.remove` "×" |
| `style.css:1761` | `.woocommerce-shipping-destination`, `.shipping-calculator-button` (a link) |
| `style.css:1928` | privacy-policy + terms text, 11.5px — legal copy at 3.6:1 |
| `style.css:1966` | review-order `.product-quantity` |
| `inc/cart.php:218` | `.slk-cart__count` |
| `inc/cart.php:241` | `.slk-cart-item__remove` — the Remove link |
| `inc/checkout-view.php:168` | `.slk-checkout .form-row .optional` |
| `inc/checkout-view.php:218` | `#order_review .product-quantity` |

Fix — one edit, `style.css:45`: `--slk-color-faint: #6e6b64;` (L = 0.14752 → **4.67:1** on ground, **5.32:1** on white; passes on all four backgrounds above). Keep `#8a867e` only if you rename it to a non-text token and swap all thirteen call sites to `--slk-color-muted` (#5f5c56, 5.85:1).

Especially damning: `class-slk-checkout-fields.php:325-334` goes to real trouble to strip `aria-hidden="true"` off field descriptions so screen readers get them — then tags them `.slk-field__hint`, which renders those same hints at 3.6:1 for sighted low-vision users.

### 4b. Field hints render as errors

`C:/ClaudeCode/sldress/local/themes/slk-child/inc/checkout-view.php:172-173`
```css
.slk-checkout .form-row .woocommerce-input-wrapper .description,
.slk-checkout .form-row .woocommerce-invalid-message{ … color:var(--slk-color-error) }
```
`woocommerce_form_field()` renders `<span class="description">` **inside** `<span class="woocommerce-input-wrapper">`. So every neutral helper hint — "This is the number we call to confirm.", "Optional — needed only for card payment.", "Couriers navigate by this, not postcodes" — renders in error red `#9a2820` on a valid, untouched form. Colour is carrying a meaning it does not have (WCAG 1.4.1), and it makes a calm checkout look like it is already failing.

Fix — scope the error colour to the error element only:
```css
.slk-checkout .form-row .woocommerce-invalid-message{ …; color:var(--slk-color-error) }
.slk-checkout .form-row .woocommerce-input-wrapper .description{ …; color:var(--slk-color-muted) }
```

### 4c. Touch targets under 44px

Guideline §8: 44×44 minimum, **8px apart**.

| File:Line | Control | Actual |
|---|---|---|
| `inc/cart.php:235` | `.slk-qty__btn` (−/+ in cart) | **36×36**, and `gap:2px` (`:234`) |
| `inc/cart.php:239` | `.slk-qty__input` | **min-height 36px**, width 34px |
| `style.css:1461-1463` | `.slk-stepper button` | **40×40**, `gap:2px` (`:1456`) |
| `style.css:1220-1224` | `.slk-chip` (removable filter chip) | **min-height 36px** |
| `style.css:1211-1215` | `.slk-filters__group label` | **min-height 38px** |
| `style.css:618` | `.slk-header__actions` | 44px buttons but **`gap: var(--slk-space-1)` = 4px** |

The comment at `style.css:621-622` — *"8px apart is satisfied by the 4px gap plus the 2px inner padding of each circle's hit area"* — is not true. `.slk-icon-btn` (`:623-633`) sets `width/height: var(--slk-touch)` with `background:none` and no padding that shrinks the hit box; the clickable area is the full 44px. Real separation is 4px.

Fix: raise all six to `var(--slk-touch)` (`.slk-qty__input` needs `min-height:44px;width:44px`), and change `style.css:618` to `gap: var(--slk-space-2)`.

### 4d. Sold-out state is effectively invisible

`inc/pdp.php:352-357` and `style.css:291-298` set disabled size/colour buttons to `#b5b1a9` plus a 1px 18%-alpha strike line. `#b5b1a9` on ground: L = 0.44129 → **1.88:1**. Technically WCAG-exempt as an inactive control, but "sold out" is the only signal being carried and it is below the threshold of legibility on a phone in daylight. Additionally `pair.btn.disabled = …` (`pdp.php:462`) removes those buttons from the tab order, so a keyboard user cannot discover which sizes are gone at all.

Fix: use `aria-disabled="true"` + `pointer-events:none` instead of `disabled`, set the label to `--slk-color-muted`, and append a visible `Sold out` text token rather than relying on the strike.

### 4e. Focusable-but-invisible `<select>`

`inc/pdp.php:360-363` clips the real variation `<select>` to 1×1 after the button UI is built (`:473`), but leaves it in the tab order. A keyboard user tabs into a control they cannot see, with the focus ring drawn on a 1px box. Fix: `.slk-pdp__sr-select{ …; }` plus `select.slk-pdp__sr-select{ tabindex }` — set `select.tabIndex = -1` and `select.setAttribute('aria-hidden','true')` in `initForm()` at the same place the class is added, since the buttons are now the accessible control.

### 4f. Interactive element nested inside a `<label>`

`inc/pdp.php:479-483` appends the "Size guide · cm" `<a>` into `row.querySelector('th.label label')`. A `<label for=…>` forwards clicks to its control, so the size-guide link is inside a click-forwarding region; its href is also the dead placeholder `#slk-size-guide` (`:510`). Move the anchor to a sibling of the `<label>` inside `th.label`, and hide it until `slk_pdp_size_guide_url` returns a real URL (same pattern already used correctly for WhatsApp at `pdp.php:181`).

### 4g. Colour conveyed by colour alone

`inc/shop.php:207-213` renders swatches as `<span class="slk-swatch" style="background:#hex" title="Name">`. `title` on a non-focusable `<span>` is not exposed to screen readers or touch. The colour options on a card have no text alternative (WCAG 1.4.1). Fix: add `<span class="slk-sr-only">` with the term name inside each swatch.

### 4h. Component collision — `.slk-swatch` defined twice at conflicting sizes

`style.css:273` defines `.slk-swatch` as 13×13 (grid card dots); `inc/pdp.php:341-349` redefines the same class as 44×44 with a border and glass fill. Both land on the `slk-child` handle, PDP's inline block is appended later and wins. On any PDP, the related-products grid (which uses `slk_template_loop_swatches`) renders 44px bordered circles instead of 13px dots. Rename the PDP control to `.slk-pdp__swatch` (the group is already `.slk-swatch-group`) — the `.slk-swatches--lg` variant at `style.css:1429-1437` already exists for exactly this and is unused.

**Clean within this category:** no `outline:none` / `outline:0` anywhere in the tree; `:focus-visible` is a 2px ink outline restated in three places (`style.css:147`, `:1032-1041`, `wordmark.php:241`). No placeholder-as-only-label — every field in `class-slk-checkout-fields.php` carries a real `label`, and the coupon input at `woocommerce/cart/cart.php:246` has a visible `<label for="coupon_code">` at `:238`. Icon-only buttons all carry `aria-label` (`pdp.php:194`, `cart.php:165,188,198`).

---

## 5. VOICE

**VIOLATION — exclamation mark.**
`C:/ClaudeCode/sldress/local/themes/slk-child/inc/cart.php:175`
```php
$whatsapp_url = slk_whatsapp_url( __( "Hi! I'm looking for something specific.", 'slk' ) );
```
`brand-guidelines.md:36`: *"Never: urgency, scarcity theatre, exclamation marks…"*. This is user-facing — it is the message body pre-filled into her WhatsApp composer, in the brand's voice.

Fix: `__( "Hi, I'm looking for something specific.", 'slk' )` — matching the comma already used correctly at `pdp.php:187` and `checkout-view.php:63`.

No emoji (checked against the pictographic ranges), no "shop now"/"buy now", no urgency, scarcity or countdown language, and no modesty-as-pitch phrasing anywhere in the tree. Copy is otherwise on-voice — "Nothing in your bag yet.", "A real person, not a robot.", "Couriers navigate by this, not postcodes", "Nothing to pay yet — keep %s ready for the courier."

**Advisory, not a violation:** `inc/pdp.php:219-221` uses `✓ ⇄ ◷` as trust-row markers. These are U+2713 / U+21C4 / U+25F7 — dingbats with default *text* presentation, not emoji code points, and they are `aria-hidden`. Strictly compliant. Worth noting anyway that Archivo has no glyph for U+21C4 or U+25F7, so those two will fall back to an arbitrary system font and break the type discipline in §4.

---

## One non-category defect worth surfacing

`C:/ClaudeCode/sldress/local/themes/slk-child/woocommerce/checkout/thankyou.php:182` renders the primary "Track this order on WhatsApp" button unconditionally, and `slk_whatsapp_track_url()` (`inc/checkout-view.php:60`) returns `'#'` when no number is configured — which is the current state. Every completed order currently ends on a dead primary CTA. `inc/cart.php:177` and `inc/pdp.php:181` both correctly suppress the button when the number is empty; this file does not. Fix: wrap the anchor in `if ( '#' !== $slk_whatsapp_url )`.