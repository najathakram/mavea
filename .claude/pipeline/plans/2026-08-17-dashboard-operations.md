# Plan: everything operational runs from the dashboard

> Authored 2026-08-17. Status: IMPLEMENTED / CLOSED 2026-08-17.
> Shipped as 1cddadc (phase 1), a29c83d (phase 2), 9abdb76 (phase 3),
> 11fd926 (phases 4 and 5), on branch campaign-imagery-and-positioning.
> One item is deliberately unbuilt: "exchange fees charged" on the Finances
> screen renders an em dash, because nothing records the fee actually collected.
> Stamping it at dispatch is the fix and it belongs in slk-exchanges.
> This file is the ONLY context the implementation and review agents receive.
> It must stand alone.

## Objective

Najath's instruction, verbatim scope: shipping rates, shipping times,
manufacturing time, when an item will be ready, every parameter about items,
and customization options must be configurable from the dashboard; plus order
handling, return management and finances. "Enable all the requested features,
plus more."

Decisions taken with Najath (2026-08-17):
- Customization = **preset options + custom length**. Per-product,
  dashboard-defined option groups (each choice with an optional fee and extra
  making days) plus an optional length-in-cm field with its own fee and extra
  days.
- Returns = **full portal + admin board**. Customer requests an exchange from
  My Account inside a configurable window; staff drive it across a status
  board; WhatsApp remains an alternative entry that staff can log manually.

## What already exists — do not rebuild

An audit of the live code found far more standing than the theme suggests.
The build must EXTEND these, never duplicate them:

| Concern | Where it lives today | Dashboard-editable? |
|---|---|---|
| District delivery rates (350/400/450) + free-over | `SLK_Delivery_Method` instance settings, WooCommerce → Settings → Shipping → zone "Sri Lanka" | YES |
| Dispatch/making calendar: cutoff hour, working days, holidays, dispatch days, default making days, tolerance, split shipments, extra-shipment fee | `SLK_Fulfilment` option `slk_fulfilment_settings`, UI in `SLK_Fulfilment_Admin` (Settings → Shipping section) | YES |
| Per-product making days + retired flag | `_slk_making_days`, `_slk_retired` meta, fields on the product Inventory tab | YES |
| Loyalty points earn/redeem rates | `slk-order-flow`, Settings → Accounts section | YES |
| COD panel on the order screen, packing slip data | `SLK_Order_Admin` | n/a (display) |
| Stock, prices, backorders, refunds, order statuses, coupons, revenue analytics | WooCommerce core | YES |

## The drift problem (bug class, fix first)

