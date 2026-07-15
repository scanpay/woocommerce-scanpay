# Scanpay for WooCommerce — agent guide

> This is the Codex-facing project guide. It mirrors `CLAUDE.md`; keep the two in
> sync — when you change project facts in one, mirror them in the other.

WordPress/WooCommerce payment gateway plugin for the [Scanpay](https://scanpay.dk)
platform. Accepts card, MobilePay Online, and Apple Pay; full WooCommerce
Subscriptions support; HPOS- and Blocks-checkout compatible.

The `dev` branch is a large rewrite (`v3.0.0`, up from `2.9.x`) and is not yet
finished (~90% done) — the authoritative list of remaining gaps is the root
`PLAN.md` file (formerly a `TODO` file).

## Repository layout

| Path         | Role |
| ------------ | ---- |
| `src/`       | Authored source. **Edit here.** |
| `build/`     | Release artifact from `build.sh` (shipped to customers). Gitignored. Never edit by hand. |
| `.stubs/`    | Machine-specific symlinks to WP/WC source for phpactor indexing. Gitignored. |
| `vendor/`    | Composer **dev tooling only** (phpcs, wp-cli). Not shipped. |
| `node_modules/` | pnpm deps (esbuild, sass, prettier, eslint). |
| `docs/`      | `requirements.md` = min-version rationale for every WP/WC/PHP API used. |

## Build & tooling

- **Package manager is pnpm, not npm.** `.gitignore` blocks `package-lock.json`.
- **`./build.sh`** produces the shippable plugin. It rsyncs `src` → `build`
  (minus css/ts), compiles SCSS via `sass`, compiles TS via
  `esbuild --bundle --minify`, generates i18n `.mo`/`.php` via wp-cli, then
  substitutes `{{ VERSION }}`, `{{ WP_MIN }}`, `{{ WC_MIN }}`, `{{ PHP_MIN }}`
  etc. from `package.json`. `build/` is the **release artifact**: the packaged
  plugin that is manually uploaded to customers / the WordPress.org plugin
  directory. build.sh does not publish it — that release step is manual.
- After building, `build.sh` prompts to also `rsync`-deploy the same build to
  the **test server** `woocommerce.scanpay.dev` (ssh alias `modules`), rewriting
  prod hosts `*.scanpay.dk` → `*.scanpay.dev` for that copy only.
- Lint: `pnpm phpcs` (WordPress + WooCommerce-Core + PHPCompatibilityWP; short
  arrays enforced), `pnpm phpcbf` to autofix. JS/CSS: `pnpm lint:js` /
  `lint:style`. Prettier config in `.prettierrc.mjs` (TS `printWidth: 120`).
  Note `lint:js` only globs `src/public/assets/js/`, so admin TS
  (`order.ts`, `settings.ts`, and any future `subs.ts`) is not covered by it.
- `webpack.config.js` and `tsconfig.json` `outDir` are stale/editor-only — the
  real build is esbuild in `build.sh`. `tsconfig.json` `include` is what governs
  type-checking.

## Conventions

- **PHP 8.0 minimum**, `declare(strict_types=1)` at the top of most files.
  `docs/requirements.md` lists why each min version is what it is — respect it
  before using a newer API.
- WordPress function-based style (not OOP-heavy); files guard with
  `defined( 'ABSPATH' ) || exit();`. Function prefix `wc_scanpay_` / `wcs_scanpay_`.
- Text domain: `scanpay-for-woocommerce`. Note: several user-facing strings are
  currently hardcoded Danish (e.g. settings nav "Generelt", subscription terms) —
  not all strings go through `__()` yet.
- **Money is never a float.** Amounts are decimal strings handled by
  `src/library/math.php`. All public helpers carry the `wc_scanpay_` prefix:
  `wc_scanpay_addmoney`, `wc_scanpay_submoney`, `wc_scanpay_cmpmoney`,
  `wc_scanpay_money_equals`, `wc_scanpay_is_zero`, `wc_scanpay_is_money`.
  Use these for any capture/charge/refund arithmetic.
- Prod hosts: `api.scanpay.dk` (API), `dashboard.scanpay.dk`, `betal.scanpay.dk`
  (hosted payment window). `.dev` variants exist for the dev server only and are
  produced by `build.sh`, not committed.

## Architecture

**Entry point** `src/woocommerce-scanpay.php` routes special requests early:

- `X-Signature` header → registers `woocommerce_api_wc_scanpay`, so
  `callback/wc-scanpay-ping.php` (Scanpay ping/sync) runs through the full
  WP+WC bootstrap via the WC API endpoint; the rest of the plugin's hooks are
  skipped when the URI ends in `wc_scanpay`.
- `?scanpay_thankyou` + `?scanpay_type` (one of `wc|wcs|wcs_free`) + `?key` →
  `public/wp-scanpay-thankyou.php`, required immediately (before the plugin
  registers anything else).
- `X-Scanpay` header + `?x=meta|ping|sub` + `?s=` →
  `admin/ajax/wp-scanpay-fetch-{meta,ping,sub}.php` (selected via `match`), also
  required immediately.

Otherwise it registers 3 gateways: `scanpay` (card, `WC_Gateway_Scanpay_Card`),
`scanpay_mobilepay` (`WC_Gateway_Scanpay_Mobilepay`), `scanpay_applepay`
(`WC_Gateway_Scanpay_ApplePay`), all extending
`WC_Gateway_Scanpay_Base` (`src/gateways/abstract-wc-gateway-scanpay-base.php`).
**Only the card gateway supports subscriptions** (the base and the other two only
`supports` `products`). Form fields load lazily via the base `get_form_fields()`
from `admin/settings/fields/<id>.php`. It also removes the WooCommerce Payments
promo menu from wp-admin (`scanpay_remove_wc_payments_menu`, on `admin_menu`).

When WooCommerce Subscriptions is active, a terms checkbox is added to checkout
via `public/wcs-scanpay-checkout-terms.php`
(`woocommerce_review_order_before_submit`).

**Payment flow**
1. Checkout → `public/generate-payment-link.php` → `WC_Scanpay_Client::new_url`
   (`POST /v1/new`) → redirect customer to `betal.scanpay.dk`.
2. Scanpay pings us → `wc-scanpay-ping.php` verifies HMAC-SHA256 of the body with
   the API key (`hash_equals` of the base64 HMAC vs the `X-Signature`), then pulls
   changes via `WC_Scanpay_Client::seq` (`GET /v1/seq/N`).
3. `WC_Scanpay_Sync` validates each change, upserts the meta table, and calls
   `$order->payment_complete()`.
4. Capture: on `woocommerce_order_status_completed`, via the bulk actions in
   `admin/orders.php` / `admin/hooks/wp-bulk-actions.php`, or via
   `admin/hooks/wp-ajax-wc-mark-order-status.php` (intercepts the admin
   "mark completed" AJAX *before* WooCommerce's own handler so capture happens
   before completion emails go out) → `WC_Scanpay_Capture::capture_or_hold`
   (`POST /v1/transactions/N/capture`).
5. Subscription renewals: WCS scheduler (`woocommerce_scheduled_subscription_payment_scanpay`)
   → `WCS_Scanpay_Charge` (`POST /v1/subscribers/N/charge`, idempotency-keyed).

**Client library** `WC_Scanpay_Client` (`src/library/class-wc-scanpay-client.php`,
curl-based, self-versioned "client lib" in its file header) is the only thing that
talks to `api.scanpay.dk`.

**Custom DB tables** (created in `install.php`, latin1):
- `scanpay_seq` — per-shop sync cursor (`shopid` PK, `seq`, `ping`, `mtime`).
- `scanpay_meta` — per-order transaction row (`orderid` PK, `shopid`, `subid`,
  `id`, `rev`, `nacts`, `currency`, and the money totals
  `authorized`/`captured`/`refunded`/`voided`).
- `scanpay_subs` — per-subscriber payment-method cache (`subid` PK, `rev`,
  `method` = card label, `method_exp` = expiry). Written by `WC_Scanpay_Sync`.
  **It does NOT store retries, an idempotency key, or an `nxt` lock** — the
  charge path only reads `rev` from it to build the idempotency key, and the subs
  meta box reads the row for display.

Settings live in options `woocommerce_scanpay_settings` (card = primary/shared;
`WC_SCANPAY_URI_SETTINGS`), `woocommerce_scanpay_mobilepay_settings`,
`woocommerce_scanpay_applepay_settings` (WC's default `woocommerce_{id}_settings`
convention). The `WC_SCANPAY_URI_*` constants in `woocommerce-scanpay.php` are
mostly order/subscription meta keys, plus the settings option name
(`WC_SCANPAY_URI_SETTINGS`). The shop's admin-AJAX auth `secret` lives inside the
settings option (minted in `install.php`), not a separate option.

**Concurrency:** `Scanpay_Flock` (`src/library/class-scanpay-flock.php`, uses
`flock( …, LOCK_EX | LOCK_NB )` on a per-shop `scanpay_{shopid}.lock` file in the
temp dir) serializes **only the ping/sync loop** in `wc-scanpay-ping.php`. The
subscription **charge path has no DB lock and no `nxt` timestamp**. Duplicate /
concurrent charges are prevented by three independent layers:
1. Local already-paid guards — `$order->is_paid()` / `get_transaction_id()` and an
   authoritative `SELECT orderid FROM scanpay_meta WHERE orderid = …` check.
2. A **stateless idempotency key** `orderid_rev_day` (order id + subscription
   `rev` + whole days since the renewal order's creation) sent as the
   `Idempotency-Key` header; Scanpay enforces the ≤1-charge binding server-side
   for 24h (key rotation / local locks are intentionally absent).
3. WCS owns retry scheduling; the plugin keeps no local retry state.

**Frontend (TS → esbuild):** `public/assets/js/checkout.ts` (Blocks checkout
registration via `window.wc.wcBlocksRegistry.registerPaymentMethod`),
`admin/assets/js/settings.ts` (settings page sync/version checks),
`admin/assets/js/order.ts` (order meta box). Shared helpers in
`admin/assets/js/util/compat.ts` and `admin/assets/js/types/meta.ts` (the latter
exports runtime helpers `showError`/`showWarning`/`buildTable`/`pluginVersionCheck`,
not just types).

## Lifecycle

- `install.php` creates the three tables, seeds the shop row, and mints the
  admin-AJAX `secret` into the settings option when absent.
- `upgrade.php` runs version-gated migrations (settings-key renames, table
  rebuilds, dropping legacy `woocommerce_scanpay_*` tables); tracked in the
  `wc_scanpay_version` option.
- `uninstall.php` drops all tables and options.
- Saving settings runs the shared `admin/settings/process-admin-options.php`
  (from `WC_Gateway_Scanpay_Base::process_admin_options`): when a gateway is
  being enabled (or its key changed while enabled), it validates the API key with
  a live `seq(0)` call and force-disables the gateway if the key is bad.
- Changing the API key in settings **drops and recreates the tables** — but only
  when the shop ID changed *and* the key passed validation (see
  `WC_Gateway_Scanpay_Card::process_admin_options`).

## Known incomplete (dev rewrite, ~90% done)

The root `PLAN.md` file is the single source of truth for remaining gaps — read it
before assuming a feature is finished. Highlights:
- The subscription meta-box JS source (`admin/assets/js/subs.ts`) is **missing**,
  yet `admin/subscriptions.php` enqueues the compiled `subs.js` (404s).
- The settings sync-status UI is **unwired**: `settings.ts` targets
  `#wcsp-set-alert` / `#wcsp-set-nav-mtime` that `admin-options.php` never renders.
- `order.ts` is a **partial implementation** (it parses & renders the
  authorized/captured/refunded totals, but error handling is console-only, the
  `#wcsp-meta-head`/`#wcsp-meta-foot` placeholders are empty, and there is no
  version check or capture/refund action UI).

## Working in this repo

- Edit `src/`, never `build/` (it's regenerated by `./build.sh`).
- Keep `declare(strict_types=1)`, the `defined( 'ABSPATH' ) || exit();` guard, the
  `wc_scanpay_` / `wcs_scanpay_` prefixes, and money-as-decimal-string.
- Run `pnpm phpcs` (autofix `pnpm phpcbf`) for PHP and `pnpm lint:js` /
  `pnpm lint:style` for the frontend before considering a change done; admin TS is
  outside the `lint:js` glob, so check it by hand / via `tsc`.
- A `.ts` file only ships once `build.sh`'s esbuild step emits its `.js` — webpack
  is not used.
