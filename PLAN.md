# PHP review follow-up plan

<!-- markdownlint-disable MD024 -->

Twelve tasks, **A** through **L**, in the order they must be done. One commit
each, titled `<summary> (task X)`. No `scanpay:` prefix — see Conventions in
`AGENTS.md`.

## How to run this plan

This runs unattended, by one agent, in one pass. Nobody is watching, so
everything here answers a question you cannot ask.

Implement every task yourself, in order. Do not parallelize, do not delegate
implementation to subagents, do not start a task before the previous one is
committed, and never put two tasks in one commit. Read-only search agents are
fine.

For each task:

1. Read `AGENTS.md`, then `HANDOFF.md` if it exists — an earlier task may have
   left this one blocked, and nothing else records that. Then this header and
   the whole task section: grep for `## Task X` and read from there. Do not
   slurp the file; it is around the size where a whole-file read comes back
   truncated, and you would silently get its first half.
2. Confirm the working tree is clean and the previous task's commit is `HEAD`.
   For the first task of the run there is no such commit: `HEAD` is then the
   commit this file was last revised in — `git log -1 --oneline -- PLAN.md`.
3. Implement it, in `src/` only — `build/` is generated.
4. Run `pnpm phpcs` (autofix `pnpm phpcbf`) and
   `find src -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l`,
   plus `pnpm lint:js` and `pnpm exec tsc` if any `.ts` changed, and
   `pnpm lint:style` if any `.scss` changed. All clean. `pnpm phpcs` is clean at
   the start of this run, so any diagnostic it prints is yours.
5. Do the task's **Verify** bullets, and write those findings plus its
   **Handoff** list into `HANDOFF.md` at the repo root. That file is gitignored
   — never `git add` it, and create it if it is not there. It is absent as this
   run begins, so task A starts it; do not go looking for an earlier run's
   copy. Head each entry `## Task X — <summary>`, so the entries stay
   distinguishable if a later run appends its own A–L to the same file.
6. Delete the task's section from this file. Every section is written to stand
   on its own, so no later task needs one back — but if you do want a landed
   task's reasoning, it is in `git log -p -- PLAN.md`, not lost.
7. `git add -A` and commit. Keep verification status out of the message.
8. Start the next task in a fresh context — a new session, or `/clear` — at
   step 1. Nothing carries over but the committed tree and this file.

When the last task lands, this file is its header alone. Close `HANDOFF.md` with
what remains to verify on a real shop, and stop. Never push, never open a PR.

**Anchors are not identifiers.** Line numbers here were correct when written and
earlier tasks will move them. Locate the cited symbol; when a number disagrees
with the source, the source wins.

**Blocked?** A task is blocked when the source contradicts its premise in a way
that changes the fix, or when step 4 cannot come clean. Do not improvise a
different design and do not commit a partial task: restore the tree, record what
you found in `HANDOFF.md` under a `## Blocked: task X` heading so step 1 finds
it, leave the section in place, and move on to the next task that does not
depend on it. "Hard" is not "blocked".

**Never run `./build.sh` bare.** It is `set -e` and prompts for a deploy, so EOF
exits non-zero and looks like a failed build. Use `printf 'n\n' | ./build.sh`.
Answering `y` rsyncs to a live test server.

## What you can verify

There is **no WordPress installation here** — no site, database, browser or
multisite network. You cannot observe a status change, send a ping or render a
checkout. So each task splits its checks:

- **Verify** — do it and report it. Reading upstream source counts; so does
  tracing a path and stating the reachable outcome.
- **Handoff** — only a real shop can settle it. Do not perform, do not simulate,
  do not claim.

Fabricating a handoff result, or calling a task verified because its static half
passed, is worse than leaving it open.

