#!/usr/bin/env bash
# Seed a small demo catalogue so templates have real data to render.
# Names, prices and copy come from the Porcelain Glass design so screenshots
# match the mockups. Idempotent: re-running updates rather than duplicating.
#
#   bash local/seed-catalog.sh
set -euo pipefail
cd "$(dirname "$0")"

wp() { docker compose run --rm -T wpcli "$@"; }

echo "==> product categories"
for c in Abayas Dresses Hijabs; do
  slug=$(echo "$c" | tr '[:upper:]' '[:lower:]')
  wp term get product_cat "$slug" --by=slug >/dev/null 2>&1 \
    || wp term create product_cat "$c" --slug="$slug" >/dev/null
done

echo "==> size attribute"
wp wc product_attribute list --field=slug --user=1 2>/dev/null | grep -qx "pa_size" \
  || wp wc product_attribute create --name=Size --slug=size --type=select --user=1 >/dev/null 2>&1 || true

# make_product <slug> <name> <price> <category> <short-description>
make_product() {
  local slug="$1" name="$2" price="$3" cat="$4" desc="$5"
  local id
  id=$(wp post list --post_type=product --name="$slug" --field=ID --posts_per_page=1 2>/dev/null | tr -d '\r')
  if [ -z "$id" ]; then
    id=$(wp post create --post_type=product --post_status=publish \
          --post_title="$name" --post_name="$slug" --porcelain | tr -d '\r')
    echo "    created $name (#$id)"
  else
    echo "    exists  $name (#$id)"
  fi
  wp post meta update "$id" _regular_price "$price" >/dev/null
  wp post meta update "$id" _price "$price" >/dev/null
  wp post meta update "$id" _manage_stock no >/dev/null
  wp post meta update "$id" _stock_status instock >/dev/null
  wp post meta update "$id" _visibility visible >/dev/null
  # Brand law: never a sale price. Explicitly clear any that exists.
  wp post meta delete "$id" _sale_price >/dev/null 2>&1 || true
  wp post update "$id" --post_excerpt="$desc" >/dev/null
  wp post term set "$id" product_cat "$cat" >/dev/null
  wp post term set "$id" product_type simple >/dev/null
}

echo "==> products"
make_product nayana-linen-abaya   "Nayana Linen Abaya"    12500 abayas  "Unlined linen · falls to the ankle · sleeves to the wrist"
make_product mihiri-crepe-abaya   "Mihiri Crepe Abaya"    14200 abayas  "Fluid crepe · side pockets · falls to the ankle"
make_product tharu-everyday-abaya "Tharu Everyday Abaya"   9800 abayas  "Washed cotton · cut for heat · sleeves to the wrist"
make_product sewwandi-shift-dress "Sewwandi Shift Dress"  11400 dresses "Handloom cotton · loose through the waist · ankle length"
make_product amaya-wide-dress     "Amaya Wide Dress"      13600 dresses "Double gauze · deep hem · sleeves to the wrist"
make_product suvi-everyday-hijab  "Suvi Everyday Hijab"    2900 hijabs  "Cotton voile · 180 × 70 cm · does not slip"
make_product ranmali-silk-hijab   "Ranmali Silk Hijab"     4300 hijabs  "Sandwashed silk · 190 × 70 cm · holds a fold"

echo "==> flush"
wp wc tool run regenerate_product_lookup_tables --user=1 >/dev/null 2>&1 || true
wp cache flush >/dev/null 2>&1 || true

echo
echo "Seeded. Shop: http://localhost:8088/?post_type=product"
