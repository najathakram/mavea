# MAVÉA — Najath's external-account checklist

These are the accounts/registrations only you can create (payments + KYC involved). Claude
configures everything after each account exists. Order matters — follow the sequence.

## Now (before/at G1 name pick)
- [x] **Pick the brand name** — **MAVÉA**, chosen 2026-08-15. Gate G1 closed.
- [ ] **Confirm with Galle**: RTW line list (styles × sizes × qty), per-piece COGS, monthly
      production capacity ← blocks pricing (G3) and catalog sizing
- [ ] **Dedicated SL mobile number** (SIM) for the brand — becomes WhatsApp Business +
      PayHere contact + Koombiyo contact

## Immediately after G1
- [ ] **NIPO trademark search** on MAVÉA (free, public since Feb 2026: nipo.lk.wipo.net) —
      screenshot the clear result before spending on domains/branding. ⚠ Note MAVEA is an
      existing brand elsewhere (Brita's water-filtration line); different goods class from
      apparel, so probably clear, but confirm before committing to a long registration term.
- [ ] **Register `mavea.lk`** at https://www.domains.lk — needs NIC/passport + contact
      address; allow 1–3 working days. Decline the bundled Mymail / MyPage / Mysite add-ons:
      they are page builders that cannot run WordPress or WooCommerce, and the hosting plan
      below already includes mailboxes.
- [ ] **Register `mavea.com`** — spend Hostinger's free-domain-for-a-year on this; the free
      domain almost certainly does not cover the `.lk` ccTLD, so `.lk` is bought separately above
- [ ] **Hostinger** — **Unlimited** plan (not Cloud Startup — see
      [HOSTING-DECISION.md](HOSTING-DECISION.md), which rules that Cloud Startup buys headroom
      this store will not touch for years), **Singapore** data center; then give Claude working
      access (recommended: you log in once in Chrome and Claude drives the logged-in session,
      same as the Higgsfield flow — never share passwords in chat)
- [ ] **Cloudflare** free account → add the .lk domain → switch nameservers at domains.lk
- [ ] **Koombiyo Delivery** merchant account (https://koombiyodelivery.lk) — ask for: rate
      card, COD remittance cycle, API credentials, pickup from Galle arrangements
- [ ] **Notify.lk** account — buy a starter SMS package, request **MAVEA** as sender ID
      (unaccented: sender IDs are ASCII and capped around 11 characters)
- [ ] **WhatsApp Business app** on the new number; set profile, catalog comes later

## After the site is live with policy pages (Claude will say when — build step 6)
- [ ] **PayHere merchant application** (https://payhere.lk) — have ready: business
      registration cert (or NIC for sole trader), LKR bank account details, the live domain
      (must match exactly — www vs non-www), SL mobile + business email. Approval 1–3 days.
- [ ] **Koko merchant application** — merchant.support@paykoko.com; ask: commission % (public
      figure is ~10–12% — negotiate), WooCommerce plugin support, onboarding timeline.
      Optionally apply to **Mintpay** in parallel as the BNPL fallback.

## Later (launch-marketing phase)
- [ ] Instagram + TikTok handles for `mavea` (ASCII, no accent) — **claim these the same day
      the name goes public; free handles get sniped.** Not yet verified as available.
- [ ] Google account for the brand → Merchant Center + Business Profile
- [ ] Accountant check-in: TIN/registration hygiene for the SL entity (VAT not required
      below LKR 60M/yr — confirmed 2026)

## Standing rules
- Live-store mutations stay staged behind your approval and logged to
  `.aeshal/logs/actions.log` (alias `WPSL::`), same as Aeshal.
- The two brands never cross-link publicly; separate voice config
  (`config/brand-voice-sl.json`, to be written at G2).