Upstream source is in `.stubs/` — read it instead of guessing. It is gitignored
and machine-local, so if it is not there the run is blocked; that is not licence
to answer an upstream question from memory. WooCommerce
citations resolve directly (`.stubs/woocommerce/includes/class-wc-order.php`),
except the abstracts, which sit one level down
(`.stubs/woocommerce/includes/abstracts/abstract-wc-payment-gateway.php`) and
the HPOS internals, which sit under `.stubs/woocommerce/src/Internal/`. WordPress
core is `.stubs/wordpress/wp-includes/` (and `wp-admin/includes/`). WCS ones sit
under `.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/`.
The WooCommerce stub is `11.1.0-dev`, the Subscriptions stub `7.2.1` and the
WordPress stub `7.1-alpha`, so they settle "what does current WordPress and
WooCommerce do", never "what does the 3.6 / 6.3 minimum do". Released 2.x
behaviour is in tags `v2.0.0`–`v2.9.1`. `php -r` settles PHP semantics
empirically — to load `src/library/math.php` directly,
`define( 'ABSPATH', … )` first, or the file `exit()`s and prints nothing.

These upstream and in-tree facts have already been read out of `.stubs/` and the
sources rather than inferred. Re-read the citation, but do not re-derive the
conclusion from scratch:

| Task | Fact | Where |
| --- | --- | --- |
| A | `get_option()` force-loads `get_form_fields()` for a key missing from the stored option; `$empty_value` is only applied to a *stored* `''` | `includes/abstracts/abstract-wc-settings-api.php:305-308`, `:310-312` |
| A | `init_settings()` merges the field defaults only when the stored option is not an array | `includes/abstracts/abstract-wc-settings-api.php:280-288` |
| B | `WC_Settings_API::process_admin_options()` is untyped, and `WC_Payment_Gateway` does not override it | `includes/abstracts/abstract-wc-settings-api.php:206` |
| C | Just-in-time l10n searches only `WP_LANG_DIR/plugins`, `WP_LANG_DIR/themes` and `custom_paths[$domain]` | `wp-includes/class-wp-textdomain-registry.php:286-297` |
| C | The ping requires only the client, sync and flock — not capture, not charge | `src/callback/wc-scanpay-ping.php:201-203` |
| D | `plugin_basename()` maps a symlinked plugin path back through `$wp_plugin_paths`, and `WP_Plugins_List_Table` builds the action-links hook from the same relative path | `wp-includes/plugin.php:776-797`, `wp-admin/includes/class-wp-plugins-list-table.php:1162` |
| E | `WC_Order::set_status()` itself fires `woocommerce_order_edit_status` when `$manual_update` is true — since 3.6.0, this plugin's floor | `includes/class-wc-order.php:330-332` |
| E | `update_status()` is `set_status( …, $manual )` + `save()`, so every upstream admin handler fires the action **twice**: once pre-save from `set_status()`, once explicitly post-save | `includes/class-wc-order.php:402-409`, `src/Internal/Admin/Orders/ListTable.php:1567-1568`, `includes/admin/list-tables/class-wc-admin-list-table-orders.php:507-508`, `includes/class-wc-ajax.php:675-676` |
| E | `capture_or_hold()`'s failure path parks the order with `update_status( 'on-hold', …, true )`, so it emits an `'on-hold'` fire of its own | `src/library/class-wc-scanpay-capture.php:160-168` |
| F | `wc_format_decimal( null, $dp )` returns `''` — `$number = $number ?? ''` then an early `return ''` | `includes/wc-formatting-functions.php`, head of `wc_format_decimal()` |
| F | Checkout puts a caught exception message in front of the customer | `includes/class-wc-checkout.php:1419-1422` |
| H | HPOS `order_key` is nullable | `src/Internal/DataStores/Orders/OrdersTableDataStore.php:3512` |
| I | `settings.ts` reloads the page after a successful reset | `src/admin/assets/js/settings.ts:135` |
| J | `WC_Data::save_meta_data()` ends by deleting the object's own meta cache entry | `includes/abstracts/abstract-wc-data.php:804-806` |
| J | `wp_cache_flush()` arrived with the 2.1.3 migration itself, uncommented and unexplained | `git show 62b3535 -- includes/upgrade.php` |

## Do not weaken

Money-string arithmetic (`src/library/math.php`), the ping protocol, the sync
flock, the API-key write-once policy, the lock-free subscription charge design.

Standing decisions, so no task reopens them:

