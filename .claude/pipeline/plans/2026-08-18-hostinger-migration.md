# Plan: migrate MAVÉA from local Docker to the live Hostinger install, without breaking the holding page

> Authored by Fable 5 on 2026-08-18. Status: IMPLEMENTED (artifacts only —
> nothing has been executed against the live server; the runbook is unwalked).
>
> Gate: shell syntax OK · all six PHP files lint clean in the slk-wp container ·
> guard wiring present · currency filter intact · live baseline serving.
> The guard was proven on the local container, which runs slk-child with its
> front-page.php: before install the front page rendered `slk-hero`; after
> install, zero occurrences of `slk-hero` / `ct-container` /
> `wp-block-template-part`, and `/our-story/` 302'd home. Removing the file
> restored `slk-hero` exactly, confirming the one-line rollback.
>
> Review caught two real defects, both fixed before commit: (1)
> `media_handle_sideload()` unlinks its source, so seeding would have consumed
> the editorial frames and a re-run would then have destroyed all six live
> attachments while still reporting success; (2) the runbook called the
> editorial seeder with no path argument, which resolved to a nonexistent
> directory and imported nothing, silently.
> This file is the ONLY context the implementation and review agents receive.
> It must stand alone: no references to "the conversation", no "as discussed".

## Objective

Move the MAVÉA build — the `slk-child` theme, the three `slk-*` plugins, the
20-product catalogue and the six editorial stills — from the local Docker dev
site onto the live Hostinger WordPress at https://mavea.lk, while the public
homepage keeps serving the existing holding page at every moment of the
migration. The store stays hidden (WooCommerce coming-soon on store pages, plus
a new pre-launch guard for everything else); flipping the site live is a later,
separate decision and is explicitly OUT of this plan's scope.

Everything in this plan lands as repo artifacts (a mu-plugin, seed scripts, a
deploy script, a runbook). The actual execution against the live server is the
runbook's job and has hard blocking prerequisites listed below.

## Constraints & conventions

### Live state (verified 2026-08-17/18, treat as ground truth)

- WordPress + WooCommerce 11.0.1, PHP 8.3.30, active theme Twenty Twenty-Five,
  LiteSpeed Cache 7.9, Hostinger's own plugins (AI, Easy Onboarding, Reach,
  Tools). Cloudflare proxied (HTML is `cf-cache-status: DYNAMIC`), SSL Full
  (Strict), A @ -> 46.17.172.250.
- SSH is ENABLED and key auth is working. WP-CLI 2.12.0 at `/usr/local/bin/wp`.
  Docroot `/home/u860340467/domains/mavea.lk/public_html`. Port 65002.
  Key: `~/.ssh/mavea_hostinger` (private stays local).
- Front page = published page "Home", **post ID 13**, containing ONE `wp:html`
  block holding the MAVÉA holding page. Settings -> Reading = static front page
  "Home"; page template "Page No Title". Source of truth for the markup:
  `design/holding-page.html` (repo). LiteSpeed caches the homepage HTML with
  `public,max-age=604800` — every mutating step must end in a purge and every
  check must cache-bust.
- WooCommerce coming-soon is ON for STORE PAGES ONLY. The site itself is
  publicly reachable.
- **Protected settings — never overwrite, never re-run anything that sets
  them:** currency LKR, 0 decimals, comma thousands; selling + shipping
  restricted to Sri Lanka; store address 140/A Godawatta, Gintota, 80280;
  shipping zone "Sri Lanka" containing flat-rate "Island-wide delivery"
  Rs. 350 AND Free shipping with Rs. 15,000 minimum (applied before coupon
  discount); taxes off; timezone Asia/Colombo; date format d/m/Y; site title
  MAVÉA.
- The temp domain `khaki-lobster-518218.hostingersite.com` still resolves and
  holds an admin session; `mavea.lk/wp-admin` needs a fresh login (user
  action). `/cart` `/checkout` `/my-account` already return
  `x-litespeed-cache: none`.

### Repo facts

- Repo root `C:\ClaudeCode\mavea`, branch `main`, pushed to
  `github.com/najathakram/mavea`. All paths below are repo-relative.
- Deployable code: `local/themes/slk-child/` (~380KB PHP, 21+ files,
  `style.css` declares `Template: blocksy`), `local/plugins/slk-checkout/`,
  `local/plugins/slk-order-flow/`, `local/plugins/slk-exchanges/`.
