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
3. Implement it, in `src/` only — `build/` is generated.
4. Run `pnpm phpcs` (autofix `pnpm phpcbf`) and
   `find src -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l`,
   plus `pnpm lint:js` and `pnpm exec tsc` if any `.ts` changed, and
   `pnpm lint:style` if any `.scss` changed. All clean.
5. Do the task's **Verify** bullets, and write those findings plus its
   **Handoff** list into `HANDOFF.md` at the repo root. That file is gitignored
   — never `git add` it.
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

Upstream source is in `.stubs/` — read it instead of guessing. WooCommerce
citations resolve directly (`.stubs/woocommerce/includes/class-wc-order.php`),
except the abstracts, which sit one level down
(`.stubs/woocommerce/includes/abstracts/abstract-wc-payment-gateway.php`) and
the HPOS internals, which sit under `.stubs/woocommerce/src/Internal/`. WordPress
core is `.stubs/wordpress/wp-includes/`. WCS ones sit under
`.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/`. The
WooCommerce stub is `11.1.0-dev` and the Subscriptions stub `7.2.1`, so they
settle "what does current WooCommerce do", never "what does the 3.6 minimum do".
Released 2.x behaviour is in tags `v2.0.0`–`v2.9.1`. `php -r` settles PHP
semantics empirically — to load `src/library/math.php` directly,
`define( 'ABSPATH', … )` first, or the file `exit()`s and prints nothing.

Six tasks here rest on upstream behaviour that has already been read out of
`.stubs/` rather than inferred. Re-read the citation, but do not re-derive the
conclusion from scratch:

| Task | Upstream fact | Where |
| --- | --- | --- |
| A | Just-in-time l10n searches only `WP_LANG_DIR` plus `load_plugin_textdomain()`'s custom path | `wp-includes/class-wp-textdomain-registry.php:286-297` |
| C | `get_option()` force-loads `get_form_fields()` for a key missing from the stored option | `includes/abstracts/abstract-wc-settings-api.php:305-307` |
| E | Both bulk handlers fire `woocommerce_order_edit_status` per changed order | `src/Internal/Admin/Orders/ListTable.php:1568`, `includes/admin/list-tables/class-wc-admin-list-table-orders.php:508` |
| F | HPOS `order_key` is nullable | `src/Internal/DataStores/Orders/OrdersTableDataStore.php:3512` |
| J | Checkout puts a caught exception message in front of the customer | `includes/class-wc-checkout.php:1419-1422` |

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
  deliberately skip the whole bootstrap. Task A adds one narrowly scoped call
  inside the ping handler; nothing in this plan turns any of them into a partial
  plugin load.
- **`init_gateway_props()` reads `$this->settings` directly, on purpose.** Task C
  extends that rule to the card gateway's constructor. It is not an oversight to
  be "modernized" back into `get_option()` calls.
- **`require` doubles as a call.** Task G makes one such file safe to include
  twice by moving a function out of it — not by converting the idiom to
  `require_once` at the render site.
- **The Blocks class reads settings raw, never through the gateway getters.**
  Task I changes one fallback literal and nothing else about that policy.

## Order

| Task | Focus | Depends on |
| --- | --- | --- |
| A | `woocommerce-scanpay.php` — text domain on the ping request | — |
| B | `woocommerce-scanpay.php`, `admin/settings.php` — derive the plugin directory | A |
| C | `class-wc-gateway-scanpay-card.php` — settings reads in the constructor | — |
| D | `class-wc-gateway-scanpay-card.php` — `process_admin_options()` return | C |
| E | `wp-bulk-actions.php` — `woocommerce_order_edit_status` | — |
| F | `wp-scanpay-thankyou.php` — empty order key passes the gate | — |
| G | `admin/settings/admin-options.php` — non-idempotent include | — |
| H | `class-wc-scanpay-sync.php` — subscriber upsert behind the WCS gate | — |
| I | `class-wc-scanpay-blocks-support.php` — card title default | — |
| J | `generate-payment-link.php` — money math outside a handler | — |
| K | `wp-ajax-wc-scanpay-reset.php` — rotate the admin-AJAX secret | — |
| L | `upgrade.php` — `wp_cache_flush()` in the 2.1.3 migration | — |

