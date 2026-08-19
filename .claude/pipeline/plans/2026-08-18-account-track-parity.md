# Plan: account & track-order repair + approved parity features

> Authored 2026-08-18. Status: DRAFT.
> Self-contained: implementing subagents see ONLY this file, never the conversation.

## Objective

The live store https://mavea.lk (WooCommerce 11.0.1, Blocksy 2.1.52 parent,
slk-child theme, three slk-* plugins) has a working but invisible/broken-looking
account system, and an order tracker that phone-only customers cannot use at
all. Repair both, and add four owner-approved features: an announcement line,
payment chips, a wishlist, and a native order-help assistant.

Everything lands as repo code under `local/`. Deployment is a separate operator
step and is NOT part of this work.

## Ground truth (verified today — trust this, do not re-derive)

- Theme root `local/themes/slk-child/`. Plugins `local/plugins/slk-checkout/`,
  `slk-order-flow/`, `slk-exchanges/`.
- **Styling mechanism**: each `inc/*.php` area file enqueues a heredoc CSS block
  through `wp_add_inline_style( 'slk-child', $css )` on `wp_enqueue_scripts`
  priority 31. Account CSS is gated on `is_account_page()` (account.php:552-554);
  pages-support CSS is unconditional. Blocksy's stylesheets load earlier, so slk
  rules win specificity ties.
- **Core `woocommerce.css` is deliberately NOT loaded.** Any core-class markup
  without an explicit slk rule renders unstyled. This is why un-overridden Woo
  screens look broken.
- Registration IS enabled and already rendering on /my-account/ (email-only).
  `woocommerce_registration_generate_username` and `..._generate_password` are
  both `yes` (auto-generate; a set-password link is emailed). **Do not change
  either option.**
- `woocommerce/myaccount/form-login.php` is a theme override that keeps ALL core
  hooks, including `woocommerce_register_form` at :110 — new registration fields
  hook there; no template surgery needed for the fields themselves.
- The four custom order statuses (`wc-pending-confirm`, `wc-confirmed`,
  `wc-dispatched`, `wc-rto`) are **labels only** (inc/account.php:39-56). Nothing
  calls `register_post_status()`. Real orders sit in stock statuses. Display via
  `wc_get_order_status_name()`. Do not register statuses in this plan.
- LiteSpeed page-caches anonymous HTML; admin-ajax is never cached. All dynamic
  reads (wishlist toggle, assist lookup) go through admin-ajax with nonces.
- SL mobile regex, house standard: `^(?:\+94|0)?7\d{8}$`. Normalise by stripping
  non-digits and comparing the last 9 digits on both sides.

### Brand law (non-negotiable)

- Porcelain Glass tokens only (`--slk-*`): card 24px, tile 20px, field 16px,
  pill 999px; ink `#232220` on ground `#f2f0ec`; **no accent colour**; motion
  280ms `cubic-bezier(.22,1,.36,1)`; touch targets 44px; body >= 14.5px, meta
  >= 11px.
- No urgency, no exclamation marks, no emoji, no scarcity, no sale badging, no
  countdowns, no popups.
- Never name a town as where the clothes are made. Delivery district lists are
  the one exception.
- **Never invent a business fact.** No WhatsApp number, phone, email address,
  Instagram handle, street address, or policy copy. `slk_whatsapp_number()`,
  `slk_contact_email()` and `slk_contact_instagram_url()` all currently return
  `''` with no filter registered anywhere — their gated surfaces must stay dark.
  Where a fact is missing, render nothing.
- Currency renders only through `wc_price()`. Never type a currency symbol.
- All user-facing strings translatable in the `slk` text domain, matching the
  style of neighbouring code.

## Work packages

Packages may share files; the runner sequences overlapping ones. Keep every edit
scoped to its own package's concern.

### P1 - Account screens: alignment, hierarchy, lost/reset password, registration fields

**files:** `local/themes/slk-child/woocommerce/myaccount/form-login.php`,
`local/themes/slk-child/inc/account.php`,
NEW `local/themes/slk-child/woocommerce/myaccount/form-lost-password.php`,
NEW `local/themes/slk-child/woocommerce/myaccount/form-reset-password.php`