- **Blocksy is NOT installed on Hostinger.** Local runs Blocksy **2.1.52**.
  It is free on wordpress.org. The Blocksy Companion plugin is NOT installed
  locally and must NOT be installed live.
- **The 20-product catalogue exists only in the local Docker database**
  (20 published products: aleena, anisa, fizza, hafsa, latheefa, maryam,
  muneera, nadira, naila, nasreen, rahma, raihana, safiya, salma, shazna,
  suhana, warda, yasmeen, zaheera, zarina). No script recreates it.
  `local/seed-catalog.sh` builds the RETIRED Aeshal 9-product set and must
  never be run anywhere, local or live — it would resurrect deleted products.
- Product meta present locally: `_regular_price`, `_price`, `_manage_stock`,
  `_stock`, `_backorders`, `_stock_status`, `_sold_individually`,
  `_tax_status`, `_tax_class`, `_product_attributes`, `_thumbnail_id`,
  `_product_image_gallery`. Taxonomies: `product_cat` (incl. `hand-beaded`,
  which carries intro copy in its term DESCRIPTION), `product_tag` (`set`),
  `pa_colour`, `pa_detail`, `pa_occasion`, `product_type`. The `_slk_*` keys
  are part of the plugin contract and are exported defensively.
- Product images: `temp/products/` holds 120 normalised frames
  (`<slug>-<role>.jpg`), generated by `local/prepare-product-images.py` from
  `dresses/` (do not regenerate; they exist). Editorial stills:
  `temp/editorial-*.jpg`, six keys: hero-group, hero-alt, room-wide,
  story-wide, studio-pair, portrait-warm (options `slk_img_*`).
- `local/bootstrap.sh` must NEVER run against live: it overwrites the
  protected store settings and sets store city "Galle".
  `local/setup-plugins.sh` is docker-bound; it is the RECORD of the intended
  wordpress.org plugin set — reissue its `wp plugin install` commands over
  SSH, never run the script against live.
- Git status: `contact.php`, `story.php`, `thankyou.php` (theme) carry
  UNCOMMITTED provenance fixes in the working tree. The deploy script ships
  the working tree, so they deploy; committing them belongs to the theme
  session, not this plan.

### The homepage hazard (why WP1 exists)

`local/themes/slk-child/front-page.php` exists. WordPress's template
hierarchy makes a theme's `front-page.php` outrank the static-front-page
setting, so activating `slk-child` would silently replace the holding page
with the store home. The fix is a must-use plugin that filters
`frontpage_template` and `template_include` while a pre-launch flag is set.
**A concurrent session owns the theme — this plan must not edit ANY file under
`local/themes/slk-child/`, front-page.php included.**

Second half of the hazard: the holding page's chrome-hiding CSS targets
Twenty Twenty-Five block classes (`.wp-block-template-part`,
`.is-layout-constrained`) which do not exist under Blocksy. The mu-plugin
solves this structurally: it renders the front page through its own bare
template with no `get_header()`/`get_footer()`, so there is no theme chrome —
TT25's or Blocksy's — to suppress at all. `design/holding-page.html` needs
**no edit** and stays the single source of truth.

### Deploy method: SSH + tar-over-ssh (decided)

- **hPanel Git deploy: rejected.** The repo is ~600MB of working tree
  (`dresses/` 202MB + `reference/` 377MB are committed) for ~4MB of deployable
  code, and Git deploy targets ONE directory — it cannot fan `local/themes/…`
  and `local/plugins/…` out into `wp-content/themes/`, `wp-content/plugins/`
  and `wp-content/mu-plugins/`.
- **SSH + `tar | ssh tar x`: chosen.** SSH + WP-CLI are required for seeding
  regardless. `ssh`, `scp`, `tar` confirmed present on the dev host;
  `rsync` is NOT (do not use it).
- **hPanel File Manager zip upload: documented fallback** for code only.

### The currency symbol — ALREADY DELIVERED, do not duplicate

`local/plugins/slk-checkout/slk-checkout.php` lines 30-37 already contain the
required filter. It reaches live when slk-checkout deploys and activates. No
new code; the reviewer verifies this block is present and unmodified:

```php
add_filter(
	'woocommerce_currency_symbol',
	static function ( $symbol, $currency ) {
		return 'LKR' === $currency ? 'Rs.' : $symbol;
	},
	10,
	2
);
```

