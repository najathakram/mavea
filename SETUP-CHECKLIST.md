# Sister SL Store — Najath's external-account checklist

These are the accounts/registrations only you can create (payments + KYC involved). Claude
configures everything after each account exists. Order matters — follow the sequence.

## Now (before/at G1 name pick)
- [ ] **Pick the brand name** from `NAMING.md` (Gate G1)
- [ ] **Confirm with Galle**: RTW line list (styles × sizes × qty), per-piece COGS, monthly
      production capacity ← blocks pricing (G3) and catalog sizing
- [ ] **Dedicated SL mobile number** (SIM) for the brand — becomes WhatsApp Business +
      PayHere contact + Koombiyo contact

## Immediately after G1
- [ ] **NIPO trademark search** on the winner (free, public since Feb 2026: nipo.lk.wipo.net) —
      screenshot the clear result before spending on domains/branding
- [ ] **Register `<name>.lk`** at https://www.domains.lk — "Standard" bundle Rs. 8,000/yr;
      needs NIC/passport + contact address; allow 1–3 working days
- [ ] **Register the `.com` variant** (agreed at G1, e.g. `wear<name>.com`) at any registrar
- [ ] **Hostinger** — Cloud Startup plan, **Singapore** data center; then give Claude working
      access (recommended: you log in once in Chrome and Claude drives the logged-in session,
      same as the Higgsfield flow — never share passwords in chat)
- [ ] **Cloudflare** free account → add the .lk domain → switch nameservers at domains.lk
- [ ] **Koombiyo Delivery** merchant account (https://koombiyodelivery.lk) — ask for: rate
      card, COD remittance cycle, API credentials, pickup from Galle arrangements
- [ ] **Notify.lk** account — buy a starter SMS package, request the brand name as sender ID
- [ ] **WhatsApp Business app** on the new number; set profile, catalog comes later

## After the site is live with policy pages (Claude will say when — build step 6)
- [ ] **PayHere merchant application** (https://payhere.lk) — have ready: business
      registration cert (or NIC for sole trader), LKR bank account details, the live domain
      (must match exactly — www vs non-www), SL mobile + business email. Approval 1–3 days.
- [ ] **Koko merchant application** — merchant.support@paykoko.com; ask: commission % (public
      figure is ~10–12% — negotiate), WooCommerce plugin support, onboarding timeline.
      Optionally apply to **Mintpay** in parallel as the BNPL fallback.

## Later (launch-marketing phase)
- [ ] Instagram + TikTok handles (Claude verifies availability at G1 — register immediately after)
- [ ] Google account for the brand → Merchant Center + Business Profile
- [ ] Accountant check-in: TIN/registration hygiene for the SL entity (VAT not required
      below LKR 60M/yr — confirmed 2026)

## Standing rules
- Live-store mutations stay staged behind your approval and logged to
  `.aeshal/logs/actions.log` (alias `WPSL::`), same as Aeshal.
- The two brands never cross-link publicly; separate voice config
  (`config/brand-voice-sl.json`, to be written at G2).
