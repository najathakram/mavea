# Hosting: WordPress.com vs Hostinger (verified 2026-08-10)

Najath asked mid-build whether to host on WordPress.com itself. Prices below are read
live from wordpress.com/pricing on 2026-08-10, billed-yearly rates.

## The 2026 change that matters

WordPress.com's own FAQ now states: *"The Personal plan… includes full plugin access. You
can install any of the 50,000+ plugins on this plan. You do not need a higher-tier plan to
use plugins."* Plugins used to be Business-only, so WooCommerce + the PayHere plugin will
technically run on a **$4/mo** plan. That is real — but it is not the whole story, because
**SFTP/SSH/WP-CLI/Git deployment starts at Business ($25/mo)**, and this build ships four
custom plugins that need repeated deployment.

## Options side by side

| | WP.com Personal | WP.com Business | WP.com Commerce | **Hostinger Cloud** |
|---|---|---|---|---|
| Yearly cost | **$48** | $300 | $540 | **~$120** |
| Install plugins (Woo, PayHere) | ✅ | ✅ | ✅ | ✅ |
| SFTP / SSH / WP-CLI / Git deploy | ❌ | ✅ | ✅ | ✅ |
| Staging | ❌ | ✅ | ✅ | ✅ |
| Managed backups + security | ✅ | ✅ real-time | ✅ real-time | ❌ (we run UpdraftPlus + Wordfence) |
| Own Cloudflare in front (Colombo PoP) | ❌ | ❌ | ❌ | ✅ |
| Server region near Sri Lanka | ❌ Automattic global (no SL PoP) | ❌ | ❌ | ✅ **Singapore** |
| Storage | 6 GB | 50 GB | 50 GB | 200 GB |
| Free domain 1st year | ✅ | ✅ | ✅ | ✅ (on annual plans) |
| Caching/security plugins | restricted (own edge cache) | restricted | restricted | any (LiteSpeed native) |

## Hostinger + WooCommerce — verified on their WooCommerce hosting page, 2026-08-10

Yes, explicitly supported and marketed: **one-click WooCommerce install**, "Recommended by
WordPress.org", **LiteSpeed web servers + the LSCWP plugin** (exactly the caching stack this
plan already specified), Object Cache, free CDN, free SSL, free domain for 1 year, 100 free
mailboxes, free store migration, 24/7 priority support, 30-day money-back guarantee.

Live sale pricing (48-month prepay; note the renewal rate is the real long-run cost):

| Plan | Sale | Total upfront | Renews at | Storage |
|---|---|---|---|---|
| **Unlimited** (enough for this store) | $3.79/mo | $181.92 / 48 mo | $16.99/mo | 50 GB NVMe |
| Cloud Startup (dedicated resources) | $7.99/mo | $383.52 / 48 mo | $25.99/mo | 100 GB NVMe |

**Take Unlimited, not Cloud Startup.** Cloud Startup buys dedicated resources for agencies and
high-traffic sites; at 5–30 orders/day with a small catalogue it is money spent on headroom we
will not touch for years, and upgrading later is trivial. Unlimited already carries LiteSpeed,
object cache, CDN and daily backups.

Two practical notes for checkout:
- **Do not claim the free domain yet.** The brand name is not picked (gate G1 deferred). Burning
  the free year on a placeholder wastes it — claim it from hPanel once the name is decided.
- Decline the upsell add-ons at checkout; nothing in this build needs them.

Also spotted: **Hostinger Connector**, a first-party integration that exposes hosting, domains
and stores to an AI agent. Worth enabling — it would let Claude drive the real server directly
instead of hand-piloting hPanel.

## Ruling: stay with Hostinger

1. **Latency is a conversion feature here.** The customer is on a Sri Lankan mobile network.
   Singapore origin + Cloudflare's Colombo PoP is measurably closer than Automattic's global
   edge, which has no Sri Lanka presence. On WP.com we cannot put our own Cloudflare in front.
2. **Cost.** $120/yr vs $300/yr for the comparable (Business) tier. On a brand positioned as
   the affordable sister, $180/yr is real margin.
3. **Deployment.** Four custom plugins get built and revised repeatedly. Hostinger gives
   SFTP/Git at base price; WP.com only at $300/yr. Personal ($48) would mean hand-uploading a
   ZIP through wp-admin for every iteration — the cheapest plan is the most expensive in time.
4. **Control at the edges.** COD reconciliation, Koombiyo API polling and cron reliability are
   easier with full server access.

**When WP.com would win:** if Najath wants zero server responsibility and will pay for
Business — its GitHub Deployments and real-time backups are genuinely excellent, and support
is the best in the market. That is a comfort purchase, not a performance one. Commerce ($540)
is never necessary; Business runs WooCommerce fine.

## Build now, buy later — sanctioned, and it is the default

Najath asked whether the site can be built without buying hosting yet. Yes, and it is the
better sequence: the whole store is built locally (`sister-lk/local/`), then moved to Hostinger
when launch is actually close. WordPress is fully portable — a database dump plus `wp-content`
*is* the site — and Hostinger additionally offers free migration, so nothing built locally is
throwaway.

Why waiting is better than buying today:
- The hosting term (and its renewal clock) starts when you buy. Buying now spends weeks of a
  4-year term on a site nobody can visit.
- The sale price is a recurring promotion, not a one-time event — waiting is unlikely to cost
  more than a few dollars.
- The brand name is still undecided; the free domain year should not be burned on a placeholder.

What genuinely **cannot** be finished until hosting exists (all of it already sits late in the
build sequence, so nothing is blocked meanwhile):
- **PayHere approval** — requires a live site on the final domain with published policy pages.
- **PayHere/Koko end-to-end payment tests** — the gateway must reach a public `notify_url`
  (a temporary tunnel can cover sandbox testing early if we want it sooner).
- **Instagram in-app browser checkout test**, email deliverability, real PageSpeed numbers.

Everything else — catalogue, checkout rules, the 25-district field, COD confirmation flow,
custom statuses, SMS/WhatsApp wiring, theme and design — is built and tested locally.

## Consequence for the build

No decision is blocked by this. The whole store is being built **locally first**
(`sister-lk/local/`, Docker), which costs nothing, needs no brand name, and exports to
whichever host wins. Hosting only has to be settled before the PayHere application, because
PayHere requires a live site on the final domain.
