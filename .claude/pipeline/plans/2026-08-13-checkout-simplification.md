# Plan: strip the checkout back and add Google sign in

> Authored 2026-08-13. Status: IMPLEMENTED
> This file is the ONLY context the implementation and review agents receive.
> It must stand alone.

## Objective

The checkout accumulated features without a design pass and now asks a shopper
to deal with **four separate account prompts before reaching a single field**.
Measured on the live page, in this order: WooCommerce's own "Returning
customer? Click here to login" notice, a points banner, a three-card "Before
you begin" block, and then a "Create an account" checkbox further down the
form. Three of those sit above the page title.

Cut it to one. Add Google sign in as the fast path. Remove fields this store
does not use.

## What the page looks like now, and what it should look like

Measured headings today: Checkout, Before you begin, 1 · You, 2 · Where,
Additional information, 3 · Paying, Your order.

Measured visible fields today: mobile number, full name, email,
**country/region**, address, landmark, city/town, district, **postal code**,
delivery notes, create-account checkbox, payment radios.

Target:

```
Checkout

1 · You
  [ Continue with Google ]   Already have an account? Sign in     <- one row, logged out only
  ------------------------------ or ------------------------------
  Mobile number *
  Full name *
  Email (optional)
  [ ] Save my details for next time
      Orders on an account earn points towards credit.

2 · Where
  Address *
  Nearest landmark or junction (optional)
  City / town *          District *
  Delivery notes (optional)

3 · Paying
  ( ) Cash on delivery
  ( ) Bank transfer
```

Gone: the "Before you begin" three-card block, WooCommerce's login-reminder
notice, the points banner, the "Additional information" heading, the
country/region control, and the postal code field.

## Constraints & conventions

Stack: WordPress + WooCommerce 11.0.1, HPOS, PHP 8.3. Theme `slk-child`
(child of Blocksy). Repo root holds `local/` with `plugins/slk-checkout`,
`plugins/slk-order-flow`, `themes/slk-child`, all bind-mounted into the
running container so edits are live.

Hard rules, each already learned the hard way here:

- **Classic shortcode checkout only.** Converting cart or checkout to
  WooCommerce blocks is a forbidden action.
- **Do not break the posted country.** Shipping rates and the district field
  depend on `billing_country` being `LK`. Remove the visible control, never
  the value. `SLK_Districts::default_country()` already defaults it.
- **Money** in rupees, 0 decimals, always through `wc_price()`.
- **Copy rules.** No em dashes, no en dashes anywhere a shopper reads. No
  sentence fragments. Short plain sentences for readers whose first language
  is not English. Never write "made to order" or "backorder". Never call a
  points credit a discount or an offer.
- **CSS** uses only `--slk-*` tokens from `themes/slk-child/style.css`. No raw
  hex. One breakpoint, `min-width:1000px`. Touch targets at least 44px.
- Text domain `slk`.
- **Do not modify:** `themes/slk-child/inc/moments.php`,
  `themes/slk-child/inc/pdp.php`, `themes/slk-child/inc/select.php`,
  `themes/slk-child/assets/js/select.js`,
  `plugins/slk-checkout/includes/class-slk-calendar.php`,
  `class-slk-fulfilment.php`, `class-slk-shipments.php`,
  `plugins/slk-order-flow/includes/class-slk-points.php`.

## Work packages

### WP1 — Fewer fields

- **files:** `local/plugins/slk-checkout/includes/class-slk-checkout-fields.php`
- **brief:**
  1. **Country/region:** stop rendering the visible control. It is a
     single-option dropdown on a store that ships only to Sri Lanka, so it is
     pure noise. The posted value must stay `LK` so shipping and districts
     keep working. Prefer WooCommerce's own mechanism over CSS hiding.
  2. **Postal code:** remove the field. This store's own delivery copy tells
     shoppers couriers navigate by landmark, not postcode, and the shipping
     rates are chosen by district. An optional field nobody reads is still a
     field everybody scrolls past.
  3. **Delivery notes:** move `order_comments` into the "Where" step so the
     unnumbered "Additional information" heading disappears. WooCommerce
     renders order notes through `woocommerce_checkout_fields['order']`; move
     it so it lands inside the shipping/where fieldset rather than its own
     section.
  4. Reword the account checkbox label from "Create an account" to **"Save my
     details for next time"**, with one line under it: **"Orders on an account
     earn points towards credit."**
- **verify:** placing a cash on delivery order still works end to end, the
  order still carries country LK and the chosen district, and delivery is
  still priced by district.

### WP2 — One account prompt, not four

- **files:** `local/themes/slk-child/woocommerce/checkout/form-checkout.php`,
  `local/themes/slk-child/inc/account.php`
