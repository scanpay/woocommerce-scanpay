# Performance and simplification review — `src/` (PHP)

Written 2026-07-27 against `bb59d01`. Scope: every PHP file under `src/` (35 files,
~5150 lines), read in full. No code was changed.

The question this answers is the one the merchant pays for: **what does this plugin
cost a shop that has it installed?** Findings are ranked by that, not by how
interesting they are. Simplifications that carry no resource claim are collected
separately at the end, and so are the things that were measured and deliberately
left alone.

## Conventions used here

- **Measured** — a number produced on this machine (PHP 8.3.29 CLI), method stated.
- **Derived** — read out of `.stubs/` (WordPress / WooCommerce / WCS source) and cited.
- **Unverified** — needs a running shop. Never presented as fact.

Nothing here was executed against a WordPress installation; there is none in this
repo. Every runtime claim is therefore derived or measured in isolation, and each
finding says which.

**Overlap with `PLAN.md`.** That plan is a correctness queue, not a performance one,
but three of its tasks touch this ground and are not repeated as findings here:

- **Task C** — the card constructor's forced form-field load. Kept below as §1.3
  anyway, because the performance rationale is not recorded there and it changes what
  the fix is worth.
- **Task L** — `wp_cache_flush()` called once *per migrated subscription* inside
  `upgrade.php:85`. Real, already queued, nothing to add: it runs once per upgrade.
- **Task A** — the text domain on the ping request. See §1.1 and §2.1; the fast path
  in §2.1 would have to carry task A's fix with it.

---

## 1. Cost on every request the shop serves

This is the part that scales with the shop's traffic rather than with its order
volume, so it is worth the most.

### 1.1 `load_plugin_textdomain()` on every request — checked, NO CHANGE

`woocommerce-scanpay.php:472-475` hooks `wc_scanpay_init()` to `init` priority 0 on
every request: front page, feed, REST, cron, admin. Most of those never render a
single plugin string, so this looks like an obvious eager-loading target. It is not,
any more:

