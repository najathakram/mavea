# Auto-deploy from GitHub to Hostinger

`.github/workflows/deploy.yml` pushes the theme and plugins to mavea.lk whenever
`main` changes. It mirrors `local/deploy/deploy-code.sh`: **code only** — never the
database, never uploads, never a WooCommerce setting.

## Why not Hostinger's own Git integration

hPanel's Git tool clones a repo *into* a directory. This repo's tree does not match
the server's: `local/themes/slk-child` has to land at `wp-content/themes/slk-child`,
and `local/plugins/*` at `wp-content/plugins/*`. A plain clone would drop the whole
repo — including 200MB of photography and every plan document — into the web root.
So the deploy tars the two source directories and unpacks them where they belong.

## One-time setup

### 1. Generate a deploy key

Run locally. Use a **new** key — do not reuse your personal one:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/mavea_deploy -N "" -C "github-actions@mavea"
```

### 2. Authorise it on Hostinger

```bash
ssh-copy-id -i ~/.ssh/mavea_deploy.pub -p 65002 u860340467@46.17.172.250
```

Verify it works before going further:

```bash
ssh -i ~/.ssh/mavea_deploy -p 65002 u860340467@46.17.172.250 "echo ok"
```

### 3. Add the secrets

GitHub → the repo → **Settings → Secrets and variables → Actions → New repository secret**:

| Secret | Value |
|---|---|
| `SSH_PRIVATE_KEY` | the **entire** contents of `~/.ssh/mavea_deploy` (the file without `.pub`), including the BEGIN and END lines |
| `SSH_DEST` | `u860340467@46.17.172.250` |
| `SSH_PORT` | `65002` |

Print the private key to copy it:

```bash
cat ~/.ssh/mavea_deploy
```

## How it behaves

- **Only `main` deploys.** Feature branches never reach the live store. The current
  branch is `campaign-imagery-and-positioning`, so nothing deploys until it merges.
- **Only relevant paths trigger it** — edits to plans, docs or photography do not
  redeploy.
- **PHP lint runs first.** A syntax error fails the job and nothing leaves the runner.
- **Deploys are serialised.** A second push waits rather than racing a half-unpacked
  theme onto the live site.
- **It verifies afterwards** — homepage, shop, my-account and track-order must all
  return 200, or the run is marked failed.
- **Manual runs:** Actions → Deploy to Hostinger → **Run workflow**.

## What it deliberately does not do

- **Activate anything.** Plugins land inactive; the theme is not switched. Activation
  stays a hand-verified step, which is what kept the homepage intact during migration.
- **Touch the database or settings.** Every WooCommerce option change stays a
  deliberate, logged act with a rollback — not a side effect of merging a branch.
- **Roll back.** If a deploy ships a bad template, revert the commit on `main` and let
  the next run overwrite it, or run `local/deploy/deploy-code.sh code` from an earlier
  checkout.

## The risk to weigh

Auto-deploy means a merge to `main` changes a live store within about a minute, with no
human between the merge and the customer. The lint gate and the post-deploy status
checks catch broken PHP and dead pages, but neither catches a change that is merely
*wrong* — bad copy, a broken layout, a mispriced product.

If you would rather keep a human in the loop, delete the `push:` block from
`deploy.yml` and keep `workflow_dispatch:`. Deploys then happen only when you click
**Run workflow**, and you still get the linting and verification.
