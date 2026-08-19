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

# Lint gate. Added 2026-08-19 after a parse error in inc/checkout-view.php was
# pushed to the live site and took every page to "critical error" for about a
# minute. The file HAD been linted — but lint and deploy were separate statements
# in one command, so a failing lint did not stop the push. The gate belongs here,
# in the thing that does the pushing, not in the discipline of whoever runs it.
# Mirrors the same check in .github/workflows/deploy.yml.
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx slk-wp; then
  echo "==> php -l every file about to ship"
  # Git Bash rewrites a /tmp/... argument into a Windows path before docker sees
  # it, which makes php -l report "Could not open input file" and read as a lint
  # failure on a perfectly good file. Export it for the whole block so it covers
  # `docker exec` too, not just `docker cp` — a gate with false positives gets
  # switched off, which is worse than no gate.
  export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
  # ONE exec, not one per file. The theme and plugins are bind-mounted into the
  # container, so the files php reads there ARE these files — no copying needed.
  # The first version of this gate did a docker cp + docker exec per file and took
  # ten minutes, which is long enough that someone would start skipping it.
  # The `|| true` is load-bearing. grep exits 1 when it matches nothing, and
  # matching nothing is exactly the SUCCESS case here (no lint errors to report).
  # Under `set -e` at the top of this script that non-zero status aborted the run
  # right after printing the banner — so a clean tree silently skipped its own
  # deploy and reported nothing wrong. Failing closed and quiet is worse than the
  # false positives this gate started with.
  lint_out="$(
    docker exec slk-wp sh -c '
      find /var/www/html/wp-content/themes/slk-child \
           /var/www/html/wp-content/plugins/slk-checkout \
           /var/www/html/wp-content/plugins/slk-order-flow \
           /var/www/html/wp-content/plugins/slk-exchanges \
           -name "*.php" -print0 2>/dev/null \
      | xargs -0 -n1 php -l 2>&1 \
      | grep -v "^No syntax errors" || true
    ' 2>&1 || true
  )"
  lint_failed=0
  if [ -n "$lint_out" ]; then
    echo "$lint_out" >&2
    lint_failed=1
  fi
  if [ "$lint_failed" -ne 0 ]; then
    echo "==> ABORTED. Nothing was deployed; the live site is untouched." >&2
    exit 1
  fi
  echo "    all clean"
else
  echo "==> WARNING: slk-wp container not running, cannot lint. Deploying UNVERIFIED." >&2
fi

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