- **WP ≥ 6.7**: `load_plugin_textdomain()` no longer loads anything. Its whole body is
  `$wp_textdomain_registry->set_custom_path( $domain, $path )` plus a `NOOP_Translations`
  reset (`wp-includes/l10n.php:999-1025`, "@since 6.7.0 Translations are no longer
  immediately loaded, but handed off to the just-in-time loading mechanism"). Cost:
  one array write.
- **WP 6.5–6.6**: `load_textdomain()` builds a `WP_Translation_File` but defers
  `parse_file()` until the first `translate()` (`l10n.php:726-860`,
  `l10n/class-wp-translation-controller.php:101-131`,
  `l10n/class-wp-translation-file.php:149-207`). Cost: a `realpath()` and an
  `is_readable()`, plus the failed stat for `WP_LANG_DIR/plugins/…` tried first.
- **WP 6.3–6.4** (our declared floor): a full MO parse on every request.

Hand-rolling `set_custom_path()` would buy something only on 6.3/6.4, and it would be
a reimplementation of what core does for us from 6.7. **Leave it alone**, and note
that this does not affect `PLAN.md` task A: the ping request returns before `init`
either way, so it needs its own explicit call regardless of what this line costs.

### 1.2 Four gateway class files are required on every request — LOW-MEDIUM

`woocommerce-scanpay.php:417-420` requires the base gateway plus all three concrete
gateways inside `wc_scanpay_plugins_loaded()`, i.e. on every request that reaches
`plugins_loaded` — and `class-wc-gateway-scanpay-card.php:7` pulls in
`library/functions.php` on top. That is ~600 lines of class definitions parsed,
linked and held in the per-request class table.

**How often are they actually used?** Less often than the requires suggest. The
gateway registry is a lazy per-request singleton (derived,
`includes/class-wc-payment-gateways.php:48-53,83-147`) and WooCommerce builds it only
where a gateway is needed: cart / checkout / add-payment-method / order-pay views
(`wc-template-functions.php:85-91`), every `?wc-ajax=` checkout action, **every Store
API cart response** (`StoreApi/Schemas/V1/CartSchema.php:377`), every `/wc-api/`
request (`Internal/Utilities/LegacyRestApiStub.php:155`), transactional emails
(`class-wc-emails.php:188`), order status transitions and refunds
(`wc-order-functions.php:771,884`), and most WC admin order/settings screens. Plain
shop, product, category and home-page views do **not** build it unless a Blocks
cart/checkout or `PaymentMethodIcons` block is on the page.

So on a content-heavy shop the majority of front-end requests parse and link four
classes they never instantiate.

**The classes are only ever named as strings.** `grep` over `src/` finds six
`WC_Gateway_Scanpay_*` references outside `src/gateways/`: three are prose in
docblocks (`wp-ajax-wc-scanpay-reset.php:24`, `admin-options.php:8`,
`fields/scanpay.php:23`) and the other three are the `::class` constants in
`wc_scanpay_register_gateways()` (`woocommerce-scanpay.php:101-103`). Nothing
instantiates them directly, and `::class` resolves at compile time without triggering
an autoload — so the requires can move into that callback, which WooCommerce calls
only when it actually builds the gateway registry.

**Measured cost of what would be skipped**: a warm `include` of a trivial file costs
**1.7 µs** (200 000 iterations, `opcache.enable_cli=1`, `validate_timestamps=0`), and
declaring a synthetic base class plus four subclasses with ~50 methods between them
costs **14.5 KB** of request memory. So the saving is on the order of *single-digit
microseconds and a few tens of KB per request* — small per request, but it is paid by
every request on every shop, and memory is the scarce resource on the shared hosting
this plugin lands on.

**Prerequisite, not optional**: `admin/orders.php` and `admin/subscriptions.php` call
`wc_scanpay_is_scanpay_order()`, and today they only get it because the card gateway
file happened to require `library/functions.php` first. Deferring the gateway requires
makes that implicit dependency load-bearing and wrong. Both files must require
`library/functions.php` themselves — which they arguably should anyway, per "the
`require` list is the linker".

**Risk**: third-party code doing `class_exists( 'WC_Gateway_Scanpay_Card' )` before
the registry is built would start getting `false`. That is not a documented contract
and WooCommerce itself resolves gateways through the registry, but it is a behaviour
change worth stating in the commit.

### 1.3 The card gateway's constructor can trigger `get_pages()` — MEDIUM

`class-wc-gateway-scanpay-card.php:29,32` call `$this->get_option( 'stylesheet' )`
and `$this->get_option( 'wc_complete_virtual' )` from the constructor.
`WC_Settings_API::get_option()` force-loads `get_form_fields()` for any key missing
from the stored option (`abstract-wc-settings-api.php:305-308`, cited in `PLAN.md`),
and our fields file runs `get_pages()` at the top
(`admin/settings/fields/scanpay.php:11`) to build the subscription-terms picker.

`get_pages()` is a `WP_Query` over every page (derived, `wp-includes/post.php:6519-6695`).
Its result is cached in the `post-queries` group salted by the `posts` last-changed
value (`class-wp-query.php:3258-3269`) — so **with no persistent object cache it is a
real database query on every request that calls it**, and with Redis it is served from
cache only until anything in `posts` changes. `get_page_children()` re-runs in PHP
either way.

On a store whose saved settings predate those two fields — every 2.x-upgraded shop
until the merchant re-saves the form — that fires **each time WooCommerce builds the
gateway registry**: every checkout view, every Store API cart response on a Blocks
store, every `/wc-api/` request, every transactional email, every WC admin order
screen. Emphatically not just the settings screen.

This is `PLAN.md` task C, framed there as a correctness/layering issue. The
performance angle is what makes it the highest-value item in this section: the fix
(read `$this->settings` directly, as `init_gateway_props()` already does) is three
characters of behaviour change and removes a page-table query from a hot path.

**Note the same shape elsewhere**: `needs_setup()` (`abstract-wc-gateway-scanpay-base.php:351-354`)
already reads the raw option instead of `get_option()`, which is correct and worth
keeping consistent.

### 1.4 The Blocks support object is built on every request — already handled, do not "fix"

Worth recording because it looks like a finding and is not.
`woocommerce_blocks_payment_method_type_registration` fires from
`IntegrationRegistry::initialize()` on **`init` priority 5, unconditionally**
(derived, `src/Blocks/Payments/Api.php:46`, `src/Blocks/Domain/Bootstrap.php:129`,
`src/Blocks/Integrations/IntegrationRegistry.php:50`) — on every request where
WooCommerce loads at all: front end, admin, admin-ajax, `?wc-ajax=`, REST, Store API,
cron and WP-CLI. So `wc_scanpay_register_blocks()` (`woocommerce-scanpay.php:107-112`)
requires `class-wc-scanpay-blocks-support.php` and constructs the object on every one
of them.

That cost is structural — WooCommerce's registry needs the object to exist — and the
plugin has **already** taken the only real mitigation available: `initialize()` is
deliberately empty (`class-wc-scanpay-blocks-support.php:12-16`), and the expensive
part (`get_payment_method_data()`, three option reads and the terms lookup) is on the
separate, conditional asset-data path (`Api.php:48-49,81-98`). Nothing to do here.

The one thing to keep in mind: this is the reason §1.2's deferral works out. The
Blocks class is required on every request no matter what, so if the gateway requires
move behind the registry callback, `class-wc-scanpay-blocks-support.php` must not
start depending on them.

### 1.5 `wc_scanpay_item_needs_processing()` loads the order once per line item — LOW

`library/functions.php:23-38` is a `woocommerce_order_item_needs_processing` filter, so
WooCommerce calls it **once per line item**, and it calls `wc_get_order( $order_id )`
each time. The cheap checks are correctly ordered first (`:25-30`), so this only bites
on orders whose items are virtual and not downloadable — but on such an order it is one
`wc_get_order()` per item.

**What that costs depends on the storage backend** (derived,
`includes/class-wc-order-factory.php:27-70`, `src/Caches/OrderCache.php:18-28`): with
HPOS on, order objects come from the `OrderCache`, so repeats are cheap. **With HPOS
off, `wc_get_order()` constructs and hydrates a fresh `WC_Order` on every call** — the
underlying rows are cached (`posts`, `orders` groups) but the object build is not.

A `static` memo keyed by order id inside the filter fixes it in three lines and is
safe: within one request an order does not change payment method. Alternatively hoist
the `wc_scanpay_is_scanpay_order()` result — the answer is per order, not per item.

### 1.6 Micro items, listed for completeness, not recommended on their own

- `WC_SCANPAY_URL` is defined at file scope (`woocommerce-scanpay.php:35`) via
  `plugins_url()` on **every** request, including pings, thank-you pages and admin
  AJAX that never emit a URL. `plugins_url()` runs `plugin_basename()` →
  `wp_normalize_path()` (two `preg_replace`) plus `set_url_scheme()` and two filters.
  A memoized `wc_scanpay_url()` helper would defer it, at the cost of turning a
  constant into a function call in ~15 places. **Unmeasured**; likely ~10 µs. Not
  worth the churn unless the file is being restructured anyway.
- `add_action( 'admin_menu', ... )` (`:502`) is registered on front-end requests too.
  One `add_action` call, ~1 µs. Mentioned only so it is not "found" again later.

---

## 2. The ping endpoint — the plugin's largest recurring cost

Scanpay pings every 5 minutes whether or not anything changed (`AGENTS.md`,
"Settled"), i.e. **288 requests per day per shop**, and by design the overwhelming
majority are heartbeats: `ping_seq === seq`, touch `mtime`, answer `ok`
(`callback/wc-scanpay-ping.php:192-199`).

### 2.1 A heartbeat pays for a full WordPress + WooCommerce bootstrap — HIGH

The dispatch at `woocommerce-scanpay.php:54-64` returns before the plugin registers
anything, which is correct as far as it goes — but the return only stops *our*
bootstrap. The request still runs everything else, and on a `/wc-api/` URL that is
more than "WordPress plus WooCommerce" (all derived):

- every other active plugin's file, `plugins_loaded`, `setup_theme`,
  `load_default_textdomain()`, `after_setup_theme`, `init`, `wp_loaded`, then
  `parse_request` (`wp-settings.php:595→799`, `class-wp.php:418`);
- **a WooCommerce session and cart.** `WooCommerce::init()` calls `wc_load_cart()`
  when `is_request( 'frontend' )`, and a `/wc-api/` URL qualifies — it is not admin
  and carries no `wp-json/` prefix (`class-woocommerce.php:686-687,986-988`). So the
  ping constructs `WC_Session_Handler`, `WC_Customer` and `WC_Cart`
  (`wc-core-functions.php:2519-2532`, `class-woocommerce.php:1239-1265`);
- **every payment gateway on the shop.** The handler itself calls
  `WC()->payment_gateways()` before dispatching
  (`Internal/Utilities/LegacyRestApiStub.php:155`) — so every gateway the merchant has
  installed, ours excepted (we returned early and never registered), is constructed on
  every heartbeat.

For a request whose entire useful work is *one indexed SELECT, one HMAC compare and
one UPDATE*.

**Stale reference — fixed 2026-07-27, recorded here for the reasoning**: `PLAN.md`
task A named `WC_API::handle_api_requests()` as what fires our action. That class no
longer exists: the Legacy REST API was removed in WooCommerce 9.0, and `/wc-api/` is
now served by `Internal/Utilities/LegacyRestApiStub` — `parse_legacy_rest_api_request()`
on `parse_request` priority 0 (`:36`) → `maybe_process_wc_api_query_var()` (`:133-174`),
which still fires `woocommerce_api_{$request}` at `:166`. The plugin works unchanged;
only the citation was wrong. If the dedicated Legacy REST API *extension* is installed,
the stub returns early (`:91-93`) and the extension dispatches instead — so the action,
not its dispatcher, is the stable contract. `AGENTS.md` said "the WC API endpoint",
which was loose rather than wrong; it now names the stub. No PHP file in `src/`
referenced the class.

**The available move**: handle the heartbeat at plugin-include time and `exit`.
Everything needed is ready by then — verified against `wp-settings.php`, where active
plugin files are included at `:595`: `$wpdb` (`:137,149`), the object cache (`:152`),
`get_option()` (`:118`), and the `alloptions` preload, which is already primed because
`wp_get_active_and_valid_plugins()` itself read `active_plugins` through it
(`wp-includes/load.php:1015`, `option.php:164,600`). `exit`ing there means the
remaining plugins are never included, `plugins_loaded` never fires, WooCommerce never
boots, no session or cart is built, no gateway is constructed and no query is parsed.
Anything that is not a plain heartbeat — a higher `seq`, a bad signature, a non-POST,
a missing cursor row — falls through to today's path unchanged.

**Two constraints on what the fast path may call** (derived, same source): `pluggable.php`
is loaded *after* the plugin files (`:610`), so `wp_get_current_user()` and friends do
not exist yet — the heartbeat needs none of them. And `__()` at that point triggers
just-in-time loading before `after_setup_theme`, which WordPress 6.7+ answers with
`_doing_it_wrong` (`l10n.php:1444-1455`) — so the fast path must emit only untranslated
strings, which `wc_scanpay_respond()` already does (`'ok'`, `'invalid signature'`).

**This is the single biggest saving available in the codebase.** Skipping a WP+WC
bootstrap 288 times a day is worth more than every other item in this document
combined.

**Three caveats, and the second is a blocker until it is answered:**

1. **Logging degrades.** At include time WooCommerce is not loaded — `woocommerce/`
   sorts after `scanpay-for-woocommerce/` in `active_plugins` — so `wc_get_logger()`
   does not exist and `scanpay_log()` is a no-op by design
   (`woocommerce-scanpay.php:38-47`). The heartbeat path logs nothing today either,
   so this only matters for the error branches, which would have to either respond
   without logging or fall through to the slow path. Falling through is the right
   answer: errors are rare, heartbeats are not.
2. **WP-Cron. `add_action( 'init', 'wp_cron' );`** — `wp-includes/default-filters.php:419`.
   On a low-traffic shop the 5-minute ping may be the *only* regular request that
   spawns cron, and Action Scheduler — which runs WooCommerce Subscriptions renewals —
   is driven by it. Short-circuiting every heartbeat would silently stop renewals on a
   quiet store. That is a far worse outcome than the CPU it saves.

   **Mitigation to design in from the start**: take the fast path only when a full
   bootstrap has happened recently, e.g. keep a `last_full` timestamp (a column on
   `scanpay_seq`, which the heartbeat already reads and writes) and fall through to
   the normal path when it is older than ~15 minutes. That keeps cron alive at 1 in 3
   heartbeats while still skipping the other two.

   **Unverified**: whether a given shop depends on ping traffic for cron at all
   (a real cron job or a busy shop makes the point moot). This must be settled on a
   live shop before shipping.

3. Security and observability plugins that expect to see every request would no
   longer see heartbeats. Worth a line in the changelog rather than a code change.

### 2.2 Debug log lines are written to disk in production — MEDIUM

WooCommerce's default log level threshold is `'none'`, i.e. **everything is stored**:
`Settings::DEFAULTS['level_threshold'] = 'none'` and `logging_enabled = true`
(`src/Internal/Admin/Logging/Settings.php:26-31`), consulted by
`WC_Logger::should_handle()` (`includes/class-wc-logger.php:115-122`). Retention
defaults to 30 days.

The plugin makes **14 `scanpay_log( 'debug', … )` calls** (55 `scanpay_log()` calls
total), several of them per-iteration or per-object:

- `callback/wc-scanpay-ping.php:325` — one line **per sync-loop round**, including the
  interpolated `elapsed` float.
- `class-wc-scanpay-sync.php:459,478` — one line **per subscription and per parent
  order** touched by a subscriber change.
- `woocommerce-scanpay.php:127` — one line per completed order.
- `class-wc-scanpay-capture.php:95,144`, `class-wcs-scanpay-charge.php:82,180`,
  `class-scanpay-flock.php:43`.

So a normal shop writes plugin debug lines to disk on every order completion, every
capture, every renewal and every sync round, forever, on a default configuration. It
is disk I/O and log volume that no one asked for — the merchant only ever looks at
these when Scanpay support asks them to.

**Proposed change**: gate the level inside `scanpay_log()` itself, so the call sites
stay as they are and the decision lives in one place:

```php
function scanpay_log( string $level, string $msg ): void {
    if ( 'debug' === $level && ! ( defined( 'WC_SCANPAY_DEBUG' ) && WC_SCANPAY_DEBUG ) ) {
        return;
    }
    ...
}
```

`WC_SCANPAY_DEBUG` already exists as the gate for the memory dump
(`callback/wc-scanpay-ping.php:91`), so this reuses an established switch rather than
inventing one. Merchants who set `WC_LOG_THRESHOLD` themselves are unaffected either
way.

**Trade-off**: support loses the debug trail from shops that have not set the
constant. That is a deliberate call — state it in the commit, and make sure the
settings screen's "Logs" link still surfaces the `error`/`warning`/`info` lines,
which it does.

**Cheaper variant if the trail is worth keeping**: leave the calls, but drop the
per-round `Sync loop: updated to seq …` line to `info` only when the loop actually
iterates more than once. That alone removes the highest-volume source.

### 2.3 `wp_cache_flush_group()` may evict a shared object cache — MEDIUM

`wc_scanpay_flush_order_runtime_cache()` (`callback/wc-scanpay-ping.php:82-87`) flushes
four groups every sixth backfill round. The comment there already admits both problems:
on a persistent drop-in (Redis/Memcached) `'orders'` is *not* registered
non-persistent, so this evicts full `WC_Order` objects **site-wide** — a cost paid by
every other visitor's request, not by the sync — and a drop-in without `flush_group`
support silently leaves the backfill with no memory bound at all.

`wp_cache_flush_runtime()` (WP 6.0, `wp-includes/cache.php:280`) is the API that means
"drop my in-memory copy, leave the shared backend alone": drop-ins that implement it
clear only local memory, and `wp_cache_supports( 'flush_runtime' )`
(`cache.php:314`, `cache-compat.php:198`) tells you whether the drop-in honours it.

**Proposed shape**:

```php
if ( wp_using_ext_object_cache() && wp_cache_supports( 'flush_runtime' ) ) {
    wp_cache_flush_runtime();   // local memory only; the shared cache survives
} else {
    wp_cache_flush_group( 'order_objects' );  // core array cache: today's behaviour
    wp_cache_flush_group( 'orders' );
    wp_cache_flush_group( 'orders_data' );
    wp_cache_flush_group( 'orders_meta' );
}
```

**Why not `wp_cache_flush_runtime()` unconditionally**: with no drop-in, core's
implementation *is* `wp_cache_flush()` (`cache.php:280-282`) — it would also drop
`alloptions` and force a re-read. Harmless but pointless, hence the branch.

**Checked and rejected**: `wp_suspend_cache_addition( true )` around the drain looks
like the elegant answer and is not one — it is consulted only in
`WP_Object_Cache::add()` (`class-wp-object-cache.php:199`), and WooCommerce's caches
write through `wp_cache_set()`, which ignores it.

---

## 3. The sync loop — what a backfill costs the shop

### 3.1 Every change builds a full `WC_Order`, including the ones that need none — HIGH

`WC_Scanpay_Sync::sync()` (`class-wc-scanpay-sync.php:245-253`) calls `wc_get_order( $oid )`
for **every** change, and then does nothing with it unless the order has no
transaction id yet:

```php
$wco = wc_get_order( $oid );
...
if ( empty( $wco->get_transaction_id( 'edit' ) ) ) {   // ← the whole body is inside this
```

A Scanpay transaction produces a change on **every** revision — the authorization,
then the capture, then any refund or void. Only the first of those needs the order
object. So on a shop in normal operation, **roughly half or more of the order loads in
the sync path are pure waste**, and during a full replay (after a reset, or a cursor
rewind) it is every order in the shop's history, one full object graph at a time. That
is precisely the memory pressure §2.3's periodic flush exists to contain — this
attacks the cause instead.

**It is worse on legacy storage.** With HPOS enabled, `wc_get_order()` can return a
cached object (`class-wc-order-factory.php:34-41`, `src/Caches/OrderCache.php:18-28`).
With HPOS **off** the factory constructs and hydrates a fresh `WC_Order` on every call
(`class-wc-order-factory.php:49`) — only the underlying rows are cached. The shops
least likely to have been migrated are the ones this hurts most.

**Proposed change**: pre-check the transaction id with a direct, uncached read and only
build the order when it is empty. `public/wp-scanpay-thankyou.php:55-69` already has
exactly this query, for both HPOS and legacy storage, with a docblock explaining why it
reads SQL rather than the order API. Lift it into `library/` and call it from both —
this is a simplification and a performance fix in the same edit (see §5.3).

**Trade-offs to weigh before doing it:**

- It puts knowledge of WooCommerce's storage layout in a second caller. Today there is
  exactly one, so extracting the helper first is what keeps it at one definition
  instead of two copies.
- The skipped `wc_get_order()` is also today's "order not found" detector
  (`:246-252`). The direct read returns no row in the same case, so the log line and
  the early return survive unchanged — but the replacement must keep them.
- **Verify on a shop**: that a capture-only change (rev 2 of a known transaction) takes
  the new path and still updates `scanpay_meta`, and that the meta box's figures
  refresh from it.

### 3.2 `upsert_meta()` costs two round-trips per change — LOW

`class-wc-scanpay-sync.php:153-172` does a `SELECT id` ownership probe and then the
`INSERT … ON DUPLICATE KEY UPDATE`. On a 10 000-change backfill that is 10 000 extra
round-trips. It *can* be folded into one statement by making the update conditional on
ownership (`rev = IF( id = VALUES(id), VALUES(rev), rev )`, then inspect
`rows_affected`).

**Do not take this one.** The ownership guard is the single thing standing between a
merchant-reference collision and one transaction's totals being spliced onto another's
row, and the two-statement form is what makes that readable and provably correct. One
extra indexed SELECT against a `PRIMARY KEY` is a rounding error next to the
`wc_get_order()` in §3.1. Listed so the idea is recorded as *considered and declined*,
not overlooked.

### 3.3 `subscriber()` rewrites meta rows that did not change — MEDIUM

`class-wc-scanpay-sync.php:454-484`, for every linked subscription on every subscriber
change:

```php
$wcs_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
$wcs_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
$wcs_sub->set_payment_method_title( $pm_title );
$wcs_sub->save();
```

`WC_Data::add_meta_data( $key, $value, true )` (derived,
`includes/abstracts/abstract-wc-data.php:489-508`) does **not** compare: it calls
`delete_meta_data( $key )` and then appends a *new* `WC_Meta_Data` with no id. On save
that is a delete plus an insert per key — two rewritten `meta` rows per subscription,
per change, for values that are identical 99% of the time. `save()` then also runs the
subscription's full save path and its hooks and cache invalidation.

(The `maybe_read_meta_data()` call on the same path is *not* a cost: order meta is
loaded eagerly with the order — `abstract-wc-order-data-store-cpt.php:163` and
`OrdersTableDataStore.php:1467` — so `get_meta()` reads an in-memory array
(`abstract-wc-data.php:415-442,621-625`). Comparing before writing is therefore free.)

A subscriber change arrives whenever Scanpay's stored payment method changes — a card
refresh, an expiry update — and the loop runs once per subscription named in the
`wcs[]` reference, so a customer with several subscriptions pays it several times over.
`set_payment_method_title()` is already well-behaved — `WC_Data::set_prop()`
records no change when the value is unchanged — so only the meta writes and the
unconditional `save()` need guarding:

```php
$dirty = false;
if ( (int) $wcs_sub->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) !== $subid ) { … $dirty = true; }
…
if ( $dirty || $wcs_sub->get_changes() ) { $wcs_sub->save(); }
```

**Unverified**: whether WCS hooks anything to the subscription save that a shop
depends on firing per sync (it should not — the sync is not a state change when
nothing changed — but WCS is a large surface).

---

## 4. Admin cost

### 4.1 Two files and ~14 hooks are loaded on every admin request — MEDIUM

`wc_scanpay_admin_init()` (`woocommerce-scanpay.php:482-490`) unconditionally requires
`admin/orders.php` and `admin/settings.php` — plus `admin/subscriptions.php` when WCS
is active — on **every** `admin_init`. Between them they register bulk-action filters
that only matter on the order list, meta-box hooks that only matter on an order edit
screen, two `wp_ajax_` handlers, an `admin_enqueue_scripts` callback and an
`admin_footer_text` filter.

**`admin_init` is not just page loads** (derived): it fires from `wp-admin/admin.php:180`,
**`wp-admin/admin-ajax.php:45`** and `wp-admin/admin-post.php:27`. So every
`admin-ajax.php` hit pays for all of it — including WordPress's own heartbeat, which
is enqueued on essentially every admin screen as a dependency of `wp-auth-check`
(`script-loader.php:865-879`, `functions.php:7523-7553`) and ticks every **60 s**
(`heartbeat.js:63`), 120 s when the window is unfocused (`:511-512`), and every **10 s**
in the block editor (`edit-form-blocks.php:191-198`).

A merchant with the orders list open in one tab and the block editor in another is
therefore loading two or three of our PHP files and registering ~14 hooks **roughly
every 10 seconds, all day**, purely for heartbeat ticks that can reach none of those
hooks — before counting WooCommerce's own admin AJAX traffic. (REST and `?wc-ajax=`
do *not* fire `admin_init`; those paths are unaffected.)

