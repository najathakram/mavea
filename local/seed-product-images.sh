#!/usr/bin/env bash
# Put the normalised studio photography onto the twenty products.
#
#   python local/prepare-product-images.py    # dresses/ -> temp/products/, 3:4, no crop
#   bash   local/seed-product-images.sh       # temp/products/ -> WooCommerce
#
# Idempotent: every run deletes the attachments the products currently point at
# and re-imports from temp/products/, so re-cutting a frame and re-running
# actually changes what the store serves.
set -euo pipefail
cd "$(dirname "$0")"

export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

wp() { docker compose run --rm -T wpcli "$@" < /dev/null; }

if [ ! -d ../temp/products ]; then
  echo "temp/products/ is missing — run: python local/prepare-product-images.py" >&2
  exit 1
fi

echo "==> staging temp/ into the container"
# rm first: `docker cp src dst` nests src INSIDE dst when dst exists, which
# silently leaves the importer reading the previous run's files.
docker exec slk-wp sh -c 'rm -rf /var/www/html/temp'
docker cp ../temp slk-wp:/var/www/html/temp
docker cp import-product-images.php slk-wp:/var/www/html/temp/import-product-images.php
docker exec slk-wp sh -c 'chown -R www-data:www-data /var/www/html/temp'
echo "    $(docker exec slk-wp sh -c 'ls /var/www/html/temp/products | wc -l') product frames staged"

echo "==> attaching"
wp eval-file /var/www/html/temp/import-product-images.php

echo "==> flush"
wp wc tool run regenerate_product_lookup_tables --user=1 >/dev/null 2>&1 || true
wp cache flush >/dev/null 2>&1 || true

echo
echo "Shop: http://localhost:8088/shop/"