With the live settings plus `woocommerce_currency_pos = left_space` (runbook
§1 sets it if unset — a display setting, not on the protected list),
`wc_price( 12500 )` renders "Rs. 12,500". Until slk-checkout activates, admin
screens show රු; store pages are behind coming-soon, so no shopper sees it.

### Brand copy rules (non-negotiable)

- Provenance is "made in Sri Lanka". Never name Galle or any town as where the
  clothes are made or ship from. THE ONE EXCEPTION: delivery districts — rate
  tables and the district selector list all 25 districts including Galle.
- No headcounts, no batch sizes.
- **Flag, do not fix (theme owned by a concurrent session; the guard hides
  these pages pre-launch so nothing leaks while wording is decided):**
  1. `local/themes/slk-child/inc/pages-help.php:204` — rendered FAQ answer
     "…before anything leaves Galle…" names Galle as the dispatch origin.
     Needs Najath's wording.
  2. `local/themes/slk-child/page-templates/story.php:9` — "eight women,
     twenty of a cut" survives in the file-header comment. Needs Najath's
     decision; do NOT invent copy.
- The É is load-bearing: every file carrying MAVÉA stays UTF-8; never
  `strtoupper()` the wordmark. `catalog.json` is written with
  `JSON_UNESCAPED_UNICODE`.

### Blocking prerequisites — user actions, not automatable

1. ~~SSH key in hPanel~~ **DONE** — SSH enabled, ed25519 key added, verified.
2. **Fresh wp-admin login on mavea.lk** for dashboard verification.
3. **G4 modesty clearance**: seeding puts photography into
   `wp-content/uploads/`, publicly addressable even while pages are hidden.
4. **Placeholder prices**: catalogue prices are DEV PLACEHOLDERS pending G3.
5. **Shipping model at launch**: activating slk-checkout provisions a SECOND
   zone named "Sri Lanka" carrying the district-tiered `slk_delivery` method,
   alongside the protected flat-rate zone. Zone ORDER decides which charges.
6. Copy items 1-2 above.

## Work packages

All four have disjoint file lists and can run in parallel. Nothing here
touches `local/themes/slk-child/**`, `design/holding-page.html`,
`local/bootstrap.sh`, `local/setup-plugins.sh`, `local/seed-catalog.sh`,
`local/seed-editorial.sh`, `local/seed-product-images.sh`, or
`local/prepare-product-images.py`.

### WP1 — Pre-launch guard mu-plugin

**files:** `local/mu-plugins/mavea-prelaunch.php`,
`local/mu-plugins/mavea/holding-template.php`

The correctness heart. The template lives in a SUBDIRECTORY on purpose:
WordPress auto-includes every top-level `mu-plugins/*.php` on every request,
and the template must only run when the template loader includes it.

**exact code — `local/mu-plugins/mavea-prelaunch.php`:**

```php
<?php
/**
 * Plugin Name: MAVÉA pre-launch guard
 * Description: While the pre-launch flag is on (option mavea_prelaunch, a MISSING option counts as ON), the front page always renders the holding page standalone and every other public URL redirects home. Lives in wp-content/mu-plugins/ so it cannot be deactivated from the dashboard by accident. Launch: wp option update mavea_prelaunch 0.
 *
 * WHY: the slk-child theme ships front-page.php, and WordPress's template
 * hierarchy lets front-page.php override the static-front-page setting —
 * activating the theme would silently replace the holding page with the
 * store home. This guard outranks the theme without editing it.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The pre-launch flag. Fail-safe: a MISSING option counts as ON, so the
 * guard holds from the moment this file lands, before anything is
 * configured. Emergency override: define( 'MAVEA_PRELAUNCH', false ).
 */
function mavea_prelaunch_on(): bool {
	if ( defined( 'MAVEA_PRELAUNCH' ) ) {
		return (bool) MAVEA_PRELAUNCH;
	}

	return '0' !== (string) get_option( 'mavea_prelaunch', '1' );
}

/**
 * Front page: bypass the active theme entirely. Hooked to BOTH
 * frontpage_template (the hierarchy's own slot — exactly where the theme's
 * front-page.php would win) and template_include at PHP_INT_MAX (so no
 * later filterer can put a theme template back).
 */
function mavea_prelaunch_template( $template ) {
	if ( mavea_prelaunch_on() && is_front_page() ) {
		return __DIR__ . '/mavea/holding-template.php';
	}

	return $template;
}
add_filter( 'frontpage_template', 'mavea_prelaunch_template', PHP_INT_MAX );
add_filter( 'template_include', 'mavea_prelaunch_template', PHP_INT_MAX );

/**
 * Everything else: the theme provisions its brand/help pages the moment it
 * activates (slk_ensure_page), and WooCommerce's coming-soon covers only
 * store pages — without this, /our-story/ and friends would be publicly
 * reachable before launch. Anonymous front-end requests for anything but
 * the front page go home. Staff (edit_posts) pass through, so pages can be
 * reviewed while hidden. Priority 9: ahead of WP's sitemap renderer (10).
 */
function mavea_prelaunch_redirect(): void {
	if ( ! mavea_prelaunch_on() || is_front_page() || is_admin() ) {
		return;
	}

	if ( is_robots() || is_favicon() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}

	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return;
	}

	wp_safe_redirect( home_url( '/' ), 302 );
	exit;
}
add_action( 'template_redirect', 'mavea_prelaunch_redirect', 9 );
```