**Proposed change**: keep one `admin_init` entry point, but branch inside it —
`wp_doing_ajax()` needs only the two `wp_ajax_` registrations; everything else is for
rendered screens. Cheap, mechanical, and it does not disturb the "the `require` list
is the linker" model.

**Watch out**: `wc_scanpay_mark_order_status` and `wc_scanpay_ajax_capture` must stay
registered on the AJAX path, and `wc_scanpay_admin_add_version`
(`admin/settings.php:12`, filter `woocommerce_admin_shared_settings`) is consumed by
WC's own admin JS, which does load over AJAX — check that one specifically before
moving it behind a branch.

### 4.2 The long-poll endpoints hold a PHP worker — accepted, documented here

`admin/ajax/wp-scanpay-fetch-meta.php` sleeps up to 5.5 s and
`wp-scanpay-fetch-sub.php` up to 15.5 s while holding an FPM worker. That sounds
alarming and mostly is not, because both are gated on the client already knowing the
current revision:

- `order.ts` only calls `refresh()` after a capture click, and then at most 3 rounds —
  worst case ~16.5 s of worker time spread over three sequential requests, once per
  merchant click.
- `subs.ts` polls with `rev=0` (`subs.ts:75`), so `$rev >= $sub['rev']` is false for any
  real row — a synced subscriber always has `rev >= 1` — and the loop is skipped
  entirely.

