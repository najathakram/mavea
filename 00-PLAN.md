# Sister-Brand Sri Lanka Store — Full Build Plan (WordPress/WooCommerce)

## Context

Aeshal (US, Shopify, premium 1-of-1) gets a **separately-named sister brand operating fully in Sri Lanka**: WordPress + WooCommerce, LKR pricing, slightly less expensive, for modern-yet-modest Sri Lankan Muslim women. Produced by the same Galle atelier. The repo has zero existing SL-market plans (verified) — this is a new workstream that must not collide with the Aeshal comeback campaign (Aug 4–Sep 12).

**Decisions locked by Najath (2026-08-10):**
- SL business entity + LKR bank account already exist → PayHere/Koko applications can start immediately
- Product: **new small-batch ready-to-wear line** (repeat sizes/stock) + replenishable hijabs — no inventory conflict with Aeshal's 1-of-1s
- Naming: Claude proposes a shortlist, Najath picks
- Hosting: **Hostinger Cloud, Singapore DC (~US$10/mo)** + Cloudflare free (Colombo PoP)

**Key research findings (verified 2026-08-10):**
- **helaPay needs no separate integration** — it is one of PayHere's 13 checkout methods (with eZ Cash, mCash, Genie, LankaQR, Sampath Vishwa, cards). One PayHere merchant account covers nearly everything.
- PayHere: official maintained WooCommerce plugin; 2.35% + Rs. 25/txn cards, no setup/monthly; approval 1–3 days but **requires an already-live site with privacy/refund policy pages** → launch sequencing matters.
- Koko BNPL: plugin exists but immature (~30 installs); **merchant commission ~10–12%** — real margin cost. Mintpay (500+ installs) is the healthier-plugin alternative. BNPL is phase 2.
- COD ≈ **52%+ of SL online orders** → COD-first design with an anti-RTO confirmation flow. Bank transfer + WhatsApp slip is a confirmed SL norm — support it.
- **No SL courier has a first-party WooCommerce plugin.** Koombiyo Delivery (API, ~Rs. 150–290/parcel, ~weekly COD remittance) is the small-fashion-startup favorite → manual portal at launch, custom API bridge later.
- Notify.lk has an official WooCommerce SMS plugin (Rs. 0.60–1.10/SMS). Meta/IG Shops **not available in SL** → Instagram = tagging + WhatsApp-to-order. Google Merchant Center **does** support SL (free listings).
- .lk domain: Rs. 5,000–8,000/yr at domains.lk, 1–3 days.
- VAT registration not required below LKR 60M/yr revenue (2026 threshold confirmed). Confirm TIN/registration details with the SL accountant.

**Why not Vercel (asked mid-planning):** Vercel runs no PHP/MySQL, so WordPress/WooCommerce cannot be hosted there directly. The only real pattern is headless (WP still on a PHP host + Next.js frontend on Vercel): doubles the stack, forces hand-building checkout/COD/PayHere flows the plugins give us free, adds a second monthly bill, and Vercel has no Colombo edge while Cloudflare does. Verdict: **Hostinger + Cloudflare now; headless is a future scale-up path only.**

---

## 1. Brand & naming

Shortlist (all avoid collisions with existing Aeshal SKU names — Noor, Zahra, Amara, Inaya, Layla, Hana, Farah etc. are dresses and excluded):

| Name | Root / meaning | Note |
|---|---|---|
| **Haya** | حياء — modesty, graceful dignity | The virtue modest fashion embodies; 4 letters; strongest candidate |
| **Sakina** | سكينة — God-given tranquility | Known name in SL Muslim community; soft, calm |
| **Zeenath** | زينة — adornment | Extremely common SL Muslim name; warmly local; slight "personal boutique" feel |
| **Rifqa** | رفق — gentleness, companionship | Quietly rhymes with Aeshal's companion-tone law; distinctive |
| **Amani** | أماني — wishes, aspirations | Easy, warm, travels well |
| **Tahira** | طاهرة — pure | Familiar SL name, clear meaning |

