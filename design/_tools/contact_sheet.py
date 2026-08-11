"""Build a labelled contact sheet so images can be judged in one look.

    python contact_sheet.py <out.jpg> <cols> <label> <dir-or-file> [more...]

Every tile is numbered and captioned with its source name, so a pick can be
referenced exactly ("row 2, #14") instead of by eyeballing a filename list.
Portrait 3:4 tiles, because that is the ratio the design's product grid uses —
a garment that does not survive a 3:4 crop is not a card candidate.
"""

import sys
from pathlib import Path
from PIL import Image, ImageDraw

TILE_W, TILE_H = 300, 400
PAD, LABEL_H = 10, 22
GROUND = (242, 240, 236)   # --slk-color-ground
INK = (35, 34, 32)

EXT = {".jpg", ".jpeg", ".png", ".webp"}


def collect(args):
    out = []
    for a in args:
        p = Path(a)
        if p.is_dir():
            out += sorted(f for f in p.iterdir() if f.suffix.lower() in EXT)
        elif p.suffix.lower() in EXT:
            out.append(p)
    return out


def main():
    out_path, cols, label = sys.argv[1], int(sys.argv[2]), sys.argv[3]
    files = collect(sys.argv[4:])
    if not files:
        print("no images found")
        return 1

    rows = (len(files) + cols - 1) // cols
    W = cols * (TILE_W + PAD) + PAD
    H = rows * (TILE_H + LABEL_H + PAD) + PAD + 30

    sheet = Image.new("RGB", (W, H), GROUND)
    draw = ImageDraw.Draw(sheet)
    draw.text((PAD, 8), f"{label} — {len(files)} images", fill=INK)

    for i, f in enumerate(files):
        try:
            im = Image.open(f)
            im.draft("RGB", (TILE_W * 2, TILE_H * 2))  # fast JPEG downscale
            im = im.convert("RGB")
        except Exception as e:                                    # noqa: BLE001
            print(f"skip {f.name}: {e}")
            continue

        # cover-crop to 3:4, anchored to the TOP so a full-length garment keeps
        # its head in frame and loses floor, not face.
        sw, sh = im.size
        scale = max(TILE_W / sw, TILE_H / sh)
        im = im.resize((max(1, int(sw * scale)), max(1, int(sh * scale))), Image.LANCZOS)
        left = (im.width - TILE_W) // 2
        im = im.crop((left, 0, left + TILE_W, TILE_H))

        c, r = i % cols, i // cols
        x = PAD + c * (TILE_W + PAD)
        y = 30 + PAD + r * (TILE_H + LABEL_H + PAD)
        sheet.paste(im, (x, y))
        draw.text((x + 2, y + TILE_H + 4), f"{i + 1:02d} {f.stem}", fill=INK)

    sheet.save(out_path, quality=82, optimize=True)
    print(f"{out_path}  {sheet.size[0]}x{sheet.size[1]}  {len(files)} tiles")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