So worker occupancy is user-triggered and bounded, not continuous. **Worth keeping in
mind rather than fixing** — but note that `fetch-sub.php`'s 15.5 s ceiling has no
caller that can currently reach it, which makes it dead weight in the risk budget. If
it stays, it should be justified in the comment; if it does not, cutting it to the
meta endpoint's 5.5 s costs nothing today.

### 4.3 `is_classic_checkout()` parses the checkout page on every checkout render — LOW

`class-wc-gateway-scanpay-applepay.php:31-40` calls
`WC_Blocks_Utils::has_block_in_page( wc_get_page_id( 'checkout' ), 'woocommerce/checkout' )`
on every `wp_enqueue_scripts` for a checkout request while Apple Pay is enabled. That
fetches and parses the checkout page's blocks. It is once per checkout page view, and
the result cannot change within a request — a `static` memo inside the method is a
one-line change if it ever shows up in a profile. **Not recommended on its own.**

---

## 5. Simplifications (no resource claim attached)

These are about lines of code and drift risk. They are worth doing on their own terms,
and §5.3 also carries the §3.1 payoff.

### 5.1 The payment payload is built twice — ~70 duplicated lines

`public/generate-payment-link.php:76-101,169-202` and
`library/class-wcs-scanpay-charge.php:209-279` build the same `billing` / `shipping`
arrays and run the same item loop with the same `wc_scanpay_addmoney()` summation and
the same "sum does not match the total" fallback, down to an identical log sentence.

