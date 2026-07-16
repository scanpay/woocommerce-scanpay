# Issue prioritization — v3.0.0 backlog

Consolidates `php-review.md` (task 9), `js-review.md` (task 10), and
`scss-review.md` (task 11) into one ranked backlog. Grouped severity (blocker →
major → minor → nit). Each item: description · source review · `file:line` · fix.

## Ship gate — nothing blocks v3.0.0

All three reviews found **no ship-blockers**. The payment-critical paths are
verified correct: SQL construction (no injection), the ping HMAC, the task-8
header secret + `hash_equals`, the nonce-guarded capture handler, the
flock-serialized sync, the lock-free idempotent charge path, and the capture
money math. The remaining items are convention breaches, robustness/UX polish,
and dead code — none gate a release.

**Recommended before shipping — ✅ all five applied:**
1. ✅ Float money math in `orders.php` — `a1651c7` (dropped the unused, float-computed `wc_total`).
2. ✅ `renew()` wrapped in try/catch — `136a131`.
3. ✅ `.catch()` on `checkVersion()` — `1cd015d` (also dropped a dead `getLastSync` import).
4. ✅ Capture button no longer sticks on "Capturing…" — `511bc1c`.
5. ✅ Dead CSS deleted — `8a8d2c8` (meta.scss 219→74 lines; meta.css 2503→745 bytes).

Everything below that is not marked ✅ remains open.

## Blocker

None.

## Major — ✅ all fixed

| Issue | Review | file:line | Status |
| ----- | ------ | --------- | ------ |
| Float subtraction of money amounts (`get_total() - get_total_refunded()`), breaching "money is never a float"; display-only and unused by `order.ts`. | PHP | `admin/orders.php:108` | ✅ `a1651c7` — dropped the `wc_total` field (and its `OrderData` type) rather than reimplement an unused value. |
| `renew()` is called outside the try/catch that wraps `new_url()`, so a backend error shows the customer a raw `"504 server error"` on the method-change path. | PHP | `public/generate-payment-link.php:102` | ✅ `136a131` — wrapped in the same try/catch; logs the real error, rethrows the friendly message. |
| ~110 of `meta.scss`'s ~220 lines are dead after the meta-box rewrite: the `.wcsp-meta-li-card--*` and `.wcsp-meta-li-status*` families (0 refs in PHP/TS). Non-functional, but shipped to every customer. | SCSS | `meta.scss:64-79, 85-95, 97-156` | ✅ `8a8d2c8` — deleted all dead rules; 219→74 lines, compiled 2503→745 bytes. |

## Minor