- **brief:**
  1. Delete the "Before you begin" three-card block entirely. It duplicated
     controls that live elsewhere: the sign-in it advertises is WooCommerce's
     login form, and the account it advertises is the checkbox in the form.
     Its copy also said the same thing twice ("Guest is already selected, so
     there is nothing you need to do" and "This is already selected, so you do
     not need to do anything").
  2. Turn WooCommerce's login-reminder notice back off by setting
     `woocommerce_enable_checkout_login_reminder` to `no`. Keep
     `woocommerce_enable_signup_and_login_from_checkout` as `yes`, because the
     in-form account checkbox depends on it. Guest checkout stays enabled.
  3. Remove the points banner printed on `woocommerce_before_checkout_form`.
     The same fact now sits under the account checkbox (WP1) where it is
     relevant, instead of shouting above the title.
  4. Render the new sign-in row at the top of the "You" step, for logged-out
     shoppers only: a "Continue with Google" button (see WP3, which provides
     `SLK_Google::button()` and `SLK_Google::available()`) beside a plain
     "Already have an account? Sign in" link that reveals WooCommerce's
     existing login form. When Google is not configured, render only the
     sign-in link, never a dead button.
- **verify:** a logged-out shopper sees exactly one account prompt on the
  page; a logged-in shopper sees none of it.

### WP3 — Google sign in

- **files:** `local/setup-plugins.sh`,
  `local/plugins/slk-checkout/includes/class-slk-google.php`
- **brief:**
  1. In `setup-plugins.sh`, install and activate `login-with-google` (rtCamp).
     It needs a Google OAuth client id and secret from the Google Cloud
     console, which only Najath can create, so leave it unconfigured and print
     a line saying exactly that, in the same style as the PayHere note already
     in this file.
  2. New class `SLK_Google` with two public static methods:
     - `available(): bool` — true only when the plugin is active **and** a
       client id is configured. Read the plugin's own stored settings; do not
       guess at a constant.
     - `button( string $redirect = '' ): string` — the markup for the
       "Continue with Google" button, styled with our own classes, pointing at
       the plugin's authorisation URL. Returns `''` when `available()` is
       false, so callers can print it unconditionally.
     Register the class in `slk-checkout.php`'s existing `require_once` block.
- **note:** do not fake a Google login. Without credentials the button must
  not render at all.

### WP4 — Make it look like one thing

- **files:** `local/themes/slk-child/inc/checkout-view.php`
- **brief:** A styling pass over the simplified page.
  - Style the Google button and the sign-in row: 44px minimum height, the
    house pill radius, tokens only. The Google button is secondary to the
    form, not a hero.
  - Style the "or" divider between the sign-in row and the fields.
  - Restyle the account checkbox and its one-line hint so it reads as part of
    the You step rather than an afterthought.
  - Check the three step cards are visually consistent now that two blocks
    above them are gone, and that nothing relies on the removed elements.
  - Keep the existing payment-card fix intact: the card is `position:relative`
    and the label's `::after` covers it at `z-index:1` with the radio and
    `.payment_box` at `z-index:2`. Do not regress it.

## Acceptance criteria

1. A logged-out shopper at `/checkout/` sees exactly one account prompt, and
   it sits inside the "You" step, not above the page title.
2. WooCommerce's "Returning customer? Click here to login" notice does not
   appear.
3. No points banner appears above the checkout form. The points line appears
   once, under the account checkbox.
4. The "Before you begin" block is gone from the markup, not merely hidden.
5. No country/region control is visible, and a placed order still records
   country LK and prices delivery by the chosen district.
6. No postal code field appears.
7. Delivery notes appear inside the "Where" step and no "Additional
   information" heading is rendered.
8. When Google is not configured, no Google button renders anywhere.
9. A logged-in shopper sees no sign-in row and no account checkbox.
10. Placing a cash on delivery order still succeeds end to end.
11. A single click anywhere on a payment card still selects it.
12. No customer-facing string contains an em dash, an en dash, a sentence
    fragment, or the words "made to order", "backorder", "discount", "offer".
13. Cart and checkout remain classic shortcode pages.

## Verification commands

```
cd /c/ClaudeCode/sldress/local && export MSYS_NO_PATHCONV=1 && fail=0; for f in plugins/slk-checkout/includes/class-slk-checkout-fields.php plugins/slk-checkout/includes/class-slk-google.php plugins/slk-checkout/slk-checkout.php themes/slk-child/woocommerce/checkout/form-checkout.php themes/slk-child/inc/account.php themes/slk-child/inc/checkout-view.php; do out=$(docker compose exec -T wordpress php -l "/var/www/html/wp-content/$f" 2>&1 | tail -1); case "$out" in "No syntax errors"*) ;; *) echo "LINT FAIL $f: $out"; fail=1;; esac; done; exit $fail
cd /c/ClaudeCode/sldress/local && bash -n setup-plugins.sh
```

`MSYS_NO_PATHCONV=1` is required before any `docker compose exec` here. wp-cli
runs as `docker compose run --rm -T wpcli <args>` with no leading `wp` and must
be given `< /dev/null`. The store is at http://localhost:8088.

## Risks & rollback

- **Removing the country control can break shipping.** The rate is chosen from
  `$package['destination']['state']` and the method refuses non-LK countries.
  If the posted country stops arriving, every rate silently disappears. Test a
  full order after the change, not just the page render.
- **Removing postcode** must not break WooCommerce's address formatting for
  LK. Check the order confirmation and the admin order screen still render an
  address.
- **The login-reminder option is global.** Turning it off must not remove the
  in-form account checkbox, which is controlled by the other option.
- Rollback is `git revert` of the feature commit. No data migration.