**They have already drifted**: the payment-link path runs
`wc_scanpay_phone_prefixer()` on the billing phone; the charge path sends the raw
phone. Whether that is deliberate or an oversight is not recorded anywhere — which is
the argument for one definition.

Extracting `wc_scanpay_order_payload( WC_Order $wco ): array` into `library/` fits the
module model exactly: a free function, primitives in and out, required by the two
callers that need it.

### 5.2 The three AJAX endpoints repeat their auth preamble

`admin/ajax/wp-scanpay-fetch-{meta,ping,sub}.php` open with the same eight lines
(`get_option`, `$secret`, `hash_equals` against `HTTP_X_SCANPAY`, derive `$shopid`) and
then diverge on how they fail: two use `wp_send_json`, one hand-rolls `status_header`
plus `echo`. One `admin/ajax/auth.php` returning the settings array — required by all
three, `require`-as-a-call in the established idiom — removes the repetition and makes
the failure shape uniform.

### 5.3 One direct order read, two callers

Covered in §3.1: `wc_scanpay_thankyou_read()` (`public/wp-scanpay-thankyou.php:55-69`)
is a general helper wearing a page-specific name. Moved to `library/` it serves the
thank-you wait and the sync fast-path, and the HPOS/legacy branch stays defined once.

### 5.4 One spelling for "is this ours" and "what shop is this"

