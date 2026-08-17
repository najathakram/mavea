# Plan: payment options, account choice at checkout, loyalty points, and two bugs

> Authored 2026-08-12. Status: IMPLEMENTED
> This file is the ONLY context the implementation and review agents receive.
> It must stand alone.

## Objective

Five things, in one run:

1. Fix two live bugs a shopper hits today: the payment method needs two clicks
   to select, and the shop filter button's piece count is stale until the
   button is pressed.
2. Add real Sri Lankan payment options beside cash on delivery and bank
   transfer: cards through PayHere, and instalments through Koko or Mintpay.
3. At checkout, an unrecognised shopper is offered three clear ways forward:
   sign in, create an account, or continue as a guest.
4. A loyalty scheme: 1 point per rupee spent, 5,000 points convert to LKR 50
   of credit towards a later order, points never expire.
5. Move the delivery promise settings into one dashboard home and make the
   free-delivery threshold editable there.

## Constraints & conventions

Stack: WordPress + WooCommerce 11.0.1, HPOS enabled, PHP 8.3. Repo root holds
`local/` containing `plugins/slk-checkout`, `plugins/slk-order-flow` (a
15-line scaffold, free to fill) and `themes/slk-child` (child of Blocksy).
Everything is bind-mounted into the running container, so edits are live.

Hard rules, every one of which has already bitten this codebase:

- **Classic shortcode checkout only.** Converting cart or checkout to
  WooCommerce blocks is a forbidden action.
- **Coupons are disabled store-wide** by
  `add_filter( 'woocommerce_coupons_enabled', '__return_false' )` in
  `themes/slk-child/inc/cart.php`. **Do not re-enable them.** Points must
  therefore be redeemed as a negative `WC_Cart::add_fee()` line, never as a
  coupon.
- **No sale theatre.** No discounts, no strikethrough pricing. A points
  redemption is the shopper spending their own credit; word it that way, never
  as a discount or an offer.
- **Money.** Sri Lankan rupees, 0 decimals. Always render through `wc_price()`.
  `SLK_Money::rupees()` normalises a settings value to a float.
- **Copy rules, enforced in review.** No em dashes, no en dashes anywhere a
  shopper can read, including placeholders and alt text. No sentence
  fragments. Short sentences, plain words, written for readers whose first
  language is not English. Never write "made to order" or "backorder" in
  customer-facing text.
- **CSS** uses only the `--slk-*` tokens in `themes/slk-child/style.css`. No
  raw hex. One breakpoint, `min-width:1000px`.
- Text domain is `slk`.
- **Do not modify:** `themes/slk-child/inc/moments.php` is owned by WP2 below
  and by nothing else; `themes/slk-child/inc/pdp.php`,
  `themes/slk-child/inc/select.php`, `themes/slk-child/assets/js/select.js`,
  and every file listed in the previous plan
  (`class-slk-calendar.php`, `class-slk-fulfilment.php`,
  `class-slk-shipments.php`) stay untouched unless a package names them.

Existing API worth knowing:

```php
SLK_Fulfilment::settings() / ::OPTION            // the delivery promise settings array
SLK_Shipping::free_over() : float                // reads the shipping method instance option
SLK_Shipping::fee_for_district( $d ) : float
SLK_Shipments::build() / ::total_fee( $district )
SLK_Money::rupees( $v ) : float
SLK_Districts::tier( $d )
```

## Work packages

### WP1 — Payment card takes one click

- **files:** `local/themes/slk-child/inc/checkout-view.php`
- **brief:** Measured on the live checkout: the payment card `<li>` is
  198x228px but its `<label>` is only 164x44px, so most of the card is dead
  space and the first click usually lands outside the label. The second click
  happens to hit it, which is exactly what the shopper reports.

  Make the whole card activate the radio, without touching WooCommerce's
  markup or its change events. Add to this file's existing inline CSS:

```css
.woocommerce-checkout-payment .wc_payment_method{position:relative}
/* The label's hit area is stretched over the whole card. The radio and the
   payment description keep their own stacking context above it so the
   description's links stay clickable. */
.woocommerce-checkout-payment .wc_payment_method > label::after{
	content:"";position:absolute;inset:0;z-index:1;cursor:pointer;
}
.woocommerce-checkout-payment .wc_payment_method > input,
.woocommerce-checkout-payment .wc_payment_method .payment_box{
	position:relative;z-index:2;
}
```

  Verify by measuring in the running store that a click at the card's top-left
  corner and at its bottom-right corner both check the radio.

### WP2 — Filter count updates as filters are chosen