- **Renewal retry pacing is solved.** `wcs_scanpay_retry_rule()` (filter
  `wcs_get_retry_rule_raw`) raises WCS's `retry_after_interval` to a ≥25h floor
  on Scanpay orders, because the idempotency key's day bucket only advances
  after 24h and an earlier retry would replay the cached decline. Do not add a
  second mechanism.
- **The subscription terms checkbox is cart-level consent**, covering whichever
  gateway the customer picks — third-party ones included, and while our own are
  disabled. Its predicate is `wcs_scanpay_terms_url()`, and the Blocks payload
  carries `$data['terms']` outside `$data['methods']` and outside the `enabled`
  gate.
- **The router's three early returns exist to register nothing.** The ping,
  thank-you and admin-AJAX branches at the top of `woocommerce-scanpay.php`
  deliberately skip the whole bootstrap. Task C adds one narrowly scoped call
  inside the ping handler; nothing in this plan turns any of them into a partial
  plugin load.
- **`init_gateway_props()` reads `$this->settings` directly, on purpose.** Task A
  extends that rule to the card gateway's constructor. It is not an oversight to
  be "modernized" back into `get_option()` calls.
- **The settings-field files read `$this->default_title()` across the require.**
  All three do, and the base class documents it as the one source the property
  and the form must share. Out of scope here; do not "fix" it in passing.
- **`require` doubles as a call.** Task L makes one such file safe to include
  twice by moving a function out of it — not by converting the idiom to
  `require_once` at the render site.
- **The Blocks class reads settings raw, never through the gateway getters.**
  Task K changes one fallback literal and nothing else about that policy.

## Order

| Task | Focus | File | Depends on |
| --- | --- | --- | --- |
| A | Settings reads in the card gateway's constructor | `class-wc-gateway-scanpay-card.php` | — |
| B | `process_admin_options()`'s return type | `class-wc-gateway-scanpay-card.php`, `abstract-wc-gateway-scanpay-base.php` | — |
| C | Text domain on the ping request | `woocommerce-scanpay.php` | — |
| D | Derive the plugin directory | `woocommerce-scanpay.php`, `admin/settings.php` | C |
| E | Post-save `woocommerce_order_edit_status` in the bulk handler | `admin/hooks/wp-bulk-actions.php` | — |
| F | Money math outside a handler | `public/generate-payment-link.php` | — |
| G | Subscriber upsert behind the WCS gate | `library/class-wc-scanpay-sync.php` | — |
| H | Empty order key passes the thank-you gate | `public/wp-scanpay-thankyou.php` | — |
| I | Rotate the admin-AJAX secret on reset | `admin/hooks/wp-ajax-wc-scanpay-reset.php` | — |
| J | `wp_cache_flush()` in the 2.1.3 migration | `upgrade.php` | — |
| K | Blocks card title default | `class-wc-scanpay-blocks-support.php` | — |
| L | Non-idempotent settings-screen include | `admin/settings/admin-options.php`, `admin/settings.php` | — |

Only one hard dependency exists: **D rewrites the languages path C adds**, so C
must land first. Everything else is file-disjoint, and the order is otherwise by
descending severity: A, C, E, F and G are live defects with a reachable
consequence; H, I and J are hardening on cold or narrow paths; B, K and L change
no behaviour at all on any store and are last of their kind.

Two soft pairings, both about not editing one file twice from two directions:
A and B are the card gateway's two methods, so B lands while that file is still
fresh; D and L both edit `admin/settings.php` (D the filter name at `:70`, L a
new function above it), so D lands first and L reads the file as D left it.

---

## Task G: Record subscriber revisions even when Subscriptions is inactive

`WC_Scanpay_Sync::subscriber()` returns at `class-wc-scanpay-sync.php:405-407`
when `WC_Subscriptions` is not loaded — above the payload validation
(`:408-421`) and above the `scanpay_subs` upsert (`:440-450`). The ping loop
advances and persists the cursor either way
(`callback/wc-scanpay-ping.php:294-306`), and `seq` only moves forward, so those
revisions are gone permanently.

