# JS/TS review — Scanpay for WooCommerce (`dev` / v3.0.0)

Scope: all `.ts`/`.js` under `src/admin/assets/js/**` and `src/public/assets/js/**`,
cross-checked against the PHP that injects `window.ScanpayOrderData` / `data-*`
attributes, renders the meta-box/settings DOM, and serves the `?x=meta|ping|sub`
and capture endpoints.

## Bottom line

**Nothing blocks shipping v3.0.0; no major bugs.** The three rewritten meta-box
scripts are correct on the load-bearing points, and the historical risks the plan
called out are resolved (see "Confirmed good"). All findings are minor/nit —
robustness, unhandled rejections, and UX polish.

## Confirmed good

- **Secret is header-only (task 8).** Every fetch sends the polling secret in the
  `X-Scanpay` header; nothing leaks it into the URL. `order.ts:134` (`?x=meta`),
  `subs.ts:73` (`?x=sub`), `compat.ts:22` (`?x=ping`). Grep for `?s=`/secret-in-URL
  across the TS tree: zero hits. Endpoints only read `$_SERVER['HTTP_X_SCANPAY']`.
- **No float math on money.** `order.ts` `fmtMoney`/`isZeroMoney`/`isMoney`
  (22-41) are pure string ops; the capturable test (73-77) compares two strings
  normalized to the same precision — no arithmetic. `subs.ts` only does
  `parseInt(x)*1000` on timestamps, not money.
- **Capture nonce flows in POST.** `order.ts:106-110` posts `action/oid/nonce`;
  `wp-ajax-wc-scanpay-capture.php` validates `ctype_digit(oid)` +
  `current_user_can('edit_shop_orders')` + `check_ajax_referer('scanpay-order-'.$oid,
  'nonce')` + `str_starts_with(payment_method,'scanpay')`. Button disabled before
  fetch, so no double-submit.
- **Blocks registration matches the server.** `checkout.ts` consumes exactly the
  shape from `class-wc-scanpay-blocks-support.php` (`methods{title,description,icons,
  supports,terms?}`). The `wcssp-terms` key (checkout.ts:45) matches the server
  validator. Per-iteration `const method`/`const terms` avoids loop-closure bugs;
  effect cleanups return the unsubscribe handles.
- **`settings.ts` historical throw resolved (task 2).** `#wcsp-set-nav-mtime`
  (settings.ts:39, non-null asserted) and `#wcsp-set-alert` (settings.ts:64) are now
  rendered by `admin-options.php`, and `settings.js` enqueues only on the plugin's
  settings screen.

## Minor

**1. `checkVersion().then()` has no `.catch()` → unhandled rejection.**
`meta.ts:31` (`pluginVersionCheck`) and `settings.ts:85`. `checkVersion`
(`compat.ts:44-45`) throws on any non-200; GitHub's unauthenticated API is rate-
limited (~60/hr/IP) and can be CSP-blocked, so 403s are common — each yields
"Uncaught (in promise)" on order/subscription/settings screens. Failures aren't
cached (`compat.ts` caches only on success), so every load re-hits GitHub. Also
`compat.ts:49` `tag_name.substring(1)` assumes a leading `v`. **Fix:** add
`.catch(() => {})` at both call sites. *(Pre-existing helpers.)*

**2. Leftover debug `console.log`.** `settings.ts:28` — `console.log(unixtime)` runs
on load and every `visibilitychange`. **Fix:** delete. *(Pre-existing.)*

**3. Meta-box alerts accumulate; capture button can stick (new code, task 3).**
`meta.ts:12-17` `showWarning` always appends and never dedups/clears. In
`order.ts` `onCapture` (116) shows "Capture requested. Updating figures…", then
`refresh()` (142) appends "Capture complete." — both persist. Worse: if `refresh()`
hits its timeout branch (`order.ts:149`, no rev bump within ~16.5 s), the success
path never re-enables the button, so it stays `disabled` reading "Capturing…" with
unchanged figures until reload — even though the capture succeeded server-side. Not
a correctness bug (server is authoritative), but confusing. **Fix:** clear prior
head alerts before re-render, and in the timeout branch reset the button / re-render
the foot to a "requested, pending sync" state.