1. **Width/alignment.** `.slk-auth-head` (form-login.php:26-29) and
   `.slk-auth-guest` (:126-128) sit outside `#customer_login`, whose only width
   constraint is `max-width:420px;margin:0 auto` (account.php:564-566). Result:
   "Welcome back." left-aligns across the full 1140px container above a 420px
   centred column. Fix in CSS (not by moving them, so core hook order is
   untouched): give both `max-width:420px;margin-inline:auto`.

2. **Blocksy divider bleed.** Blocksy's `.ct-woo-unauthorized .col2-set > *`
   rules survive the theme's `display:block` stacking override, adding 40px
   inline padding plus a dashed border between the two stacked cards at >=690px,
   and a dashed bottom border variant below that. Reset both breakpoints inside
   the account CSS block: zero the padding and border on the first and last
   children.

3. **Symmetric headings.** The login card's `<h2>` is `screen-reader-text`
   (form-login.php:39) while the register card's `<h2>` (:77) is visible and
   unstyled, so it falls to Blocksy's 20-35px bold above an h1 set at 24px.
   Give both a visible heading with a shared class `slk-auth-card__h`
   ("Sign in" / "Create an account"), and if the register heading sits outside
   `.woocommerce-form-register`, move it inside so both cards match. Style:
   `font:400 20px/1.2 var(--slk-font-display); margin:0 0 var(--slk-space-4);
   color:var(--slk-color-ink)`.

4. **Small text.** Give the classless auto-password note (form-login.php:106)
   a class `slk-auth-note` and style it `font:400 12.5px/1.55 var(--slk-font-ui);
   color:var(--slk-color-muted)`. Style
   `.woocommerce-account .woocommerce-privacy-policy-text` the same, with its
   link `color:var(--slk-color-ink)` and a 2px underline offset — this kills the
   Blocksy blue link inside the glass card. Leave the link's href alone; the
   privacy page is an unpublished draft and publishing it is not this plan's job.

5. **Remember-me collision.** account.php:585 sets the rememberme label to flex,
   but account.php:773 (`.woocommerce-account .form-row label`) is more specific
   and forces `display:block`, killing the 8px gap. Narrow :773's selector with
   `:not(.woocommerce-form-login__rememberme)`.

6. **Registration fields** (decision: email + full name + mobile, passwordless).
   In inc/account.php add a section hooked on `woocommerce_register_form` that
   renders two fields using the existing
   `.woocommerce-form-row.form-row-wide` + `input-text` pattern, so the current
   CSS mapping (account.php:579-584) already styles them:
   - `reg_full_name` — label "Full name", required, `autocomplete="name"`.
   - `reg_phone` — label "Mobile number", required, `type="tel"`,
     `autocomplete="tel"`, hint "The number we confirm orders on."
   Validate on `woocommerce_register_post` (append to the `$errors` object):
   name must be non-empty; phone must match the SL regex after whitespace is
   stripped, error copy "Enter a Sri Lankan mobile number, like 07X XXX XXXX."
   Persist on `woocommerce_created_customer`: split the full name on the first
   space into `first_name` / `last_name`, set `display_name`, and store
   `billing_phone`. Sanitise every POST read with `wp_unslash` +
   `sanitize_text_field`. Repopulate both fields on validation failure.