**exact code — `local/mu-plugins/mavea/holding-template.php`:**

```php
<?php
/**
 * Standalone holding-page template, selected by ../mavea-prelaunch.php while
 * the pre-launch flag is on. In a subdirectory ON PURPOSE: WordPress
 * auto-loads every top-level mu-plugins/*.php on every request, and this
 * file must only ever run when the template loader includes it.
 *
 * It renders the CONTENT of the static front page (live: page 13, one
 * wp:html block whose source of truth is design/holding-page.html) in a bare
 * HTML shell. No get_header()/get_footer(), so there is no Twenty
 * Twenty-Five and no Blocksy chrome to suppress — the TT25-scoped :has()
 * rules inside the block simply never match, and design/holding-page.html
 * needs no edit for the theme switch.
 */

defined( 'ABSPATH' ) || exit;

$mavea_front = get_post( (int) get_option( 'page_on_front' ) );
$mavea_html  = $mavea_front instanceof WP_Post ? do_blocks( $mavea_front->post_content ) : '';

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( get_bloginfo( 'name', 'display' ) ); ?></title>
</head>
<body>
<?php
if ( '' !== trim( $mavea_html ) ) {
	echo $mavea_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-authored block content: the holding page itself.
} else {
	// Emergency fallback, reached only if the static front page is unset or
	// emptied. Deliberately plain: the real markup's single source of truth
	// stays design/holding-page.html, never a copy here.
	echo '<h1 style="font-family:Georgia,serif;text-align:center;margin-top:40vh;letter-spacing:.24em;">MAV&Eacute;A</h1>';
	echo '<p style="font-family:sans-serif;text-align:center;letter-spacing:.16em;">OPENING SOON</p>';
}
?>
</body>
</html>
```

### WP2 — Catalogue export/import pair

**files:** `local/deploy/export-catalog.php`, `local/deploy/seed-catalog-live.php`

The 20-product catalogue lives only in the local Docker DB.
`export-catalog.php` runs in the LOCAL container via `wp eval-file` and writes
`/var/www/html/temp/catalog.json`; `seed-catalog-live.php` runs on HOSTINGER
via `wp eval-file … catalog.json` and is idempotent by product slug. Images
are deliberately NOT in the JSON — WP3 owns them. Neither script touches any
`woocommerce_*` option.

**`local/deploy/export-catalog.php`** — exports:
- meta whitelist: `_regular_price`, `_price`, `_manage_stock`, `_stock`,
  `_backorders`, `_stock_status`, `_sold_individually`, `_tax_status`,
  `_tax_class`, `_product_attributes`, `_low_stock_amount`, `_slk_making_days`,
  `_slk_retired`, `_slk_custom_options`, `_slk_custom_length`
- NO `_thumbnail_id`, NO `_product_image_gallery`, NO `_sale_price`
- taxonomies `product_cat`, `product_tag`, `pa_colour`, `pa_detail`,
  `pa_occasion` — WITH term descriptions (the hand-beaded shelf intro is one)
- writes with `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`
- run: `docker cp` into `slk-wp`, `wp eval-file temp/export-catalog.php`,
  `docker cp` the JSON back to `local/deploy/catalog.json`, with
  `MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'`

**`local/deploy/seed-catalog-live.php`** — imports, in order:
1. Global attribute taxonomies (`pa_*`) via `wc_create_attribute()` then
   `register_taxonomy()` (Woo registers these on `init`, which has already run,
   so terms would otherwise not land in this process)