**4. Unconditional `pluginVersionCheck()` + non-null DOM assertions (currently safe).**
`meta.ts:16,27` do `getElementById(...)!.…` on `#wcsp-meta-head`/`-ul`, which throw
if absent. `subs.ts:92` calls `pluginVersionCheck()` unconditionally, even in the
no-box path; only safe because `subs.js` is enqueued together with the box.
`order.ts` is more defensive (whole block gated on `if (dom && data)`). **Fix:** guard
`pluginVersionCheck()` on `box`, and make `meta.ts` helpers no-op when their target
is missing.

**5. `innerHTML` sinks receive unescaped server/backend data — not customer-exploitable.**
`meta.ts:16` (`div.innerHTML = msg`), `meta.ts:19-27` `buildTable`, `settings.ts:19`,
`order.ts:89` (`foot.innerHTML` with `data.dashboard` in an `href`). Traced every
value: order figures are `fmtMoney`(digits) + `currency` (ISO code); `data.dashboard`
is `WC_SCANPAY_DASHBOARD . rawurlencode(shopid)/rawurlencode(tid)` (numeric);
subs `payid`/`sub.method` and all alert messages come from the DB / Scanpay backend /
server response text — never from a checkout customer. **No XSS is exploitable by an
untrusted party** under the plugin's threat model (Scanpay backend trusted). Flag as
defense-in-depth only: these are generic HTML sinks, so a future caller passing
customer data would be vulnerable. **Optional:** use `textContent` for value cells /
escape in `buildTable`.

**6. `checkout.ts` icons rendered without `key` or `alt`.** `checkout.ts:81-86` —
`method.icons.map((icon) => createElement('img', { src, className }))` has no React
`key` (dev-console warning) and no `alt` (a11y). **Fix:** add `key: icon` and
`alt: ''`. *(Pre-existing.)*

**7. `canMakePayment` unconditionally returns `true`, including Apple Pay.**
`checkout.ts:14-16,96`. Scanpay's Apple Pay redirects to the hosted window rather
than the native Apple Pay JS API, so showing it on non-Apple browsers may let a
shopper pick a method that can't complete — possibly intentional (hosted window
gates it). **Uncertain** — product decision, not asserting a bug.

## Nit

**8.** `settings.ts:10-21,85-93` reimplement `meta.ts`'s `showWarning`/
`pluginVersionCheck`; justified (different DOM target `#wcsp-set-alert`), noted for
awareness.
**9.** `settings.ts:20` version-warning div gets `id="wcsp-set-alert-false"`
(`'wcsp-set-alert-' + false`). Cosmetic.
**10.** Dead injected data: `order.d.ts` declares `wc_total`/`tid`/`subid`/`shopid`/
`payid`, but `order.ts` uses only `oid`/`meta`/`currency`/`wc_decimals`/`secret`/
`dashboard`/`nonce`; `orders.php` ships the rest for nothing. Harmless payload bloat.
**11.** `settings.ts:86`/`meta.ts:32` pass the literal `'{{ VERSION }}'`;
`isVersionGreater` `Number()`s parts → `NaN` on unbuilt source or a non-numeric tag
(`3.0.0-beta`). Only affects unbuilt/pre-release; build.sh substitutes the real
version. Informational.

## Cross-cutting caveat (PHP, affects JS robustness)

The long-poll endpoints `echo "\n"` + `flush()` **before** `wp_send_json()` sets
headers (`wp-scanpay-fetch-meta.php:44-51`, `wp-scanpay-fetch-sub.php:48-55`).
`Response.json()` tolerates the leading whitespace, so the client parse works in
production. But once output is flushed, `wp_send_json`'s
`header('Content-Type: application/json')` hits the "headers already sent" path, and
with `WP_DEBUG` display on a PHP notice could be injected into the body and break
`res.json()` in `order.ts:137` / `subs.ts:77`. Fine with `WP_DEBUG` off (normal
production). Also tracked in `php-review.md`.