7. **Lost/reset password overrides** (NEW). Base them on the core reference
   copies under `design/_reference/woocommerce-templates/myaccount/`, keeping the
   template-version header convention used by the existing form-login.php
   override. Wrap each in the established pattern: a `.slk-auth-head` block with
   an h1 and a one-line intro in house voice (lost: "Enter your email or username
   and we will send a link to set a new one."), then a single glass card in a
   `.slk-auth-single` wrapper constrained to 420px and centred, fields in the
   `woocommerce-form-row` pattern.
   **Add `woocommerce-form-login__submit` to each submit button's existing core
   classes** so the current pill rule (account.php:586-591) styles it — this
   removes the raw blue Blocksy button with zero new button CSS.
   Keep every core hook (`woocommerce_lostpassword_form`,
   `woocommerce_resetpassword_form`, and the form tags).

### P2 - Chrome: desktop account icon, announcement line, footer payment chips

**files:** `local/themes/slk-child/inc/chrome.php`,
`local/themes/slk-child/style.css`

1. **Desktop account icon.** The header has wordmark, drawer-only Menu, Search
   and Bag — no account route. At >=1000px the hamburger is hidden
   (chrome.php:411-413), so the only desktop path to sign-up is a footer link.
   Add an anchor in the header actions cluster: class
   `slk-icon-btn slk-icon-btn--account`, href `wc_get_page_permalink('myaccount')`,
   `aria-label` "Account", an inline SVG person glyph drawn to match the existing
   search/bag glyphs (ink stroke ~1.5px, same viewBox convention). Hidden by
   default, shown `inline-flex` inside the existing >=1000px media block so mobile
   keeps the drawer pill. 44px target.

2. **Announcement line.** A single quiet line above the header pill — ground
   coloured, not a banner: centred, `font:500 11px/1 var(--slk-font-ui)`,
   `letter-spacing:var(--slk-track-label)`, uppercase,
   `color:var(--slk-color-muted)`, ~10px vertical padding.
   **The copy is computed, never hardcoded.** Read the free-delivery threshold
   from the live `slk_delivery` shipping method (inspect
   `local/plugins/slk-checkout/includes/class-slk-shipping.php` for the real
   setting key; guard with `class_exists`/`method_exists`) and COD availability
   from `WC()->payment_gateways()->get_available_payment_gateways()`.
   - threshold + COD -> "Cash on delivery island-wide - Free delivery over
     {threshold}" (threshold through `wc_price()`, then
     `wp_strip_all_tags( html_entity_decode( ... ) )` as done elsewhere).
   - COD only -> "Cash on delivery island-wide".
   - neither resolvable -> render nothing at all. No fallback copy, no
     exclamation marks.

3. **Footer payment chips.** Add a "We accept" row built from
   `get_available_payment_gateways()`, rendering one text chip per gateway using
   its `get_title()`. **Text chips, not logos** — do not fabricate or embed brand
   marks. Render nothing when the list is empty. Chip style: `font:500 11px`,
   `letter-spacing:.08em`, `border:1px solid var(--slk-hairline)`,
   `border-radius:var(--slk-radius-pill)`, `padding:6px 12px`,
   `color:var(--slk-color-muted)`. Today this shows Cash on delivery only;
   PayHere/Mintpay chips appear automatically when those gateways activate,
   which is the point.

### P3 - Track order: layout, result template, phone-aware lookup

**files:** `local/themes/slk-child/woocommerce/order/form-tracking.php`,
NEW `local/themes/slk-child/woocommerce/order/tracking.php`,
`local/themes/slk-child/inc/pages-support.php`,
`local/themes/slk-child/page-templates/track-order.php`

1. **Form layout.** Blocksy's unscoped `.form-row-first/.form-row-last`
   (`width:48%`, floated, >=690px) makes the two fields sit side by side inside a
   600-760px shell, unlike every other slk form. In pages-support.php's CSS block
   scope a reset under `.slk-track__panel`: `float:none; width:100%`.
   Delete the orphaned `p:first-child` rule (:208-211) — it was written for a
   stock intro paragraph that the form-tracking override deliberately removed, and
   now hits the first field, giving the two columns unequal bottom margins.
   Replace with a uniform `.slk-track__panel .form-row` margin.
   Add `slk-btn slk-btn--primary` to the submit button in form-tracking.php
   (keeping `name="track"` and its core classes) so it stops depending on the
   `button[name="track"]` attribute selector.

2. **Field label.** Change the second field's label to "Email or mobile number"
   and its placeholder to "The email or number you used". Keep
   `name="order_email"` unchanged — core posts that name; the resolver
   interprets it.

3. **Phone-aware lookup.** In page-templates/track-order.php, replace the bare
   `do_shortcode( '[woocommerce_order_tracking]' )` with: if
   `class_exists( 'SLK_Track' )` and the tracking form was POSTed with a valid
   `woocommerce-order_tracking` nonce, call `SLK_Track::resolve()`. On failure
   print a neutral error through `wc_print_notice( ..., 'error' )`: "We could not
   find that order. Check the number and the email or mobile you used." On
   success render the new result template via `wc_get_template()`. If the class
   is absent, fall back to the original shortcode call verbatim. Always render
   the form again beneath a failed lookup, matching core behaviour.

