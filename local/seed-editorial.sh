#!/usr/bin/env bash
# Import the editorial stills the designed pages ask for, and point the
# slk_img_* options at them.
#
#   bash local/seed-editorial.sh
#
# These are the CAMPAIGN frames (reference/website-campaign, converted into
# temp/editorial-*.jpg), not product photography. Six frames carry three pages:
# the home hero, the home "made by eight women" frame, the Our Story hero and
# its pair, and the workshop frame on Contact.
#
# Split out of seed-catalog.sh on purpose. That script rebuilds the demo
# CATALOGUE, and the catalogue it knows about is the retired Aeshal one — running
# it today would resurrect nine deleted products. The editorial stills have
# nothing to do with the catalogue, so they get their own owner and can be
# re-run safely on their own.
#
# Idempotent, and idempotent about CONTENT: the attachment for a key is deleted
# and re-imported every run, so editing temp/editorial-<key>.jpg and re-running
# actually changes what the site serves. (Importing over an existing slug would
# not — WordPress would keep the old file and just hand back the old ID.)
set -euo pipefail
cd "$(dirname "$0")"

# Git Bash rewrites anything shaped like a Unix absolute path before the process
# sees it, which turns /var/www/html/... into C:/Program Files/Git/var/www/...
# and fails every import. Container paths are not host paths.
export MSYS_NO_PATHCONV=1
export MSYS2_ARG_CONV_EXCL='*'

# `< /dev/null` is load-bearing: `docker compose run` inherits and DRAINS stdin,
# so without it the first wp call inside a read-loop swallows the rest of the input.
wp() { docker compose run --rm -T wpcli "$@" < /dev/null; }

# key|alt text
#
# Alt describes the GARMENTS in frame, never the model, and never the place:
# these are campaign frames shot on location, so an alt claiming "the workshop
# in Galle" would be a lie told only to the people who cannot see the picture.
STILLS='
hero-group|A blush abaya with embroidered sleeves, a lilac open abaya over a matching dress, and a black abaya with silver leaf embroidery.
hero-alt|A sage abaya with beaded sleeve detail, a lilac coat dress with covered buttons, and a camel abaya with cutwork cuffs.
room-wide|A taupe open abaya worn over cream, a grey abaya with woven sleeve trim, and a soft blue abaya, in a daylit room.
story-wide|A taupe open abaya with lace panels worn over cream, beside a pale blue abaya with a draped hood.
studio-pair|A lilac coat dress with covered buttons, a black abaya with beaded sleeves, and a camel abaya with cutwork cuffs.
portrait-warm|A blush abaya with embroidered sleeves and a piped front seam, cut full length.
'

echo "==> staging temp/ into the container"
# temp/ is in the repo but is NOT a bind mount (compose mounts only our plugins
# and theme), so wp media import cannot see it until it is copied in.
#
# The rm is not tidiness, it is correctness. `docker cp src dst` copies src
# *into* dst when dst already exists, so on the second run the files land at
# /var/www/html/temp/temp/ and the stale first-run copies are what the importer
# actually reads — silently, with every import "succeeding". That cost a run:
# three heroes imported as the retired Aeshal stills because the new files were
# one directory deeper than anything was looking.
docker exec slk-wp sh -c 'rm -rf /var/www/html/temp'
docker cp ../temp slk-wp:/var/www/html/temp
docker exec slk-wp sh -c 'chown -R www-data:www-data /var/www/html/temp'

echo "==> editorial stills"
echo "$STILLS" | while IFS='|' read -r name alt; do
  [ -z "${name:-}" ] && continue

  slug="editorial-${name}"
  file="/var/www/html/temp/${slug}.jpg"
  opt="slk_img_${name//-/_}"

  # Drop the previous import so the new file is what actually lands.
  old=$(wp post list --post_type=attachment --name="$slug" --field=ID --posts_per_page=1 2>/dev/null | tr -d '\r')
  [ -n "$old" ] && wp post delete "$old" --force >/dev/null 2>&1 || true

  # `|| true` so a single missing file reports itself and the rest of the run
  # still lands: under `set -e` a failing command substitution takes the whole
  # loop down, which reads exactly like "the script finished" from the outside.
  att=$(wp media import "$file" --title="Editorial — ${name//-/ }" --porcelain 2>/dev/null | tr -d '\r' || true)
  if [ -z "$att" ]; then
    echo "    ! could not import $slug (looked for $file)"
    continue
  fi

  wp post meta update "$att" _wp_attachment_image_alt "$alt" >/dev/null
  wp option update "$opt" "$att" >/dev/null
  echo "    $slug -> #$att"
done

echo "==> flush"
wp cache flush >/dev/null 2>&1 || true

echo
echo "Stills in place: http://localhost:8088/"