A and B touch the same two lines, as do C and D; landing each pair contiguously
avoids rewriting the same region twice. Everything after E is hardening or
consistency work and is ordered by descending severity, not by dependency.

---

## Task A: Load the text domain on the ping request

`woocommerce-scanpay.php:54-64` returns before the loader registers anything —
including `add_action( 'init', 'wc_scanpay_init', 0 )` at `:475`, whose callback
holds the only `load_plugin_textdomain()` call in the tree (`:473`). Both ping
URL forms take that return: `WC()->api_request_url( 'wc_scanpay' )` yields
`/wc-api/wc_scanpay/` under pretty permalinks and `/?wc-api=wc_scanpay` without,
and the `str_ends_with()` pair at `:61` matches both.

Sync writes three translated, merchant-facing order notes in exactly that
request:

- `class-wc-scanpay-sync.php:281` — the authorized amount does not cover the
  order total
- `class-wc-scanpay-sync.php:385` — the reconciliation note
- `class-wc-scanpay-sync.php:482` — "Subscription initiated without payment."

They are persisted to the database, so this does not self-heal on a later,
fully-bootstrapped request.

**WordPress will not fill the gap.** `_load_textdomain_just_in_time()`
(`.stubs/wordpress/wp-includes/l10n.php:1423`) resolves the path through
`WP_Textdomain_Registry::get()`, and `get_paths_for_domain()`
(`class-wp-textdomain-registry.php:286-297`) searches exactly three places:
`WP_LANG_DIR/plugins`, `WP_LANG_DIR/themes`, and `custom_paths[ $domain ]` —
which is written only by `load_plugin_textdomain()`,
`load_muplugin_textdomain()` and `load_theme_textdomain()` (`l10n.php:1017`,
`:1053`, `:1096`). A site carrying a WordPress.org language pack under
`WP_LANG_DIR/plugins` is therefore unaffected; a site running the catalogs
`build.sh` bundles into the plugin's own `languages/` — the manually shipped
builds — gets English notes.

The thank-you and admin-AJAX branches (`:72-75`, `:86-98`) return the same way.
Neither emits a translated string today, so this task is about the ping alone.

### Fix

1. Load the domain inside `wc_scanpay_handle_ping()` (`:55-57`), ahead of the
   `require`. The action it is hooked to, `woocommerce_api_wc_scanpay`, fires
   from `WC_API::handle_api_requests()` on `parse_request` — well after
   `init` — so this cannot trip the `_doing_it_wrong()` at `l10n.php:1444`,
   which fires only for a load before `after_setup_theme`.
2. **Do not move `add_action( 'init', 'wc_scanpay_init', 0 )` above the dispatch
   block instead.** It would work, and it is the wrong shape: it pays a
   `load_textdomain()` on every admin-AJAX poll and every thank-you request for
   strings none of them emit, and it puts the plugin's first hook registration
   above three early returns whose entire purpose is to register nothing.
3. Leave `wc_scanpay_init()` and its `init` registration untouched for the
   normal path. This adds a second, narrower call site; it does not replace the
   first.
4. Keep the hardcoded `'scanpay-for-woocommerce/languages'` argument as-is here.
   Task B replaces both call sites in one commit, and pre-empting it would leave
   B with half a change.
5. Say in a comment why the call is here rather than on `init` — the early
   return above it is the whole reason, and it is four lines away but easy to
   miss.

### Verify

- Quote `WC_API::add_endpoint()`/`api_request_url()` from the stub, derive both
  URL forms, and confirm each reaches the `return` at `:62`.
