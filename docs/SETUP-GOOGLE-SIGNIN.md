# Google sign-in / sign-up — setup

Everything on the MAVÉA side is already built. The only missing piece is a Google
OAuth client, which only you can create: it needs a Google account, and creating
accounts and handling client secrets is yours to do, not something to hand to an
assistant.

Once the client ID is in, the button appears by itself. Nothing else to deploy.

## What is already done

- `login-with-google` (rtCamp, v1.4.4) is installed and active on mavea.lk.
- `SLK_Google::available()` (`local/plugins/slk-checkout/includes/class-slk-google.php`)
  gates every Google button on "plugin active **and** a non-empty `client_id`".
  Until then `SLK_Google::button()` returns `''`, so no dead button reaches a shopper.
- Storefront call sites:
  - **Checkout** — `slk_checkout_signin_row()` in `inc/account.php`, above the billing form.
  - **My account** — added 2026-08-18 in the account-screen redesign. It did *not*
    exist before that, which is why a configured client ID still showed no button
    on `/my-account/`.
- `WP_Google_Login` renders its own button on `wp-login.php`. That one is **not**
  gated, which is why a dead "Login with Google" control is visible there today.
  Finishing this setup fixes it; there is nothing else to change.

## Step 1 — Create the Google Cloud project

1. Go to <https://console.cloud.google.com/>.
2. Project dropdown (top left) → **New Project**.
3. Name it `MAVEA` → **Create**, then make sure it is the selected project.

## Step 2 — Configure the consent screen

**APIs & Services → OAuth consent screen**

| Field | Value |
|---|---|
| User type | **External** |
| App name | `MAVÉA` |
| User support email | your address |
| Application home page | `https://mavea.lk` |
| Authorised domain | `mavea.lk` |
| Developer contact | your address |

Scopes: the defaults are right — `email`, `profile`, `openid`. Nothing else is needed,
and asking for more will slow review down for no gain.

Leave it in **Testing** while you try it out; add your own address under **Test users**.
Publish it to **In production** before launch, otherwise only listed test users can sign in.

> A consent screen limited to `email`/`profile`/`openid` does not require Google
> verification. Adding sensitive scopes would.

## Step 3 — Create the OAuth client

**APIs & Services → Credentials → Create Credentials → OAuth client ID**

- **Application type:** Web application
- **Name:** `MAVEA web`

**Authorised JavaScript origins**

```
https://mavea.lk
```

**Authorised redirect URIs** — add **both**:

```
https://mavea.lk/wp-login.php
https://khaki-lobster-518218.hostingersite.com/wp-login.php
```

Create it, then copy the **Client ID** and **Client secret**.

> **The callback is the bare `wp-login.php`, with no query string.** Verified by
> reading the URL the plugin actually builds:
> `authorization_url()` emits `redirect_uri=https%3A%2F%2Fmavea.lk%2Fwp-login.php`.
> `?action=google_login` is the *button's* href, not the OAuth callback — registering
> that instead fails with `redirect_uri_mismatch`. (An earlier version of this file
> said otherwise; it was wrong.)
>
> The Hostinger preview domain is listed too because the wp-admin session lives
> there while the site's canonical URL is mavea.lk, so sign-in can start from
> either host.

## Step 4 — Put the credentials into WordPress

`https://mavea.lk/wp-admin/options-general.php?page=login-with-google`

1. Paste the **Client ID**.
2. Paste the **Client secret** — do this yourself; do not paste it into a chat.
3. Tick **Enable Google login registration**, so a new customer can sign *up*, not
   only sign in.
4. Leave **Whitelisted domains** empty — it restricts sign-in to named email
   domains, which for a public shop would lock customers out.
5. Save.

## Step 5 — Verify

1. Open `https://mavea.lk/my-account/` in a private window — a **Continue with
   Google** button should now sit above the sign-in form. If it does not, the
   client ID did not save.
2. Sign in with a Google account that has never ordered. You should land on the
   account dashboard.
3. **Users → All Users** in wp-admin: the new account should have the **Customer**
   role, not Subscriber.
4. Delete that test user afterwards.

## Notes worth knowing

- **Existing customers are matched by email.** Signing in with Google using the same
  address as an existing account signs into that account rather than creating a
  second one.
- **Google accounts have no WooCommerce billing details.** A Google sign-up will not
  carry a phone number or address, so the first checkout still collects them. This is
  why phone stays required at checkout rather than at registration.
- **Google sign-up bypasses the my-account register form**, so the full-name and
  mobile fields that form adds are not collected on this path. The name comes from
  the Google profile; the mobile is captured at first checkout.
