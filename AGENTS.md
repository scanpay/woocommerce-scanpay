# Scanpay for WooCommerce — agent guide

> This is the Codex-facing project guide. It mirrors `CLAUDE.md`; keep the two in
> sync — when you change project facts in one, mirror them in the other.

WordPress/WooCommerce payment gateway plugin for the [Scanpay](https://scanpay.dk)
platform. Accepts card, MobilePay Online, and Apple Pay; full WooCommerce
Subscriptions support; HPOS- and Blocks-checkout compatible.

The `dev` branch is the `v3.0.0` rewrite (up from `2.9.x`), now feature-complete.
`PLAN.md` records the rewrite plan that produced it; the root `*-review.md` files
and `issue-prioritization.md` capture the post-rewrite code review and its ranked
backlog.

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
  the **test server** `woocommerce.scanpay-modules.dev` (ssh alias `modules`), rewriting
  prod hosts `*.scanpay.dk` → `*.scanpay.dev` for that copy only.
- Lint: `pnpm phpcs` (WordPress + WooCommerce-Core + PHPCompatibilityWP; short
  arrays enforced), `pnpm phpcbf` to autofix. JS/CSS: `pnpm lint:js` /
  `lint:style`. Prettier config in `.prettierrc.mjs` (TS `printWidth: 120`).
  `lint:js` globs the whole `src/` tree, and the flat `eslint.config.mjs`
  scopes rules to `src/**/*.ts`, so all admin + public TS (incl. `subs.ts`) is
  covered. Requires the `@eslint/js` dev dep.
- There is no webpack — the real build is esbuild in `build.sh`. `tsconfig.json`
  is type-check-only (`noEmit`, `moduleResolution: bundler`); its `include` globs
  govern what gets type-checked.

## Conventions

- **PHP 8.0 minimum**, `declare(strict_types=1)` at the top of most files.
  `docs/requirements.md` lists why each min version is what it is — respect it
  before using a newer API.
- WordPress function-based style (not OOP-heavy); files guard with
  `defined( 'ABSPATH' ) || exit();`. Function prefix `wc_scanpay_` / `wcs_scanpay_`.
- Text domain: `scanpay-for-woocommerce`. User-facing strings go through
  `__()`/`esc_html__()` with **English as the source language**; translations live
  in `src/languages/scanpay-for-woocommerce.pot` (regenerate with `pnpm i18n:po`).
  Settings-field *defaults* (checkout title/description) are English source strings
  a merchant localizes by editing the setting — `__()` cannot localize a stored value.
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
- `X-Scanpay` header (carrying the shared secret) + `?x=meta|ping|sub` →
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
   changes via `WC_Scanpay_Client::seq` (`GET /v1/seq/N`) in a flock-guarded loop
   until the local cursor reaches the pinged seq (see **Ping protocol** below).
3. `WC_Scanpay_Sync` validates each change, upserts the meta table, and calls
   `$order->payment_complete()`.
4. Capture: on `woocommerce_order_status_completed`, via the bulk actions in
   `admin/orders.php` / `admin/hooks/wp-bulk-actions.php`, via
   `admin/hooks/wp-ajax-wc-mark-order-status.php` (intercepts the admin
   "mark completed" AJAX *before* WooCommerce's own handler so capture happens
   before completion emails go out), or via the order meta box's "Capture" button
   (`wp_ajax_wc_scanpay_capture` → `admin/hooks/wp-ajax-wc-scanpay-capture.php`,
   nonce-guarded) → `WC_Scanpay_Capture::capture_or_hold`
   (`POST /v1/transactions/N/capture`). Refunds are **not** issued by the plugin
   (`can_refund_order()` is false); the order box links to the Scanpay dashboard,
   and refunded totals are reflected read-only via sync.
5. Subscription renewals: WCS scheduler (`woocommerce_scheduled_subscription_payment_scanpay`)
   → `WCS_Scanpay_Charge` (`POST /v1/subscribers/N/charge`, idempotency-keyed).

**Ping protocol** ([docs](https://docs.scanpay.dev/synchronization)) — `POST`,
body `{ seq: int, shopid: int }` (the plugin caps it at 512 bytes), header
`X-Signature: base64(hmac_sha256(body, apikey))`. `seq` is the shop's *current*
sequence number at ping time.

**Scanpay pings every 5 minutes**, not only when something changes. That keepalive
is the recovery backstop for the entire sync design: a ping that is dropped,
stranded, or rejected is re-covered within ~5 minutes by the next one, which
carries a seq above the local cursor and drains normally. The plugin therefore
keeps **no ping-level retry state of its own** — don't add recovery machinery
justified by "otherwise the ping is lost forever"; it isn't. Pings are retried
until we answer 200 and time out after ~7s — that last part is backend-team
knowledge, as the docs specify neither retry count, backoff, nor non-2xx handling.

`wc-scanpay-ping.php` branches on the pinged seq vs the local `scanpay_seq.seq`:

- `ping_seq < seq` → replayed / out-of-order ping, 400.
- `ping_seq === seq` → heartbeat: update `mtime` only, 200. **The common case for
  a quiet shop** — it's what the 5-minute keepalive normally hits, and what keeps
  the settings "last sync" indicator fresh.
- `ping_seq > seq` → drain: take the flock, re-read the cursor under it, and loop
  `seq(N)` until caught up. The target is `max( ping_seq, ping )`, so a run can
  drain past the ping that woke it — the handoff column counts. If `seq(N)` ever
  returns no changes while the target is still above the cursor, that contradicts
  the no-visibility-window guarantee, so the drain **throws** (500) rather than
  ack a sync that did not happen.

**`seq` is only valid when read under the flock** — an incumbent worker can advance
the cursor at any time, so the drain path re-reads it immediately after every
successful `acquire()`, and the pre-lock read only picks the branch above.

On flock contention the request records the pinged seq in `scanpay_seq.ping` and
answers 200 (that pinger will not retry), betting the incumbent worker drains it;
the incumbent re-checks that column before and after releasing the lock. This is a
**latency optimization over the keepalive**, not a correctness requirement.

**Client library** `WC_Scanpay_Client` (`src/library/class-wc-scanpay-client.php`,
curl-based, self-versioned "client lib" in its file header) is the only thing that
talks to `api.scanpay.dk`.

**Custom DB tables** (created in `install.php`, latin1):
- `scanpay_seq` — per-shop sync cursor (`shopid` PK, `seq` = last synced sequence
  number, `ping` = highest seq handed off to a busy worker, `mtime` = unix seconds
  of the last ping, which drives the settings "last sync" indicator).
- `scanpay_meta` — per-order transaction row (`orderid` PK, `shopid`, `subid`,
  `id`, `rev`, `nacts`, `currency`, and the money totals
  `authorized`/`captured`/`refunded`/`voided`).
- `scanpay_subs` — per-subscriber payment-method cache (`subid` PK, `rev`,
  `method` = payment-method *type* (e.g. `card`/`mobilepay`; not the pretty card
  label, which lives on the WC subscription's method title), `method_exp` = card
  expiry as unix seconds). Written by `WC_Scanpay_Sync`.
  **It does NOT store retries, an idempotency key, or an `nxt` lock** — the
  charge path only reads `rev` from it to build the idempotency key, and the subs
  meta box reads the row for display.

Settings live in options `woocommerce_scanpay_settings` (card = primary/shared;
`WC_SCANPAY_URI_SETTINGS`), `woocommerce_scanpay_mobilepay_settings`,
`woocommerce_scanpay_applepay_settings` (WC's default `woocommerce_{id}_settings`
convention). The `WC_SCANPAY_URI_*` constants in `woocommerce-scanpay.php` are
mostly order/subscription meta keys, plus the settings option name
(`WC_SCANPAY_URI_SETTINGS`). The shop's admin-AJAX auth `secret` lives inside the
settings option (minted in `install.php`), not a separate option, and is sent to the
polling endpoints in the `X-Scanpay` request header (see `secret-auth-review.md`).

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
`admin/assets/js/order.ts` (order meta box: figures + nonce-guarded capture),
`admin/assets/js/subs.ts` (subscription meta box: card method/expiry). Shared helpers
in `admin/assets/js/util/compat.ts` and `admin/assets/js/types/meta.ts` (the latter
exports runtime helpers `showError`/`showWarning`/`buildTable`/`pluginVersionCheck`,
not just types).

## Lifecycle

- `install.php` creates the three tables, seeds the shop row, and mints the
  admin-AJAX `secret` into the settings option when absent. On a **fresh install**
  it also stamps `wc_scanpay_version`, so the loader gate skips `upgrade.php`
  instead of running the `< 2.0.0` branch over a new shop and overwriting the
  gateway field defaults with the 1.x ones. "Fresh" is decided *before* the secret
  creates the settings option, and **absent settings is the discriminator, not an
  absent version**: 1.x never wrote `wc_scanpay_version` but did write the settings
  option, so gating on the version alone would permanently skip the 1.x migration
  (leaving `capture_on_complete` unconverted and auto-capture silently off).
  `register_activation_hook` fires on *every* activation, not just installs — the
  stamp is a no-op in install.php's other callers (`upgrade.php`, the reset
  endpoint, the card gateway's first-key save), which all run on a shop that
  already has settings, a version, or both.
- `upgrade.php` runs version-gated migrations (settings-key renames, table
  rebuilds, dropping legacy `woocommerce_scanpay_*` tables); tracked in the
  `wc_scanpay_version` option, stamped **last** so an interrupted run retries from
  the start. The loader gate catches `Throwable`, logs, and **deliberately keeps**
  the `wc_scanpay_updating` transient on failure: that throttles a failing upgrade
  to one attempt per 5 minutes instead of fataling `plugins_loaded` on every
  request, which would take out wp-admin and leave the merchant no way to react.
- `uninstall.php` drops all tables and options.
- Saving settings runs the shared `admin/settings/process-admin-options.php`
  (from `WC_Gateway_Scanpay_Base::process_admin_options`): when a gateway is
  being enabled (or a key is first set while enabled), it validates the API key
  with a live `seq(0)` call and force-disables the gateway if the key is bad.
  This is a **UX check only** — nothing destructive hangs off its result.
- **The API key is write-once.** It is never rendered back to the browser: the
  `apikey` field is a custom type handled by
  `WC_Gateway_Scanpay_Base::generate_apikey_html()` (masked display, no input
  once set) and gated by `::validate_apikey_field()` (WC dispatches
  `validate_{$key}_field` ahead of `validate_{$type}_field`). An empty POST means
  *unchanged* — WC's `validate_password_field()` would otherwise save the blank
  and wipe the key. Replacing a stored key is refused; use the reset button.
- **Deleting data is the reset button's job alone**, never a side effect of a
  save: `wp_ajax_wc_scanpay_reset` → `admin/hooks/wp-ajax-wc-scanpay-reset.php`
  (nonce + `manage_woocommerce`) drops the three tables, clears the key, disables
  every gateway, then re-runs `install.php` to recreate them empty. Safe because
  the tables hold only what the sync API can replay: entering a key for the same
  shop reseeds `seq=0` and the next ping rebuilds every row —
  `WC_Scanpay_Sync::upsert_meta()` runs *outside* the already-paid guard, so a
  replay restores the meta rows without re-firing `payment_complete()`.

## Post-rewrite status

The `v3.0.0` rewrite is feature-complete — the subscription meta box (`subs.ts`),
the settings sync-status UI, and the order meta box (inline warnings, version check,
capture/refund UI) are all wired up. The root `php-review.md`, `js-review.md`, and
`scss-review.md` capture a full post-rewrite code review, consolidated and ranked in
`issue-prioritization.md` (no ship-blockers; a backlog of convention/robustness/dead-
code cleanups). `secret-auth-review.md` records the admin-AJAX auth decision.

## Working in this repo

- Edit `src/`, never `build/` (it's regenerated by `./build.sh`).
- Keep `declare(strict_types=1)`, the `defined( 'ABSPATH' ) || exit();` guard, the
  `wc_scanpay_` / `wcs_scanpay_` prefixes, and money-as-decimal-string.
- Run `pnpm phpcs` (autofix `pnpm phpcbf`) for PHP and `pnpm lint:js` /
  `pnpm lint:style` for the frontend before considering a change done. `lint:js`
  covers all `src/` TS (eslint) but does not type-check — run `tsc` for that.
- A `.ts` file only ships once `build.sh`'s esbuild step emits its `.js` — webpack
  is not used.
