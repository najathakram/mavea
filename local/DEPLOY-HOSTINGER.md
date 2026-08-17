# Deploying MAVÉA to Hostinger (mavea.lk)

This is the execution runbook for the plan at
`.claude/pipeline/plans/2026-08-18-hostinger-migration.md`. Everything else in
this migration (the mu-plugin guard, the catalogue export/import pair, the
image seeding scripts, `local/deploy/deploy-code.sh`) is a repo artifact
prepared ahead of time. This document is where those artifacts actually get
run against the live server — nobody should improvise a step that isn't
written down here.

**Read this whole file before running anything.** Steps are ordered on
purpose: the guard lands and is proven live, twice, before any theme code
exists on the server; theme activation — the single most dangerous step — is
last among the code steps, not first.

## Conventions used below

- `SSH_DEST`, `SSH_PORT`, `SSH_KEY`, `WP_ROOT` mean the same thing here as in
  `local/deploy/deploy-code.sh`:
  ```bash
  SSH_DEST=u860340467@46.17.172.250
  SSH_PORT=65002
  SSH_KEY=~/.ssh/mavea_hostinger
  WP_ROOT=domains/mavea.lk/public_html
  ```
- `deploy-code.sh` invocations run from the dev host, repo root:
  ```bash
  SSH_DEST=u860340467@46.17.172.250 bash local/deploy/deploy-code.sh guard
  SSH_DEST=u860340467@46.17.172.250 bash local/deploy/deploy-code.sh code
  ```
- Every other `wp ...` command below runs **on the server**. SSH in once and
  stay there:
  ```bash
  ssh -i ~/.ssh/mavea_hostinger -p 65002 u860340467@46.17.172.250
  cd domains/mavea.lk/public_html
  ```
- The homepage check (below) always runs from the **dev host**, never over
  SSH — it is what a real visitor's browser would see.

## The homepage check (canonical — run after every step marked ⟳)

Defined once here. Every step below marked ⟳ means: come back to this exact
block and run it before doing anything else. Cache-busted first, then plain —
LiteSpeed caches the homepage HTML for a week, so the plain line alone can
mask a breakage for days.

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

If either line fails: **stop, do not proceed to the next step**, and use the
rollback noted under the step you just ran.

---

## §0 — Blocking prerequisites

None of these are automatable. Six items; the ones marked BLOCKING gate a
specific later section and this runbook stops there until they're resolved.

1. ~~SSH key in hPanel~~ — **DONE.** SSH enabled, ed25519 key added, verified.
2. **Fresh wp-admin login on mavea.lk.** The temp domain
   `khaki-lobster-518218.hostingersite.com` still holds an old admin session;
   `mavea.lk/wp-admin` needs its own fresh login. BLOCKING for any step below
   that asks you to eyeball the dashboard (§2, §5, §6, §7 verification) —
   WP-CLI over SSH works without it, but visual confirmation does not.
3. **G4 modesty clearance.** Seeding puts photography into
   `wp-content/uploads/`, which is publicly addressable even while every page
   is hidden behind the guard. BLOCKING §8.
4. **Placeholder prices.** The catalogue's prices are DEV PLACEHOLDERS
   pending G3 sign-off. BLOCKING §8 as a real, priced catalogue — the import
   itself is idempotent and safe to run, but do not treat prices seeded under
   this plan as final.
5. **Shipping model at launch.** Activating slk-checkout provisions a SECOND
   zone named "Sri Lanka" (district-tiered `slk_delivery`) alongside the
   protected flat-rate zone (§6). Zone ORDER decides which one charges.
   BLOCKING §10 — this is Najath's decision, not automatable.
6. **The two copy items flagged in §11** (`pages-help.php:204`,
   `story.php:9`). BLOCKING §10 — the guard keeps both pages hidden
   pre-launch, so nothing leaks while this plan runs, but launch cannot
   happen with either line unresolved.

---

## §1 — Baseline recon (read-only)

No writes except the one currency display setting, which is not on the
protected list.

```bash
wp core version
wp theme list
wp plugin list
wp option get siteurl
wp option get page_on_front
wp option get show_on_front
wp option get woocommerce_currency_pos
```

If `woocommerce_currency_pos` is not `left_space`:

```bash
wp option update woocommerce_currency_pos left_space
```

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

---

## §2 — Guard first

The mu-plugin guard must be live and verified before any theme code exists on
the server.

```bash
SSH_DEST=u860340467@46.17.172.250 bash local/deploy/deploy-code.sh guard
```

Then on the server:

```bash
wp option update mavea_prelaunch 1
```

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

