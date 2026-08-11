# Design brief — Sri Lanka modest-fashion store

Written to be pasted into the design tools. Run them **in this order** — each one consumes the
previous one's output, and running them out of order throws work away:

```
1. /ui-ux-pro-max:ui-ux-pro-max   → design system (palette, type, style, anti-patterns)
2. /ui-ux-pro-max:brand           → identity + brand-guidelines.md + design tokens (CSS vars)
3. claude-design                  → high-fidelity HTML mockups using those tokens
4. /ui-ux-pro-max:ui-styling      → component craft + accessibility pass
5. port tokens/templates into local/themes/slk-child (Blocksy child)
```

**Stack warning for tools 1 and 4.** The build target is a **WordPress + WooCommerce Blocksy
child theme** — PHP templates and plain CSS. It is *not* React. `ui-styling` is written around
shadcn/ui + Tailwind, so take its token architecture, spacing, state and accessibility patterns,
but **emit vanilla CSS custom properties, not React components**. For `ui-ux-pro-max`, declare
the stack explicitly as `html-css` (its docs warn never to let it assume a stack).

---

## 1. The master brief (paste this into any of the tools)

> **Product:** An online modest-fashion boutique for Sri Lanka. Small-batch ready-to-wear
> abayas and dresses plus everyday hijabs, handmade by a Galle atelier, sold island-wide in
> Sri Lankan rupees.
>
> **Who it is for:** Sri Lankan Muslim women, roughly 18–40, in Colombo, Kandy, Galle and
> smaller towns. They want to be fashionable and current while dressing within their religious
> boundaries. They are Instagram-native, shop almost entirely on mobile phones over uneven
> mobile data, and are used to buying from small sellers via DM and cash on delivery. They are
> price-conscious — this brand is deliberately more affordable than its US sister label.
>
> **What it must feel like:** her trusted friend with excellent taste. Warm, current, quietly
> confident. Modesty is the given, not the pitch — never preachy, never "modest fashion for
> sisters", never austere or religious-institutional. It should look like a real fashion brand
> that happens to be modest, not a modesty brand that happens to sell clothes. Proudly and
> openly Sri Lankan — the island is an asset here, not something to hide.
>
> **What it must NOT feel like:** cheap, dropship-y, or like a template store. No sale banners,
> no countdown timers, no strikethrough pricing, no urgency theatre, no stock-photo Arab
> luxury clichés (gold filigree, desert dunes, Dubai skylines).
>
> **Product type:** e-commerce / fashion boutique / small catalogue.
> **Stack:** HTML + CSS (WordPress WooCommerce Blocksy child theme). No React.
> **Currency format:** `Rs. 12,500` — prefix, space, comma thousands, **zero decimals**.
> **Primary language:** English. Sinhala and Tamil come later — do not hard-code text widths
> that would break when translated.

---

## 2. Hard constraints the design must respect

**The brand name is not chosen yet.** This is the single biggest constraint.
- Do **not** deliver a finished logo. Deliver a **wordmark system**: a typographic treatment,
  lockup rules and clear-space spec that works for any name of 4–8 letters.
- Use a neutral placeholder in all mockups. Keep the logo a single swappable asset/token so
  swapping it later touches one file, not fifty.
- Do not build the palette or motifs around a name-derived symbol (no moon icon, no
  letter-monogram) until the name exists.

**It must not look like Aeshal.** The US sister brand owns a bone/off-white palette with
Cormorant (serif) + Jost (geometric sans). This brand must be visibly a different label —
avoid that palette and those typefaces. Related, not matching. There is no public cross-linking
between the two brands.

**Mobile-first, and genuinely fast.** The customer is on a phone on Sri Lankan mobile data.
- Design to a performance budget: hero under ~200KB, WebP/AVIF, lazy-loaded below the fold,
  reserved image dimensions so nothing shifts (CLS < 0.1).
