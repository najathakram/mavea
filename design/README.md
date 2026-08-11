# Design source — Porcelain Glass

Imported 2026-08-11 from Claude Design project *Sri Lanka commerce design directions*
(`fee09a7a-fc2e-4004-8bd3-7ae75c3cb856`), file `SL Dress - Porcelain Site.dc.html`.

## What is here

| Path | What |
|---|---|
| `porcelain-site.dc.html` | The full canvas document, reassembled byte-for-byte (274,058 bytes, 2,626 lines) |
| `sections/` | The same document sliced by section, plus `index.json` mapping all 44 screens to files |
| `sections/00-canvas-chrome.html` | **Canvas scaffolding only** — `.sec/.fr/.scr/.rail` are the design *board*, never the site |
| `assets/design-tokens.css` | The token set — the single source of colour, type, space, shape, motion |
| `assets/components.css` | The component class contract (`.slk-*`) |
| `assets/sri-lanka-commerce.json` | Districts, address fields, delivery zones, fees, payment methods |
| `docs/brand-guidelines.md` | The governing rules, plus implementation corrections |
| `_reference/woocommerce-templates/` | Real WooCommerce 11.0.1 templates, extracted from the running container so nothing is written from memory |
| `_assemble.mjs`, `_slice.mjs` | The reassembly and slicing tools (re-runnable) |

## The design in one paragraph

**Porcelain Glass.** Warm neutrals only — porcelain ground (`#f2f0ec`), ink text
(`#232220`), bone glass panels. The photography is the only colour on the page. Surfaces
are frosted glass (blur 22px, saturate 1.4, 1px near-white edge), corners 16–24px, depth
from soft wide shadows, and everything touchable moves on 280ms `cubic-bezier(.22,1,.36,1)`
— lift on hover, squash on press. Newsreader 300 for display, Archivo for everything else.

## 44 screens

Mobile at 390px (39 frames) and desktop at 1280px (10 frames), covering: home, shop, product,
cart, checkout (including its error state and every payment variant), order received, size
guide, delivery & COD, our story, search, filters, empty cart, track order, exchange request
and policy, FAQ, contact, 404, sign in, account, orders, addresses, plus the wordmark system,
photography spec and share card.

## Two things to know before touching it

1. **The wordmark reads `AESHAL` in 40 places — it is a placeholder, not the brand.** The
   wordmark screen uses NILA / AESHAL / SERENDIB purely as 4/6/8-letter tracking tests. Aeshal
   is the US sister label and is the one name this store may never carry. The implementation
   drives the wordmark from a single `slk_wordmark` filter; renaming is a one-line change.
2. **67 image slots, zero real images.** Imagery is deferred to the shoot. The photography
   spec in `docs/brand-guidelines.md` §5 is a hard constraint list, not a mood board.