2. Terms with descriptions, updating name/description if changed
3. Products idempotent by slug via `get_page_by_path( slug, OBJECT, 'product' )`;
   `update_post_meta` for each whitelisted key; `delete_post_meta( id, '_sale_price' )`
   defensively (brand law: this store never carries a sale price);
   `wp_set_object_terms( id, 'simple', 'product_type' )`
4. Ends telling the operator to run
   `wp wc tool run regenerate_product_lookup_tables --user=1`

### WP3 — Image seeding, made host-agnostic

**files:** `local/import-product-images.php` (edit),
`local/deploy/seed-editorial-live.php` (new) · **effort: low**

Assessment of every seed script:
- `seed-catalog.sh` — retired Aeshal catalogue; never ported, never run.
- `seed-product-images.sh` — pure docker-staging wrapper; stays local-only.
- `import-product-images.php` — ONE change, the hardcoded container path
  becomes an overridable argument. Replace:

```php
$dir = '/var/www/html/temp/products';
```

  with:

```php
// Where the normalised frames are. Defaults to the local container's staging
// path; on Hostinger pass the directory as the eval-file argument:
//   wp eval-file import-product-images.php /home/uXXXXXXXXX/mavea-seed/products
$dir = isset( $args[0] ) ? rtrim( (string) $args[0], '/' ) : '/var/www/html/temp/products';
```

  No other line of that file changes. Local behaviour is byte-identical when
  no argument is passed.
- `seed-editorial.sh` — docker-bound; replaced for live by a PHP twin,
  `seed-editorial-live.php`, with the six keys and their alt texts copied
  VERBATIM. The shell script itself stays untouched for local use.
- `prepare-product-images.py` — host-side generator, unchanged.

**`local/deploy/seed-editorial-live.php`** covers exactly six keys —
hero-group, hero-alt, room-wide, story-wide, studio-pair, portrait-warm —
deletes the prior attachment for a key before importing (so a re-cut frame
actually replaces the old), sets `_wp_attachment_image_alt`, and points
`slk_img_<key>` at the new attachment ID. Alt strings must be byte-identical
to `local/seed-editorial.sh`: they describe the garments, never the model,
never a place.

### WP4 — Deploy script and the runbook

**files:** `local/deploy/deploy-code.sh`, `local/DEPLOY-HOSTINGER.md`

**`deploy-code.sh`** — code only, two hand-verified stages (guard first, on
purpose), tar-over-ssh (no rsync on the dev host):

```bash
#!/usr/bin/env bash
# Push deployable code to the live Hostinger install over SSH. Code only:
# never the database, never uploads, never a WooCommerce setting, and it
# never runs bootstrap.sh anywhere.
#
#   SSH_DEST=u860340467@46.17.172.250 bash local/deploy/deploy-code.sh guard
#   SSH_DEST=u860340467@46.17.172.250 bash local/deploy/deploy-code.sh code
#
# Stages are separate ON PURPOSE: the mu-plugin guard must be live and
# verified BEFORE any theme code exists on the server.
set -euo pipefail
cd "$(dirname "$0")/../.."

STAGE="${1:-}"
[ "$STAGE" = guard ] || [ "$STAGE" = code ] || { echo "usage: deploy-code.sh guard|code" >&2; exit 2; }

: "${SSH_DEST:?set SSH_DEST=user@host}"
SSH_PORT="${SSH_PORT:-65002}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/mavea_hostinger}"
WP_ROOT="${WP_ROOT:-domains/mavea.lk/public_html}"

run() { ssh -i "$SSH_KEY" -p "$SSH_PORT" "$SSH_DEST" "$1"; }

echo "==> sanity: WordPress lives at ~/$WP_ROOT"
run "test -f '$WP_ROOT/wp-load.php'"

if [ "$STAGE" = guard ]; then
  echo "==> mu-plugin guard"
  run "mkdir -p '$WP_ROOT/wp-content/mu-plugins'"
  tar czf - -C local/mu-plugins . | run "tar xzf - -C '$WP_ROOT/wp-content/mu-plugins'"
else
  echo "==> plugins (land inactive; activation is a hand-verified runbook step)"
  tar czf - -C local/plugins slk-checkout slk-order-flow slk-exchanges \
    | run "tar xzf - -C '$WP_ROOT/wp-content/plugins'"
  echo "==> theme (NOT activated here)"
  tar czf - -C local/themes slk-child | run "tar xzf - -C '$WP_ROOT/wp-content/themes'"
fi

echo "==> purge LiteSpeed so what is served is what was deployed"
run "cd '$WP_ROOT' && wp litespeed-purge all" || echo "    (purge failed - purge from wp-admin)"

echo "==> done. Run the homepage check from DEPLOY-HOSTINGER.md before the next step."
```

