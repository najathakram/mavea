# Made-to-order lead times, retired pieces, and colour variations

Plan only. Implementation goes through `dev-pipeline` per the repo CLAUDE.md.

## What Najath asked for

Three fulfilment realities, one promise shown to the shopper:

1. **In stock.** The size is on the shelf. Ship on the normal dispatch time.
2. **Not in stock, but we can make it.** Take the order and promise a later
   date, based on how long that design takes to sew plus a safety margin.
   Simple cuts are quick, roughly 3 to 4 days. Complex ones can be 10.
3. **Retired.** We no longer make this piece. Sell whatever sizes remain. When
   a size is gone it is gone, and that size reads "not available".

The dashboard decides which of the three applies, automatically, from
inventory. Everything above is configurable there.

Plus variations: dresses vary by **size and height**; some pieces also vary by
**colour**; hijabs vary by **colour only**. Selecting a colour must change the
photographs.

## Two findings that shape this plan

**1. WooCommerce already models these three states. We should not build a
fourth.**

Core stock status maps onto Najath's three cases exactly:

| Reality | Stock status | How it gets there |
|---|---|---|
| In stock | `instock` | qty > 0 |
| We can make it | `onbackorder` | qty 0, backorders allowed |
| Retired and gone | `outofstock` | qty 0, backorders not allowed |

`is_in_stock()` is true for `onbackorder`, so a made-to-order size stays
buyable, and `false` only for the retired-and-gone case. That matters because
`inc/pdp.php` already greys out any attribute value whose variations are all
unavailable (`slk_pdp_compute_sold_out_values()`), so **"not available for
that size" already works** the moment a variation goes `outofstock`. No new
state machine, no parallel flags to drift out of sync with core.

The operator should still see one switch, not two. "Retired" is the switch;
the plugin derives backorders from it (Active → allow and notify, Retired →
do not allow) so the two can never disagree.

**2. The theme was already built for variable products. The catalogue was
not.**

`inc/pdp.php` already carries the sold-out computation above, and already
splits variation controls into `data-slk-kind="colour"` vs `"size"` through
`woocommerce_dropdown_variation_attribute_options_html`. But
`seed-catalog.sh` creates every product as `product_type simple` with
`_manage_stock no`. So the UI groundwork exists and the data does not. Phase 0
is a data migration, not a rewrite.

## The promise engine

One function is the source of truth. Everything else renders what it returns.

```
ready_days(line):
    in stock        -> dispatch_days                    (config)
    can make        -> production_max + tolerance       (config, per product)
    retired, gone   -> UNAVAILABLE

promise(cart, district):
    ready   = max(ready_days(line) for every line)      # slowest line owns it
    transit = tier_days(district)                       # 1-2 / 2-3 / 3-5, exists
    return working_days_from(start, ready + transit)     # a date range
```

Notes that decide correctness:

- **The slowest line owns the order.** A stocked hijab in the same bag as a
  10-day abaya ships when the abaya is ready. Splitting shipments is a later
  question, not this build.
- **Transit already exists.** `SLK_Shipping::tier_label()` and
  `SLK_Districts::tier()` hold 1-2 / 2-3 / 3-5 working days by district. The
  engine consumes those rather than restating them.
- **Working days must skip Sri Lankan holidays.** Poya falls monthly, plus
  the usual public holidays. A naive "add 10 days" over-promises roughly once
  a month. Holidays are a configurable date list.
- **Freeze the promise on the order.** Store the computed range and each
  line's mode as order meta at creation. Otherwise changing a production tier
  next month silently rewrites what an old customer was told.

## Configuration surface

**WooCommerce → Settings → Delivery promise** (new section)

- Dispatch days for in-stock items
- Daily cut-off time, in Asia/Colombo (orders after it count from tomorrow)
- Production tiers, repeatable: label + min days + max days.
  Seed: Quick 3-4, Standard 5-7, Complex 8-10
