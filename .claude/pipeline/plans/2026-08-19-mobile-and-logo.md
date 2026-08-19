# Plan: mobile alignment sweep + put the real logo on the site

## Context

Najath, 2026-08-19, browsing mavea.lk on a phone:

> "some texts, icons and logos are not properly aligned... One example is when
> you press the menu. Mavea text goes up in a weird way. also, I don't see the
> logo anywhere. we need to put the logo appropriately as well"

Two named defects with confirmed causes, plus a sweep.

## Ground truth (verified 2026-08-19)

1. **The menu jump is a scroll-lock bug.** `inc/chrome.php:1068` does
   `document.documentElement.style.overflow = isOpen ? 'hidden' : ''`. Setting
   `overflow:hidden` on `<html>` stops it being the scroll container, so the
   browser clamps scrollTop to 0 and the page snaps to the top the instant the
   drawer opens. Closing restores `overflow` but the reading position is gone.
   That snap is what reads as the wordmark lurching upward.
2. **The É very likely clips.** `style.css:634-644` sets `.slk-wordmark` to
   `line-height:1` with `text-transform:uppercase`. An uppercase É carries its
   acute above cap height and a line box of exactly 1 has no room for it. The
   pre-launch holding page used `line-height:1.15` for precisely this reason
   (`design/holding-page.html`); the header never received the same fix.
   `text-indent: var(--slk-wordmark-tracking)` is also applied to compensate for
   trailing letter-space — verify it does not now push the mark off-centre in the
   flex row on narrow viewports.
3. **No logo anywhere.** `custom_logo` is unset. Every "MAVÉA" on the site is
   TEXT from `slk_wordmark_text()`. Assets now staged, deployable, at
   `local/themes/slk-child/assets/brand/`:
   - `wordmark.png` — 3044x840, the full wordmark. Downscaled to a ~21px header
     mark it is far above 2x, so it is sharp.
   - `monogram.svg` — vector, but a DARK BADGE (`#090909` fill with an `#E7DED7`
     rule), so it is not a drop-in for the porcelain header.
   - `monogram.png` — 584x531, black on transparent, correct for light surfaces.
   The site icon IS set (attachment 12). The email header image and the PDF
   invoice logo are both unset — those are configuration, handled outside this
   plan, not here.
4. The header is `position:relative` (`.slk-header--over` is `absolute` over the
   homepage hero). There is no sticky header and no scroll-driven class, so the
   jump in (1) is the only motion involved.

## Approach

### WP1 · Fix the drawer scroll lock
**Files:** `local/themes/slk-child/inc/chrome.php`

Replace the `documentElement.style.overflow` line with a position-based lock that
preserves the reading position:

- On open: read `window.scrollY`, store it, set on `<body>`
  `position:fixed; top:-<y>px; left:0; right:0; width:100%`.
- On close: clear those, then `window.scrollTo(0, y)` — restore BEFORE returning
  focus so the focus return does not scroll somewhere else.
- Must be idempotent: opening the search panel and the drawer together, or a
  double-open, must not stack two locks or lose the stored offset.
- The existing focus trap and Escape handling must keep working unchanged.
- Respect `prefers-reduced-motion` as the rest of the file does.

**Acceptance:** scroll halfway down /shop/ on a 390px viewport, open the menu —
the page behind does not move. Close it — you are still exactly where you were.

### WP2 · Stop the É clipping
**Files:** `local/themes/slk-child/style.css`

Give `.slk-wordmark` (and the `.site-title` selectors sharing the rule) enough
line box for the acute — the holding page's `1.15` is the known-good value.
Confirm the header pill's height and vertical centring do not shift as a result:
`.slk-header__inner` is a flex row with `align-items:center`, so a taller line
box changes the mark's box height. Adjust only what is needed to keep the pill
the same height as today. Check the drawer head and the footer wordmark, which
use the same class.

**Acceptance:** at 390px and at 1440px the acute on É is fully visible, the
header pill height is unchanged from today, and the wordmark stays optically
centred in the pill.

### WP3 · Put the real wordmark in the header and footer
**Files:** `local/themes/slk-child/inc/chrome.php`,
`local/themes/slk-child/inc/wordmark.php`, `local/themes/slk-child/style.css`

Render `assets/brand/wordmark.png` as the header and footer mark instead of
setting the name in type. Requirements:

- The text stays as the accessible name (`alt`), so screen readers and SEO are
  unaffected. Do not remove `slk_wordmark_text()` — it stays the single source of
  truth and feeds the alt text.
- Explicit `width`/`height` attributes so the header does not shift while the
  image loads (CLS). Target render height 21px desktop, 19px mobile, matching
  today's type sizes; set intrinsic size accordingly.
- `loading="eager"`, `decoding="async"` — it is above the fold on every page.
- A filter (e.g. `slk_wordmark_use_image`, default true) that falls back to the
  existing text rendering, so this is revertible without an edit.
- If the image fails to resolve, the text must render instead — never an empty
  header.
- The dark `monogram.svg` badge is NOT to be used on the porcelain header. Leave
  it staged for the email/invoice work.

**Acceptance:** the wordmark is the real artwork on desktop and mobile, occupies
the same space as the text did, header height is unchanged, and disabling the
filter restores the text exactly.

### WP4 · Mobile alignment sweep
**Files:** any of `local/themes/slk-child/inc/*.php`,
`local/themes/slk-child/style.css` needed by findings

Audit at 390x844 and 360x740 and fix what is genuinely misaligned across: home,
shop, a PDP, cart, checkout, my-account (logged out), track-order, contact,
story, delivery, size-guide, FAQ, exchanges. Look specifically for:

- icons and their labels not sharing a baseline or optical centre;
- touch targets under 44px;
- text overflowing or wrapping badly at 360px;
- horizontal overflow (any element wider than the viewport);
- inconsistent gutters between sections;
- the announcement line, header pill, drawer, footer columns and the assist pill.

Report every issue found with page, viewport and selector, and fix each. Do NOT
restyle anything that is merely different from desktop by design — mobile is
allowed its own composition. Fix misalignment, not deliberate difference.

**Acceptance:** no horizontal scrollbar at 360px on any listed page; every
interactive control at least 44px; no clipped or overlapping text.

## Constraints

Porcelain Glass tokens only, no new hex outside the token set (the Google G
data-URI in `inc/account.php` and `inc/checkout-view.php` is an existing,
documented trademark exception — do not "fix" it). No urgency, no invented
business facts. Every new string translatable in `slk`.

## Verification

- `php -l` on every touched PHP file; `bash local/deploy/deploy-code.sh code`
  now lints before pushing, so a parse error cannot reach live.
- Live curls for 200s across the page list.
- Confirm no horizontal overflow at 360px.