- Grep `callback/` and `library/class-wc-scanpay-sync.php` and confirm those
  three notes are the complete set of translated strings reachable from the ping
  request. `WC_Scanpay_Capture`'s notes are *not* — name which request types
  reach those, and why they are already covered.
- Read `get_paths_for_domain()` in the stub and state in one sentence why a
  bundled `languages/*.mo` is unreachable without the explicit call.
- Confirm `wc_scanpay_handle_ping()` cannot run before `init` — cite the hook,
  not the intuition.
- `printf 'n\n' | ./build.sh` leaves `src/languages/` untouched: this task adds
  no strings.

### Handoff

On a Danish shop running the bundled catalog with no WordPress.org language pack
installed, force each of the three notes — an authorization below the order
total, a `payment_complete()` that returns false, and a free-trial subscriber —
and confirm each note lands in Danish rather than English.

---

## Task B: Derive the plugin directory instead of hardcoding it

Two places spell the plugin's directory name out. `load_plugin_textdomain()` at
`woocommerce-scanpay.php:473` passes `'scanpay-for-woocommerce/languages'`,
which is resolved relative to `WP_PLUGIN_DIR`, and `admin/settings.php:70` hooks
`plugin_action_links_scanpay-for-woocommerce/woocommerce-scanpay.php`.

Both are correct for the shipped artifact — `build.sh` rsyncs to
`…/plugins/scanpay-for-woocommerce/` and WordPress.org serves the same slug — so
this is robustness, not a live bug. Under any other directory name (a git
checkout symlinked in, a zip renamed on download, a staging copy) translations
silently stop loading and the Plugins-screen Settings link disappears, with no
error either way.

Task A adds a second copy of the languages path, which is why both call sites
move together now rather than earlier.

### Fix

1. In `woocommerce-scanpay.php`, derive the directory once alongside the
   existing `WC_SCANPAY_DIR` / `WC_SCANPAY_URL` defines at `:34-35`:
   `plugin_basename( __FILE__ )` gives `<dir>/woocommerce-scanpay.php`, and
   `dirname()` of that gives the directory. Use it for both
   `load_plugin_textdomain()` call sites.
2. Use `define()`, not `const`. A `const` cannot hold a function call, and the
   two neighbouring path constants already establish the pattern for exactly
   this reason.
3. In `admin/settings.php:70`, build the filter name from the same constant.
   Do not recompute it there with
   `plugin_basename( WC_SCANPAY_DIR . '/woocommerce-scanpay.php' )` — that
   reintroduces a hardcoded file name one directory over from the one you just
   removed.
4. Leave the `Text Domain:` plugin header and the `'scanpay-for-woocommerce'`
   domain string alone everywhere. Only the *directory* is derived; the domain
   is a fixed identifier and must stay a literal for `wp i18n make-pot` to find
   it.

### Verify

- Show the `plugin_basename()` / `dirname()` composition and what it evaluates
  to for the shipped layout; confirm it is exactly `scanpay-for-woocommerce`.
- Grep `src/` for any remaining literal `scanpay-for-woocommerce/` used as a
  path or a hook suffix, and confirm each survivor is a domain string rather
  than a directory.
- Confirm the constant is defined before both consumers run — `admin/settings.php`
  is required from `admin_init`, the textdomain calls from `init` and from the
  ping handler.
- `printf 'n\n' | ./build.sh` leaves `src/languages/` untouched, and the POT
  still lists every string it did before: `make-pot` reads the domain literal,
  which this task does not move.

### Handoff

Rename the plugin directory on a test site and confirm both the Settings link
and the Danish catalog still resolve.

---

## Task C: Stop forcing the lazy form fields from the card gateway's constructor

`WC_Gateway_Scanpay_Card::__construct()` reads two settings through
`WC_Settings_API::get_option()` — `'stylesheet'` at
`class-wc-gateway-scanpay-card.php:29` and `'wc_complete_virtual'` at `:32`.
Upstream, that method is

```php
if ( ! isset( $this->settings[ $key ] ) ) {
    $form_fields            = $this->get_form_fields();
    $this->settings[ $key ] = isset( $form_fields[ $key ] ) ? … : '';
}
```