- **files:** `local/themes/slk-child/inc/moments.php`
- **brief:** The shop filter panel's submit button reads "Show N pieces"
  (rendered around line 424 from a server-side `$total`). It only changes
  after the form is submitted. It must update every time a filter checkbox or
  price radio changes, before submitting.

  Add an AJAX endpoint (`wp_ajax_slk_filter_count` and the `nopriv` twin) that
  takes the current filter selection, runs the same query the shop archive
  would run, and returns the count. Debounce the client by about 250ms, mark
  the button `aria-busy="true"` while in flight, and never leave a stale
  number: on failure keep the last known good count rather than showing a
  wrong one. Use the existing filter form's inputs as the source of truth so
  price and category selections are both honoured.
- **exact code (the count query, so it matches the archive exactly):**

```php
$args = array(
	'status'   => 'publish',
	'limit'    => -1,
	'return'   => 'ids',
	'category' => $categories, // array of slugs, empty for all
);
if ( $min > 0 || $max > 0 ) {
	$args['price_between'] = array( $min, $max > 0 ? $max : PHP_INT_MAX );
}
$count = count( (array) wc_get_products( $args ) );
```

### WP3 — Sri Lankan payment gateways

- **files:** `local/setup-plugins.sh`,
  `local/plugins/slk-checkout/includes/class-slk-payments.php`
- **brief:** Two parts.

  1. In `setup-plugins.sh`, install and activate the two real gateways from
     wordpress.org, both confirmed reachable from this machine:
     `payhere-payment-gateway` (cards, eZ Cash, helaPay, LankaQR through one
     screen) and `mintpay` (instalments; Koko's own plugin is immature, Mintpay
     is the healthier one and covers the same "pay in instalments" need). Leave
     them **unconfigured**: they need a merchant ID and secret that only
     Najath can obtain, and PayHere approval additionally requires a live site
     with published policy pages. Print a clear line saying so.
  2. In `class-slk-payments.php`, order the gateways so cash on delivery is
     first and add a short plain description under each. Gate any gateway
     that has no credentials configured so it never appears to a shopper
     half-built: if PayHere or Mintpay is active but its merchant id is empty,
     unset it from `woocommerce_available_payment_gateways`.
- **note:** do not attempt to complete a real card payment. It cannot work
  without credentials and must not be faked.

### WP4 — Sign in, create an account, or continue as a guest

- **files:** `local/themes/slk-child/woocommerce/checkout/form-checkout.php`,
  `local/themes/slk-child/inc/account.php`
- **brief:** A shopper who is not logged in currently drops straight into the
  guest form with no choice. Add a block at the top of checkout, before the
  "1 · YOU" section, offering three routes: sign in, create an account, or
  continue as a guest (which stays the default so nobody is blocked).

  Turn on WooCommerce's own account creation at checkout rather than building
  a parallel one: set the options `woocommerce_enable_signup_and_login_from_checkout`
  and `woocommerce_enable_checkout_login_reminder` to `yes` from the theme's
  setup path, and style core's resulting markup. Guest checkout stays enabled.

  Because email is optional at this checkout by design, account creation must
  require an email and say why in one short sentence. Do not make email
  required for guests.
- **verify:** a logged-out shopper sees the three choices; choosing guest
  leaves the form exactly as it works today; creating an account produces a
  real customer the order attaches to.

### WP5 — Loyalty points

- **files:** `local/plugins/slk-order-flow/slk-order-flow.php`,
  `local/plugins/slk-order-flow/includes/class-slk-points.php`,
  `local/plugins/slk-order-flow/includes/class-slk-points-admin.php`
- **brief:** `slk-order-flow` is a 15-line scaffold. Fill it with a points
  system, self-contained, no third-party plugin.

  Rules, all configurable in the admin (see WP6 for where the screen lives, but
  the option itself is owned here):
  - Earn 1 point per LKR 1 of the order's item total, excluding delivery.
  - 5,000 points redeem for LKR 50 of credit. Redemption is in whole blocks of
    5,000; a shopper with 12,000 points can spend 10,000 for LKR 100.
  - Points never expire.
  - Points are awarded when an order reaches a paid or completed state, never
    at checkout, so an abandoned or returned order earns nothing.
  - Points are reversed if the order is later refunded, cancelled or returned.
  - Balance is stored in user meta `_slk_points_balance`, with an append-only
    ledger in user meta `_slk_points_ledger` (order id, delta, reason, ISO
    timestamp) so a balance can always be explained.
  - Guests earn nothing. Say so in one sentence at checkout, as a reason to
    make an account, not as a warning.
  - Redemption applies as a negative fee, never a coupon:

```php
// Coupons are disabled store-wide, so credit is applied as a negative fee.
// Fees are recalculated on every cart change, so this hook is the only
// place the credit amount is decided.
add_action( 'woocommerce_cart_calculate_fees', function ( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	$credit = SLK_Points::pending_credit(); // rupees, already clamped
	if ( $credit > 0 ) {
		$cart->add_fee( __( 'Points credit', 'slk' ), -$credit, false );
	}
} );
```

  Clamp the credit so it can never exceed the cart's item total (a shopper must
  never reach a zero or negative order through credit alone, and delivery is
  never paid with points). Guard against double-award with a per-order meta
  flag `_slk_points_awarded`.

  Show the balance in My Account, with the ledger as a simple list: date,
  order, points in or out. Copy rules apply.

### WP6 — One dashboard home for the delivery promise

- **files:** `local/plugins/slk-checkout/includes/class-slk-fulfilment-admin.php`
- **brief:** The delivery promise settings already exist here. Two changes:
  1. Add a **free delivery threshold** field to this screen, writing to the
     same shipping method instance option `free_over` that
     `SLK_Shipping::free_over()` already reads, so there is one number and one
     place to edit it. If writing the instance option is impractical, read and
     write it through the method's own settings array rather than introducing
     a second threshold that could disagree with the first.
  2. Add a short read-only note at the top of the section stating the rules the
     shopper sees, so the operator knows what the numbers do: items ready on
     the same day always travel together, delivery is charged per shipment, and
     a shipment at or above the threshold travels free.
- **effort:** low.

## Acceptance criteria

1. On the checkout, a single click anywhere on a payment card selects that
   method, including at the card's top-left and bottom-right corners.
2. On the shop page, changing any filter updates the button's piece count
   without submitting the form, and the number matches what the filtered
   archive actually returns.
3. PayHere and Mintpay are installed and active, and neither is offered to a
   shopper while its merchant credentials are empty.
4. Cash on delivery is the first payment option listed.
5. A logged-out shopper at checkout is offered sign in, create an account, and
   continue as a guest, with guest as the default path.
6. An order that reaches a paid or completed state awards its customer 1 point
   per rupee of item total, once and only once.
7. 5,000 points redeem as exactly LKR 50 of credit, applied as a negative fee
   line, in whole 5,000 blocks, never exceeding the cart's item total.
8. Refunding or cancelling an order removes the points it awarded.
9. My Account shows the points balance and a ledger explaining it.
10. The free delivery threshold is editable in the admin, and changing it
    changes both the charged delivery and the cart's "add X more" copy.
11. No customer-facing string contains an em dash, an en dash, a sentence
    fragment, or the words "made to order", "backorder", "discount" or "offer".
12. Cart and checkout remain classic shortcode pages.

## Verification commands

```
cd /c/ClaudeCode/mavea/local && export MSYS_NO_PATHCONV=1 && fail=0; for f in plugins/slk-checkout/includes/class-slk-payments.php plugins/slk-checkout/includes/class-slk-fulfilment-admin.php plugins/slk-order-flow/slk-order-flow.php plugins/slk-order-flow/includes/class-slk-points.php plugins/slk-order-flow/includes/class-slk-points-admin.php themes/slk-child/inc/moments.php themes/slk-child/inc/checkout-view.php themes/slk-child/inc/account.php themes/slk-child/woocommerce/checkout/form-checkout.php; do out=$(docker compose exec -T wordpress php -l "/var/www/html/wp-content/$f" 2>&1 | tail -1); case "$out" in "No syntax errors"*) ;; *) echo "LINT FAIL $f: $out"; fail=1;; esac; done; exit $fail
cd /c/ClaudeCode/mavea/local && bash -n setup-plugins.sh
```

`MSYS_NO_PATHCONV=1` is required before any `docker compose exec` on this
Windows machine. The store is at http://localhost:8088.

## Risks & rollback

- **Negative fees and totals.** A negative fee larger than the cart total
  produces a negative order. The clamp in WP5 is the guard and must be tested
  with a credit larger than the cart.
- **Double awarding.** Order status can reach a paid state more than once
  (processing then completed). `_slk_points_awarded` must make the award
  idempotent.
- **Gateways cannot be proven.** PayHere and Mintpay need real merchant
  accounts. Installing and gating them is the whole of what can be verified
  here. Do not claim a working card payment.
- **Filter count accuracy.** The AJAX count must use the same query as the
  archive or the button will lie. Compare both for at least two filter
  combinations.
- Rollback is `git revert` of the feature commit; no destructive migration.