- Execution step 1: verify `.lk` + `.com` + Instagram handle + obvious trademark collisions for all six → present to Najath with evidence → **GATE G1: name pick**. Register `.lk` (Standard bundle Rs. 8,000/yr) + `.com`.
  - ✅ **G1 RESOLVED 2026-08-15 — the name is MAVÉA**, on `mavea.lk`. Najath chose a name from outside the six shortlisted here; the table above is kept as the record of what was considered. The handle check and the NIPO trademark search were **not** done before the pick and remain outstanding — see [SETUP-CHECKLIST.md](SETUP-CHECKLIST.md).
- Brand system: new logo/wordmark, typography and palette **deliberately distinct from Aeshal's Bone/Cormorant+Jost world** (the two must not look like clones). Voice: companion register carries over as DNA, but this brand **inverts Aeshal's geography ban — proudly Sri Lankan** ("made on our island" energy; final lines written at execution, Fable-only). New `config/brand-voice-sl.json` so agents never cross-contaminate the two brands. **GATE G2: brand look.**
- Positioning: her trusted friend who makes dressing modestly easy and affordable in LKR — never framed as "cheap Aeshal". No public cross-linking between the brands.

## 2. Pricing architecture (draft — pending unit economics)

Repo has **no COGS data** (07-RISK-REGISTER unit-economics template is blank). Najath supplies per-piece COGS + Galle monthly capacity for the RTW line; then prices lock at ≥60% contribution after gateway (2.35%) + courier (~Rs. 300 avg) + packaging.

Placeholder architecture to react to:
- Hijabs Rs. 1,900–3,900 · RTW dresses/abayas Rs. 9,500–19,500 · sets later
- Delivery Rs. 350 flat island-wide · **free ≥ Rs. 15,000** · COD handling fee Rs. 250 (fee on COD, not discount on prepaid — respects house no-discount culture)
- Koko/Mintpay shown only ≥ Rs. 10,000 carts (its 10–12% fee priced in or it stays off)
- Aeshal's 1-of-1 no-discount law doesn't bind RTW, but keep the culture: no strikethrough/sale theater. **GATE G3: pricing sheet.**

## 3. Platform & hosting

- Hostinger Cloud Startup (SG), PHP 8.3, SSL, staging enabled. Cloudflare free in front (proxied, Colombo PoP serves cached assets locally). Target TTFB <500ms from Colombo, PDP mobile PageSpeed ≥85.
- **Theme: Blocksy (free) + child theme.** Vanilla JS, ~50KB critical CSS, native Woo templates — survives SL mobile networks where builder-heavy themes (Woodmart) don't.
- Plugin stack (lean, all free at launch): WooCommerce · LiteSpeed Cache (QUIC.cloud images only, CDN off — Cloudflare owns edge) · PayHere Payment Gateway · Notify.lk SMS · Flexible Shipping (WP Desk) · PDF Invoices & Packing Slips (WP Overnight) · Rank Math SEO · Wordfence (2FA on) · UpdraftPlus → Google Drive · WP Mail SMTP + Brevo relay · PixelYourSite (GA4 + Meta pixel) · Fluent Forms Lite. **No checkout-field or status-manager plugins — that's our custom code** (below). Paid spend at launch: Rs. 0.
- **HPOS ON** (compat/sync mode first 2 weeks). LKR: "Rs." prefix, 0 decimals, comma thousands.

## 4. Payments (launch → phase 2)

| Phase | Methods |
|---|---|
| Launch day 1 | **COD** (with confirmation flow) + **bank transfer** (BACS + WhatsApp slip — zero code: prefilled wa.me link on thank-you page) |
| ~Day 3–7 (on approval) | **PayHere** → cards, helaPay, eZ Cash, mCash, Genie, LankaQR, Sampath Vishwa |
| Phase 2 | **Koko** (user-requested; apply now, wire when approved) and/or **Mintpay**, gated ≥ Rs. 10,000 |

PayHere sequencing: site goes live **with Privacy, Refund/Exchange, Terms, Delivery, Contact pages first**, then the application goes in (domain must match exactly, www vs non-www). Sandbox txn + live Rs. 100 test + refund before enabling.

