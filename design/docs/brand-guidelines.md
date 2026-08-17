# MAVÉA — brand guidelines (working draft)

The name is **MAVÉA**, settled at gate G1 on 2026-08-15. Everything here was
built to survive that decision before it was taken, and that still holds: the
wordmark is a typographic system, not a mark, and no motif is derived from the
name. Swapping it touches `--slk-wordmark-font` and `/assets/wordmark.svg`.

---

## 1. Direction

**Porcelain Glass.** Warm neutrals only — porcelain ground, bone glass, ink.
The photography is the only colour on the page; everything else recedes.
Surfaces are frosted glass (blur 22px, saturate 1.4, a 1px near-white edge),
corners are generously rounded (16–24px), depth comes from soft wide shadows,
and every touchable thing moves on a 280ms cubic-bezier(.22,1,.36,1) curve —
lift on hover, gentle squash on press. Newsreader at weight 300 for display,
Archivo for everything else.

Chosen after exploring Island Modern, Quiet Luxe, Market Warmth and two glass
studies: the client wanted a smooth, floating, Apple-like interface rather
than flat editorial rules, and a neutral base rather than a brand colour.

Not present, on purpose: gold filigree, dunes, skylines, arabesque borders,
crescents, any name-derived monogram, and any accent colour competing with
the garments.

## 2. Voice

Her friend with excellent taste, not a boutique assistant and not a preacher.

- Say what a thing is: "Linen, unlined, falls to the ankle."
- Modesty is the given. Never the pitch. Never "for sisters", never "cover up".
- Sri Lanka is stated plainly and often — districts by name, rupees, Galle.
- Numbers over adjectives: "eight women", "twenty of a cut", "7 days".

Never: urgency, scarcity theatre, exclamation marks, "shop now babe", emoji.

## 3. Colour

| Token | Value | Use |
|---|---|---|
| `--slk-color-ground` | #f2f0ec | Page ground |
| `--slk-color-ink` | #232220 | Text, primary buttons — 13.8:1 on ground |
| `--slk-color-ink-soft` | #44413c | Long-form body |
| `--slk-color-muted` | #5f5c56 | Secondary text — 5.6:1 |
| `--slk-color-faint` | #8a867e | Meta only, never body copy |
| `--slk-glass` | rgba(250,249,246,.55) | Overlay panels on imagery, + blur |
| `--slk-glass-solid` | rgba(255,255,255,.60) | Panels on the ground |
| `--slk-color-error` | #9a2820 | Inline validation |

No accent colour. Product photography and the hijab colour bubbles are the
only saturated elements. Every text pair listed clears 4.5:1.

## 4. Type

- **Newsreader** — display only: page titles, product names on the PDP, section
  heads. Weights 300–500. Optical sizing on.
- **Archivo** — everything else: navigation, buttons, prices, forms, body.

Uppercase eyebrows carry `--slk-track-label` (0.16em). Body never below 14.5px;
meta never below 11px. No text is set in a fixed-width container — Sinhala and
Tamil run roughly 15–30% longer and must not break the layout.

## 5. Photography

Hard constraints. These are not stylistic preferences.

- Full-length garment, hem to the ankle in frame, head in frame.
- Long sleeves to the wrist. High neckline. No skin above the wrist or below
  the ankle. Hair fully covered.
- Loose, non-clinging drape. Opaque fabric — nothing sheer, nothing backlit
  through the cloth.
- Portrait crops only: 3:4 for grid cards, 2:3 for hero and PDP.
- Daylight, real Sri Lankan interiors and streets. No studio seamless, no sand
  dunes, no skylines.
- One detail shot per product carrying drape, weight and texture at close
  range, because she cannot touch it.

Budget: hero under 200KB, AVIF with WebP fallback, width/height always
declared, everything below the fold lazy-loaded. CLS target under 0.1.

## 6. Wordmark system

The name is MAVÉA — 5 letters, inside the 4–8 this system was designed for, so
nothing below changed at G1 except the accent rule in the last bullet.

- Set in `--slk-wordmark-font` (Newsreader), weight 300, letter-spacing
  `--slk-wordmark-tracking` (0.26em), all caps, optical centre corrected by
  adding a trailing indent equal to the tracking.
- Clear space on all sides equals the cap height of the wordmark.
- Minimum width 96px. Below that, use the initial alone at the same tracking.
- One colour only: ink on the porcelain ground. Never brass, never outlined,
  never on a photograph without a solid plate behind it.
- Ships as a single SVG at `/assets/wordmark.svg`, referenced once in the
  header partial and once in the footer.
- **The accent is load-bearing.** É sits above cap height, so the wordmark sets
  `line-height: 1.15`; at `1` the line box equals the font-size and the acute
  clips wherever no min-height floor exists. Uppercasing is CSS-side only —
  PHP's `strtoupper()` is byte-based and would corrupt the UTF-8 to `MAVÃ‰A`.
  Where the accent cannot travel (domains, handles, slugs, filenames) the form
  is the bare ASCII `mavea`.

## 7. Commerce rules

- Currency renders `Rs. 12,500` — prefix, space, comma thousands, no decimals.
- No strikethrough, no "compare at", no discount badge, no countdown, no
  "only 2 left". Value is carried by honest price and visible craft.
- Cash on delivery is presented first, as a designed choice, with its handling
  fee stated in the open and the confirmation call framed as reassurance.
- Exchange policy is stated in plain words wherever a size is chosen.

## 8. Interaction

- Touch targets 44×44px minimum, 8px apart. Nothing depends on hover.
- Motion 150–300ms, only where it explains a change. No carousels, no parallax.
- `:focus-visible` is a 2px ink outline at 2px offset. Never removed.
- `prefers-reduced-motion` collapses all durations to 0.

---

## IMPLEMENTATION NOTES (added during the build, 2026-08-11)

Two corrections applied when porting this to WordPress:

1. **The mockups set the wordmark as `AESHAL` in 40 places.** That was the
   designer's 6-letter placeholder for the tracking demo (the wordmark screen
   showed NILA / AESHAL / SERENDIB as length tests). It is also the one name
   this brand may never carry — Aeshal is the US sister label, and the founding
   constraint is that this store carries a different name. The implementation
   used a neutral placeholder driven by one filter, exactly as §6 requires.

   **Resolved 2026-08-15 (gate G1): the brand is MAVÉA**, and all 40 rendered
   occurrences now read `MAVÉA`. The length ladder is NILA / MAVÉA / SERENDIB
   at 4 / 5 / 8 letters. Two consequences of the accent, both live in the code:
   the É must be preserved as UTF-8 (two bytes, `C3 89`) and never passed
   through PHP's byte-based `strtoupper()` — uppercasing is CSS-side, where it
   is Unicode-aware; and `.slk-wordmark` now sets `line-height: 1.15` rather
   than `1`, because at `1` the line box equals the font-size and the acute
   accent overflows it, which clipped in the footer and in the 26px specimen.
   §6's system is otherwise unchanged — it always held for 4–8 letters.
2. **§3 and §8 originally referenced a teal focus ring and `--slk-color-on-teal`.**
   No teal token exists in this palette — a leftover from the rejected Island
   Modern direction. Resolved in favour of the shipped token set: focus rings
   and the wordmark are ink. `components.css` already does this correctly.