What is lost is the local `rev`, which `WCS_Scanpay_Charge::idempotency_key()`
reads (`class-wcs-scanpay-charge.php:51`) and which is the only thing that lets
a charge retry inside the 24h day bucket after a card update. A shop that
deactivates Subscriptions for a while and re-enables it therefore keeps building
keys from a stale revision, and the admin subscription meta box shows stale
method data.

The class gate is right for the WCS-dependent half — `wcs_get_subscription()` at
`:455` is undefined without it — but the table write needs nothing from WCS.

### Fix

1. Move the `wcs_enabled` check down so it guards only the WCS-dependent tail,
   leaving the validation and the `scanpay_subs` upsert unconditional. Put it
   immediately after the upsert's error check at `:450`, so it covers `:452-485`
   — `$pm_title` at `:452` included, since that value is read only at `:462` and
   `:481`, both inside the loop, and a shop without WCS should not compute it
   either.
2. Keep the guards above the upsert exactly where they are. The id and rev
   throws (`:408-417`) stay fail-loud, and the `ref` early return (`:418-421`)
   stays above the upsert — a subscriber with no reference still has nothing to
   link, so the set of rows written for a WCS-active shop must not change.
3. `$this->wcs_enabled` is set once in the constructor (`:51`); keep it a
   property rather than re-testing `class_exists()` per call.
4. Do not log or throw when the loop is skipped. A shop without Subscriptions
   receiving subscriber changes is a normal state, not a protocol violation, and
   an error log per change would be noise on every ping.
5. **Accept that three throws become reachable for a WCS-less shop** — the id
   throw, the rev throw and the upsert's DB-error throw. That is the point of
   the move, not a regression: a malformed subscriber payload is a backend
   protocol violation and must fail loud, exactly as it already does on every
   shop that has WCS active. Do not add a WCS-less bypass to keep them quiet.

### Verify

- Trace a `subscriber` change with WCS inactive under the new code and state
  exactly which rows and which order/subscription metadata get written.
- Name the throws that become newly reachable for a WCS-less shop and confirm
  that list is complete — i.e. that nothing *else* between `:408` and `:450`
  can throw. Then confirm each one is a case a WCS-active shop already throws
  on today, so the change adds no new failure mode, only new shops that see it.
- Confirm the cursor still advances for every change the ping loop completes,
  and that a throw wedges the loop the same way it already does for a
  WCS-active shop.
- Confirm `idempotency_key()`'s "subscriber does not exist" throw
  (`class-wcs-scanpay-charge.php:57-60`) is now reachable only for a subid that
  genuinely never synced.
- Confirm the written-row set is byte-identical for a WCS-active shop: same
  columns, same `ON DUPLICATE KEY UPDATE` clause, same `ref` gating.

### Handoff

Deactivate Subscriptions, push a subscriber revision (a card update on a live
subscriber), reactivate, and confirm `scanpay_subs.rev` advanced and that a
renewal then charges under the new key rather than replaying the cached decline.

---

## Task H: Reject an empty order key on the payment-return page

`wc_scanpay_init_thankyou()` gates on

```php
hash_equals( (string) $row['order_key'], (string) wp_unslash( $_GET['key'] ?? '' ) )
```

(`public/wp-scanpay-thankyou.php:107-112`), and the free-trial handler repeats
the shape at `:146-151`. When the stored key reads back as `NULL`, both sides
become `''`, `hash_equals()` returns true, and a request carrying a bare `key=`
passes the gate — `isset()` at `woocommerce-scanpay.php:72` is satisfied by an
empty value, so the router lets it through.

The column is nullable upstream — `order_key varchar(100) NULL` in the HPOS
operational-data table
(`.stubs/woocommerce/src/Internal/DataStores/Orders/OrdersTableDataStore.php:3512`)
— and the query joins it with a `LEFT JOIN` (`:58-61`), so a missing
operational-data row yields the same `NULL`. The legacy branch aggregates
`MAX( CASE WHEN meta_key = '_order_key' … )` (`:65`), which is `NULL` when the
meta is absent.

