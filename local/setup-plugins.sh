#!/usr/bin/env bash
# Install and configure the operational plugin stack from 00-PLAN.md §3.
#
# Deliberately split three ways, because "install everything and switch it on"
# is how a dev site ends up slow and a production site ends up misconfigured:
#
#   ACTIVE   — earns its place on a local dev store today
#   PARKED   — installed so the choice is recorded and the update path exists,
#              but inactive because it needs credentials we do not have yet
#   SKIPPED  — would be actively wrong locally; noted for the Hostinger build
#
#   bash local/setup-plugins.sh
set -euo pipefail
cd "$(dirname "$0")"

# Container paths are not host paths — see seed-catalog.sh for the same trap.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

wp() { docker compose run --rm -T wpcli "$@"; }

install() {  # install <slug> <activate|parked> <why>
  local slug="$1" mode="$2" why="$3"
  if wp plugin is-installed "$slug" >/dev/null 2>&1; then
    echo "    present  $slug"
  else
    wp plugin install "$slug" >/dev/null 2>&1 || { echo "    ! FAILED  $slug ($why)"; return 0; }
    echo "    fetched  $slug"
  fi
  if [ "$mode" = "activate" ]; then
    wp plugin activate "$slug" >/dev/null 2>&1 && echo "      active — $why" || echo "      ! could not activate $slug"
  else
    wp plugin deactivate "$slug" >/dev/null 2>&1 || true
    echo "      parked — $why"
  fi
}

echo "==> ACTIVE: useful on this dev store today"
install woocommerce-pdf-invoices-packing-slips activate \
  "packing slips carry the landmark + district a COD courier actually navigates by"
install seo-by-rank-math activate \
  "product schema and sitemaps; Google Merchant Center feed later"
install payhere-payment-gateway activate \
  "cards, eZ Cash, helaPay and LankaQR in one screen; active but left unconfigured"
install mintpay activate \
  "instalments; Koko's own plugin is immature, this is the healthier BNPL pick; active but left unconfigured"
install login-with-google activate \
  "Continue with Google on checkout; active but left unconfigured"

echo
echo "    PayHere and Mintpay are installed and ACTIVE but have NO merchant"
echo "    credentials set. Each needs a merchant ID and secret that only"
echo "    Najath can obtain from the provider. PayHere approval additionally"
echo "    requires a live site with published policy pages before it will"
echo "    issue credentials. Until a merchant ID is entered under WooCommerce"
echo "    > Settings > Payments, class-slk-payments.php keeps that gateway"
echo "    hidden from shoppers, so being active here is safe."

echo
echo "    Login with Google is installed and ACTIVE but has NO OAuth client"
echo "    configured. It needs a client ID and secret that only Najath can"
echo "    create in the Google Cloud console. Until a client ID is entered"
echo "    under Settings > Login with Google, class-slk-google.php keeps the"
echo "    Continue with Google button off checkout, so being active here is"
echo "    safe."

echo
echo "==> PARKED: installed, inactive until credentials exist"
install paykoko-bnpl-payment-gateway parked \
  "needs a signed Koko merchant agreement (~10-12% commission — negotiate first)"
install notifylk-sms-for-woocommerce parked \
  "needs a Notify.lk account and an approved sender ID"
install wp-mail-smtp parked \
  "no outbound mail from a laptop; configure against Brevo on the real host"
install pixelyoursite parked \
  "needs the GA4 and Meta pixel IDs, which do not exist until the brand does"

echo
echo "==> SKIPPED on purpose (production-only — do these on Hostinger)"
echo "    litespeed-cache   this container is Apache; LiteSpeed Cache would do nothing"
echo "    wordfence         a firewall on localhost costs dev speed and protects nothing"
echo "    updraftplus       backing up a disposable container is theatre; the repo is the backup"

echo
echo "==> WooCommerce housekeeping"
# Analytics must count our custom COD statuses or reported revenue undercounts.
wp option update woocommerce_analytics_enabled yes >/dev/null 2>&1 || true
# Stop WooCommerce nagging on every admin screen of a dev store.
wp option update woocommerce_show_marketplace_suggestions no >/dev/null 2>&1 || true
wp option update woocommerce_merchant_email_notifications no >/dev/null 2>&1 || true
wp option update woocommerce_task_list_hidden yes >/dev/null 2>&1 || true
wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null 2>&1 || true

echo
echo "==> final plugin state"
wp plugin list --fields=name,status,version --format=table 2>/dev/null | tr -d '\r'