**Plus**, confirm no theme chrome leaked through:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -c wp-block-template-part
```

Must print `0`.

**Rollback:** delete the mu-plugin over SSH and purge — this restores today
exactly, byte-for-byte, because nothing else has changed yet.

```bash
ssh -i "$SSH_KEY" -p "$SSH_PORT" "$SSH_DEST" \
  "rm -f '$WP_ROOT/wp-content/mu-plugins/mavea-prelaunch.php' && \
   rm -rf '$WP_ROOT/wp-content/mu-plugins/mavea' && \
   cd '$WP_ROOT' && wp litespeed-purge all"
```

Then re-run the homepage check to confirm the rollback worked:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

---

## §3 — Code

Ships the three slk plugins and the theme. Nothing is activated here, so
nothing public may change.

```bash
SSH_DEST=u860340467@46.17.172.250 bash local/deploy/deploy-code.sh code
```

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

---

## §4 — Blocksy

Blocksy is not installed on Hostinger. Install and pin the version the theme
was built against; do not activate it yet and do not install Blocksy
Companion (not used locally, must not appear live).

```bash
wp theme install blocksy --version=2.1.52
```

If 2.1.52 is unavailable, install the latest available version and record
which one you got here — the guard means even a version-drifted theme cannot
take down the homepage.

Do **not** run `wp theme activate blocksy`.
Do **not** install `blocksy-companion` (or any plugin with "blocksy" and
"companion" in its name).

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

---

## §5 — wordpress.org plugin set

Installs nine plugins (the record kept in `local/setup-plugins.sh`, reissued
here as `wp plugin install` over SSH — the script itself is docker-bound and
must never run against live). Activate only the five active locally; park the
rest exactly as they are parked locally.

```bash
# ACTIVE — installed and activated, same five as the local dev store
wp plugin install woocommerce-pdf-invoices-packing-slips --activate
wp plugin install seo-by-rank-math --activate
wp plugin install payhere-payment-gateway --activate
wp plugin install mintpay --activate
wp plugin install login-with-google --activate

# PARKED — installed, left inactive, credentials not yet available
wp plugin install paykoko-bnpl-payment-gateway
wp plugin install notifylk-sms-for-woocommerce
wp plugin install wp-mail-smtp
wp plugin install pixelyoursite
```

PayHere, Mintpay and Login with Google are active but unconfigured (no
merchant ID / OAuth client yet — only Najath can obtain those). Each plugin's
own code keeps its feature hidden from shoppers until configured, so being
active with no credentials is safe here, same as locally.

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

---

## §6 — Activate slk plugins, ONE AT A TIME

Each activation gets its own check before moving to the next.

```bash
wp plugin activate slk-checkout
```

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

Then verify the shipping and currency behaviour that only slk-checkout
provisions:

```bash
wp eval 'SLK_Shipping::maybe_provision_zone();'
wp wc shipping_zone list --user=1
```

Expect **two** zones named "Sri Lanka". Confirm the protected one (flat-rate
"Island-wide delivery" Rs. 350, plus free shipping over Rs. 15,000) is
unchanged — this plan never edits it, this step should only ever add a
second zone alongside it.

```bash
wp eval 'echo wp_strip_all_tags( html_entity_decode( wc_price( 12500 ) ) ), "\n";'
```

Must print `Rs. 12,500`.

```bash
wp plugin activate slk-order-flow
```

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

```bash
wp plugin activate slk-exchanges
```

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

---

## §7 — Theme activation — THE dangerous step

This is why the guard exists. It happens last among the code steps, after the
guard has already been proven live twice.

```bash
wp theme activate slk-child
```

⟳ **Immediately**, homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

**Plus**, confirm no Blocksy chrome leaked through:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -c ct-container
```

Must print `0`.

Then verify the guard is still hiding everything except the front page — pick
any page the theme provisions on activation (e.g. `/our-story/`) and confirm
it 302s home:

```bash
curl -o /dev/null -s -w "%{http_code} -> %{redirect_url}\n" https://mavea.lk/our-story/
```

Expect `302` to `https://mavea.lk/`.

**Rollback:** the database is never touched by this step — page 13 is never
touched by anything in this plan — so rollback is one line plus a purge.

```bash
wp theme activate twentytwentyfive
wp litespeed-purge all
```

Then re-run the homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

---

## §8 — Seeding (needs G4 + price sign-off — §0 items 3 and 4)

Do not start this section until the prerequisites it's gated on are resolved.

1. **Export locally** (in the local Docker container, per WP2):
   produces `local/deploy/catalog.json`.