- No carousel libraries, no parallax, no heavy JS. Motion is 150–300ms and meaningful only.
- Touch targets 44×44px minimum, 8px apart. Nothing hover-dependent.

**Modesty rules for all imagery and illustration.** Garments shown full-length, long sleeves to
the wrist, high neckline, loose non-clinging drape, opaque fabric, hair fully covered, hem to
the ankle. No skin above the wrist or below the ankle, no cleavage, no sheer, no tight fit.

**No sale theatre.** No strikethrough or "compare at" pricing anywhere, no discount badges on
apparel. Value is communicated by honest pricing and visible craft, never by fake markdowns.

---

## 3. The design problems that actually matter here

A generic e-commerce template solves none of these. Judge every proposal against them.

1. **Make cash on delivery feel premium, not downmarket.** COD is over half of Sri Lankan
   online orders and will be our main method — but on most sites it is presented as the sad
   fallback at the bottom of the list. Here it is a first-class, designed choice. It also
   carries a real-world step: *we phone or WhatsApp to confirm before we dispatch.* Design that
   confirmation as reassurance ("a real person checks your order before it ships"), not as
   friction or suspicion.
2. **Earn trust with zero reviews and zero history.** A brand-new store asking for money in a
   market full of vanishing Instagram sellers. Trust has to come from design: real photographs
   of real garments, a visible Sri Lankan phone number, a named human, a plainly-worded
   exchange policy, the atelier shown honestly, WhatsApp reachable from every page.
3. **Sell a full-length garment in a phone-sized frame.** Modest pieces are long — the whole
   silhouette *is* the product. Portrait imagery (3:4 or 2:3), grid cards that never crop the
   head or the hem, and detail shots that carry drape, weight and fabric texture, because she
   cannot touch it.
4. **Remove size anxiety before it kills the cart.** No fitting rooms, no free returns culture,
   and an exchange-only policy. The size guide must be genuinely usable on a phone — real
   measurements in centimetres, how to measure, and how each cut is meant to sit.
5. **Design for the WhatsApp shopper.** Many customers will want to ask before they buy. A
   persistent, tasteful WhatsApp affordance — not a flashing green blob covering the buy button.
6. **Two very different product shapes in one catalogue.** Dresses and abayas are hero,
   full-length, small-batch. Hijabs are cheap, repeat-purchase, colour-driven, bought in
   multiples. They need different card treatments and probably different browse experiences.

---

## 4. Pages and components to design

**Core commerce:** Home · Shop / category grid (with filters that work on a phone) ·
Product page · Cart · **Checkout (the highest-value screen — see below)** · Order received.

**Trust and support:** Our Story (the Galle atelier, the founders) · Delivery & Cash on Delivery
· Size Guide · Exchange Policy · Contact (WhatsApp-first) · FAQ · Track Order.

**Components:** header + mobile nav · product card (dress vs hijab variants) · price display ·
size selector · quantity · add-to-cart and its loading/success states · WhatsApp affordance ·
trust strip · footer · form fields with visible labels and inline errors · empty cart · toasts.

**Checkout specifically** — this is where the design has to be unusually thoughtful:
- One page, minimal fields, no forced account creation.
- **Phone number is the primary identity**, not email. Email is optional and only required for
  card payment. Design the form so this feels natural rather than broken.
- Address block is Sri Lankan: **District dropdown (25 districts)**, postcode optional, and a
  "nearest landmark or junction" note field, because that is what couriers actually navigate by.
