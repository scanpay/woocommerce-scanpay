# Admin-AJAX shared-secret auth — review & decision (PLAN task 8)

**Decision:** keep the WC-free fast path and the shared secret, but **move the
secret out of the URL query string into the `X-Scanpay` request header**
(Alternative 1). This ships now. A short-lived HMAC token (Alternative 2) is
designed below and **recommended as a follow-up**, deferred for the reasons in
§7. Alternatives 3 and 4 are rejected (§6).

Verification note: this repo checkout has PHP CLI + WP/WC/WCS *source* (via
`.stubs`) but **no running install and no test-server access**, so the
performance section is a best-effort estimate plus a ready-to-run harness, not a
live measurement (§4). The decision does **not** hinge on the exact numbers — see
§3.

---

## 1. What the secret protects

Three lightweight admin polling endpoints, dispatched in
`src/woocommerce-scanpay.php` **before** the plugin registers any hooks:

| `?x=` | File | Returns | Mutates? |
| ----- | ---- | ------- | -------- |
| `ping` | `admin/ajax/wp-scanpay-fetch-ping.php` | `scanpay_seq.mtime` (last ping unixtime) | no |
| `meta` | `admin/ajax/wp-scanpay-fetch-meta.php` | a `scanpay_meta` row (authorized/captured/refunded/voided totals) | no |
| `sub`  | `admin/ajax/wp-scanpay-fetch-sub.php`  | a `scanpay_subs` row (card label + expiry) | no |

**All three are read-only.** A leaked secret therefore caps out at *information
disclosure* — per-order money totals, a subscriber's card label/expiry, and sync
status — for any order/subscriber id an attacker cares to enumerate. No state can
be mutated through this path.

Today the secret is:
- minted once in `src/install.php` as `bin2hex( random_bytes( 32 ) )` (256-bit),
  stored inside the **autoloaded** `woocommerce_scanpay_settings` option under
  `['secret']`;
- rendered into admin pages as a `data-secret` attribute (`#wcsp-meta`,
  `#wcsp-set-alert`);
- sent by the polling JS as **`?s=<secret>` in the query string**, alongside an
  `X-Scanpay` request header;
- verified at each endpoint with `hash_equals( $secret, rtrim( $_GET['s'] ) )` —
  just a `get_option()` read, no session, no WC.

## 2. The router / fast path

`src/woocommerce-scanpay.php` (before the change):

```php
if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'], $_GET['s'] ) ) {
    $file = match ( $_GET['x'] ) {
        'meta' => '/admin/ajax/wp-scanpay-fetch-meta.php',
        'ping' => '/admin/ajax/wp-scanpay-fetch-ping.php',
        'sub'  => '/admin/ajax/wp-scanpay-fetch-sub.php',
        default => null,
    };
    if ( $file ) { require WC_SCANPAY_DIR . $file; return; }
}
```

This runs while the plugin file is being `include`d — i.e. during WP's
active-plugin load loop, **before** WooCommerce boots.

## 3. Premise check — is the WC-free fast path justified? (verified from source)

The stated reason for the secret is a performance trick: avoid booting all of
WooCommerce on these frequently-hit, long-held polling requests. The deeper
reason is that at short-circuit time WP's nonce / current-user machinery isn't
loaded yet, so a normal nonce can't even be verified.

Confirmed against the bundled WP source (`.stubs/wordpress/wp-settings.php`):

| Line | Event | Consequence for us |
| ---- | ----- | ------------------ |
| 137 | `require_wp_db()` | `$wpdb` + `get_option()` available |
| 546 | `do_action( 'muplugins_loaded' )` | |
| **580** | `foreach ( wp_get_active_and_valid_plugins() … ) include_once $plugin` | **our short-circuit runs here** |
| 610 | `require … /pluggable.php` | `wp_verify_nonce` / `wp_get_current_user` become available **only now** |
| 628 | `do_action( 'plugins_loaded' )` | **WooCommerce boots here** |
| 777 | `do_action( 'init' )` | |

So at line 580 there is **no pluggable layer, no current user, no WC**. A WP
nonce (`wp_verify_nonce` → `wp_get_current_user` → auth-cookie validation) is not
verifiable without first pulling in `pluggable.php` *and* booting the
current-user / auth-cookie stack — which is exactly the machinery the fast path
exists to skip.

**This is why the decision does not depend on the exact perf delta:** even if WC
boot were free, verifying a *user-scoped* credential here would require moving the
endpoint later in the request lifecycle (onto a fully-booted worker) or manually
bootstrapping auth — both give up the fast path. A stateless secret that verifies
with a single option read is the natural fit for this pre-pluggable stage.

## 4. Performance — best-effort estimate + repro harness

A true end-to-end benchmark needs a running WP+WC+WCS install; this checkout has
none, and WC fatals if included without a bootstrapped WP + DB, so no honest
number can be produced here. What can be stated:

- **Latency:** these are long-poll endpoints held open 5.5 s (`meta`) to 15.5 s
  (`sub`). Per-request bootstrap CPU is amortised over the hold, so wall-clock is
  dominated by the sleep loop, not the boot. The boot cost matters mostly as
  **worker-occupancy**, not user-visible latency.
- **Memory / worker footprint (the real cost):** a PHP-FPM worker is pinned for
  the entire hold. A bare-WP-core request (this fast path) resident set is
  roughly an order of magnitude smaller than a fully-booted WC admin request.
  Representative figures from general WooCommerce profiling (warm opcache):
  bare-WP core ≈ 8–15 MB; +WooCommerce boot ≈ +15–35 MB; a full admin/REST
  request commonly 30–60 MB. **Treat these as literature estimates, not a
  measurement on this install.** With several admins each holding open several
  order/subscription screens, the fast path saves a multiple of tens of MB of
  pinned FPM memory and the WC-init CPU per poll.