## 5. Checkout design (SL-specific)

- **Classic shortcode checkout, NOT block checkout.** Blocks ignore `woocommerce_checkout_fields`, break the field customizations and PayHere has no block integration. The "convert to blocks" nudge is documented as a forbidden action.
- **Phone-first:** phone required + validated (`^(?:\+94|0)?7\d{8}$`, normalized to +947…), optional WhatsApp number field. **Email optional** — required only when PayHere selected (their API needs it); COD/BACS orders get a synthetic placeholder address on a subdomain published with **null MX** + suppressed customer emails (no bounces poisoning the domain).
- **Districts:** override `woocommerce_states` for LK with the 25 districts (matching Koombiyo's list), label "District", postcode optional, address_2 hidden, order-notes renamed "Delivery notes (nearest landmark)".
- COD presented as "Cash on Delivery — Rs. 250 handling, we call to confirm"; hidden above Rs. 25,000 and for repeat-RTO phones. One-page, minimal fields.

## 6. Order lifecycle & COD ops

Statuses (≤20-char slugs): `wc-pending-confirm` → `wc-confirmed` → `wc-dispatched` → `completed`, branches `wc-rto` / `cancelled`. PayHere-paid orders skip to `processing`.

- **Stock reduced at order creation** (not at `processing`) and restored on cancel/RTO — prevents small-batch oversell while orders await confirmation.
- Notify.lk SMS on `pending-confirm` and `dispatched` (+waybill). English/GSM-7 (~Rs. 2/order).
- Admin order screen + HPOS list column get a **WhatsApp button** (prefilled wa.me message: order, items, total, address, "Reply YES to confirm") + one-click "Mark confirmed". 48h auto-cancel of unconfirmed COD via Action Scheduler.
- Custom statuses added to Analytics included-statuses (else revenue undercounts). Weekly COD reconciliation export (waybill, amount, remittance delta) vs courier manifest.
- Roles: Najath = Admin (2FA). Galle/ops staff = custom orders-only role (no product/settings caps). WooCommerce mobile app = owner's revenue glance + push only (it can't see custom statuses); ops staff use mobile wp-admin.

## 7. Fulfillment

- **Courier: Koombiyo Delivery** primary (merchant account, COD collection, weekly remittance). Launch = manual portal entry per order; **phase 2 = `koombiyo-bridge` plugin** (waybill create via REST, tracking into order meta, Action Scheduler polling for status write-back, district→city-ID map). Backup courier: Trans Express/Royal Express (Curfox ecosystem) if Koombiyo quality slips.
- Promise: island-wide 2–4 working days. Returns: **7-day exchange only** (size/defect), customer ships back; no cash refunds — mirrors Aeshal policy shape, drafted into policy pages.

## 8. Site structure & content

Home · Shop · Abayas · Dresses · Hijabs · PDP · Our Story (atelier, proudly SL) · Delivery & COD · Size Guide · Exchange Policy · Contact (WhatsApp-first) · FAQ · Track Order · policies. English-first (no WPML at launch). Photography: reuse the Aeshal Higgsfield/photo pipeline for the new line but distinct art direction; every product gets real-photo plates. **GATE G4: catalog copy + modesty check.**

## 9. Custom code inventory (all via dev-pipeline, versioned in repo, deployed via SFTP/Git — never staging-DB-push once live orders exist)

| Component | Size | Purpose |
|---|---|---|
| `<brand>-child` theme | S | Blocksy child: brand CSS, PDP badges, Woo template hooks |
| `<brand>-checkout` plugin | M | Fields, districts, phone validation, email-optional logic, COD fee/gating |
| `<brand>-order-flow` plugin | M | Custom statuses, stock-at-creation, SMS hooks, WhatsApp admin buttons, ops role, 48h auto-cancel |
| `<brand>-rto-guard` | S | Phone-keyed repeat-RTO blocklist → hides COD |
| COD reconciliation export | S | CSV stream for weekly courier settlement check |
| `koombiyo-bridge` | M/L | Phase 2 API integration |