**State the severity accurately.** The gate is the only thing between an
anonymous request and the busy-poll below it, which holds a PHP worker for ~3.5s
(`:120-123`) — but reaching it also requires `payment_method` to start with
`scanpay` *and* `transaction_id` to be empty (`:117`), so a normal paid order
returns immediately regardless. Orders written by the data store always get a
key (`OrdersTableDataStore:2962-2963`), which leaves partially migrated or
hand-edited data as the realistic trigger. This is hardening on a load-bearing
gate, not a live exploit — and the fix is one clause.

### Fix

1. Require a non-empty stored key in both gates, evaluated before the
   `hash_equals()` comparison.
2. Keep `hash_equals()` for the comparison itself. The point is the emptiness
   precondition, not the constant-time property, and dropping the latter to add
   the former would trade one weakness for another.
3. Apply the same clause to `wcs_scanpay_init_thankyou_free()`, which has the
   same gate in a different shape (a single combined `if` with an early
   `return`).
4. Extend the comment at `:104-106` with one sentence naming the nullable
   column, so a later reader does not delete the clause as redundant
   defensiveness.
5. **Do not gate on a non-empty `$_GET['key']` instead.** The router already
   requires the parameter to be present (`woocommerce-scanpay.php:72`), and it
   is the *stored* value being empty that opens the case where both sides match.

### Verify

- Quote the DDL line and confirm both query branches — HPOS join and legacy meta
  aggregate — can yield `NULL` for `order_key`.
- State the outcome of all four combinations of empty/non-empty stored key and
  supplied key under the new gate.
- Confirm the poll is unreachable when the gate fails, and that the gate still
  runs only on the first iteration (`0 === $i`) so a genuine wait is not
  re-checked on every loop.
- Confirm the free-trial handler's parent-order ownership check at `:165` is
  unaffected — it guards a different thing and must stay.

### Handoff

Confirm a genuine payment return still waits and renders payment data, and that
a request with `key=` against a Scanpay order returns immediately without
holding a worker.

---

## Task I: Rotate the admin-AJAX secret when the merchant resets

`install.php:92-98` mints `settings['secret']` only when it is empty and
deliberately preserves an existing one, and the reset endpoint clears `apikey`
and `enabled` but never the secret
(`admin/hooks/wp-ajax-wc-scanpay-reset.php:107-129`). So the secret a store was
set up with survives every reset and never rotates for the life of the
installation.

That secret authenticates the three lightweight polling endpoints
(`woocommerce-scanpay.php:86-98`), which read any row of `scanpay_meta` and
`scanpay_subs` by id with no per-order check
(`admin/ajax/wp-scanpay-fetch-meta.php:36`, `wp-scanpay-fetch-sub.php:38`).

**State the severity accurately.** The exposure is order ids, transaction ids,
revisions, currency and amounts — no PII, no card data — and the secret only
ever reaches users who already hold `edit_shop_orders` (`admin/orders.php:110`)
or `manage_woocommerce` (`admin/settings/admin-options.php:139`,
`admin/subscriptions.php:33`). The reason to fix it is the one the reset button
already states: it exists to hand the store to a different Scanpay account, and
the outgoing account's operator should not keep a working credential.

### Fix

1. Unset `secret` alongside `apikey` in the reset loop at `:118`. Doing it for
   all three options is fine and simplest — the two secondary gateway options
   never carry one, so it is a no-op there.
2. Do not mint a replacement here. `install.php` is required later in the same
   request (this file's `:148` — not a line in `install.php`, which is 107 lines
   long) and its `if ( empty( $settings['secret'] ) )` branch at
   `install.php:92-98` mints a fresh one; confirm that ordering. The reason is
   one minting site, not two — that branch is `empty()`-gated, so a second mint
   here would *survive* rather than be overwritten, and the tree would then have
   two places that decide what a secret is.
3. Extend the postcondition block at `:186-189` — which already rereads the
   primary option — to assert that a secret exists and differs from the
   pre-reset value. Capture the old value before the loop, next to where
   `$wcsp_shopid` is derived at `:64-65` and for the same reason: the loop
   destroys what you need to compare against.
