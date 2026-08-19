# Plan: make the shop filters work in real time, and fit the bands to the catalogue

> 2026-08-18. Status: DRAFT. Self-contained: implementers see only this file.

## Objective

On https://mavea.lk/shop/ the price and category filters do not update the
results as you use them, and one price band can never match anything. Fix both.

## What is wrong, verified on the live site

**A. No real-time result.** Ticking a facet fires an AJAX request that returns
ONLY a count, used to relabel the submit button ("Show 1 piece"). The product
grid, the `<h1>` count and the active-filter chips do not change until the form
is submitted and the page reloads. Reproduced: ticking "Dresses" relabelled the
button to "Show 1 piece" while the grid still showed all 20 products and the
header still read "20 pieces". Submitting then worked correctly, landing on
`/shop/?product_cat[]=dresses` with "Dresses 1 piece".

**B. A dead price band.** `slk_moments_price_buckets()` in
`local/themes/slk-child/inc/moments.php` hardcodes `$under = 5000; $over =
10000;` from the design mockup. The real catalogue is 20 products priced
Rs. 9,900 to Rs. 17,900, so the buckets resolve to: under-5,000 = 0 products
(can never match), 5,000-10,000 = 2, over-10,000 = 18.

**C. Stale button after apply.** After applying, the button still reads
"Show 1 piece" while the page already shows exactly that.

## Constraints

- Design system Porcelain Glass: rounded (card 24px, tile 20px, field 16px,
  pill 999px), warm neutrals only, no accent colour, motion 280ms
  cubic-bezier(.22,1,.36,1), 44px touch targets, body text >= 14.5px.
- Currency renders through `wc_price()` only. Never type "Rs." into a string.
- **The no-JS path must keep working.** The form already submits and filters
  correctly without JavaScript; that must remain true. Real-time behaviour is
  progressive enhancement layered on top, never a replacement.
- The URL must stay shareable and bookmarkable: real-time updates must push the
  same query args the form would have submitted, via `history.pushState`, and
  Back/Forward must restore the matching result set.
- Do not introduce a JS framework or a build step. Plain JS, in the existing
  inline-script pattern this file already uses.
- Do not run a second custom `WP_Query` shape: the archive's own query args are
  the contract, exactly as `slk_moments_ajax_filter_count()` already does.

## Work packages

### WP1 - Derive the price bands from the real catalogue

**files:** `local/themes/slk-child/inc/moments.php`

`slk_moments_price_buckets()` must stop hardcoding 5000/10000 and derive the two
boundaries from the actual published price range, so no band can ever be dead.

- Compute min and max published product price. Cache in a transient
  (e.g. `slk_price_bounds`, 12 hours) so this is not a query per request, and
  flush it on `woocommerce_update_product` / `woocommerce_new_product` /
  `save_post_product`.
- Split the range into three bands on round, human numbers - round the two
  interior boundaries to a sensible step (e.g. nearest 1,000) rather than
  emitting Rs. 12,566. Guard the degenerate cases: fewer than 2 distinct prices,
  or all products at one price, must not produce equal or inverted boundaries -
  fall back to a single "All prices" state or omit the facet entirely rather
  than rendering a band that cannot match.
- Labels keep coming from `wc_price()`.
- Acceptance: with the current catalogue (min 9,900 / max 17,900) every rendered
  band returns at least one product, and the three bands together return all 20.

### WP2 - Real-time filtering

**files:** `local/themes/slk-child/inc/moments.php`

Extend the existing AJAX endpoint so it returns the rendered result set, not
just a number, and have the client swap it in.

- `slk_moments_ajax_filter_count()` already runs the archive's own query. Have
  it also return: the products grid markup, the heading text with its count, and
  the active-filter chips - reusing the SAME renderers the PHP template uses, so
  the AJAX and no-JS paths can never drift.
- Client: on `change` of any facet (the delegated listener at ~line 1542 already
  exists), debounce ~250ms, request, then replace the grid, heading and chips.
  Push the resulting URL with `history.pushState`, and handle `popstate` so
  Back/Forward restore correctly.
- Announce the new count to assistive tech via an `aria-live="polite"` region;
  do not move focus.
- Show a non-janky pending state on the results region (e.g. reduced opacity,
  `aria-busy="true"`) that respects `prefers-reduced-motion`.
- Guard races: ignore a response that is not the newest request (sequence number
  or `AbortController`).
- Keep the submit button working as the no-JS fallback. Once results update
  live, the button is redundant for JS users - either hide it when JS is active,
  or relabel it to reflect state so it can never read "Show 1 piece" while 1
  piece is already shown (fixes issue C).
- Failure path: if the request errors or times out, leave the current results in
  place, clear the pending state, and let the button still submit normally.
  Never leave the grid blank.

## Acceptance criteria

1. Ticking a category or price band updates the grid, the heading count and the
   chips without a page reload, within ~1s on a normal connection.
2. The URL updates to the same args the form would have submitted; reloading it
   reproduces the same result set; Back/Forward work.
3. With JavaScript disabled the form still submits and filters exactly as now.
4. No rendered price band returns zero products for the current catalogue.
5. The submit button never claims to show a count that is already displayed.
6. A failed AJAX request leaves the previous results visible and usable.
7. No new colours, radii or motion outside the existing `--slk-*` tokens.
8. `php -l` clean.

## Verification

```bash
# lint
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
docker cp local/themes/slk-child/inc/moments.php slk-wp:/tmp/lint.php && docker exec slk-wp php -l /tmp/lint.php

# the dead band must be gone
grep -n 'under *= *5000\|over *= *10000' local/themes/slk-child/inc/moments.php && echo 'STILL-HARDCODED' || echo 'BANDS-DERIVED'

# no-JS filtering still works
curl -s "https://mavea.lk/shop/?product_cat%5B%5D=dresses" | grep -c 'Hafsa'
```

## Risks

- Returning markup from AJAX risks the two render paths drifting. Mitigated by
  requiring both to call the same renderer functions.
- `pushState` on every keystroke-ish change can spam history. Debounce, and
  prefer `replaceState` for rapid successive changes within one interaction.
- Caching: LiteSpeed caches HTML. The AJAX endpoint is admin-ajax and must not
  be cached; confirm the response sends no-cache headers.