- `wc_scanpay_is_scanpay_order()` exists (`library/functions.php:44-46`) but four call
  sites open-code it instead: `class-wc-scanpay-sync.php:254`,
  `admin/hooks/wp-ajax-wc-mark-order-status.php:42`,
  `admin/hooks/wp-ajax-wc-scanpay-capture.php:36`, `upgrade.php:69`.
- The shop id is derived from the API key in eight places, in **two different idioms**:
  `(int) strstr( $apikey, ':', true )` (ping, capture, charge, admin-options, all three
  AJAX endpoints) and `(int) explode( ':', $apikey )[0]`
  (`class-wc-gateway-scanpay-card.php:83,85`, `install.php:70`). A
  `wc_scanpay_shopid( string $apikey ): int` helper settles it — but note it must live
  somewhere the AJAX endpoints can cheaply reach, and they deliberately load almost
  nothing, so `library/functions.php` may be the wrong home. Possibly not worth it;
  picking *one* of the two existing idioms and applying it everywhere is.

### 5.5 Small local ones

- `class-wc-scanpay-sync.php:226-231`: `$subid_col` / `$subid_val` splice column names
  into the SQL. Since `subid` is nullable, the column list can be constant and the
  value `$subid ?? 'NULL'` — two fewer variables, one fixed statement.