4. Reuse the existing `verify_failed` code and fail closed through `$wcsp_fail`.
   Do not add a response code the settings JS does not recognize — it shows the
   code to the merchant verbatim.
5. Comment the one consequence worth recording: a reset that fails *after* the
   loop (a `drop_failed`, say) leaves the option with no secret at all until the
   merchant retries and `install.php` mints one, so the three polling endpoints
   fail closed in the meantime. That is the same shape the cleared `apikey`
   already has, and the endpoints' own `'' === $secret` guard is what makes it
   safe rather than open.

   **Do not write the comment the obvious way round** — that the open settings
   page keeps polling with a dead secret. It does not:
   `admin/assets/js/settings.ts:135` calls `window.location.reload()` on
   success, so the stale `data-secret` is gone before anything reads it. Only the
   failure path leaves the page standing, and there `checkMtime()` on the next
   `visibilitychange` surfaces the endpoint's error to the merchant, which is
   the correct outcome for a failed reset.

### Verify

- Confirm `install.php`'s mint branch is reached after the removal within the
  same request, that it reads the option *after* the loop's write
  (`install.php:69`), and that it writes with the autoload flag unchanged.
- Confirm the new postcondition cannot pass with the old secret still stored,
  and that it releases the flock through `$wcsp_fail` like every other failure
  in the file.
- Confirm no other reader caches the secret across the reset inside one request,
  and list the three places that embed it in a page
  (`admin-options.php:139`, `admin/orders.php:110`, `admin/subscriptions.php:33`)
  with the request each is rendered by.

### Handoff

Reset a configured store and confirm the secret changed, that the old secret
403s against all three `?x=` endpoints, and that the settings page comes back
working after its automatic reload.

---

## Task J: Do not flush the whole object cache per migrated subscription

`upgrade.php:85` calls `wp_cache_flush()` inside the 2.1.3 subscriber-id
backfill loop, once for every subscription it changes. On a persistent drop-in
(Redis, Memcached) that empties the entire site cache, repeatedly, during an
upgrade that is already holding the five-minute `wc_scanpay_updating` transient.

The write it appears to guard is two lines up: `update_meta_data()` +
`save_meta_data()` on a `WC_Subscription` (`:83-84`). WooCommerce's data store
already invalidates that object's own cache entries on save, so the flush is
either redundant or is standing in for something the next iteration reads — and
the next iteration loads a *different* subscription by id (`:68`) plus two
`$wpdb` reads that bypass the object cache entirely (`:76-77`).

This is a cold path — only a 1.x or early-2.x store crossing 2.1.3 reaches it —
which is why it is late in the plan.

### Fix

1. **The archaeology step 1 used to ask for is done.**
   `git log --follow -S 'wp_cache_flush' -- src/upgrade.php` returns exactly one
   commit, `62b3535` "Fix wcs issue caused by our old plugin (<2.0.0)", which is
   the commit that wrote this migration in the first place. The call arrived
   with the loop, uncommented, and the commit message says nothing about
   caching; the `$black_subid` / `$trn` precedence logic came later. Re-run the
   command to confirm, then say in the commit body that no rationale was ever
   recorded — that is the finding, not an absence of research.
2. If nothing in the loop reads stale data, drop the call. If something does,
   replace it with the narrowest invalidation that covers that read — the
   subscription's own cache entry, never the site's.
   `wc_scanpay_flush_order_runtime_cache()` (`callback/wc-scanpay-ping.php:82-87`,
   with the reasoning in the docblock above it) is the in-tree precedent for the
   narrow form: `wp_cache_flush_group()` on the order groups, with a comment on
   how a drop-in without group support degrades.
3. Do not compromise by moving it below the loop. A single site-wide flush is
   still a site-wide flush; only step 2's answer justifies keeping one at all.
4. Leave the rest of the migration untouched, including the `$black_subid` /
   `$trn` precedence logic at `:74-81`. That is the part with reasoning behind
   it, and it is not what this task is about.
