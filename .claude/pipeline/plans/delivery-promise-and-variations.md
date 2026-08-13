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

## Decisions from Najath (2026-08-12)

**Making time is a per-item field the operator edits, not a computed queue.**
If the atelier is running behind, they raise the number on that item. No
capacity model. This is the right call for a small workshop: the person who
knows the backlog is the person setting the number.

One cheap addition so the number is set with information rather than from
memory: show the **count of open orders waiting on that item** next to the
field, and in the products list. The failure mode here is not a bad model, it
is a busy week where nobody remembers to raise the number, which is exactly
the week it matters.

**The shopper is told a dispatch time, not an arrival date.** "Ships in 10
days." This is better than a delivery date: dispatch is the part we control,
the courier is not. It also removes the holiday and date arithmetic from the
customer-facing promise.

It does need the transit line kept next to it, or "ships in 10 days" gets read
as "arrives in 10 days" and the courier's 2 to 3 days on top feel like a
broken promise. The PDP already carries "Colombo in 1 to 2 days · island-wide
in 3 to 5", so the two sit together.

Making time is counted in **working days**, matching every other duration on
the site.

**Heights are Short, Regular and Tall**, by the wearer rather than the
garment: Short is under 5'1", Regular is 5'1" to 5'6", Tall is above 5'6".
Those ranges have to appear at the moment of choosing, not only in the size
guide, or people guess. Show feet and centimetres, both are used here.

An optional field lets a customer give a different height, and ops can confirm
by phone on the call they already make.

**Made-to-order pieces are prepay, and we do not use the words "made to
order".** The shopper is told "ships in N days" and nothing about how the
workshop is organised. That is normal retail and it reads better.

## Consequences that need a decision

**1. A mixed bag breaks payment.** Payment methods are chosen per order, not
per line. A bag holding one in-stock hijab (cash on delivery) and one
made-to-order abaya (prepay) cannot be both. Three options:

  - a. Any prepay line makes the whole order prepay. Simplest, and the
       shopper is told once at the bag, not discovered at checkout.
  - b. Block the mix, with a clear message in the bag.
  - c. Split into two orders. Most work, worst confirmation-call experience,
       two deliveries.

Recommend (a), stated in the bag as soon as the line is added.

**2. Every product page currently promises cash on delivery.**
`slk_pdp_trust_rows()` prints "Cash on delivery, with a call to confirm before
dispatch" on all of them. On a prepay piece that is false, and it is false at
the exact moment the shopper decides. The trust row already has `$product` in
scope, so it can vary per item. This has to ship with the prepay switch, not
after it.

**3. A custom height is not exchangeable.** The 7-day exchange assumes we can
sell the piece to somebody else. A garment cut to one person's measurements
cannot be. Either custom-height orders are final sale and say so at the point
of entry, or we accept the loss. Needs Najath's call.

**4. Prepay-only will convert worse.** Cash on delivery is roughly half of
Sri Lankan online orders. Nothing to fix here, just worth watching per item
once real numbers exist, and worth revisiting if made-to-order pieces
underperform in-stock ones sharply.

## Remaining risks

1. **The lead time is only true if someone updates it.** See the open-order
   count above.
2. **Variation count.** 4 sizes × 3 heights × 4 colours is 48 rows per
   product. Workable, but every row is a stock number somebody keeps true.
3. **A retired piece with nothing left** should probably drop out of the shop
   listing rather than sit there entirely greyed out.
4. **Existing copy already makes a promise.** The FAQ says timings count "from
   the confirmation call rather than from checkout". A prepay piece has no
   confirmation call before payment, so that sentence needs to be true for
   both paths or split.

## Open questions for Najath

1. Mixed bag: confirm option (a), whole order becomes prepay?
2. Are custom-height orders final sale?
3. When a retired piece sells out completely, hide it or leave it visible?
