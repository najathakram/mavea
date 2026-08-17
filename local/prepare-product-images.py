#!/usr/bin/env python3
"""Normalise the studio product photography into one ratio, without cropping it.

    python local/prepare-product-images.py

Reads  dresses/<Product Name>/<role>.png
Writes temp/products/<slug>-<role>.jpg

WHY THIS EXISTS
---------------
The shoot came back at inconsistent ratios — 0.56 to 0.80 w/h, with 2:3 the most
common. Every box the site puts them in is a fixed ratio with object-fit:cover:
the grid card is 3:4, the PDP thumbnails are 3:4, and WooCommerce's own
`woocommerce_thumbnail` is a HARD 3:4 crop at 600x800. Cover-cropping a 2:3
full-length abaya into 3:4 takes 5.6% off the top and 5.6% off the bottom, which
on these frames is precisely the crown of the hijab and the hem — the two things
a modest-wear shopper is looking for, and the two things style.css:106 promises
the card "never crops".

So the fix is not a cleverer crop. It is to stop cropping: pad each frame out to
3:4 with its own backdrop, and let the garment keep every pixel it was shot with.

HOW THE PADDING STAYS INVISIBLE
-------------------------------
The backdrop is a smooth vertical gradient, so a single flat fill colour would
band against it. Each pad column is instead sampled from the real edge of the
frame — a per-row median of the outermost strip, smoothed along its length — and
tiled outward. On a seamless sweep that is indistinguishable from more backdrop.
Where a garment does run to the edge of frame it extends that garment's own
colour a few percent sideways, which is a far smaller lie than amputating a hem.

WHAT IS DELIBERATELY LEFT OUT
-----------------------------
pattern-detail.jpg and sleeve-detail.jpg are not shipped. They are small
derivative crops, not photography: the set runs from 125x147 to 985x336, ratios
from 0.21 to 3.30, and several are split composites with a visible seam down the
middle (Aleena, Safiya, Shazna). At 125px wide, filling a 600x800 card means a
5x upscale. There is no ratio and no padding that rescues them; they are the
"cutting out from here and there" rather than a cure for it.
"""

import os
import re
import sys

import numpy as np
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "dresses")
DST = os.path.join(ROOT, "temp", "products")

TARGET = 3 / 4          # w/h. One ratio for the card, the PDP and its thumbs.
MAX_H = 1600            # Never upscale past this; sources top out near 1680.
QUALITY = 88            # 4:4:4 below — the backdrop gradient banks on it.
EDGE = 6                # Columns/rows sampled to build the pad.
SMOOTH = 9              # Median window along the sampled edge.

# Featured first, then the gallery in the order she should meet them.
ROLES = ["front", "back", "sleeve", "top", "bottom", "waist-bands"]


def smooth1d(a, k):
    """Box-smooth an (n, 3) strip along n, edges included."""
    if k < 2:
        return a
    pad = k // 2
    padded = np.pad(a, ((pad, pad), (0, 0)), mode="edge")
    ker = np.ones(k) / k
    return np.stack([np.convolve(padded[:, c], ker, mode="valid")[: len(a)] for c in range(3)], axis=1)


def pad_to_ratio(im):
    """Return im padded (never cropped) to TARGET, using its own edges."""
    a = np.asarray(im, dtype=np.float32)
    h, w, _ = a.shape
    ratio = w / h

    if abs(ratio - TARGET) < 1e-3:
        return im

    if ratio < TARGET:
        # Too narrow: grow the width, sampling each side's own rows.
        need = int(round(h * TARGET)) - w
        left, right = need // 2, need - need // 2
        lcol = smooth1d(np.median(a[:, :EDGE], axis=1), SMOOTH)
        rcol = smooth1d(np.median(a[:, -EDGE:], axis=1), SMOOTH)
        out = np.concatenate(
            [np.repeat(lcol[:, None, :], left, axis=1), a, np.repeat(rcol[:, None, :], right, axis=1)],
            axis=1,
        )
    else:
        # Too wide: grow the height instead. Same idea, turned ninety degrees.
        need = int(round(w / TARGET)) - h
        top, bottom = need // 2, need - need // 2
        trow = smooth1d(np.median(a[:EDGE, :], axis=0), SMOOTH)
        brow = smooth1d(np.median(a[-EDGE:, :], axis=0), SMOOTH)
        out = np.concatenate(
            [np.repeat(trow[None, :, :], top, axis=0), a, np.repeat(brow[None, :, :], bottom, axis=0)],
            axis=0,
        )

    return Image.fromarray(np.clip(out, 0, 255).astype(np.uint8))


def main():
    if not os.path.isdir(SRC):
        sys.exit("no dresses/ directory at %s" % SRC)

    os.makedirs(DST, exist_ok=True)
    for stale in os.listdir(DST):
        os.remove(os.path.join(DST, stale))

    total = 0
    for folder in sorted(os.listdir(SRC)):
        d = os.path.join(SRC, folder)
        if not os.path.isdir(d):
            continue

        # "Nasreen Lace Abaya" -> nasreen, which is the WooCommerce post_name.
        slug = re.split(r"[^a-z0-9]+", folder.lower())[0]
        wrote = []

        for role in ROLES:
            path = os.path.join(d, role + ".png")
            if not os.path.exists(path):
                continue

            im = Image.open(path).convert("RGB")
            before = "%dx%d %.2f" % (im.size[0], im.size[1], im.size[0] / im.size[1])
            im = pad_to_ratio(im)

            if im.size[1] > MAX_H:
                im = im.resize((int(round(MAX_H * TARGET)), MAX_H), Image.LANCZOS)

            out = os.path.join(DST, "%s-%s.jpg" % (slug, role))
            im.save(out, "JPEG", quality=QUALITY, optimize=True, progressive=True, subsampling=0)
            wrote.append(role)
            total += 1
            print("  %-28s %-14s -> %dx%d  %5.0fKB" % (
                "%s/%s" % (folder, role), before, im.size[0], im.size[1], os.path.getsize(out) / 1024))

        print("%-26s %s" % (slug, ", ".join(wrote) or "NOTHING"))

    print("\n%d files -> %s" % (total, DST))


if __name__ == "__main__":
    main()
