# PHP review — Scanpay for WooCommerce (`dev` / v3.0.0)

Scope: every PHP file under `src/`, focused on the payment/sync/capture/charge
flows, money handling, HMAC + admin-AJAX auth, SQL on the custom tables,
concurrency, fail-loud error handling, and PHP 8.0 / `strict_types` compliance.
Findings were verified against the code before inclusion.

## Ship-blocker summary

**No hard PHP ship-blockers in the payment/sync/capture/charge flows.** Verified
clean:

- **SQL injection: none.** Every value interpolated into a `$wpdb` query is an
  `(int)` cast, a `ctype_*`-validated token, or a money string constrained by the
  `wc_scanpay_is_money` regex (`^-?[0-9]+(\.[0-9]+)?$`). All 30+ query sites checked.
- **HMAC (ping):** `hash_equals( base64_encode( hash_hmac('sha256', $body, $apikey,
  true) ), $sig )` — correct algorithm, known-value-first argument order, over the
  raw body (capped at 512 B).
- **Admin-AJAX secret path (task 8):** the three fetch endpoints and the capture
  handler compare with `hash_equals`; the secret is read from `X-Scanpay` (not the
  query string); empty-secret is denied. `admin-ajax.php` fires `admin_init` before
  `wp_ajax_*`, so the handlers are reachable.
- **Capture handler (task 3):** `current_user_can('edit_shop_orders')` +
  `check_ajax_referer('scanpay-order-'.$oid,'nonce',false)` matches the nonce minted
  in `orders.php`; re-fetches the order and re-checks the payment method; the amount
  is recomputed server-side in `WC_Scanpay_Capture` (client value ignored).

## Major