(`.stubs/woocommerce/includes/abstracts/abstract-wc-settings-api.php:305-307`),
so a key missing from the stored option force-loads `get_form_fields()` — which
for this gateway requires `admin/settings/fields/scanpay.php`, whose first
statement is an unbounded `get_pages()` (`:11`) building the terms-page picker.

That is precisely the trap `init_gateway_props()` documents and sidesteps
(`abstract-wc-gateway-scanpay-base.php:40-42`), and the constructor reintroduces
it four lines after calling it.

**It is reachable.** `init_settings()` (`abstract-wc-settings-api.php:280-288`)
merges the field defaults only when the stored option is *not* an array — and on
a fresh install `install.php:92-98` has already written `[ 'secret' => … ]`, so
the option is an array missing every other key. Until the merchant saves the
settings form once, every request that instantiates the payment gateways pulls
all published pages as full `WP_Post` objects, and the card gateway is
registered unconditionally (`woocommerce-scanpay.php:101`).

### Fix

1. Read both values from `$this->settings` directly with a `??` fallback, the
   way `init_gateway_props()` does at `:45-49`. `parent::__construct()` has
   already run `init_settings()`, so the array is populated by the time the
   constructor body resumes.
2. Use the defaults the field definitions declare, so a store that has never
   saved keeps behaving exactly as it does today: `'yes'` for `stylesheet`
   (`fields/scanpay.php:74`), `'no'` for `wc_complete_virtual` (`:92`).
3. Comment why it is a direct read, pointing at the base class's paragraph
   rather than restating it.
4. **Leave `get_icon()`'s `$this->get_option( 'card_icons', [] )` (`:55`)
   alone.** It runs at checkout render rather than in the constructor, its
   `$empty_value` argument is load-bearing (see the comment at `:52-54`), and it
   is unreachable before a save: rendering requires the gateway to be enabled,
   which requires a settings save, which writes every field key.
5. Do not add a `has_option()`-style helper, and do not defer the two hooks to a
   later action to make the constructor cheaper. `woocommerce_order_item_needs_processing`
   must be registered before anything consults `needs_processing()`, which
   `generate-payment-link.php:74` does during checkout.

### Verify

- Quote `get_option()` and `init_settings()` from the stub, then state which
  stored-option shapes reach `get_form_fields()` under the old code and under
  the new one.
- Confirm `get_pages()` at `fields/scanpay.php:11` takes no arguments, is
  unbounded, and returns full post objects.
- Confirm both hooks still register for a store that saved `stylesheet=yes` /
  `wc_complete_virtual=yes`, and register for neither otherwise.
- Confirm `get_icon()`'s `get_option()` call is unreachable before a first save,
  and say why — this is the reason step 4 leaves it in place.
- Confirm the settings screen itself still loads the form fields; this task must
  not make the picker disappear from the admin.

### Handoff

On a fresh install with the settings form untouched and a few hundred pages,
confirm a front-end request no longer issues the pages query, and that the
checkout stylesheet still enqueues once the form has been saved.

---

## Task D: Declare and return `process_admin_options()`'s documented bool

`WC_Gateway_Scanpay_Card::process_admin_options(): void`
(`class-wc-gateway-scanpay-card.php:82`) overrides a method the base declares as
`@return bool Whether anything was saved`
(`abstract-wc-gateway-scanpay-base.php:114-119`), which in turn returns whatever
its own body returns — `false` when nothing was saved, `true` otherwise.

Adding `void` where the parent has no return type is legal PHP, so nothing
errors: the value is simply discarded and any caller reading it gets `null`.
WooCommerce's own hook (`woocommerce_update_options_payment_gateways_{$id}`,
registered at base `:13`) ignores the return, so there is no live failure. The
card gateway is nonetheless the one whose result is worth having, since it is
the only gateway that can store an API key and the only one whose save can
trigger `install.php`.