- `install.php:11-67`: three near-identical `SHOW TABLES LIKE` + `CREATE TABLE` + error
  blocks. A loop over a `[ $suffix => $ddl ]` map removes ~25 lines and leaves one
  error path. The DDL stays literal and readable.

---

## 6. Measured, and deliberately left alone

Recording these so they are not re-opened.

### 6.1 `wc_scanpay_is_money()` — keep `preg_match`

`library/math.php:23-25` uses `preg_match( '/^-?[0-9]+(\.[0-9]+)?$/D', $s )`. The house
preference is `ctype_*`/`strpos` over regex, decided on data. Here the data says regex:

| implementation                                   | per call | ratio |
| ------------------------------------------------ | -------: | ----: |
| `preg_match` (current)                            | 0.038 µs |  1.0× |
| `ctype_digit` + `strpos`/`substr` equivalent      | 0.076 µs |  2.0× |

3 000 000 calls over a 10-value sample (valid, negative, zero, multi-digit-fraction,
and four invalid forms), PHP 8.3.29 CLI. The hand-rolled version needs a sign strip, a
`strpos`, two `substr`s and two `ctype_digit` calls to match the regex's semantics — it
loses on call overhead alone. The two implementations were cross-checked for identical
answers on 17 edge cases (`-0`, `1.`, `.5`, `+5`, leading space, trailing newline,
`1e3`, `--5`, `-`, `''`) before timing. **PCRE wins; leave it.**