2. **Bundle and transfer to the server** — via `tar` + `scp` (no rsync on the
   dev host, same constraint as code deploy). The three seed scripts travel
   with the bundle: `deploy-code.sh` ships only the plugins and the theme, so
   nothing under `local/deploy/` reaches the server any other way. Land the
   bundle at `/home/u860340467/mavea-seed/`, laid out like this:

   ```
   mavea-seed/catalog.json                 <- local/deploy/catalog.json
   mavea-seed/editorial-*.jpg              <- the six temp/editorial-*.jpg
   mavea-seed/products/<slug>-<role>.jpg   <- the normalised temp/products/ frames
   mavea-seed/seed-catalog-live.php        <- local/deploy/
   mavea-seed/import-product-images.php    <- local/
   mavea-seed/seed-editorial-live.php      <- local/deploy/
   ```
3. **On the server**, in order:

```bash
wp eval-file /home/u860340467/mavea-seed/seed-catalog-live.php /home/u860340467/mavea-seed/catalog.json
wp eval-file /home/u860340467/mavea-seed/import-product-images.php /home/u860340467/mavea-seed/products
wp eval-file /home/u860340467/mavea-seed/seed-editorial-live.php /home/u860340467/mavea-seed
wp wc tool run regenerate_product_lookup_tables --user=1
wp cache flush && wp litespeed-purge all
```

(All three take a path argument: the catalogue JSON for
`seed-catalog-live.php`, the product frames' `products/` folder for
`import-product-images.php`, the folder holding the six `editorial-*.jpg`
frames for `seed-editorial-live.php`. Adjust all three to wherever the bundle
actually landed on the server. Neither image script's default is correct here:
without the argument they fall back to a local staging path that does not
exist on the server. Both stop with an error rather than doing half a job:
`import-product-images.php` refuses to run at all if the directory is missing —
it force-deletes the existing imagery product by product, so a wrong path would
otherwise strip all twenty and attach nothing — and `seed-editorial-live.php`
stops if it cannot find a still rather than leaving the home hero, Our Story and
Contact frames unset.)

**Checks:**

```bash
wp post list --post_type=product --post_status=publish --format=count
```

Must print `20`.

```bash
wp post list --post_type=product --post_status=publish --fields=post_title,post_type --format=table
```

Spot-check a few products for a thumbnail (`_thumbnail_id` set).

⟳ Homepage check:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

`/shop/` must still be gated — the guard covers it, and WooCommerce
coming-soon covers it independently:

```bash
curl -o /dev/null -s -w "%{http_code}\n" https://mavea.lk/shop/
```

Expect `302`.

---

## §9 — Keeping repo and live in sync

`design/holding-page.html` is the single source of truth for the front
page's content (page 13's one `wp:html` block). Going forward, page 13 is
edited **only** by editing `design/holding-page.html` first and pushing that
edit into the live block afterward — never by editing the live page directly
and letting the repo drift out of sync. If you do edit page 13, treat it like
any other step that touches the homepage: run the homepage check afterward.

---

## §10 — Launch (OUT OF SCOPE for this plan)

Flipping the site from pre-launch to live is a separate, later decision and
is explicitly out of scope here. Recorded so it is not improvised when the
time comes:

- The flag that gates everything is `mavea_prelaunch` (missing counts as
  ON). The mu-plugin's own header names the eventual command:
  `wp option update mavea_prelaunch 0`.
- Launch cannot happen until §0 items 5 and 6 are resolved: the
  shipping-zone order decision, and the two copy items in §11.
- Launch also depends on the G3 price sign-off and G4 imagery clearance
  already gating §8 — a pre-launch catalogue is not the same thing as a
  launch-ready one.
- This runbook does not specify the launch mechanism, sequencing, or
  verification beyond what's written above. That is a separate plan.

---

## §11 — Flags for Najath

Two copy issues live in theme files owned by a concurrent session. They are
flagged here, not fixed — the pre-launch guard keeps both pages hidden, so
nothing leaks while the wording is decided. No replacement copy is proposed
for either; that decision belongs to Najath.

1. `local/themes/slk-child/inc/pages-help.php:204` — the rendered FAQ answer
   reads "…before anything leaves Galle…", naming Galle as the dispatch
   origin. This conflicts with the brand rule that provenance is stated as
   "made in Sri Lanka" and no town is ever named as where the clothes are
   made or ship from.
2. `local/themes/slk-child/page-templates/story.php:9` — the file-header
   comment still contains "eight women, twenty of a cut", a headcount/batch
   detail the brand rules exclude from any copy.

Both need Najath's decision on wording before launch (§10, blocking item 6).
