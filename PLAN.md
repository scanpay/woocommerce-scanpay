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
