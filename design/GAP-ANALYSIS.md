# What of the design is built, and what is not

Checked 2026-08-11 against the 44 screens in `sections/index.json` and the running site.

## Built (12 screens)

| Screen | Where |
|---|---|
| Shop / Desktop shop | `woocommerce/content-product.php`, `inc/shop.php` |
| Product / Desktop product | `woocommerce/content-single-product.php`, `inc/pdp.php` |
| Cart / Desktop cart | `woocommerce/cart/cart.php`, `inc/cart.php` |
| Checkout / Desktop checkout | `woocommerce/checkout/*`, `inc/checkout-view.php`, `slk-checkout` plugin |
| Order received | `woocommerce/checkout/thankyou.php` |
| Empty cart | `inc/cart.php` |
| Mobile nav drawer | `inc/chrome.php` |
| Header pill · nav · footer | `inc/chrome.php` |
| Wordmark system | `inc/wordmark.php` — built as a system, not a page |

## Not built (about 24 screens)

### Content pages — 8. None of these exist as WordPress pages at all.
The design supplies finished copy for most of them, so these are the cheapest to build.

| Page | Note |
|---|---|
| **Home** | The biggest gap. `show_on_front` is still `posts`, so `/` renders the blog list. The design's hero, product rails and story teaser do not exist. |
| Our story | Full copy in the design (the Galle atelier, the 8/20/25 stat cards) |
| Size guide | Full cm table + how-to-measure copy + the "between two sizes" WhatsApp card |
| Delivery & COD | Full copy: three district tiers, the 3-step COD explainer, payment notes |
| Exchange policy | Design screen exists |
| FAQ | Design screen exists |
| Contact | WhatsApp-first, design screen exists |
| Track order | Design screen exists |

### Account area — 6. WooCommerce's My Account page exists and inherits button/panel styling, but none of the designed screens were built.
Sign in · Account dashboard · Orders · Order tracking · Addresses · Desktop account

### States and moments — 6.
| State | Status |
|---|---|
| Filter sheet | Not built — shop has no filters |
| Added to bag | Not built — no confirmation toast/sheet |
| Image zoom | Not built |
| Search | Not built (results page unstyled) |
| Exchange request | Not built |
| Connection and language | Not built (offline + language switch) |

### Partial — 3.
| Screen | What is missing |
|---|---|
| Payment variants | COD and bank transfer are live. The design's *expanded* bank-transfer panel (account details, Copy-details and Send-slip-on-WhatsApp buttons) is not built. Koko and PayHere are installed but inactive pending merchant accounts. |
| Sold out | CSS exists; nothing exercises it because the seeded catalogue has no variable products with sizes. |
| Hijabs browse | The round colour-led card variant is built; the dedicated browse view is not. |

### Other — 2.
- **404 / Not found** — no `404.php`; WordPress returns a bare 404.
- **Share card** — no Open Graph image. The brief's own test is whether she would send a product to a friend on WhatsApp, so this one earns its place.

### Not code
- **Photography spec** — a hard constraint list for the shoot, already recorded in `docs/brand-guidelines.md` §5.

## Suggested order

1. **Home** — the entry point, and currently a blog list.
2. **The four trust pages** — Delivery & COD, Size guide, Our story, Exchange policy. The design
   already contains their copy, and they are what makes a brand-new store credible.
3. **404 + search + added-to-bag** — small, and each is a visible hole today.
4. **Account area** — matters only once real orders exist.
5. **Filter sheet, image zoom, exchange request** — polish.
6. **Share card** — before any social push.