Repo home: new top-level workstream folder (named after the brand once picked) holding docs, `config/brand-voice-sl.json`, `wp/` source for the code above, and scripts. HANDOFF.md gains a workstream section; live-store mutations staged behind Najath approval and appended to `.aeshal/logs/actions.log` (new alias `WPSL::`), same governance as Aeshal.

## 10. Marketing wiring (launch scope only)

New IG + TikTok handles (checked at G1) · WhatsApp Business number + catalog · Google Business Profile · Google Merchant Center free listings (Rank Math product feed) · GA4 + Meta pixel + Clarity. No paid ads until the store proves demand (house prove-first rule). Email = Brevo free tier; WhatsApp is the CRM.

## 11. Build sequence (each step has a verification)

0. **G1 name gate** → domains, Hostinger, Cloudflare, accounts (Koombiyo, Notify.lk, WhatsApp Business; PayHere/Koko apps drafted). ✔ SSL A, Colombo TTFB <500ms
1. WP + Woo core config (LKR/Rs./0 decimals, HPOS, Galle origin) ✔ price renders identically in cart/checkout/email/PDF
2. Blocksy + child + LiteSpeed (cache-exclude cart/checkout/session cookies; Cloudflare bypass same paths) ✔ add-to-cart works with warm cache; PSI ≥85 mobile
3. **G2 brand look** → design system applied, core pages + policy pages ✔ mobile visual QA
4. Custom checkout + order-flow plugins ✔ no-email COD order completes; bad phone rejected; district required; SMS fires; WhatsApp button correct; 48h auto-cancel drill; RTO drill restores stock
5. **G3 pricing** → catalog build (RTW line + hijabs), shipping zones ✔ Colombo vs Jaffna rates; stock decrements at order creation
6. Site live (COD+BACS) → **PayHere application** → sandbox + live Rs. 100 txn + refund; **test full flow inside Instagram's in-app browser on Android + iOS** (webview breaks redirects) ✔
7. Analytics/SEO/backup ✔ UpdraftPlus **restore drill on staging**; GA4 purchase in LKR
8. **G4 catalog/modesty gate** → soft launch: 5 friends-and-family orders across COD/BACS/PayHere, reconciled against Koombiyo manifest ✔
9. **G5 go/no-go** → public launch (timed around the Aeshal comeback calendar — likely post-reveal, Najath's call)

My build effort: ~2–3 weeks calendar with gates; external clocks: domain 1–3 days, PayHere 1–3 days after site live, Koko unknown (weeks, phase 2).

## 12. Running costs

Fixed: Hostinger ~$10/mo · .lk Rs. 8,000/yr · .com ~$12/yr · Notify.lk from Rs. 690/mo · plugins Rs. 0. Variable: PayHere 2.35% + Rs. 25 · Koko 10–12% (gated) · Koombiyo ~Rs. 150–290/parcel · SMS ~Rs. 2/order. Total fixed ≈ **US$15–20/mo**.

## 13. Top risks

1. Block-checkout conversion silently breaks COD/fields → documented forbidden action
2. PayHere hash uses 2-decimal amounts vs 0-decimal display → sandbox-test odd totals
3. COD RTO abuse → confirmation gate + fee + cap + phone blocklist + 48h auto-cancel
4. Koko's 10–12% fee vs "less expensive" positioning → gate BNPL to high carts or price it in
5. Galle capacity for a repeatable RTW line is **an open question in the risk register** — Najath must confirm monthly units before catalog sizing
6. Two-brand contamination (voice/assets) → separate config, separate folders, no cross-linking
7. Najath's attention is on the comeback until ~Sep 12 → this build is Claude-heavy with only 5 short gates by design

## Verification (end-to-end, pre-launch)

Full COD drill on a real handset (order → SMS → WhatsApp confirm → dispatch → deliver → reconcile) · RTO drill (stock restored, phone flagged) · PayHere live txn + refund · IG in-app browser checkout on both OSes · bank-transfer slip flow · backup restore drill · PSI mobile ≥85 · all five gates signed by Najath.