4. **Result template** (NEW `order/tracking.php`). Base it on
   `design/_reference/woocommerce-templates/order/tracking.php`, keeping its
   version header. Porcelain result card `.slk-track__result` on a panel:
   - Order number and date as plain spans in meta type — **no `<mark>` elements**
     (stock markup uses them and they render with the browser's default yellow
     highlight, since the only mark reset in the theme is scoped to
     `.woocommerce-account`).
   - Status headline via `wc_get_order_status_name()` at display-serif 20px.
   - A three-step timeline reusing the `.slk-step` markup and classes from
     `woocommerce/checkout/thankyou.php` (read it first and match exactly).
     Map stock statuses: pending/on-hold -> step 1, processing -> step 2,
     completed -> step 3. For cancelled/refunded/failed render a single muted
     line instead of the timeline.
   - Customer notes from `$order->get_customer_order_notes()` as a simple list
     (date meta + body), without the stock `comment_container`/`comment-text`
     nesting that the theme does not style.
   - Escape everything (`esc_html`, `wp_kses_post`).
   If `.slk-step` rules are gated to checkout rather than global, duplicate the
   minimal ones into pages-support.php scoped under `.slk-track__result` — check
   first, and do not define them twice.

### P4 - SLK_Track resolver

**files:** NEW `local/plugins/slk-order-flow/includes/class-slk-track.php`,
`local/plugins/slk-order-flow/slk-order-flow.php`

This is the correctness heart: it is what lets a phone-only COD customer track an
order at all, and it must not become an order-enumeration surface.

1. `class SLK_Track` exposing one public static:
   `resolve( $order_id, $contact )` returning `WC_Order|null`.
   - `$order_id`: strip `#` and whitespace, then `absint`.
   - `$contact`: trim. If it contains `@`, compare case-insensitively against
     `$order->get_billing_email()`. Otherwise strip all non-digits; if what
     remains ends in 9 digits beginning with `7`, compare those last 9 digits
     against the last 9 digits of `$order->get_billing_phone()`. Anything else
     returns null.
   - The order must load through `wc_get_order()` **and** the contact must match,
     or return null. Never reveal which half failed. Never query by phone alone.
   - Only consider an additional phone meta key if one genuinely exists — check
     slk-checkout's checkout-fields class for a second-phone field. Do not
     invent a meta key.
2. Require it from slk-order-flow.php beside the existing includes, following the
   same pattern (read :35-43 first). It has no hooks, so no init call.
3. `defined( 'ABSPATH' ) || exit;` header. Writes no options.

### P5 - Checkout sign-up clarity

**files:** `local/plugins/slk-checkout/includes/class-slk-checkout-fields.php`

The core "Create an account?" checkbox is currently relabelled by a gettext
filter (:441-447) to "Save my details for next time" — which reads as a
convenience toggle, so neither shoppers nor the owner can tell it creates an
account.

1. Change the replacement string to **"Create an account and save my details"**,
   through the same checkout-gated gettext mechanism.
2. Extend the hint printed at :457-464 to two short lines in the same element:
   the existing "Orders on an account earn points towards credit." plus "We will
   email you a link to set your password."
   Email is already validated as required when the box is ticked, by the theme
   (inc/account.php:444-464) — do not duplicate that logic here.

### P6 - Wishlist: "Saved pieces"

**files:** NEW `local/plugins/slk-order-flow/includes/class-slk-saved.php`,
`local/plugins/slk-order-flow/slk-order-flow.php`,
`local/themes/slk-child/inc/shop.php`, `local/themes/slk-child/inc/pdp.php`

1. `class SLK_Saved`, mirroring the architecture of
   `local/plugins/slk-exchanges/includes/class-slk-exchange-account.php` (read it
   first: endpoint constant, one-shot rewrite-flush option, `woocommerce_get_query_vars`
   filter, `woocommerce_account_menu_items` filter, endpoint renderer).
   - Storage: user meta `_slk_saved_products`, an array of product IDs.
   - Endpoint `saved`, menu label "Saved pieces", inserted at filter priority 24
     so it lands between the theme's items (20) and Exchanges (25).
   - admin-ajax action `slk_saved_toggle`, **logged-in only** (no nopriv), nonce
     `slk-saved`, POSTs a product ID, validates the product exists, toggles, and
     returns the new state.
   - Endpoint page: a grid of saved products with image (`slk_card` size), name,
     `get_price_html()`, and an unsave control per card. Empty state: "Nothing
     saved yet. Pieces you save appear here." plus a link to the shop. No counts
     anywhere, no price-drop nudges, no sharing.