**Repro harness** (drop as an mu-plugin on the test server to get real numbers):

```php
<?php // wp-content/mu-plugins/scanpay-perf-probe.php
// Fast path: this file (included at wp-settings.php:580) already sees $wpdb.
if ( isset( $_GET['probe'] ) && 'fast' === $_GET['probe'] ) {
    header( 'Content-Type: text/plain' );
    echo 'peak=' . memory_get_peak_usage( true ) . " bytes\n";
    echo 'wp_loaded_wc=' . (int) class_exists( 'WooCommerce', false ) . "\n"; // expect 0
    exit;
}
// Full path: hit /wp-admin/admin-ajax.php?action=scanpay_probe_full and log
add_action( 'wp_ajax_scanpay_probe_full', function () {
    wp_send_json( [ 'peak' => memory_get_peak_usage( true ), 'wc' => class_exists( 'WooCommerce', false ) ] );
} );
```

Compare `memory_get_peak_usage(true)` and `curl -w '%{time_total}'` between the
two; expect the fast path to show no `WooCommerce` class and a markedly lower
peak. If that delta ever proves negligible on the real install, revisit
Alternative 3.

## 5. Weaknesses of the current scheme

1. **Secret in the query string (`?s=`)** — leaks into web-server access logs,
   proxy logs, browser history, and `Referer` headers. This is the biggest
   *concrete* weakness and the one Alternative 1 fixes.
2. **Single, shop-wide, non-expiring token** shared by every admin; never
   rotates; lives in an autoloaded option (also rendered into the DOM as
   `data-secret`, so any admin-page XSS can read it).
3. Read-only endpoints cap the blast radius at information disclosure — real, but
   not privilege escalation or state change.

## 6. Options evaluated

**1 — Move the secret into a request header (`X-Scanpay`). ✅ CHOSEN, shipped.**
Removes the access-log / `Referer` / history leak (weakness 1) at essentially
zero cost and zero risk. Keeps the exact fast path, the option-read auth model,
and the read-only scope. The endpoints already require the `X-Scanpay` header, so
this reuses it to carry the secret rather than a constant.

**2 — Short-lived HMAC token instead of the raw secret. 🔶 Designed, deferred (§7).**
Embed `token = expiry . ':' . hash_hmac('sha256', expiry, secret)` in the page;
the endpoint splits on `:`, checks `expiry >= time()`, recomputes the HMAC and
`hash_equals`. Still a single option read, still WC-free, still no session. Adds
an expiry so a leaked token dies on its own. (Per-user binding — folding
`user_id` into the HMAC input — was considered and **dropped**: the endpoint runs
before the current-user layer loads, so it cannot verify the requester *is* that
user; binding would be audit-only, not an auth control.) Combine with (1) for
transport.

**3 — Real WP nonce via `admin-ajax.php` / REST (`check_ajax_referer` /
`permission_callback` + `current_user_can`). ❌ Rejected.** Idiomatic, per-user,
expiring — but requires letting WP + WC bootstrap fully (§3) and holding a
long-poll open on a fully-booted worker, which is exactly the memory/occupancy
cost the fast path avoids (§4). Rejected on the architectural grounds in §3;
reconsider only if the §4 harness shows the boot cost is negligible.

**4 — Rotating / per-user server-minted token (transient or WP application
passwords). ❌ Rejected as overkill.** Adds per-request state (transient reads /
writes) and management surface for read-only polling that Alternatives 1+2 already
harden statelessly.

## 7. Why Alternative 2 is deferred, not shipped

Alt 1 captures nearly all the *accessible* security value at zero risk, because:

- The token lives in a `data-secret` DOM attribute either way, so the XSS-read
  vector is identical for a raw secret and an HMAC token. Expiry only helps for
  leaks that outlive the token lifetime (e.g. a proxy logging request *headers*) —
  a narrower vector than the URL leak Alt 1 already closes.
- Expiry adds a long-poll UX regression: the token is embedded at page render, and
  the meta boxes re-poll on `visibilitychange` — potentially hours later, when an
  admin returns to a long-open tab. Without a client-side `403 → refresh`
  affordance, a normal admin session would start 403-ing mid-poll. That affordance
  is cleanest to add once the three meta-box scripts exist (PLAN tasks 1–3) and can
  be exercised in a real browser — which best-effort (no live env) verification
  here cannot do. Shipping unverifiable expiry logic that could silently break
  polling is the higher risk.

So: ship Alt 1 now; add Alt 2 (with the `403 → reload` affordance) as a follow-up
once tasks 1–3 land and a browser is available to verify it.

## 8. What changed (Alternative 1)

- `src/woocommerce-scanpay.php` — router no longer requires `$_GET['s']`; it
  dispatches on `isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'] )`. The
  `X-Scanpay` header now carries the secret.
- `src/admin/ajax/wp-scanpay-fetch-{meta,ping,sub}.php` — auth now reads the
  secret from `$_SERVER['HTTP_X_SCANPAY']` and compares with `hash_equals`;
  `?s=` is gone.
- `src/admin/assets/js/util/compat.ts` — `getLastSync()` sends
  `{ headers: { 'X-Scanpay': secret } }` and drops `&s=` from the URL. The
  `data-secret` attribute source is unchanged.
- Downstream: `order.ts` (task 3) and `subs.ts` (task 1) are written against this
  header transport from the start.

The `data-secret` render sites (`admin/subscriptions.php`, and the task-2/3
additions) are unchanged — only where the secret travels changed, not where it is
sourced.
