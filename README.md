# MAVÉA — Sri Lanka modest-fashion store (WordPress / WooCommerce)

Sister brand to Aeshal (US, Shopify), operating entirely in Sri Lanka: LKR pricing, COD-first
checkout, small-batch ready-to-wear from the Galle atelier, aimed at modern-yet-modest Sri
Lankan Muslim women at a lower price point than Aeshal.

**The brand name is MAVÉA**, settled at gate G1 on 2026-08-15, on the domain `mavea.lk`.
The accent is part of the name but travels only in display strings; anything that must be
ASCII — domain, handles, slugs, file names, this repo — uses `mavea`. The `slk-` code prefix
is **not** a brand marker: it stands for Sri Lanka, and it stays as it is.

## Start here

| File | What it is |
|---|---|
| [HANDOFF.md](HANDOFF.md) | **Read first.** Living status: what is done, what is open, and what bit us |
| [00-PLAN.md](00-PLAN.md) | The approved build plan — architecture, payments, order flow, gates G1–G5 |
| [HOSTING-DECISION.md](HOSTING-DECISION.md) | WordPress.com vs Hostinger, with verified 2026 pricing; why we build local and buy later |
| [SETUP-CHECKLIST.md](SETUP-CHECKLIST.md) | The accounts only Najath can create (PayHere, Koko, Koombiyo, domains) |
| [NAMING.md](NAMING.md) | 26 candidate names with live domain/Instagram/collision evidence — gate G1 closed on MAVÉA, 2026-08-15 |
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
- `slk-child` — Blocksy child theme: the Porcelain Glass design system, the WooCommerce and
  Blocksy mapping, the page templates, and the house dropdown (`inc/select.php` +
  `assets/js/select.js`, which replaces both native selects and select2)

`slk-checkout` and `slk-child` are implemented; `slk-order-flow` is still a scaffold. Further
implementation goes through the `dev-pipeline` skill.

Gate G1 is closed and the brand is MAVÉA. Nothing hardcodes it: the wordmark is text supplied
by the `slk_wordmark` filter, whose default is the single source of truth in
[inc/wordmark.php](local/themes/slk-child/inc/wordmark.php). Renaming remains a one-line
change. The `slk-` prefix on plugins, classes, functions and CSS is a Sri Lanka marker, not a
brand one, so it was deliberately left alone — renaming it would churn every PHP file, the
`_slk_*` order meta already written to live orders, and the activated theme/plugin paths in
the local database, for no gain.

## Conventions carried over from Aeshal

- Live-store mutations stay staged behind Najath's approval and are logged.
- No sale theatre — no strikethrough pricing.
- The two brands never cross-link publicly and keep separate voice configuration. Unlike Aeshal,
  this brand is proudly and openly Sri Lankan.