**`local/DEPLOY-HOSTINGER.md`** — sections §0-§11. The canonical homepage
check, defined once and reused after EVERY step marked ⟳:

```bash
curl -fsS "https://mavea.lk/?slk=$(date +%s)" | grep -q mavea-holding && echo "HOME OK" || echo "HOME BROKEN - STOP"
curl -fsS https://mavea.lk | grep -q mavea-holding && echo "PUBLIC OK"
```

- §0 Blocking prerequisites (the six, each marked BLOCKING where it blocks)
- §1 Baseline recon (read-only): `wp core version`, `wp theme list`,
  `wp plugin list`, `wp option get siteurl|page_on_front|show_on_front`,
  set `woocommerce_currency_pos left_space` if unset ⟳
- §2 **Guard first** — `deploy-code.sh guard`, `wp option update mavea_prelaunch 1`,
  ⟳ plus `grep -c wp-block-template-part` must print `0`.
  Rollback: delete the mu-plugin + purge, restores today exactly.
- §3 Code — `deploy-code.sh code`. Nothing public may change ⟳
- §4 Blocksy — `wp theme install blocksy --version=2.1.52`, do NOT activate,
  do NOT install Blocksy Companion ⟳
- §5 wordpress.org plugin set — install nine, activate only the five active
  locally (woocommerce-pdf-invoices-packing-slips, seo-by-rank-math,
  payhere-payment-gateway, mintpay, login-with-google); park the rest ⟳
- §6 Activate slk plugins ONE AT A TIME, ⟳ after each. After slk-checkout:
  `wp eval 'SLK_Shipping::maybe_provision_zone();'`, then
  `wp wc shipping_zone list --user=1` — EXPECT TWO zones named "Sri Lanka";
  verify the protected one untouched. Currency check:
  `wp eval 'echo wp_strip_all_tags( html_entity_decode( wc_price( 12500 ) ) ), "\n";'`
  must print `Rs. 12,500`
- §7 **Theme activation — THE dangerous step** — `wp theme activate slk-child`,
  IMMEDIATELY ⟳ plus `grep -c ct-container` must print `0`. Verify the guard
  hides a provisioned page (302 to home). Rollback:
  `wp theme activate twentytwentyfive` + purge
- §8 Seeding (needs G4 + price sign-off): export locally, tar/scp the bundle,
  then on the server run seed-catalog-live, import-product-images,
  seed-editorial-live, `wp wc tool run regenerate_product_lookup_tables --user=1`,
  `wp cache flush && wp litespeed-purge all`. Checks: 20 products, thumbnail
  present, ⟳, `/shop/` still 302
- §9 Keeping repo and live in sync — page 13 is edited ONLY by editing
  `design/holding-page.html` first
- §10 Launch (OUT OF SCOPE, recorded so it is not improvised)
- §11 Flags for Najath

## Acceptance criteria

1. `mavea-prelaunch.php` matches WP1: missing option counts as ON; filters
   BOTH `frontpage_template` and `template_include` at `PHP_INT_MAX`; redirect
   on `template_redirect` priority 9 with admin/robots/favicon/ajax/cron/REST/
   `edit_posts` bypasses; no other hooks.
2. `holding-template.php` is IN THE SUBDIRECTORY, renders `page_on_front`
   via `do_blocks()` in a bare shell with no `get_header()`/`get_footer()`,
   and its fallback is NOT a copy of the holding-page markup.
3. The diff touches NOTHING under `local/themes/slk-child/` and does not
   modify `design/holding-page.html`, `bootstrap.sh`, `setup-plugins.sh`,
   `seed-catalog.sh`, `seed-editorial.sh`, `seed-product-images.sh`, or
   `prepare-product-images.py`.
4. The only change to `import-product-images.php` is the `$dir`
   parameterization; the no-argument default is the unchanged container path.
5. `export-catalog.php` exports exactly the WP2 meta whitelist (no
   `_thumbnail_id`, no `_product_image_gallery`, no `_sale_price`) and the
   five taxonomies including term descriptions.
