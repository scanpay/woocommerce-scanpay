# Scanpay for WooCommerce

WordPress/WooCommerce payment gateway plugin for the [Scanpay](https://scanpay.dk)
platform. Accepts card, MobilePay Online, and Apple Pay; full WooCommerce
Subscriptions support; HPOS- and Blocks-checkout compatible.

The `dev` branch is a large rewrite (`v3.0.0`, up from `2.9.x`) and is not yet
finished (~90% done) — the authoritative list of remaining gaps is the root
`TODO` file.

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
  `src/library/math.php` (`wc_scanpay_addmoney`/`submoney`/`cmpmoney`/`money_equals`).
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
- `?scanpay_thankyou` + `?scanpay_type` → `public/wp-scanpay-thankyou.php`,
  required immediately (before the plugin registers anything else).
- `X-Scanpay` header + `?x=meta|ping|sub` + `?s=` →
  `admin/ajax/wp-scanpay-fetch-*.php`, also required immediately.

Otherwise it registers 3 gateways: `scanpay` (card), `scanpay_mobilepay`,
`scanpay_applepay`, all extending `WC_Gateway_Scanpay_Base`. **Only the card
gateway supports subscriptions.** Form fields load lazily from
`admin/settings/fields/<id>.php`. It also removes the WooCommerce Payments
promo menu from wp-admin (`scanpay_remove_wc_payments_menu`).

When WooCommerce Subscriptions is active, a terms checkbox is added to checkout
via `public/wcs-scanpay-checkout-terms.php`
(`woocommerce_review_order_before_submit`).

**Payment flow**
1. Checkout → `public/generate-payment-link.php` → `WC_Scanpay_Client::new_url`
   (`POST /v1/new`) → redirect customer to `betal.scanpay.dk`.
2. Scanpay pings us → `wc-scanpay-ping.php` verifies HMAC-SHA256 of the body with
   the API key, then pulls changes via `WC_Scanpay_Client::seq` (`GET /v1/seq/N`).
3. `WC_Scanpay_Sync` validates each change, upserts the meta table, and calls
   `$order->payment_complete()`.
4. Capture: on `woocommerce_order_status_completed`, via the bulk actions in
   `admin/orders.php` / `admin/hooks/wp-bulk-actions.php`, or via
   `admin/hooks/wp-ajax-wc-mark-order-status.php` (intercepts the admin
   "mark completed" AJAX *before* WooCommerce's own handler so capture happens
   before completion emails go out) → `WC_Scanpay_Capture`
   (`POST /v1/transactions/N/capture`).
5. Subscription renewals: WCS scheduler → `WCS_Scanpay_Charge`
   (`POST /v1/subscribers/N/charge`, idempotency-keyed).

**Client library** `WC_Scanpay_Client` (curl, "client lib 4.0.0") is the only
thing that talks to `api.scanpay.dk`.

**Custom DB tables** (created in `install.php`, latin1):
- `scanpay_seq` — per-shop sync cursor (`seq`, `ping`, `mtime`).
- `scanpay_meta` — per-order transaction totals (authorized/captured/refunded/voided).
- `scanpay_subs` — subscription state (rev, retries, idempotency key, `nxt` lock).

Settings live in options `woocommerce_scanpay_settings` (card = primary/shared),
`woocommerce_scanpay_mobilepay_settings`, `woocommerce_scanpay_applepay_settings`.
The `WC_SCANPAY_URI_*` constants in `woocommerce-scanpay.php` are mostly
order/subscription meta keys, plus the settings option name
(`WC_SCANPAY_URI_SETTINGS`).

**Concurrency:** `Scanpay_Flock` (flock) serializes the sync loop; charges use
the `nxt` timestamp lock + idempotency key in `scanpay_subs`.

**Frontend (TS → esbuild):** `public/assets/js/checkout.ts` (Blocks checkout
registration), `admin/assets/js/settings.ts` (settings page sync/version checks),
`admin/assets/js/order.ts` (order meta box). Shared helpers in
`admin/assets/js/util/compat.ts` and `admin/assets/js/types/meta.ts`.

## Lifecycle

- `install.php` creates the three tables and seeds the shop row.
- `upgrade.php` runs version-gated migrations (settings-key renames, table
  rebuilds); tracked in the `wc_scanpay_version` option.
- `uninstall.php` drops all tables and options.
- Saving settings runs the shared `admin/settings/process-admin-options.php`
  (from `WC_Gateway_Scanpay_Base::process_admin_options`): when a gateway is
  being enabled, it validates the API key with a live `seq(0)` call and
  force-disables the gateway if the key is bad.
- Changing the API key in settings **drops and recreates the tables** (see
  `WC_Gateway_Scanpay_Card::process_admin_options`).

## Known incomplete (dev rewrite, ~90% done)

The root `TODO` file is the single source of truth for remaining gaps —
read it before assuming a feature is finished. Highlights: the subscription
meta box JS (`subs.ts`) is missing, the settings sync-status UI is unwired,
and `order.ts` is a stub.
