# sldress — Sri Lanka modest-fashion store (WordPress / WooCommerce)

Sister brand to Aeshal (US, Shopify), operating entirely in Sri Lanka: LKR pricing, COD-first
checkout, small-batch ready-to-wear from the Galle atelier, aimed at modern-yet-modest Sri
Lankan Muslim women at a lower price point than Aeshal.

**The brand name is not chosen yet.** `sldress` and the `slk-` code prefix are placeholders
picked so nothing needs renaming later — when the name lands, only display strings change.

## Start here

| File | What it is |
|---|---|
| [00-PLAN.md](00-PLAN.md) | The approved build plan — architecture, payments, order flow, gates G1–G5 |
| [HOSTING-DECISION.md](HOSTING-DECISION.md) | WordPress.com vs Hostinger, with verified 2026 pricing; why we build local and buy later |
| [SETUP-CHECKLIST.md](SETUP-CHECKLIST.md) | The accounts only Najath can create (PayHere, Koko, Koombiyo, domains) |
| [NAMING.md](NAMING.md) | 26 candidate names with live domain/Instagram/collision evidence — gate G1 is deferred, not closed |
| [reference/](reference/) | Verified market data (the 25 districts, etc.) |

## Run the site locally

Requires Docker Desktop running (its engine service needs Administrator once).

```bash
bash local/bootstrap.sh
```

Brings up WordPress + MariaDB + WP-CLI + Adminer, installs WooCommerce, Blocksy and the Sri
Lankan payment plugins, then applies every store default — LKR with "Rs." and zero decimals,
Galle origin, Sri Lanka-only selling and shipping, taxes off, pretty permalinks, HPOS on.

- Store: http://localhost:8088 · Admin: http://localhost:8088/wp-admin
- Database UI: http://localhost:8089 (server `db`, user `slk`, password `slk_dev_pw`, db `slk`)
- Admin password is written to `local/.admin-password` (gitignored)

Stop with `docker compose down`; wipe and start clean with `docker compose down -v`.

## How this repo is laid out

Our own code lives in `local/plugins/*` and `local/themes/*` and is bind-mounted into the
container, so edits are instant and version-controlled here. WordPress core, WooCommerce and
third-party plugins live in a Docker volume and are **not** committed.

- `slk-checkout` — Sri Lanka checkout rules (25-district field, phone-first identity, optional
  email, COD fee and gating)
- `slk-order-flow` — COD confirmation lifecycle (custom statuses, stock reservation, SMS hooks,
  WhatsApp confirm actions, ops role)
- `slk-child` — Blocksy child theme; brand styling lands after the naming and design gates

Both plugins are currently scaffolds. Implementations go through the `dev-pipeline` skill.

## Conventions carried over from Aeshal

- Live-store mutations stay staged behind Najath's approval and are logged.
- No sale theatre — no strikethrough pricing.
- The two brands never cross-link publicly and keep separate voice configuration. Unlike Aeshal,
  this brand is proudly and openly Sri Lankan.