6. `seed-catalog-live.php` is idempotent by slug, creates missing `pa_*`
   attributes via `wc_create_attribute` + `register_taxonomy`, deletes
   `_sale_price`, sets `product_type` to `simple`, and contains NO write to
   any option starting `woocommerce_`.
7. `seed-editorial-live.php` covers exactly the six keys, alt strings
   byte-identical to `seed-editorial.sh`, and deletes the prior attachment
   before importing.
8. `deploy-code.sh` has exactly the two stages; `guard` ships only
   `local/mu-plugins/`; `code` ships only the three slk plugins and the
   theme; no `wp option` write, no database command, no bootstrap.sh
   reference; tar-over-ssh, not rsync.
9. `DEPLOY-HOSTINGER.md` contains §0-§11 in the order guard → verify → code →
   verify → Blocksy → verify → wp.org plugins → verify → slk plugins one at a
   time → theme activation → seeding, with the cache-busted homepage check
   after EVERY step that could affect the homepage.
10. §0 lists the blocking user actions; §10 marks the launch flip out of scope.
11. `slk-checkout.php` still contains the `woocommerce_currency_symbol` filter
    unmodified, and no second copy is introduced anywhere.
12. No file hardcodes an SSH password; connection details enter only via
    `SSH_DEST`/`SSH_PORT`/`SSH_KEY`/`WP_ROOT`.
13. Every new PHP file begins with `defined( 'ABSPATH' ) || exit;` and every
    file carrying the brand name is UTF-8.
14. §11 flags `pages-help.php:204` and `story.php:9` and does NOT propose
    replacement copy for the headcount line.

## Verification commands

No npm/composer/test tooling in this repo; `php` is NOT on the Windows host;
lint runs in the local `slk-wp` container. `bash`, `curl`, `ssh`, `scp`, `tar`
exist in Git Bash; `rsync` does not.

```bash
# 1. Shell syntax
bash -n local/deploy/deploy-code.sh

# 2. PHP lint via the running local container (host has no php)
for f in local/mu-plugins/mavea-prelaunch.php \
         local/mu-plugins/mavea/holding-template.php \
         local/deploy/export-catalog.php \
         local/deploy/seed-catalog-live.php \
         local/deploy/seed-editorial-live.php \
         local/import-product-images.php; do
  docker cp "$f" slk-wp:/tmp/lint.php && docker exec slk-wp php -l /tmp/lint.php || exit 1
done

# 3. Live baseline intact (read-only; the gate must not deploy anything)
curl -fsS https://mavea.lk | grep -q mavea-holding && echo LIVE-BASELINE-OK

# 4. Guard wiring present
grep -q "frontpage_template" local/mu-plugins/mavea-prelaunch.php
grep -q "template_include"   local/mu-plugins/mavea-prelaunch.php
grep -rn "woocommerce_currency_symbol" local/plugins/slk-checkout/slk-checkout.php
```

If Docker is not running when the gate executes, step 2 is skipped and review
carries the lint burden — say so in the gate output rather than faking a pass.

## Risks & rollback

- **The mu-plugin itself breaks the homepage.** Caught by the §2 check seconds
  after the guard stage, while the theme is still TT25. Rollback: delete the
  file over SSH + purge — byte-for-byte back to today.
- **LiteSpeed serves week-old HTML and masks a breakage.** Every mutating step
  purges; every check cache-busts. Never judge a step by the cached view.
- **Theme activation is the single most dangerous moment.** It happens LAST
  among code steps, after the guard has been proven live twice, and has a
  one-line rollback. The DB is never edited by the switch; page 13 is never
  touched by anything in this plan.
- **slk-checkout provisioning creates a second "Sri Lanka" zone.** Expected
  and verified in §6. Pre-launch no shopper can reach checkout, so no wrong
  rate can be charged. Zone order at launch is Najath's decision.
- **Blocksy version drift**: pin 2.1.52; if unavailable install latest and
  record it — the guard means even a broken theme cannot take down the
  homepage.
- **Seeding is idempotent**, so a partial failure is fixed by re-running.
- **Public uploads pre-launch** (G4): imported images are addressable under
  `/wp-content/uploads/` even while every page is hidden.
- **Not automatable, ever:** the mavea.lk wp-admin login, the G3 pricing and
  G4 imagery sign-offs, the shipping-zone decision, and the two copy
  rewordings. The runbook stops and says so at each point rather than guessing.
