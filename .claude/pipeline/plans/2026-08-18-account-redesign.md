# Plan: redesign /my-account/ (logged-out auth screen)

## Context

Najath, 2026-08-18, looking at https://mavea.lk/my-account/ at 1568px:
"This page sucks. keep the look and feel, branding, colors, fonts and styles
like this. but redesign this page."

So: **the design system is not in question.** Porcelain Glass stays exactly as
it is — tokens, Newsreader display serif, Archivo UI, ink #232220 on porcelain
#f2f0ec, no accent colour, 24px cards, 16px fields, 999px pills, 280ms
cubic-bezier(.22,1,.36,1), 44px targets. What changes is the LAYOUT and what
the page gives the visitor.

## Ground truth (verified on live, 2026-08-18)

1. **The page is a 420px ribbon.** `#customer_login` is capped at 420px and
   centred (`inc/account.php:666`), `.slk-auth-head` and `.slk-auth-guest` match
   it at 420px (`:669`, `:707`). At 1568px roughly 73% of the viewport is empty
   ground with a thin column down the middle.
2. **Register is below the fold.** Blocksy's `.ct-woo-unauthorized .col2-set` is
   overridden to `display:block`, so the two cards stack. "Create an account"
   begins around y=595 in a 726px viewport — the visitor sees a sign-in form and
   nothing else. This is the mechanical reason sign-up "looked missing".
3. **No Google button exists on this page.** `SLK_Google::available()` is now
   TRUE (client id configured 2026-08-18), and `SLK_Google::button()` returns
   real markup — but the only call site in the codebase is
   `slk_checkout_signin_row()` (`inc/account.php:513-514`), hooked to
   `woocommerce_before_checkout_billing_form`. Checkout only. The account page
   never calls it.
4. **Nothing says why an account is worth having.** Three real, shipped features
   would answer that and none are mentioned: order tracking (`SLK_Track`),
   Saved pieces (`SLK_Saved`, menu priority 24), and points toward credit
   (`SLK_Points`, which renders on the dashboard and discloses its own rate).
5. The template `woocommerce/myaccount/form-login.php` preserves every core
   hook: `woocommerce_before_customer_login_form` (:20),
   `woocommerce_login_form_start/​_end` (:43/:67),
   `woocommerce_register_form_start/​_end` (:81/:117),
   `woocommerce_after_customer_login_form` (:130).
6. Core `woocommerce.css` is deliberately not loaded, so any core-class markup
   without an explicit slk rule renders unstyled.

## Approach

One package. All layout work lives in the two files that already own this
screen; no new template is needed because every insertion point already exists
as a core hook.

### The composition (desktop >=1000px)

```
            ┌───────────────────────────────────────────┐
            │            Welcome back.                  │   centred, max 620px
            │  Sign in, or make an account in a moment.  │
            └───────────────────────────────────────────┘
            ┌───────────────────────────────────────────┐
            │        [ Continue with Google ]           │   max 420px, centred
            │  ───────────────  or  ─────────────────   │
            └───────────────────────────────────────────┘
   ┌──────────────────────────┐   ┌──────────────────────────┐
   │ Sign in                  │   │ Create an account        │   1fr 1fr
   │ email or phone           │   │ full name                │   gap space-5
   │ password                 │   │ mobile                   │   align-items:start
   │ remember me              │   │ email                    │
   │ [ Sign in ]              │   │ [ Create account ]        │
   │ Forgot your password?    │   │ privacy note              │
   └──────────────────────────┘   └──────────────────────────┘
            ┌───────────────────────────────────────────┐
            │  Track every order · Saved pieces ·        │   3 quiet tiles
            │  Points towards credit                     │
            └───────────────────────────────────────────┘
```

Below 1000px it collapses to exactly today's single 420px column, sign-in
first — so the mobile experience is unchanged and no new mobile risk is taken.

### WP1 · Account auth layout

**Files:** `local/themes/slk-child/inc/account.php`,
`local/themes/slk-child/woocommerce/myaccount/form-login.php`

1. **Widen the wrapper, two-up the cards.** Replace the 420px cap on
   `#customer_login` with a `--slk-auth-max` of 980px at >=1000px and a
   `display:grid;grid-template-columns:1fr 1fr` on `.col2-set` (undoing the
   `display:block` that currently stacks them), `align-items:start` so unequal
   card heights do not stretch. Below 1000px keep `display:block` and the 420px
   cap exactly as now. `.slk-auth-head` and `.slk-auth-guest` follow the same
   width so nothing drifts out of alignment — this is the alignment bug fixed
   earlier today and it must not regress.
2. **Keep Blocksy neutralised.** The existing
   `.ct-woo-unauthorized .col2-set > *:first-child/:last-child{padding:0;border:0}`
   reset (`account.php:679-680`) must survive the grid change — verify the 40px
   offset and dashed divider do not return.
3. **Google row.** New function on `woocommerce_before_customer_login_form`
   printing, ONLY when `class_exists('SLK_Google') && SLK_Google::available()`:
   the `SLK_Google::button()` markup plus an "or" divider. Pass
   `wc_get_page_permalink('myaccount')` as the redirect so Google returns the
   visitor here, not to checkout. When unavailable it must print NOTHING — not
   an empty wrapper, not a divider. Reuse the `.slk-google-button` class the
   button already emits; mirror the divider treatment from
   `inc/checkout-view.php:302,329` so the two surfaces match.
4. **Reasons to have an account.** On `woocommerce_after_customer_login_form`,
   three tiles: "Track every order" / "Saved pieces" / "Points towards credit".
   Copy must describe only what is actually shipped — no numbers, no rates, no
   urgency, no exclamation marks. If `SLK_Points` is not active, drop that tile
   rather than describing a feature that is not there.
5. **Heading and intro.** Keep "Welcome back." Change the intro to acknowledge
   both actions, since the register card is now level with sign-in rather than
   hidden below it. Suggested: "Sign in, or make an account in a moment."
   No exclamation mark.

**Acceptance**

- At 1440px both cards are fully visible without scrolling, side by side, with
  no dead gutter wider than the page's own container padding.
- At 375px the layout is byte-for-byte the current single column, sign-in first.
- Google button renders on /my-account/ and returns the visitor to /my-account/
  after auth; with the client id removed it renders nothing at all.
- No dashed divider, no 40px offset between the cards.
- `reg_full_name` and `reg_phone` still submit and still validate.
- Every core `do_action` in form-login.php remains, in its original order.
- Contrast: all text >=4.5:1 on its surface; no colour introduced outside the
  token set.

## Risks

- `.col2-set` is Blocksy-owned; the grid must be scoped to
  `.woocommerce-account` so cart/checkout col2-sets are untouched.
- The 420px cap is load-bearing for lost-password and reset-password
  (`.slk-auth-single`, `account.php:716`). Those pages must stay 420px — the
  widening applies only where `#customer_login` exists.
- Do not touch `woocommerce_registration_generate_password` behaviour; the
  register card stays passwordless with the emailed set-password link.

## Verification

- `php -l` on both files.
- Live curls after deploy: register fields present, Google button present,
  no `ct-woo-unauthorized` dashed rule in the computed CSS.
- Chrome at 1440px and 375px.