- Payment choice presented as: **Cash on Delivery** (with the confirmation-call explained and
  its handling fee stated honestly), **Pay now by card / eZ Cash / helaPay / LankaQR** (one
  PayHere button covering all of them), and **Bank transfer** (with a clear "send us the slip on
  WhatsApp" flow).
- Buy-now-pay-later (Koko/Mintpay) appears only on higher-value carts — design it so its
  absence on a small cart doesn't look broken.

---

## 5. Direction candidates (pick or hybridise — do not do all three)

| Direction | Palette feel | Typography feel | Risk |
|---|---|---|---|
| **Island Modern** | warm sand + deep teal/emerald, drawn from the blue water lily and island greenery | humanist serif headline + clean grotesque body | can slide into "tropical resort" if over-saturated |
| **Quiet Luxe** | charcoal + warm ivory + one saturated accent | high-contrast editorial serif + restrained sans | risks reading austere, and risks looking like Aeshal |
| **Market Warmth** | terracotta / clay + cream + brass | rounded humanist sans + soft serif | risks looking craft-fair rather than fashion |

Whatever is chosen must hold **4.5:1 text contrast**, work in bright outdoor daylight on a phone
screen, and survive being seen next to Instagram's white UI.

---

## 6. Tool-by-tool prompts

### Step 1 — `/ui-ux-pro-max:ui-ux-pro-max`

Generate and persist the design system. Run from the repo root:

```bash
python "${CLAUDE_PLUGIN_ROOT}/.claude/skills/ui-ux-pro-max/scripts/search.py" \
  "ecommerce modest fashion boutique women apparel mobile-first warm trustworthy" \
  --design-system --persist -p "SL Dress" --output-dir "C:/ClaudeCode/sldress"
```

Ask it for: pattern + style recommendation, colour palette, font pairing, effects, and the
anti-patterns list — with the stack declared as `html-css`, not React. Output lands in
`design-system/sl-dress/MASTER.md`. Then create page overrides for `product`, `checkout` and
`home`, since those three diverge most from a generic store.

### Step 2 — `/ui-ux-pro-max:brand`

Turn the chosen direction into an identity and, critically, into **tokens we can ship**:
- `docs/brand-guidelines.md` — voice, visual identity, palette with hex + usage rules,
  typography scale, photography direction (with the modesty rules above as hard constraints),
  logo/wordmark **system** rules given the name is still unknown.
- Sync to `assets/design-tokens.json` + `assets/design-tokens.css`. Those CSS custom properties
  are what get pasted into the Blocksy child theme, so name them for a WordPress context
  (`--slk-color-*`, `--slk-space-*`, `--slk-font-*`) rather than Tailwind-flavoured names.

### Step 3 — `claude-design`

Build high-fidelity, reviewable HTML mockups before touching WordPress. Two design systems are
available: **Modernist** (better fit for a contemporary fashion brand) and **Classical** (the
default; more editorial). Recommend starting from Modernist unless Quiet Luxe is chosen.

Create a project named `SL Dress — Storefront`, then produce, in this order:
`home.html`, `product.html`, `shop.html`, `checkout.html`, and a `components.html` sheet.
Mobile viewport first (390px), then the desktop layout. Use real Sri Lankan content — real
district names, `Rs. 12,500` pricing, real garment names — never lorem ipsum, because fake
content hides real layout problems.

### Step 4 — `/ui-ux-pro-max:ui-styling`

Component-level pass: states (default/hover/focus/active/disabled/loading), form validation
display, focus rings kept visible, contrast verified, reduced-motion honoured, and the 44px
touch-target rule enforced. **Emit vanilla CSS, not shadcn/React.**

### Step 5 — Port into the theme

Tokens become CSS custom properties in `local/themes/slk-child/style.css`; layouts become
Blocksy customiser settings plus template overrides in the child theme. Nothing gets hand-styled
outside the token system.

---

## 7. How to judge the output

- Does it look like a **fashion** brand, or like a modesty brand? It must be the former.
- Would she screenshot a product card and send it to a friend on WhatsApp?
- Is COD presented as a confident choice rather than an apology?
- Does the full-length silhouette survive a 390px-wide screen?
- Is there a single sale badge, strikethrough, or countdown anywhere? There must not be.
- Could the wordmark be swapped for a different name tomorrow without a redesign?
- Does it hold 4.5:1 contrast and 44px touch targets throughout?