5. In the same commit, change `$wc_sub->get_payment_method()` at `:69` to pass
   `'edit'`, matching every other payment-method read in the tree. A `view`-context
   read runs the `woocommerce_order_get_payment_method` filter during a
   migration, which is a third party deciding what a stored value is while we
   decide whether to rewrite it.

### Verify

- Quote what the loop reads after the write and state whether any of it could be
  stale without the flush — the next `wcs_get_subscription()` is a different id,
  and the two `$wpdb->get_var()` reads never consult the object cache.
- Confirm `WC_Subscription::save_meta_data()` invalidates the object's own cache:
  `WC_Data::save_meta_data()` ends in
  `wp_cache_delete( $cache_key, $this->cache_group )`
  (`.stubs/woocommerce/includes/abstracts/abstract-wc-data.php:803-806`). Read
  it; do not take this line's word for it.
- Confirm the migration is still idempotent on a re-run after an interruption:
  the version is stamped last (`:160`), so a partial run must be safe to repeat.
- Confirm the `'edit'` context change alters no stored value and no branch
  outcome for an unfiltered store.

### Handoff

Only a store crossing 2.1.3 with a persistent object cache can settle the
performance half. The correctness half — that the backfill still selects the
right subid — is a data-shape question: describe the fixtures it would need
rather than claiming a result.

---

## Task K: Align the Blocks card title default with the classic one

`WC_Scanpay_Blocks_Support::get_payment_method_data()` falls back to `'Scanpay'`
for the card method's title (`class-wc-scanpay-blocks-support.php:77`), while
the gateway's own shipped default is `'Pay by card'`
(`class-wc-gateway-scanpay-card.php:37-39`, reached through
`init_gateway_props()`). MobilePay and Apple Pay agree across both renderers
(`:101` and `:112` against their respective `default_title()`s); only the card
disagrees.

**Reachability is narrow, and the task must not overstate it.** Saving the
settings form writes every field key, and `upgrade.php`'s 2.0.0 branch writes a
`title` too, so a store reaches this fallback only when the option was written
by something other than the settings form — WP-CLI `wp option patch`, a staging
import, a partial migration. This is consistency hygiene on a fallback that
should never have differed, not a live bug report.

Reading the option directly is deliberate and stays: the class must not apply
`woocommerce_gateway_title`, whose callbacks may return HTML that React would
render as literal text (see the docblock at `:35-45`). Only the literal is
wrong.

### Fix

1. Change the fallback at `:77` to the same string
   `WC_Gateway_Scanpay_Card::default_title()` returns.
2. Add a short comment naming `default_title()` as the source of truth so the
   two are found together next time. **Do not call the method or instantiate the
   gateway** to obtain it — that would drag the whole gateway, and Task A's
   field load with it, into a payload built on most admin and frontend pages.
3. Leave the description fallback `''` alone. An empty description renders
   nothing, which is the correct degradation; the title has no such
   safe-empty form.
4. Do not wrap either literal in `__()`. These are stored-value defaults, and
   the Conventions section of `AGENTS.md` settles that `__()` cannot localize a
   stored value.
5. While here, check `upgrade.php`'s 2.0.0 fallback `'Pay by card.'` — with a
   trailing period, at `upgrade.php:24` — against `default_title()`'s
   `'Pay by card'`. Report the discrepancy in `HANDOFF.md`; **do not change it**.
   That value has been written into live stores' settings for releases, and
   rewriting a stored title during a migration is a different decision from
   fixing an unused fallback.

### Verify

- Confirm all three methods' **title** fallbacks now match their gateway
  counterparts. All three *description* fallbacks deliberately still do not —
  they stay `''` per step 3, against non-empty `default_description()`s — so
  say that rather than "fixing" them.
- Confirm nothing in the Blocks class instantiates a gateway or calls a gateway
  method.
- Enumerate the ways the stored option can end up without a `title` key, and say
  which of them a real store can hit — the honest answer is what belongs in
  `HANDOFF.md`.
- Confirm `src/languages/*.pot` is unchanged.

### Handoff

On a store whose option was patched to hold `enabled=yes` with no `title` key,
confirm the Blocks and classic checkouts render the same label.