2. Theme hooks, both guarded with `class_exists( 'SLK_Saved' )`:
   - Shop cards (inc/shop.php): a heart toggle in the card media, top-right,
     `.slk-save-btn`, 44px hit area via padding, inline SVG heart — outline when
     unsaved, filled when saved. **Ink, never red.**
   - PDP (inc/pdp.php): a secondary ghost text button near Add to bag,
     "Save this piece", same glyph.
   - Logged out: the control is an anchor to
     `wc_get_page_permalink( 'myaccount' )` with `aria-label` "Sign in to save" —
     which now leads somewhere findable thanks to P1 and P2.
   - Logged in: fetch to admin-ajax, optimistic fill, revert on error. Localise
     the ajax URL and nonce using whichever pattern that file already uses
     (`wp_localize_script` on an existing handle, or the `wp_add_inline_script`
     approach used for the quantity steppers — check before choosing).

### P7 - Order-help assistant (native, no vendor)

**files:** NEW `local/plugins/slk-order-flow/includes/class-slk-assist.php`,
`local/plugins/slk-order-flow/slk-order-flow.php`

A rules-based helper. **No external service, no AI vendor, no invented answers** —
it may only surface what the site already knows.

1. `class SLK_Assist`: a floating pill, bottom-right, on all front-end pages
   **except checkout** (`is_checkout()` excluded — nothing competes with
   payment). Glass pill, >=44px, label "Help" with a drawn chat glyph in ink.
   Clicking opens a panel: fixed, `width:min(360px, calc(100vw - 32px))`,
   `max-height:70vh`, scrollable, glass card at 24px radius with the blur and
   float-shadow tokens. Move focus into the panel on open; Escape and an explicit
   close button dismiss it and return focus to the pill. Respect
   `prefers-reduced-motion` (appear, do not slide).
2. Four rows:
   - **"Where is my order?"** — expands an inline mini-form (order number, and
     email-or-mobile) posting to admin-ajax `slk_assist_track` (priv + nopriv,
     nonce). The handler calls `SLK_Track::resolve()` behind a `class_exists`
     guard (hide the row entirely if absent) and returns the status label, date
     and step names, rendered as a compact status line. Failures use the same
     neutral copy as P3. Rate-limit with a per-IP transient, 10 lookups per 10
     minutes; over-limit returns the same neutral failure text.
   - **"Delivery costs"** — computed from the same live sources as P2's
     announcement (threshold and COD fee via slk-checkout helpers, guarded),
     plus a link to /delivery/. Hide the row if the values cannot be resolved.
   - **"Exchanges"** — **read `page-templates/exchange.php` and
     `inc/pages-help.php` first.** Only summarise the policy if the summary
     matches the copy that is actually published; otherwise render a bare link
     to /exchanges/ with no summary. Do not restate a policy from memory.
   - **"Size guide"** — a link row to /size-guide/.
   - A WhatsApp handoff row renders **only** when the theme's
     `slk_whatsapp_number()` returns non-empty (call via `function_exists`). It
     currently returns `''`, so the row stays dark. Add no placeholder and no
     "coming soon" note.
3. Render on `wp_footer`; CSS and vanilla JS through the same inline-style/script
   pattern as neighbouring code; escape all output.
4. Require and init from slk-order-flow.php like the other modules.

## Acceptance criteria

1. /my-account/ logged out: head and guest line align to the same 420px column as
   the cards; no dashed divider and no 40px offset between cards; both cards carry
   matching 20px display-serif headings; privacy and password notes are <=12.5px
   muted with ink links; remember-me is flex with its 8px gap.
2. The register form asks for email, Full name and Mobile number. A non-SL mobile
   is rejected with the specified message. On success the new user has
   first/last/display name and `billing_phone` set. Both generate_* options are
   untouched.
3. /my-account/lost-password/ and the reset screen use the slk auth head, a 420px
   card and an ink pill submit. **No `#2872fa` anywhere on account surfaces.**
4. At >=1000px the header shows the account icon linking to my-account; below
   1000px it does not render; the drawer pill is unchanged.
5. The announcement line shows only computed facts, or nothing. No exclamation
   marks anywhere in the diff.
6. The footer "We accept" chips exactly match the available gateways — today,
   Cash on delivery alone.
7. /track-order/: fields stacked at every width with equal margins, pill submit,
   label "Email or mobile number".