The PUBLIC copy hardcodes numbers the CHECKOUT reads from settings. Edit the
zone rates in the dashboard today and the Delivery page, FAQ, footer and
added-to-bag drawer keep advertising Rs. 350/400/450. Same for day ranges
(`SLK_Shipping::tier_label()` hardcodes them), the COD fee (`SLK_Payments::
COD_FEE` = theme's `slk_delivery_cod_fee()` = 150, two copies), the exchange
send fee (350) and the exchange window ("7 days" in at least three templates).

Rule for the whole build: **every rupee amount, day count and window printed on
the storefront must be read from the same source the checkout charges from.**

## Phase 1 — one source of truth (wire-ups, no new features)

1. `SLK_Delivery_Method::init_form_fields()` gains three text fields:
   `days_metro`, `days_regional`, `days_island` (defaults: the current
   strings). `SLK_Shipping::tier_label()` reads the active instance's setting,
   falling back to the constants.
2. New `SLK_Shipping::zones_public(): array` returns
   `[{label, days, fee}, …]` from live settings — the shape the theme's
   `slk_delivery_zones()` already emits.
3. Theme `slk_delivery_zones()` becomes a thin proxy: return
   `SLK_Shipping::zones_public()` when the class exists, else the current
   hardcoded array (theme must survive the plugin being off).
4. COD fee: new field on the COD gateway's own settings form
   (`woocommerce_settings_api_form_fields_cod` filter or gateway option),
   read by `SLK_Payments` through the existing `slk_cod_handling_fee` filter;
   theme `slk_delivery_cod_fee()` proxies the same value.
5. Exchange settings join `slk_fulfilment_settings` (they are fulfilment):
   `exchange_window_days` (default 7), `exchange_send_fee` (default 350),
   rendered in `SLK_Fulfilment_Admin` under a "Exchanges" heading. Theme
   `slk_exchange_send_fee()` proxies; the "Exchange within 7 days" strings in
   pdp.php, front-page.php and faq answers print the setting.

## Phase 2 — customization options

New file `slk-checkout/includes/class-slk-customization.php` (+ admin class).

Product data: a "Customization" tab on the product editor.
- Repeater of option groups: `{label, required?, choices: [{label, fee,
  extra_days}]}` stored as JSON in `_slk_custom_options`.
- Custom length block: enable checkbox, fee, extra days, min/max cm
  (`_slk_custom_length`).

Storefront (PDP): render selects/radios for each group and the length input
above the buy dock; selections travel via `woocommerce_add_cart_item_data` →
line item meta on the order. Fees add to the line via
`woocommerce_before_calculate_totals` price adjustment (NOT cart fees — a fee
is per-cart, this is per-line). Extra days feed
`SLK_Fulfilment::making_days()` through a new filter
`slk_line_making_days( $days, $product, $cart_item )` so the EXISTING ready-
date and split-shipment machinery prices the delay without modification.
Packing slip and admin order screen print every chosen option through the
existing `slk_packing_slip_data` filter.

Edge cases that must hold:
- A customized line never merges with a stock line of the same product
  (cart item data already prevents merging — verify, don't assume).
- Retired products refuse customization like they refuse purchase.
- Length outside min/max is rejected at add-to-cart validation, not at
  checkout.

## Phase 3 — exchanges: portal + board

New plugin directory `local/plugins/slk-exchanges/` (own plugin: it depends on
slk-checkout's calendar but owns its lifecycle).

Data: custom post type `slk_exchange`, one per request, linked to order id +
line item id. Statuses (post statuses, not order statuses — the order itself
stays `completed`): `requested → approved → collecting → received → dispatched
→ closed`, plus `declined`. Each transition stamps an activity log entry in
meta.

Customer side:
- My Account endpoint `exchanges` (list + request form). An order is eligible
  when status is `completed`, delivery date (or completion date) is within
  `exchange_window_days`, and the line has no open exchange already.
- Request form: line item, reason (fits small / fits large / flaw / other),
  wanted size, note. Confirmation email on submit and on each status change
  (reuse WooCommerce email infrastructure, one `SLK_Email_Exchange` class).
- The Exchanges page template (theme) keeps its copy but gains a "Start an
  exchange" button when logged in with an eligible order.

Admin side:
- WooCommerce submenu "Exchanges": a `WP_List_Table` board — columns:
  request, order, piece, reason, wanted size, age, status; row actions move
  status forward/back; bulk approve. A meta box on the order screen shows its
  exchanges.
- On `dispatched`, if `exchange_send_fee > 0`, staff see the fee stated on
  the request (collect via COD on the courier; no online payment build).
- Staff can create a request manually (the WhatsApp path) from the order
  screen.

## Phase 4 — finances

New file in `slk-order-flow` (it already owns points):
`class-slk-finance-report.php`. WooCommerce submenu "Finances", one screen,
period picker (this month default):

- Revenue: item totals, delivery charged, COD handling collected — from
  `wc_get_orders` on paid statuses in period.
- COD position: outstanding (orders shipped, not yet `completed`) vs
  collected, because COD cash arrives after dispatch.
- Exchange fees charged (phase 3 meta).
- Points liability: outstanding points × block value ÷ block points, from the
  points table — the store's deferred cost.
- Refunds in period.
- One CSV export button (orders in period with the columns above), nonce-
  gated, streamed.

No charts. Numbers with labels, in the admin design language.

## Phase 5 — plus more (small, high-leverage)

1. Dashboard widget "The studio today": lines due to be ready today/overdue
   (from making days vs order date), orders awaiting the confirmation call,
   exchanges open, products at ≤ 2 stock. Links into each.
2. Low-stock threshold: surface WooCommerce's per-product threshold field
   (it exists but hides under Inventory) — no build beyond ensuring it's
   visible; notification email address check.
3. Every new option ships with a sane default so a fresh install renders the
   current live values exactly.

## Order of work

Phase 1 first (it is the bug), then 2, 3, 4, 5. Each phase ends with: PHP lint
in the container, a walk of the affected storefront pages at 375px and 1440px,
and a dashboard walk proving the edited value appears on the storefront.

## What is explicitly out

- Online payment for exchange fees (COD on the courier covers it).
- Courier API integration (rates and tracking stay manual).
- Multi-currency, tax — the store is LKR, tax off.
- Editing WooCommerce core screens beyond the fields named here.