### Fix

1. Declare `: bool` on the card and return the parent's value.
2. Keep the seeding condition and its comment (`:83-98`) exactly as they are.
   The only change is that the parent's result is captured and handed back
   instead of dropped — the `install.php` require still runs on the same
   condition, after the parent call.
3. **Declare `: bool` on the base too, in the same commit**, and drop the
   `@return bool` line the signature then states. Both halves are required at
   once: `void` is not a subtype of `bool`, so a typed base with the card still
   on `void` is a fatal at class-declaration time, and a typed card alone leaves
   the contract documented rather than enforced.

   The upstream check this step used to defer is done. `WC_Settings_API::process_admin_options()`
   is untyped (`woocommerce-stubs.php:817`) and `WC_Payment_Gateway` does not
   override it, so the base's `parent::` reaches `WC_Settings_API` directly;
   adding a return type where the parent has none is covariant and legal. All
   three concrete gateways are `final`, so the card is the only override in the
   tree and no third party can subclass one.

### Verify

- Confirm every path through both methods returns a bool, including the one that
  requires `install.php`.
- Grep `src/` for every `process_admin_options` call site and confirm none
  depended on the `void` — in particular that the base's own
  `parent::process_admin_options()` still sees `WC_Settings_API`, not the card.
- Confirm PHP accepts both signatures against their ancestors: `php -l` on each
  file, plus loading the classes together so the card is checked against the
  typed base, not just parsed.
- `pnpm phpcs` clean.

### Handoff

None. This is settled statically, and saying so is the correct entry in
`HANDOFF.md` — do not invent a shop check to fill the section.

---

## Task E: Fire `woocommerce_order_edit_status` from the bulk handler

`admin/hooks/wp-bulk-actions.php` stands in for WooCommerce's own
`do_bulk_action_mark_orders()`, and its header states that "anything it does
around the status change is replicated below". Only half of it is: the handler
calls `WC()->payment_gateways()` at `:26`, but never fires
`woocommerce_order_edit_status`.

Both upstream implementations do, once per changed order, immediately after the
status write:

- HPOS — `.stubs/woocommerce/src/Internal/Admin/Orders/ListTable.php:1567-1569`
- Legacy —
  `.stubs/woocommerce/includes/admin/list-tables/class-wc-admin-list-table-orders.php:507-509`

and the plugin's sibling handler for the single-order row action already
replicates it (`wp-ajax-wc-mark-order-status.php:59` and `:71`). So an
integration hooked on that action sees a Scanpay order completed from the row
action and misses every order completed in bulk — **including non-Scanpay
orders**, because `wc_scanpay_add_bulk_actions()` renames `mark_completed` for
the whole list, not just for our own orders (`admin/orders.php:41`).

### Fix

1. Fire `do_action( 'woocommerce_order_edit_status', $oid, 'completed' )` inside
   `wc_scanpay_handle_bulk_capture()`'s loop, after `$wco->save()` at
   `wp-bulk-actions.php:51` and before `++$changed`, matching upstream's
   ordering.
2. Pass what upstream passes: the order id, and the bare status without the
   `wc-` prefix.
3. **Fire nothing for the orders the loop skips.** A `continue` at `:44`
   (missing, already completed, or trashed) or at `:48` (capture failed, order
   parked on hold) means no status change happened, and upstream only fires
   after a successful `update_status()`.
4. Leave the note text alone. The two upstream copies disagree with each other —
   `'Order status changed by bulk edit.'` in HPOS, `…bulk edit:'` in legacy —
   ours matches HPOS, and churning a translated msgid to chase that is not worth
   a POT regeneration.
5. Do not add the action to `capture_or_hold()` or anywhere shared. It belongs
   to the two admin handlers that replace WooCommerce's own, and putting it in
   the capture primitive would fire it for the order-status hook path, where
   WooCommerce already fired it.

### Verify

- Quote both upstream loops and confirm the action fires exactly once per
  successfully changed order, after the write, with those two arguments.
