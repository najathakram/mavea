#!/usr/bin/env bash
# Build the demo catalogue from the real studio photography in ../temp.
#
# Nine garments, each with the three frames the design's PDP asks for:
# front (featured), back, and a detail that carries drape and texture — because
# she cannot touch the cloth. Copy is written the way the brand guidelines
# demand: say what the thing IS, no adjectives doing work that facts should do.
#
#   bash local/seed-catalog.sh
#
# Idempotent: re-running updates prices/copy and re-attaches images without
# duplicating products or re-importing media.
set -euo pipefail
cd "$(dirname "$0")"

# Git Bash rewrites any argument that looks like a Unix absolute path into a
# Windows one before the process sees it, so `/var/www/html/temp/x.jpg` reached
# WP-CLI as `C:/Program Files/Git/var/www/html/temp/x.jpg` and every media
# import failed with "File doesn't exist". Container paths are not host paths;
# turn the conversion off for the whole script.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

# `< /dev/null` is load-bearing. The product loop is a `while IFS='|' read`
# fed from a here-string, and `docker compose run` inherits and DRAINS stdin —
# so the first wp call inside the loop swallowed every remaining product line
# and the loop silently ran exactly once. Detaching stdin fixes it.
wp() { docker compose run --rm -T wpcli "$@" < /dev/null; }

# temp/ lives in the repo and is NOT a bind mount (the compose file mounts only
# our own plugins and theme). Stage it into the container so wp media import can
# see it; the uploads it creates live in the wp_data volume, so this copy is
# needed once per import run, not per page load.
echo "==> staging temp/ into the container"
docker cp ../temp slk-wp:/var/www/html/temp
docker exec slk-wp sh -c 'chown -R www-data:www-data /var/www/html/temp'
echo "    $(docker exec slk-wp sh -c 'ls /var/www/html/temp | wc -l') files staged"

echo "==> categories"
for c in Abayas Dresses Hijabs; do
  slug=$(echo "$c" | tr '[:upper:]' '[:lower:]')
  wp term get product_cat "$slug" --by=slug >/dev/null 2>&1 \
    || wp term create product_cat "$c" --slug="$slug" >/dev/null
done

echo "==> retiring the placeholder catalogue"
for old in nayana-linen-abaya mihiri-crepe-abaya tharu-everyday-abaya \
           sewwandi-shift-dress amaya-wide-dress suvi-everyday-hijab ranmali-silk-hijab; do
  id=$(wp post list --post_type=product --name="$old" --field=ID --posts_per_page=1 2>/dev/null | tr -d '\r')
  [ -n "$id" ] && { wp post delete "$id" --force >/dev/null; echo "    removed $old"; } || true
done

# slug|Name|price|category|short description
PRODUCTS='
amara|Amara|13500|abayas|Block-printed cotton · side pockets · falls to the ankle
noor|Noor|14800|abayas|Feather-print crepe · shirred waist · sleeves to the wrist
liana|Liana|12900|abayas|Printed viscose · wrap tie · falls to the ankle
inaya|Inaya|15600|abayas|Tiered cotton lace · deep hem · sleeves to the wrist
mira|Mira|13900|dresses|Watercolour chiffon · tiered skirt · falls to the ankle
dahlia|Dahlia|12400|dresses|Printed chiffon · lined · sleeves to the wrist
mizna|Mizna|11800|dresses|Ditsy floral chiffon · gathered yoke · falls to the ankle
layla|Layla|12200|dresses|Colour-blocked tiers · gathered waist · sleeves to the wrist
rania|Rania|14200|dresses|Brushstroke print · unlined · falls to the ankle
'

echo "==> products + imagery"
echo "$PRODUCTS" | while IFS='|' read -r slug name price cat desc; do
  [ -z "${slug:-}" ] && continue

  id=$(wp post list --post_type=product --name="$slug" --field=ID --posts_per_page=1 2>/dev/null | tr -d '\r')
  if [ -z "$id" ]; then
    id=$(wp post create --post_type=product --post_status=publish \
          --post_title="$name" --post_name="$slug" --porcelain | tr -d '\r')
    echo "    created $name (#$id)"
  else
    echo "    update  $name (#$id)"
  fi

  wp post meta update "$id" _regular_price "$price" >/dev/null
  wp post meta update "$id" _price "$price" >/dev/null
  wp post meta update "$id" _manage_stock no >/dev/null
  wp post meta update "$id" _stock_status instock >/dev/null
  wp post meta update "$id" _visibility visible >/dev/null
  # Brand law: this store never carries a sale price.
  wp post meta delete "$id" _sale_price >/dev/null 2>&1 || true
  wp post update "$id" --post_excerpt="$desc" >/dev/null
  wp post term set "$id" product_cat "$cat" >/dev/null
  wp post term set "$id" product_type simple >/dev/null

  # Images: front is featured, then back and detail form the gallery.
  gallery=""
  for role in front back detail; do
    file="/var/www/html/temp/${slug}-${role}.jpg"
    title="$name — $role"
    att=$(wp post list --post_type=attachment --name="${slug}-${role}" --field=ID --posts_per_page=1 2>/dev/null | tr -d '\r')
    if [ -z "$att" ]; then
      att=$(wp media import "$file" --title="$title" --porcelain 2>/dev/null | tr -d '\r')
    fi
    [ -z "$att" ] && { echo "      ! could not import ${slug}-${role}"; continue; }
    # Alt text describes the garment, never the model — accessibility, and the
    # modesty rules mean the garment is the subject anyway.
    wp post meta update "$att" _wp_attachment_image_alt "$name — $desc" >/dev/null
    if [ "$role" = "front" ]; then
      wp post meta update "$id" _thumbnail_id "$att" >/dev/null
    else
      gallery="${gallery:+$gallery,}$att"
    fi
  done
  [ -n "$gallery" ] && wp post meta update "$id" _product_image_gallery "$gallery" >/dev/null
done

echo "==> editorial stills for the pages"
for name in hero-group hero-alt portrait-warm pair-close single-floral room-wide studio-pair; do
  slug="editorial-${name}"
  att=$(wp post list --post_type=attachment --name="$slug" --field=ID --posts_per_page=1 2>/dev/null | tr -d '\r')
  if [ -z "$att" ]; then
    att=$(wp media import "/var/www/html/temp/${slug}.jpg" \
            --title="Editorial — $name" --porcelain 2>/dev/null | tr -d '\r')
  fi
  [ -n "$att" ] && wp option update "slk_img_${name//-/_}" "$att" >/dev/null && echo "    $slug -> #$att"
done

echo "==> flush"
wp wc tool run regenerate_product_lookup_tables --user=1 >/dev/null 2>&1 || true
wp cache flush >/dev/null 2>&1 || true

echo
echo "Catalogue ready: http://localhost:8088/shop/"