8. A COD order with a phone and **no email** can be tracked with order number +
   phone, in both `07XXXXXXXX` and `+947XXXXXXXX` forms. Order number + wrong
   phone fails with the neutral message. Email lookups still work. The result has
   no `<mark>`, uses the `.slk-step` timeline, and shows customer notes.
9. The checkout checkbox reads "Create an account and save my details" with the
   two-line hint.
10. Wishlist: a logged-in toggle persists across reload; the Saved pieces endpoint
    lists and unsaves; logged-out hearts route to my-account; menu order is
    Orders, Addresses, Account details, Saved pieces, Exchanges, Sign out.
11. The assist pill appears on home/shop/PDP/cart but **not** checkout; the lookup
    works for logged-out visitors; the rate limit holds; no WhatsApp row renders;
    Escape closes it and returns focus.
12. Every touched PHP file lints. No `woocommerce_*` option is written anywhere in
    the diff. No hardcoded price, phone number, address, town, email or handle.
    All new strings translatable in `slk`. CSS uses only existing tokens.

## Verification commands

Confirmed available: Docker with the `slk-wp` container for `php -l` (the Windows
host has no PHP), `curl`, `grep`, `bash`. There is no npm/composer/test tooling.

`slk-wp` already bind-mounts the theme and the three plugin directories, so
lint the files **in place** through a single `docker exec`. Do not `docker cp`
each file first: on Docker Desktop for Windows every copy costs seconds, and
sixteen of them overrun a two-minute gate budget before one file is linted.

```bash
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
docker exec slk-wp sh -c '
T=/var/www/html/wp-content/themes/slk-child
O=/var/www/html/wp-content/plugins/slk-order-flow
C=/var/www/html/wp-content/plugins/slk-checkout
for f in \
  $T/woocommerce/myaccount/form-login.php \
  $T/woocommerce/myaccount/form-lost-password.php \
  $T/woocommerce/myaccount/form-reset-password.php \
  $T/inc/account.php \
  $T/inc/chrome.php \
  $T/inc/pages-support.php \
  $T/inc/shop.php \
  $T/inc/pdp.php \
  $T/inc/moments.php \
  $T/woocommerce/order/form-tracking.php \
  $T/woocommerce/order/tracking.php \
  $T/page-templates/track-order.php \
  $O/slk-order-flow.php \
  $O/includes/class-slk-track.php \
  $O/includes/class-slk-saved.php \
  $O/includes/class-slk-assist.php \
  $C/includes/class-slk-checkout-fields.php ; do
  test -f "$f" && php -l "$f" 2>&1 | tail -1 || echo "LINT-UNAVAILABLE $f"
done'
```

```bash
grep -q 'Create an account and save my details' local/plugins/slk-checkout/includes/class-slk-checkout-fields.php && echo LABEL-OK
grep -q 'reg_phone' local/themes/slk-child/inc/account.php && echo REG-FIELDS-OK
test -f local/themes/slk-child/woocommerce/order/tracking.php && echo TRACKING-TEMPLATE-OK
grep -rniE '(made|packed|leaves|studio)[^.]{0,14}(Galle|Gintota)' local/themes/slk-child/ local/plugins/ && echo GALLE-ORIGIN || echo NO-GALLE-ORIGIN
grep -rnE 'wa\.me|[0-9]{9,}' local/plugins/slk-order-flow/includes/class-slk-assist.php && echo CHECK-FOR-INVENTED-NUMBER || echo NO-INVENTED-NUMBER
curl -fsS --max-time 30 https://mavea.lk/ >/dev/null && echo LIVE-BASELINE-OK
```

## Risks and rollback

- **Hook preservation.** form-login.php and the two new templates must keep every
  `do_action` exactly where core puts it; only heading markup moves. Diff the
  hook list before finishing.
- **New rewrite endpoint.** `/my-account/saved/` needs a rewrite flush. Use the
  one-shot option pattern from class-slk-exchange-account.php:141-146 verbatim.
  If it 404s, the operator fix is visiting Settings > Permalinks — not a code
  change.
- **Enumeration.** P4 must never resolve on a contact alone. Both the order
  number and the contact must match, and failures must be indistinguishable.
- **Checkout interference.** The assist panel is excluded from checkout by
  design; verify it does not render there.
- Everything is additive files or guarded hooks. Rollback of any package is a
  `git checkout` of its files. The only option written anywhere is the
  saved-endpoint one-shot flush flag.