- Trace all three skip paths and confirm each fires nothing.
- Confirm the row-action handler's two call sites (`:59` for the
  non-autocapture branch, `:71` for the captured one) still fire it exactly once
  each and are mutually exclusive — the bulk and row paths must now agree, not
  double up.
- Confirm `src/languages/*.pot` is unchanged.

### Handoff

Hook `woocommerce_order_edit_status` on a test shop and confirm: one call per
order for "Capture and complete"; one per order for the hijacked "Mark as
completed"; none for an order whose capture failed and was parked on hold; and
identical behaviour for a mixed selection of Scanpay and non-Scanpay orders.

---

## Task F: Reject an empty order key on the payment-return page

`wc_scanpay_init_thankyou()` gates on

```php
hash_equals( (string) $row['order_key'], (string) wp_unslash( $_GET['key'] ?? '' ) )
```

(`public/wp-scanpay-thankyou.php:107-112`), and the free-trial handler repeats
the shape at `:147-151`. When the stored key reads back as `NULL`, both sides
become `''`, `hash_equals()` returns true, and a request carrying a bare `key=`
passes the gate.

The column is nullable upstream — `order_key varchar(100) NULL` in the HPOS
operational-data table
(`.stubs/woocommerce/src/Internal/DataStores/Orders/OrdersTableDataStore.php:3512`)
— and the query joins it with a `LEFT JOIN` (`:58-61`), so a missing
operational-data row yields the same `NULL`. The legacy branch aggregates
`MAX( CASE WHEN meta_key = '_order_key' … )` (`:65`), which is `NULL` when the
meta is absent.

That gate is the only thing between an anonymous request and the busy-poll below
it, which holds a PHP worker for ~3.5s (`:120-123`). It also requires
`payment_method` to start with `scanpay`, so this is a narrow, low-severity hole
rather than a general one — but the gate is load-bearing and the fix is one
clause.

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

## Task G: Make the settings-screen include idempotent

`admin/settings/admin-options.php` declares `wc_scanpay_admin_notice()` at `:22`
and is pulled in with a bare `require` from
`WC_Gateway_Scanpay_Base::admin_options()`
(`abstract-wc-gateway-scanpay-base.php:111`). A second call in one request is a
fatal redeclaration.

The require-as-a-call idiom is deliberate (see Code style in `AGENTS.md`) and
this file genuinely needs `$gateway` in scope — but the idiom is only safe for
files that *declare* nothing. The plugin's other two such files already know
this: `public/generate-payment-link.php` and `admin/hooks/wp-bulk-actions.php`
both declare functions and are both pulled in with `require_once`.

WooCommerce renders one gateway's screen per request, so nothing calls it twice
today. This removes a trap, it does not fix a crash — which is why it sits
below the three substantive tasks.

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
  after `admin/settings.php` is loaded.
- Confirm `admin/settings.php` cannot itself be included twice — trace
  `wc_scanpay_admin_init()` and its `admin_init` registration, and say what
  would happen if it were.
- Confirm no other `require`d (not `require_once`) file in `src/` declares a
  function or a class. If one does, name it in `HANDOFF.md` rather than fixing
  it here.
- `pnpm phpcs` clean — the moved function keeps its docblock and its ignore
  comment.

### Handoff

Render all three gateway settings screens and confirm both notice variants — the
unconfigured "Thank you for choosing Scanpay" and the configured "Finish your
Scanpay setup" — still appear with their markup and links intact.

---

## Task H: Record subscriber revisions even when Subscriptions is inactive

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

1. Move the `wcs_enabled` check down so it guards only the subscription loop
   (`:453-485`), leaving the validation and the `scanpay_subs` upsert
   unconditional.
2. Keep the guards above the upsert exactly where they are. The id and rev
   throws (`:408-417`) stay fail-loud, and the `ref` early return (`:418-421`)
   stays above the upsert — a subscriber with no reference still has nothing to
   link, so the set of rows written for a WCS-active shop must not change.