- Tolerance days added to every made-to-order estimate
- Working days of the week, plus a holiday date list
- Whether the clock starts at checkout or at the COD confirmation call
- Copy templates for each of the three states

**Product data → a "Making" panel**

- Fulfilment: Active (we still make this) / Retired (sell remaining only)
- Production tier, or a per-product override in days

**Per variation**

- Stock quantity (native)
- Production tier override (a complex colourway can be slower)
- Backorders is derived, not shown

## Where the promise appears

It must say the same thing in every one of these, which is why it is one
function:

PDP (updates as the shopper picks size and colour, with a sensible line before
any selection) · cart line · checkout · thank-you page · order emails · the
WhatsApp confirmation message ops sends · the admin order screen, so ops know
what was promised.

## Colour changes the photographs

Native WooCommerce swaps a single image per variation. Najath wants the whole
set to change: front, back, detail.

- Store a gallery per colour term, edited in the Making panel.
- Extend each variation's payload through `woocommerce_available_variation`
  with its colour gallery (thumb, srcset, and full size for the zoom).
- On `found_variation`, swap the gallery figures.
- The zoom needs nothing: `inc/moments.php` re-reads
  `.woocommerce-product-gallery` from the DOM every time it opens, so it picks
  up whatever is currently rendered.
- Hijabs have colour and no size, so the size control must not render an empty
  row for them.

## Phases, each with a check that can fail

**0 · Data model.** Convert products to variable. Attributes: Size, Height,
Colour. Backfill the nine existing pieces, extend `seed-catalog.sh` so a
re-seed reproduces it.
*Check:* every product page still renders, adds to bag, and reaches checkout.

**1 · Configuration.** Settings section, product panel, variation fields,
derived backorders.
*Check:* flipping Active → Retired on a product with one size in stock makes
the other sizes read "not available" and leaves the stocked one buyable.

**2 · Promise engine.** The calculator, the working-day and holiday maths,
rendering in all eight places, freezing onto the order.
*Check:* a stocked line, a made-to-order line, and a mixed bag each produce
the right range for Colombo and for Jaffna, and the frozen order value does
not move when a tier is edited afterwards.

**3 · Colour galleries.** Per-colour images, variation payload, swap on
select, zoom continuity.
*Check:* choosing a colour changes all three photographs, and opening the zoom
shows that colour's set.

**4 · Ops.** Admin list column for promised date, the WhatsApp confirmation
text, a view of what is due to be sewn.
*Check:* an order placed against a made-to-order size shows the promise and
the reason on the admin screen.

## Risks worth naming before we build

1. **Capacity is not modelled.** A fixed 10-day lead time is honest for the
   first order and a lie for the twentieth in the same week. If the atelier
   can sew N pieces a week, the estimate should lengthen as the queue fills.
   Otherwise we will miss dates in exactly the situation we most want to get
   right, a successful launch week.
2. **COD plus a 10-day wait raises return-to-origin risk.** The customer has
   paid nothing and has ten days to change their mind, on a piece cut for
   them. Worth deciding whether made-to-order requires prepayment, or at least
   a deposit.
3. **Existing copy already makes a promise.** The FAQ says timings are counted
   "from the confirmation call rather than from checkout". The engine's clock
   must match that sentence, or the sentence changes with it.
4. **Variation count.** 4 sizes × 3 heights × 4 colours is 48 rows per
   product. Workable, but the admin gets heavy, and every one of them is a
   stock number somebody has to keep true.
5. **A retired piece with nothing left** should probably drop out of the shop
   listing rather than sit there entirely greyed out.

## Open questions for Najath

1. What are the height options, and are they stocked or made to measure?
2. Does the clock start at checkout or at the confirmation call?
3. Is cash on delivery allowed on made-to-order pieces, or prepay only?
4. Should the estimate stretch as the sewing queue fills (risk 1), or is a
   fixed per-design time close enough for now?
5. When a retired piece sells out completely, hide it or leave it visible?
