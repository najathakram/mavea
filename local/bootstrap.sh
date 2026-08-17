#!/usr/bin/env bash
# One-shot bring-up for the local SL store dev site.
# Prereq: Docker engine running (Docker Desktop must be started as Administrator once).
#
#   bash local/bootstrap.sh
#
# Idempotent: safe to re-run. Destroy with `docker compose down -v`.
set -euo pipefail
cd "$(dirname "$0")"

SITE_URL="http://localhost:8088"
ADMIN_USER="slkadmin"
ADMIN_EMAIL="owner@example.test"

echo "==> starting containers"
docker compose up -d db wordpress adminer

echo "==> waiting for WordPress to answer"
for i in $(seq 1 60); do
  if curl -fsS -o /dev/null "$SITE_URL"; then break; fi
  sleep 3
done

wp() { docker compose run --rm -T wpcli "$@"; }

if wp core is-installed 2>/dev/null; then
  echo "==> WordPress already installed, skipping core install"
else
  echo "==> installing WordPress core"
  # Alphanumeric only. The previous version base64'd /dev/urandom, and a
  # password containing shell-significant bytes did not survive the trip
  # through `docker compose run` intact — the file on disk and the hash in the
  # database disagreed, and the recorded password simply did not work.
  ADMIN_PASS="$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 18)"
  wp core install \
    --url="$SITE_URL" \
    --title="MAVÉA" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email

  # Never record a credential without proving it works. A password file that
  # lies is worse than no password file: it sends you hunting a phantom
  # "expired session" instead of a wrong string.
  if wp eval "exit( wp_check_password( '$ADMIN_PASS', get_user_by( 'login', '$ADMIN_USER' )->user_pass, 1 ) ? 0 : 1 );"; then
    printf '%s\n' "$ADMIN_PASS" > .admin-password
    chmod 600 .admin-password 2>/dev/null || true
    echo "    admin password verified and written to local/.admin-password (gitignored)"
  else
    echo "    !! password did not verify against the stored hash — resetting"
    # `wp user update`, never wp_set_password() inside `wp eval`. Measured: the
    # eval path reported success and verified within its own process, but the
    # row was never committed, so the next request still saw the old hash. A
    # reset that only works inside the process that performed it is not a reset.
    ADMIN_PASS="$(LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 18)"
    wp user update 1 --user_pass="$ADMIN_PASS" --skip-email >/dev/null
    # Prove it in a FRESH process before recording it.
    if wp eval "exit( wp_check_password( '$ADMIN_PASS', get_user_by( 'id', 1 )->user_pass, 1 ) ? 0 : 1 );"; then
      printf '%s\n' "$ADMIN_PASS" > .admin-password
      chmod 600 .admin-password 2>/dev/null || true
      echo "    admin password reset, verified, and written to local/.admin-password"
    else
      echo "    !! RESET FAILED — no password file written rather than write one that lies"
      rm -f .admin-password
    fi
  fi
fi

echo "==> permalinks (WooCommerce needs pretty permalinks)"
wp rewrite structure '/%postname%/' --hard

echo "==> installing WooCommerce + Blocksy"
wp plugin install woocommerce --activate
wp theme install blocksy --activate
wp theme activate slk-child 2>/dev/null || echo "    (slk-child needs Blocksy present; retry after first run)"

echo "==> installing Sri Lanka payment/SMS plugins (inactive - inspect before enabling)"
# Slugs verified against the wordpress.org plugin API on 2026-08-10.
# PayHere is the one that matters: cards + helaPay + eZ Cash + Genie + LankaQR in one gateway.
wp plugin install payhere-payment-gateway            # PayHere Payment Gateway v2.4.5, ~2,000 installs
wp plugin install notifylk-sms-for-woocommerce       # Notify.lk SMS for WooCommerce v1.1.2
wp plugin install paykoko-bnpl-payment-gateway       # Koko BNPL v-current, only ~30 installs - inspect before trusting
wp plugin install mintpay                            # Mintpay BNPL v2.2.3, ~600 installs - healthier alternative to Koko

echo "==> Sri Lanka store defaults (LKR, Rs., 0 decimals, Galle origin)"
wp option update woocommerce_currency LKR
wp option update woocommerce_currency_pos left_space
wp option update woocommerce_price_thousand_sep ","
wp option update woocommerce_price_decimal_sep "."
wp option update woocommerce_price_num_decimals 0
# Plain "LK" on purpose: WooCommerce core ships provinces, not the 25 districts couriers
# price by. The district list is installed by slk-checkout (plan section 5), not guessed here.
wp option update woocommerce_default_country "LK"
wp option update woocommerce_store_country "LK"
wp option update woocommerce_store_city "Galle"
wp option update woocommerce_store_postcode "80000"
# No VAT registration below LKR 60M/yr turnover (verified 2026), so taxes stay off.
wp option update woocommerce_calc_taxes "no"
wp option update woocommerce_allowed_countries "specific"
wp option update woocommerce_specific_allowed_countries '["LK"]' --format=json
wp option update woocommerce_ship_to_countries "specific"
wp option update woocommerce_specific_ship_to_countries '["LK"]' --format=json
wp option update woocommerce_weight_unit "kg"
wp option update woocommerce_dimension_unit "cm"
wp option update woocommerce_enable_guest_checkout "yes"
wp option update woocommerce_enable_signup_and_login_from_checkout "no"

echo "==> enabling HPOS (High-Performance Order Storage)"
wp option update woocommerce_custom_orders_table_enabled "yes" 2>/dev/null || true
wp option update woocommerce_feature_custom_order_tables_enabled "yes" 2>/dev/null || true

echo "==> activating our scaffold plugins"
wp plugin activate slk-checkout slk-order-flow 2>/dev/null || true

echo
echo "READY  ->  $SITE_URL/wp-admin   (user: $ADMIN_USER)"
echo "DB UI  ->  http://localhost:8089  (server: db, user: slk, pass: slk_dev_pw, db: slk)"