3. `$this->wcs_enabled` is set once in the constructor (`:51`); keep it a
   property rather than re-testing `class_exists()` per call.
4. Do not log or throw when the loop is skipped. A shop without Subscriptions
   receiving subscriber changes is a normal state, not a protocol violation, and
   an error log per change would be noise on every ping.

### Verify

- Trace a `subscriber` change with WCS inactive under the new code and state
  exactly which rows and which order/subscription metadata get written.
- Confirm the cursor still advances in both cases, and that no statement moved
  above the upsert can throw where it previously returned — this must not turn a
  quiet skip into a wedged sync loop.
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

## Task I: Align the Blocks card title default with the classic one

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
   gateway** to obtain it — that would drag the whole gateway, and Task C's
   field load with it, into a payload built on most admin and frontend pages.
3. Leave the description fallback `''` alone. An empty description renders
   nothing, which is the correct degradation; the title has no such
   safe-empty form.
4. Do not wrap either literal in `__()`. These are stored-value defaults, and
   the Conventions section of `AGENTS.md` settles that `__()` cannot localize a
   stored value.
5. While here, check `upgrade.php`'s 2.0.0 fallback `'Pay by card.'` — with a
   trailing period — against `default_title()`'s `'Pay by card'`. Report the
   discrepancy in `HANDOFF.md`; **do not change it**. That value has been
   written into live stores' settings for releases, and rewriting a stored
   title during a migration is a different decision from fixing an unused
   fallback.

### Verify

- Confirm all three methods' title and description fallbacks now match their
  gateway counterparts, or state deliberately why one still does not.
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

## Task J: Contain the payment-link money math

`wc_scanpay_process_payment()` sums the order lines with
`wc_scanpay_addmoney()` at `public/generate-payment-link.php:179` and compares
the sum against the order total with `wc_scanpay_cmpmoney()` at `:190`. Both
throw `InvalidArgumentException` on non-money input (`library/math.php:39-42`),
and only the two `$client` calls are wrapped (`:146-151`, `:231-249`).

`WC_Checkout::process_checkout()` catches `Exception` and puts the message
straight in front of the shopper —
`wc_add_notice( $e->getMessage(), 'error' )`
(`.stubs/woocommerce/includes/class-wc-checkout.php:1419-1422`). So a bad line
total shows the customer `invalid money amount: '0' or ''` instead of the fixed
sentence every other failure in this file uses.

The input is genuinely outside our control: `$wco->get_line_total()` passes
through the `woocommerce_order_amount_line_total` filter, where a third party
can return `null`, which `wc_format_decimal()` turns into `''`.
`WCS_Scanpay_Charge::charge()` names this exact hazard and wraps its whole body
for it (`class-wcs-scanpay-charge.php:195-207`); the checkout path is the same
code without the handler.

### Fix

1. Wrap the item loop and the total comparison (`:166-202`) so a throw from the
   money helpers becomes the same customer-facing message the link-creation
   failures already produce, with the concrete cause logged through
   `scanpay_log()`.
2. Catch `\Throwable`, not `Exception`, for the reason `charge()` gives at
   `:315-317`: an `Error` from a filter callback is as fatal to the checkout as
   an exception, and here it would be an uncaught fatal rather than a notice.
3. **Reuse the existing msgid** — "Error: We could not create a link to the
   payment window. Please wait a moment and try again." (`:150`, `:248`). Do not
   add a string; this task must leave the POT untouched.
4. Keep the mismatch fallback at `:191-202` doing exactly what it does. An item
   list that does not add up is a warning and a degraded dashboard view, not a
   failed checkout. Only a *throw* is what this task contains.
5. Do not pre-validate each `$line_str` with `wc_scanpay_is_money()` instead.
   The helpers already validate; a second gate would be a third source of truth
   about what counts as money.

### Verify

- Quote WooCommerce's catch and confirm the raw message reaches
  `wc_add_notice()`.