---

## Task L: Make the settings-screen include idempotent

`admin/settings/admin-options.php` declares `wc_scanpay_admin_notice()` at `:22`
and is pulled in with a bare `require` from
`WC_Gateway_Scanpay_Base::admin_options()`
(`abstract-wc-gateway-scanpay-base.php:111`). A second call in one request is a
fatal redeclaration.

The require-as-a-call idiom is deliberate (see Code style in `AGENTS.md`) and
this file genuinely needs `$gateway` in scope — but the idiom is only safe for
files that *declare* nothing, once the include site can be reached more than
once. The plugin's other two files in that position already know this:
`public/generate-payment-link.php` and `admin/hooks/wp-bulk-actions.php` both
declare functions and are both pulled in with `require_once`.

WooCommerce renders one gateway's screen per request, so nothing calls it twice
today. This removes a trap, it does not fix a crash — which is why it is last.

### Fix

1. Move `wc_scanpay_admin_notice()` out of the rendered file and into
   `admin/settings.php`. That is the admin bootstrap, it is required exactly
   once from `wc_scanpay_admin_init()`, and it already holds the other
   admin-screen helpers.
2. **Keep `require` — not `require_once` — at base `:111`** once the file
   declares nothing. The include *is* the render call; `require_once` would make
   a second screen render silently blank.
3. Confirm the ordering rather than assuming it: `admin/settings.php` is loaded
   on `admin_init` and the gateway screen renders later in the same request, so
   the function exists by the time the notice is emitted. Say which hook settles
   it.
4. Leave the `phpcs:ignore` and the pre-escaped-HTML contract on the function
   exactly as they are. Its callers pass assembled, already-escaped markup, and
   moving the function must not change that contract.

### Verify

- Grep every caller of `wc_scanpay_admin_notice()` and confirm each is reached
  after `admin/settings.php` is loaded. There are two, both in the file being
  changed (`:48` and `:60`).
- Confirm `admin/settings.php` cannot itself be included twice — trace
  `wc_scanpay_admin_init()` and its `admin_init` registration, and say what
  would happen if it were.
- Confirm no other file that is `require`d (not `require_once`) **from a call
  site that can run twice in one request** declares a function or a class. Two
  candidates survive the grep and both come back clean — `install.php` (four
  call sites, declares nothing) and `public/wcs-scanpay-checkout-terms.php`
  (hooked to `woocommerce_checkout_after_terms_and_conditions`, declares
  nothing). Confirm both rather than re-deriving the list. The one-shot sites
  are not findings and must not be listed as such: the router's own three
  branches, the four gateway/Blocks class files, and `admin/orders.php` /
  `settings.php` / `subscriptions.php` all declare things and are all reached at
  most once per request. If a repeatable one does turn up, name it in
  `HANDOFF.md` rather than fixing it here.
- That grep answers "included twice from one site". The neighbouring question it
  does not answer is a bare `require` of a file another site already
  `require_once`d: `require_once` registers the file, but a later plain
  `require` includes it again regardless, and the second one redeclares.
  `public/generate-payment-link.php:7-8` is the tree's only instance — it
  bare-`require`s `library/class-wc-scanpay-client.php` (a class) and
  `library/math.php` (functions), while four other sites `require_once` one or
  both (`abstract-wc-gateway-scanpay-base.php:157`,
  `library/class-wc-scanpay-capture.php:7`, `:9`,
  `callback/wc-scanpay-ping.php:201`, `library/class-wcs-scanpay-charge.php:15-16`).
  State whether any one request can reach one of those *before*
  `process_payment()` runs; the reverse order is safe, since `require_once`
  skips what the bare `require` already registered. **Record the answer in
  `HANDOFF.md`, do not fix it here** — one task, one commit, and that is a
  different file from the one this task changes.
- `pnpm phpcs` clean — the moved function keeps its docblock and its ignore
  comment.

### Handoff

Render all three gateway settings screens and confirm both notice variants — the
unconfigured "Thank you for choosing Scanpay" and the configured "Finish your
Scanpay setup" — still appear with their markup and links intact.