### 1. Float arithmetic on money — `admin/orders.php:108`
```php
'wc_total' => (int) wc_add_number_precision( $wco->get_total() - $wco->get_total_refunded() ),
```
`get_total() - get_total_refunded()` is float subtraction of two money amounts,
breaching the hard "money is never a float" rule in a file where `math.php` is
available. **Runtime impact is low** — `wc_total` is display-only *and* (per
`js-review.md` #10) `order.ts` never even reads it — but it violates the convention.
`get_total()` here also uses the default `'view'` context while the codebase
otherwise uses `get_total('edit')` (filter-exposed, inconsistent).
**Fix:** `wc_scanpay_submoney( (string) $wco->get_total('edit'), (string)
$wco->get_total_refunded() )`, then convert to minor units — or drop `wc_total`
entirely since nothing consumes it.

### 2. Uncaught `renew()` exception surfaces a raw API error to the customer — `public/generate-payment-link.php:102`
```php
if ( $subid ) {
    return [ 'result' => 'success', 'redirect' => $client->renew( $subid, $data ) ];
}
```
The subscription payment-method-change path calls `renew()` **outside** the
`try/catch` that wraps `new_url()` (lines 165-180). `renew()` throws
`\RuntimeException` on any transport/API error, so a transient backend failure
propagates a raw technical message (e.g. `"504 server error"`) to the customer,
instead of the friendly, logged message the `new_url` path produces.
**Fix:** wrap the `renew()` call in the same try/catch (log the real error, rethrow
the friendly `Exception`).

## Minor

### 3. Missing `declare(strict_types=1)` on money/payment-critical files
`library/math.php`, `public/generate-payment-link.php`,
`public/wp-scanpay-thankyou.php`, the four gateway files, `install.php`,
`upgrade.php`, `uninstall.php`, and the settings files lack the declaration CLAUDE.md
calls for on "most files." Most important is **`math.php`**: without strict types an
`int`/`float` passed to a `string`-typed money helper is silently coerced instead of
throwing — it removes a guardrail on exactly the code the project most wants
protected. (No active coercion bug today; all call sites pass strings/casts.)
`math.php` also has no `defined('ABSPATH')` guard (always reached via `require_once`
from guarded files, so low risk).
**Fix:** add `declare(strict_types=1);` at least to `math.php` and
`generate-payment-link.php`.

### 4. Null `get_date_created()` → **uncaught** fatal in the charge path — `library/class-wcs-scanpay-charge.php:192`
```php
$idem = $this->idempotency_key( $oid, $subid, $wco->get_date_created( 'edit' )->getTimestamp() );
```
If `get_date_created()` returns `null`, `->getTimestamp()` throws `\Error` — and the
surrounding `catch ( \Exception $e )` (line 194) catches only `\Exception`, so it is
**not** caught. It becomes an uncaught fatal rather than the clean
`update_status('failed')` the rest of the block relies on (Action Scheduler would
mark the action failed instead). Renewal orders essentially always have a creation
date, so this is a rare edge, but the failure mode is worse than a clean fail.
**Fix:** guard the null date (fall back to `time()` or throw an explicit labeled
`RuntimeException` inside the `try`).

### 5. Order-id format check runs before the auth gate — `admin/hooks/wp-ajax-wc-scanpay-capture.php:21-31` (also `wp-ajax-wc-mark-order-status.php:36-39`)
The `ctype_digit(oid)` check (→ `400`) precedes `current_user_can` + nonce (→ `403`).
No state is mutated pre-auth, so this is only a minor information-disclosure nit (an
unauthenticated caller can distinguish "numeric oid" from "non-numeric"). Best
practice is auth-first.
**Fix:** move the capability + nonce check to the top of the handler.

### 6. Long-poll endpoints hold a worker without `set_time_limit` — `admin/ajax/wp-scanpay-fetch-sub.php` (~15.5 s) / `wp-scanpay-fetch-meta.php` (~5.5 s)
Neither raises the execution-time limit (unlike the ping handler's
`set_time_limit(60)`). The sub endpoint's cumulative sleeps (0.5+1+2+4+8) approach a
common 30 s FPM limit once request overhead is added. Secret-gated, so no pre-auth
DoS — a robustness note. Minor inconsistency: `fetch-meta.php:39` uses `usleep()` for
values >1 s while `fetch-sub.php` switches to `sleep()` above 1 s (comment: "usleep is
only OS-safe below 1s"); both work on Linux.

## Nit

- **`public/generate-payment-link.php:171`** — `WC_SCANPAY_URI_AUTOCPT` order meta is
  written but **never read** anywhere (grep-confirmed: only the const + this write).
  Same for `WC_SCANPAY_URI_STATUS = 'free trial'` (`class-wc-scanpay-sync.php:405`).
  Dead meta writes; remove or document the external consumer.
- **`library/class-wc-scanpay-sync.php:252`** — `$nacts = count( $c['acts'] )` relies
  on `count()`'s implicit `TypeError` for a non-array, whereas the surrounding fields
  throw explicit labeled `RuntimeException`s. **This is consistent with the project's
  fail-loud-via-TypeError convention** (typed params preferred over defensive
  guards), so it is acceptable as-is; adding `is_array($c['acts'])` would only improve
  the diagnostic message. Optional.
- **`install.php:8,26,51`** — `SHOW TABLES LIKE '$table'` interpolates `$wpdb->prefix`
  unescaped; `_` in a prefix is a `LIKE` wildcard. Prefix is trusted (theoretical),
  but `$wpdb->esc_like()` would be correct.
- **`callback/wc-scanpay-ping.php:27-88`** — top-level functions defined inside a file
  `require`d from `wc_scanpay_handle_ping()`; a second invocation would fatal on
  redeclaration. The WC-API endpoint dispatches once per request, so it can't happen.
- **`callback/wc-scanpay-ping.php:59`** — `getrusage()` is unavailable on Windows;
  would fatal only if `WC_SCANPAY_DEBUG` were enabled on a Windows host. Debug-only.
- **`admin/ajax/wp-scanpay-fetch-meta.php:25,29`** — `wp_send_json()` without the
  trailing `die` that `fetch-sub.php` uses; harmless (`wp_send_json` exits), but the
  sibling endpoints are inconsistent.
- **`library/class-wc-scanpay-sync.php:257`** — currency is taken from
  `totals.authorized` only; the suffix on `captured`/`refunded`/`voided` is not checked
  to match. Same-transaction data, so effectively safe.

## Cross-cutting (also in `js-review.md`)

The long-poll endpoints `echo "\n"` + `flush()` **before** `wp_send_json()` sets its
`Content-Type` header (`wp-scanpay-fetch-meta.php:44-51`, `-sub.php:48-55`). Once
output is flushed, `wp_send_json`'s `header()` hits the "headers already sent" path;
with `WP_DEBUG` display on, a PHP notice can be injected into the body and break the
client's `res.json()`. Fine with `WP_DEBUG` off (production). Consider buffering or
sending the header before the first flush.

## Verified OK (non-findings)

- **Concurrency:** all `scanpay_meta`/`scanpay_subs` **writes** happen only inside
  `WC_Scanpay_Sync`, which runs under `Scanpay_Flock` in the ping handler — no
  write-write race. The charge path only reads these tables and is correctly
  lock-free, relying on the `orderid_rev_day` idempotency key (Scanpay-enforced ≤1
  charge/24 h) plus the local `is_paid()` / `scanpay_meta` guards. The busy-path ping
  hand-off (record `ping` → running worker drains → re-acquire) has no stranded-ping
  window that Scanpay's ping-retry doesn't cover.
- **Capture math** (`class-wc-scanpay-capture.php:76-92`) is correct: targets
  `net = total − wc_refunds`, subtracts already-captured-net, caps at
  `authorized − captured`, all via the string helpers, with a `≤0` short-circuit. The
  Scanpay `index` (= `nacts`) is a server-side optimistic lock against double-capture;
  a losing concurrent request throws and parks the order `on-hold` (never `failed`).
- **Fail-loud vs degrade** matches the convention: `transaction`/`charge` validation
  and `extract_amount` throw on malformed backend data (halting sync); display-only
  method/card parsing degrades to `'Scanpay'`/empty; not-found / shopid / currency /
  underpayment cases log + return rather than wedging the loop.
- **PHP 8.0:** no 8.1+ syntax anywhere (no enums, `readonly`, nullsafe `?->`, `never`,
  first-class-callable, `array_is_list`). `mixed`/`match`/`str_starts_with`/`CurlHandle`
  are 8.0-safe.
- The `data-secret` exposure on admin meta boxes and the shop-wide, non-expiring
  secret are the accepted trade-offs recorded in `secret-auth-review.md` (task 8), not
  new findings.