| Issue | Review | file:line | Fix |
| ----- | ------ | --------- | --- |
| `checkVersion().then()` has no `.catch()`; GitHub 403/rate-limit → "Uncaught (in promise)" on order/subscription/settings screens; failures aren't cached so every load re-hits GitHub. | JS | `types/meta.ts:31`, `settings.ts:85` | ✅ `1cd015d` — no-op `.catch()` at both call sites (+ removed a dead `getLastSync` import). Failures are still uncached (open, minor). |
| Meta-box alerts accumulate (no dedup/clear) and, if the post-capture poll times out, the capture button stays `disabled` reading "Capturing…" with stale figures — though the capture actually succeeded. (New task-3 code.) | JS | `order.ts:116,142,149`; `types/meta.ts:12-17` | ✅ `511bc1c` — `refresh()` returns whether the sync landed; one terminal message per capture, button restored on timeout. Accumulation across *repeated* captures remains (open, nit). |
| Null `get_date_created()` → `->getTimestamp()` throws `\Error`, which `catch (\Exception)` does **not** catch → uncaught fatal (not the intended clean `failed`). Rare (renewals almost always have a date). | PHP | `library/class-wcs-scanpay-charge.php:192` | Guard the null date (fall back to `time()` or throw a labeled `RuntimeException` inside the `try`). |
| Missing `declare(strict_types=1)` on money/payment-critical files — most importantly `math.php` (silent int/float coercion into string helpers). `math.php` also lacks the `ABSPATH` guard. | PHP | `library/math.php` + gateways, install/upgrade/uninstall, settings | Add `declare(strict_types=1);` at least to `math.php` and `generate-payment-link.php`. |
| Order-id format check (`400`) runs before the capability + nonce gate (`403`) — minor pre-auth info disclosure. | PHP | `admin/hooks/wp-ajax-wc-scanpay-capture.php:21-31`; `wp-ajax-wc-mark-order-status.php:36-39` | Move the auth check to the top. |
| Long-poll endpoints hold a worker up to ~15.5 s (sub) / ~5.5 s (meta) without `set_time_limit` (ping handler sets 60). Secret-gated (no pre-auth DoS). | PHP | `admin/ajax/wp-scanpay-fetch-sub.php`, `-meta.php` | Add `set_time_limit()` sized to the backoff. |
| Leftover debug `console.log(unixtime)` runs on load + every `visibilitychange`. | JS | `settings.ts:28` | Delete. |
| `pluginVersionCheck()` called unconditionally (even the no-box path); `meta.ts` helpers use non-null `getElementById` assertions. Safe today (script enqueued with the box) but fragile. | JS | `subs.ts:92`; `types/meta.ts:16,27` | Guard on `box`; make the helpers no-op when the target is absent. |
| Fragile positional selectors key off settings-table row order; any field add/remove/reorder silently mis-targets. | SCSS | `settings.scss:13-37` | Re-anchor to stable hooks (`#woocommerce_scanpay_apikey` row via `:has()`, wrap the group) or comment each index. |
| `.wcsp-meta-acts-link` is dead **and** points at a non-existent `images/admin/link.svg` (the file is `images/link.svg`). | SCSS | `meta.scss:175-180` | Delete the rule. |
| Dead alert/loader rules: `.wcsp-meta-loading`, `.wcsp-loader` + `@keyframes l3`, `.wcsp-meta-alert-pending`, `.wcsp-meta-alert--spin` (no markup emits them). | SCSS | `meta.scss:43-50, 187-219` | Delete. |
| `innerHTML` sinks receive unescaped data — traced to server/backend/DB only, **not customer-exploitable** under the plugin's threat model. Defense-in-depth. | JS | `types/meta.ts:16,19-27`; `order.ts:89`; `settings.ts:19` | Optional: `textContent` for value cells / escape in `buildTable`. |
| Blocks icons rendered without React `key` (dev warning) or `alt` (a11y). | JS | `public/assets/js/checkout.ts:81-86` | Add `key: icon`, `alt: ''`. |
| `canMakePayment` always returns `true`, including Apple Pay on non-Apple browsers (Scanpay AP is a hosted-window redirect). Possibly intentional. | JS | `public/assets/js/checkout.ts:14-16` | Product decision — gate Apple Pay or confirm the hosted window handles it. |
| Long-poll endpoints `echo "\n"`+`flush()` before `wp_send_json()` sets headers; with `WP_DEBUG` display on, a notice can corrupt the JSON body and break `res.json()`. Fine in production. | PHP + JS | `wp-scanpay-fetch-meta.php:44-51`, `-sub.php:48-55` | Buffer, or send the `Content-Type` header before the first flush. |

## Nit

| Issue | Review | file:line | Fix |
| ----- | ------ | --------- | --- |
| `WC_SCANPAY_URI_AUTOCPT` and `URI_STATUS='free trial'` order meta are written but never read. | PHP | `generate-payment-link.php:171`; `class-wc-scanpay-sync.php:405` | Remove, or document the external consumer. |
| `count($c['acts'])` relies on an implicit `TypeError` — **consistent with the fail-loud-via-TypeError convention**, so acceptable; explicit `is_array` only improves the message. | PHP | `class-wc-scanpay-sync.php:252` | Optional. |
| `SHOW TABLES LIKE '$table'` interpolates an unescaped `$wpdb->prefix` (`_` is a LIKE wildcard). Trusted prefix → theoretical. | PHP | `install.php:8,26,51` | Use `$wpdb->esc_like()`. |
| `.wcsp-meta-alert-warning` / `-error` are byte-identical, and `.wcsp-meta-alert-info` is emitted ~6× but has **no** rule (info renders as warning-yellow). The dead `> strong` block was removed in `8a8d2c8`. | SCSS | `meta.scss` | Merge the duplicate; add a neutral `-info` rule. |
| `.wc-admin-header` unprefixed (against the file's own convention). | SCSS | `settings.scss:6` | Rename to `.wcsp-set-header`. |
| Dead injected data shipped in `ScanpayOrderData` but unused by `order.ts`. `wc_total` removed in `a1651c7`; `tid`/`subid`/`shopid`/`payid` still shipped. | JS | `admin/orders.php` / `order.d.ts` | Trim the rest of the payload. |
| `settings.ts` version-warning div gets `id="wcsp-set-alert-false"`; `{{ VERSION }}` → `NaN` in `isVersionGreater` only on unbuilt/pre-release. | JS | `settings.ts:20,86` | Cosmetic / informational. |
| Sibling nits: `ping.php` function redeclaration (can't recur), `getrusage()` Windows-only fatal (debug-only), `fetch-meta.php` missing trailing `die`, currency taken from `authorized` only, MobilePay Blocks icon has no size rule. | PHP/SCSS | see reviews | Low priority. |
