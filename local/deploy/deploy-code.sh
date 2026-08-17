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