- Enumerate every statement in `:166-202` that can throw and confirm each is
  inside the new handler.
- Confirm the two `$client` handlers are not nested inside the new one in a way
  that swallows their more specific messages, or state why nesting is harmless
  where it occurs.
- Confirm the Blocks/Store API checkout reaches the same `process_payment()` and
  benefits identically — name the path.
- Confirm `src/languages/*.pot` is unchanged.

### Handoff

Hook `woocommerce_order_amount_line_total` to return `null` on a test shop and
confirm the customer sees the fixed sentence, the log carries the concrete
cause, and no order is left holding a half-built payment link.

---

## Task K: Rotate the admin-AJAX secret when the merchant resets

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
or `manage_woocommerce` (`admin/settings/admin-options.php:139`). The reason to
fix it is the one the reset button already states: it exists to hand the store
to a different Scanpay account, and the outgoing account's operator should not
keep a working credential.

### Fix

1. Unset `secret` alongside `apikey` in the reset loop at `:118`. Doing it for
   all three options is fine and simplest — the two secondary gateway options
   never carry one, so it is a no-op there.
2. Do not mint a replacement here. `install.php` runs later in the same request
   (`:148`) and its `if ( empty( $settings['secret'] ) )` branch mints a fresh
   one; confirm that ordering rather than adding a second mint that would
   immediately be overwritten.
3. Extend the postcondition block at `:186-189` — which already rereads the
   primary option — to assert that a secret exists and differs from the
   pre-reset value. Capture the old value before the loop, next to where
   `$wcsp_shopid` is derived at `:64-65` and for the same reason: the loop
   destroys what you need to compare against.
4. Reuse the existing `verify_failed` code and fail closed through `$wcsp_fail`.
   Do not add a response code the settings JS does not recognize — it shows the
   code to the merchant verbatim.
5. Comment the one user-visible consequence: the settings page that triggered
   the reset still holds the old secret in its `data-secret` attribute
   (`admin-options.php:139`), so its polling is dead until reload.

### Verify

- Confirm `install.php`'s mint branch is reached after the removal within the
  same request, and that it writes with the autoload flag unchanged.
- Confirm the new postcondition cannot pass with the old secret still stored,
  and that it releases the flock through `$wcsp_fail` like every other failure
  in the file.
- Confirm no other reader caches the secret across the reset inside one request.
- Read `admin/assets/js/settings.ts` and state what the open settings page
  actually does after a successful reset — reload, or keep polling with a dead
  secret. That answer decides whether step 5 is a comment or a `HANDOFF.md`
  entry.

### Handoff

Reset a configured store and confirm the secret changed, that the old secret
403s against all three `?x=` endpoints, and that the settings page recovers on
reload.

---

## Task L: Do not flush the whole object cache per migrated subscription

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
which is why it is last.

### Fix

1. Establish what the flush is for before removing it.
   `git log -S 'wp_cache_flush' -- upgrade.php` and the surrounding 2.x tags are
   the record. If it turns out to be cargo-culted, say so in the commit body.
2. If nothing in the loop reads stale data, drop the call. If something does,
   replace it with the narrowest invalidation that covers that read — the
   subscription's own cache entry, never the site's.
3. Do not compromise by moving it below the loop. A single site-wide flush is
   still a site-wide flush; only step 1's answer justifies keeping one at all.
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
  stale without the flush.
- Confirm `WC_Subscription::save_meta_data()` invalidates the object's own cache
  — read the data store in `.stubs/`, do not assume it.
- Confirm the migration is still idempotent on a re-run after an interruption:
  the version is stamped last (`:160`), so a partial run must be safe to repeat.
- Confirm the `'edit'` context change alters no stored value and no branch
  outcome for an unfiltered store.

### Handoff

Only a store crossing 2.1.3 with a persistent object cache can settle the
performance half. The correctness half — that the backfill still selects the
right subid — is a data-shape question: describe the fixtures it would need
rather than claiming a result.
