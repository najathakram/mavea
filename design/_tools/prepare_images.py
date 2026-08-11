"""Copy ONLY the images actually used into ./temp, web-sized.

Sources are 5,900-8,256px studio originals (the Dress Types library is 5.5GB);
nothing that large belongs anywhere near a WooCommerce upload. Each pick is
resized once, here, and everything downstream reads from temp/.

Crops follow the design's photography rules (docs/brand-guidelines.md §5):
portrait only, 3:4 for product frames, and the crop is TOP-anchored so a
full-length garment loses floor rather than head or hem.

    python prepare_images.py <repo-root>
"""

import sys
from pathlib import Path
from PIL import Image

DRESSES = Path(r"C:/ClaudeCode/aeshal/Photos/Dress Types")
WEB = Path(r"C:/ClaudeCode/aeshal/Photos/_web")

# product slug -> (folder, front, back, detail)
PRODUCTS = {
    "amara":  ("Amara",  "DSC_2966", "DSC_3008", "DSC_3056"),
    "dahlia": ("Dahlia", "DSC_3558", "DSC_3601", "DSC_3613"),
    "inaya":  ("Inaya",  "DSC_2447", "DSC_2461", "DSC_2465"),
    "layla":  ("Layla",  "DSC_2636", "DSC_2694", "DSC_2664"),
    "liana":  ("Liana",  "DSC_2890", "DSC_2943", "DSC_2936"),
    "mira":   ("Mira",   "DSC_3470", "DSC_3522", "DSC_3536"),
    "mizna":  ("Mizna",  "DSC_3077", "DSC_3115", "DSC_3086"),
    "noor":   ("Noor",   "DSC_3282", "DSC_3346", "DSC_3359"),
    "rania":  ("Rania",  "DSC_2304", "DSC_2354", "DSC_2323"),
}

# editorial stills from the curated web set, for the pages rather than the grid
EDITORIAL = {
    "hero-group":   "DSC_3763",  # three women, gold + floral — the home hero
    "hero-alt":     "DSC_3699",
    "portrait-warm": "DSC_2615",  # close warm portrait — story / about
    "pair-close":   "DSC_2573",
    "single-floral": "DSC_3657",
    "room-wide":    "DSC_3646",  # two figures in a room — story
    "studio-pair":  "DSC_2503",
}

PRODUCT_W, PRODUCT_H = 1200, 1600   # 3:4
HERO_W, HERO_H = 1800, 1200         # 3:2 landscape for the home hero
STORY_W, STORY_H = 1200, 1600       # 3:4 portrait


def load(path: Path) -> Image.Image:
    im = Image.open(path)
    im.draft("RGB", (2400, 3200))
    return im.convert("RGB")


def cover(im: Image.Image, w: int, h: int, top_anchor: bool = True) -> Image.Image:
    sw, sh = im.size
    scale = max(w / sw, h / sh)
    im = im.resize((max(1, round(sw * scale)), max(1, round(sh * scale))), Image.LANCZOS)
    left = (im.width - w) // 2
    top = 0 if top_anchor else (im.height - h) // 2
    if not top_anchor:
        top = max(0, min(top, im.height - h))
    return im.crop((left, top, left + w, top + h))


def find(folder: Path, stem: str) -> Path | None:
    for ext in (".jpg", ".JPG", ".jpeg", ".png"):
        p = folder / f"{stem}{ext}"
        if p.exists():
            return p
    hits = list(folder.glob(f"{stem}.*"))
    return hits[0] if hits else None


def main() -> int:
    root = Path(sys.argv[1])
    temp = root / "temp"
    temp.mkdir(parents=True, exist_ok=True)

    manifest, missing = [], []

    for slug, (folder, *stems) in PRODUCTS.items():
        src_dir = DRESSES / folder
        for role, stem in zip(("front", "back", "detail"), stems):
            src = find(src_dir, stem)
            if not src:
                missing.append(f"{folder}/{stem}")
                continue
            out = temp / f"{slug}-{role}.jpg"
            cover(load(src), PRODUCT_W, PRODUCT_H).save(out, quality=82, optimize=True, progressive=True)
            manifest.append((str(out.relative_to(root)), str(src), out.stat().st_size))

    for name, stem in EDITORIAL.items():
        src = find(WEB, stem)
        if not src:
            missing.append(f"_web/{stem}")
            continue
        out = temp / f"editorial-{name}.jpg"
        if name.startswith("hero"):
            cover(load(src), HERO_W, HERO_H, top_anchor=False).save(out, quality=84, optimize=True, progressive=True)
        else:
            cover(load(src), STORY_W, STORY_H).save(out, quality=82, optimize=True, progressive=True)
        manifest.append((str(out.relative_to(root)), str(src), out.stat().st_size))

    total = sum(m[2] for m in manifest)
    for rel, src, size in manifest:
        print(f"  {rel:34} {size/1024:7.0f} KB   <- {Path(src).parent.name}/{Path(src).name}")
    print(f"\n{len(manifest)} files, {total/1024/1024:.1f} MB total")
    if missing:
        print("MISSING: " + ", ".join(missing))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