### 6.2 `library/math.php` internals — no change

The digit-string algorithms could be replaced with scaled-integer arithmetic (parse to
`int`, add, reformat) with a string fallback above 18 digits. It would be faster and
shorter. It is also the code that decides what a customer is charged, it is marked
settled in `AGENTS.md`, and the call volume is a handful of operations per payment —
the win is unmeasurable in a request and the risk is not. **No.**

### 6.3 The thank-you busy-wait

`public/wp-scanpay-thankyou.php:99-124` holds a worker up to ~3.5 s (17 rounds,
backing off) per paid order. That is the deliberate trade: a worker for a few seconds
against a thank-you page that renders without payment data. It is bounded, it is
gated on the order key before it sleeps, and it overlaps the redirect from the payment
window. **No change** — but if worker starvation is ever reported on a high-volume
shop, this is the first thing to look at, and the 3.5 s ceiling is the dial.

### 6.4 The flock, the client, capture money math, the ping protocol

Read, nothing to add. `WC_Scanpay_Client` reuses one `CurlHandle` across the whole
sync drain with `CURLOPT_TCP_KEEPALIVE` and a 180 s DNS cache — that is already the
right shape for a backfill of hundreds of sequential API calls.

---

## Suggested order of work

| # | Item | Section | Size | Payoff |
| - | ---- | ------- | ---- | ------ |
| 1 | Gate `debug` logging behind `WC_SCANPAY_DEBUG` | §2.2 | tiny | every shop, every order, forever |
| 2 | Read `$this->settings` in the card constructor (`PLAN.md` task C) | §1.3 | tiny | removes a `get_pages()` query from checkout, Store API cart responses and WC admin |
| 3 | Branch `admin_init` on `wp_doing_ajax()` | §4.1 | small | every heartbeat tick from every open admin tab |
| 4 | Skip the `wc_get_order()` for already-paid changes | §3.1 | medium | the sync path's dominant cost; worst on non-HPOS shops |
| 5 | Guard the subscriber meta rewrites | §3.3 | small | two fewer row rewrites per subscription per renewal |
| 6 | Memoize the order lookup in `wc_scanpay_item_needs_processing()` | §1.5 | tiny | one order build per line item on virtual orders |
| 7 | `flush_runtime` when the object cache supports it | §2.3 | small | stops a backfill evicting shared Redis site-wide |
| 8 | Move the gateway requires into the registry callback | §1.2 | small | most front-end page views |
| 9 | Heartbeat fast path | §2.1 | **large** | by far the biggest — **blocked on the WP-Cron question** |

Items 1–8 are independent of each other; 1, 2, 3 and 6 are each an afternoon at most.
Item 9 is worth more than 1–8 combined — it removes a full WordPress + WooCommerce
boot, a session, a cart and every installed payment gateway from 288 requests a day —
and should not be attempted until someone has answered, on a real shop, whether ping
traffic is load-bearing for that shop's cron.

Item 8 has a prerequisite (§1.2: `admin/orders.php` and `admin/subscriptions.php` must
require `library/functions.php` themselves) that is worth landing on its own regardless
of whether the deferral follows.
