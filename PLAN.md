# PHP review follow-up plan

<!-- markdownlint-disable MD024 -->

Twenty tasks, **A** through **T**, in the order they must be done. One commit
each, titled `<summary> (task X)`. No `scanpay:` prefix — see Conventions in
`AGENTS.md`.

This is the second plan of its kind. The first one's twelve tasks landed as
`307e83b`..`2be8a7a`, plus the follow-up `e0e42b2`; nothing here reopens them.

## How to run this plan

This runs unattended, by one agent, in one pass. Nobody is watching, so
everything here answers a question you cannot ask.

Implement every task yourself, in order. Do not parallelize, do not delegate
implementation to subagents, do not start a task before the previous one is
committed, and never put two tasks in one commit. Read-only search agents are
fine.

For each task:

1. Read `AGENTS.md`, then `HANDOFF.md` — an earlier task may have left this one
   blocked, and nothing else records that. Then this header and the whole task
   section: grep for `## Task X` and read from there. Do not slurp the file; it
   is well past the size where a whole-file read comes back truncated, and you
   would silently get its first half.
2. Confirm the working tree is clean and the previous task's commit is `HEAD`.
   For the first task of the run there is no such commit: `HEAD` is then the
   commit this file was last revised in — `git log -1 --oneline -- PLAN.md`.
3. Implement it, in `src/` only — `build/` is generated.
4. Run `pnpm phpcs` (autofix `pnpm phpcbf`) and
   `find src -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l`,
   plus `pnpm lint:js` and `pnpm exec tsc` if any `.ts` changed, and
   `pnpm lint:style` if any `.scss` changed. All four are clean as this plan is
   written, so any diagnostic they print is yours.
5. Do the task's **Verify** bullets, and write those findings plus its
   **Handoff** list into `HANDOFF.md` at the repo root. That file is gitignored
   — never `git add` it. **It already exists** and holds the previous run's
   twelve entries: append, never truncate. Head each entry
   `## Run 2 · Task X — <summary>`, so the two runs stay distinguishable.
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

**Translations.** Two tasks add a msgid: **P** (a field title) and **T** (a
customer-facing order note). Two others touch strings a translator does *not*
see — B adds an untranslated hidden input, S fixes two untranslated log lines —
and their Verify steps say so; do not report them as catalog work. Leave
`src/languages/` alone either way: `./build.sh` owns extraction and the previous
run already left one catalog regeneration open. Record every msgid you touched in
`HANDOFF.md` so a translator can pick them up in one pass.

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
to answer an upstream question from memory. WooCommerce citations resolve
directly (`.stubs/woocommerce/includes/class-wc-order.php`), except the
abstracts, which sit one level down
(`.stubs/woocommerce/includes/abstracts/abstract-wc-payment-gateway.php`), the
templates (`.stubs/woocommerce/templates/`), the Blocks server code
(`.stubs/woocommerce/src/Blocks/`) and the HPOS internals
(`.stubs/woocommerce/src/Internal/`). WordPress core is
`.stubs/wordpress/wp-includes/` (and `wp-admin/includes/`). WCS ones sit under
`.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/`.
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
| A | A change-payment request reaches the gateway as `process_payment( $subscription->get_id() )`, and WCS's own comment above it calls that "a $0 order total" | `includes/class-wc-subscriptions-change-payment-gateway.php:335-339` |
| A | The $0 is produced by a **filter**, `woocommerce_subscription_get_total` → `maybe_zero_total()`, registered unconditionally in `init()` | `includes/class-wc-subscriptions-change-payment-gateway.php:33`, `:618-629` |
| A | `WC_Data::get_prop()` applies the `{hook_prefix}{prop}` filter **only** in the `view` context, so `get_total( 'edit' )` never sees that zeroing | `includes/abstracts/abstract-wc-data.php`, `get_prop()` |
| A, C | `WC_Subscriptions_Data_Copier` excludes only WC/WCS internals, so every `_scanpay_*` key on a subscription is copied onto every renewal order | `includes/class-wc-subscriptions-data-copier.php:20-44` (`DEFAULT_EXCLUDED_META_KEYS`) |
| B | The whole of `templates/checkout/terms.php` — including the `woocommerce_checkout_after_terms_and_conditions` hook our checkbox renders on — is inside `if ( apply_filters( 'woocommerce_checkout_show_terms', true ) && … )` | `templates/checkout/terms.php:11`, `:39` |
| B | WooCommerce enforces its own terms checkbox only when a hidden `terms-field` marker was posted | `templates/checkout/terms.php:33`, `includes/class-wc-checkout.php:794`, `:981` |
| D | `WC_Order_Refund extends WC_Abstract_Order` and defines no `get_transaction_id()`; that method exists only on `WC_Order`, and nothing in the chain defines `__call` | `includes/class-wc-order-refund.php:17`, `includes/class-wc-order.php:898` |
| E | `WC_Order::update_status()` catches `Exception` itself, logs, adds its own note and **returns false** — it only throws for a non-`Exception` `Throwable` | `includes/class-wc-order.php:402-426` |
| F | `wc_get_orders()` defaults `'limit'` to `get_option( 'posts_per_page' )`, and both data stores honour it | `includes/abstracts/abstract-wc-object-query.php:84` |
| G | WooCommerce's own row-action handler answers a bad nonce with `check_admin_referer()` → `wp_nonce_ays()`, WordPress's "please try again" page, not JSON | `includes/class-wc-ajax.php:666-682`, `wp-includes/pluggable.php:1394-1397` |
| G | The row-action nonce is per *action*, not per order — the order id rides in the query string next to it | `src/Internal/Admin/Orders/ListTable.php:1340`, `:1348` |
| H | `do_action( "activate_{$plugin}", $network_wide )` fires **once** however wide the activation is, so a network activation runs `install.php` for one blog only | `wp-admin/includes/plugin.php:703` |
| I | `WC_Settings_API::get_form_fields()` returns `apply_filters( 'woocommerce_settings_api_form_fields_' . $this->id, array_map( [ $this, 'set_defaults' ], $this->form_fields ) )` | `includes/abstracts/abstract-wc-settings-api.php:66-68` |
| I | An included file inherits the including method's class scope, so `$this->default_title()` inside `admin/settings/fields/*.php` resolves and its `protected` visibility is legal | standalone `php` script; a style bound, not a latent fatal |
| K | `array_merge()` with a non-array argument is a `TypeError` on PHP 8, not a warning | `php -r 'array_merge( [], false );'` |
| M | `wp_send_json()` sets the content type and status only `if ( ! headers_sent() )` | `wp-includes/functions.php:4593-4598` |
| N | HPOS `get_bulk_actions()` returns `array()` when the user lacks `edit_others_posts`, and `WP_List_Table` applies the filter **before** testing for emptiness | `src/Internal/Admin/Orders/ListTable.php:323-327`, `wp-admin/includes/class-wp-list-table.php:598`, `:605` |
| O | WCS copies the parent order's meta onto the subscription from `woocommerce_checkout_order_processed` priority 100, which fires *before* `process_order_payment()` calls our `process_payment()` | `includes/class-wc-subscriptions-checkout.php:24`, `:184`, `includes/class-wc-checkout.php:1396`, `:1414` |
| Q | Nothing in `src/` or `build/` reads a `scanpay` key off `wcSettings`; the Blocks payload arrives through the separate `scanpay_data` setting | `src/public/assets/js/checkout.ts:13`, `grep -r wcSettings src build` |
| R | `WC_Payment_Gateway` **declares** `$title` and `$description` (uninitialized) and its own `init_settings()` already normalizes `$enabled` to `'yes'`/`'no'` | `includes/abstracts/abstract-wc-payment-gateway.php:68`, `:75`, `:247-250` |
| R | The Blocks payload is built from `woocommerce_blocks_checkout_enqueue_data` **and** `woocommerce_blocks_cart_enqueue_data`, the latter fired by the Cart *and* Mini Cart blocks | `src/Blocks/Payments/Api.php:48-49`, `src/Blocks/BlockTypes/Cart.php:303`, `MiniCart.php:236` |
| R | `WC_Countries::get_country_calling_code()` applies no filter and unwraps an array itself | `includes/class-wc-countries.php:165-182` |

## Settled with Scanpay — answered, and not fixed here

Two backend facts, confirmed by Scanpay and derivable from no stub. Both are
load-bearing; neither may be re-derived from the documentation or from what the
code appears to assume.

1. **`/v1/new` with a `subscriber.ref` and no `items` creates a subscriber, and
   nothing else.** No transaction, and the `orderid` is discarded rather than
   stored, so nothing comes back through the seq. Task A relies on this.
2. **`/v1/subscribers/{subid}/renew` charges nothing.** It returns a link to a
   page where the customer updates their payment details. The documentation was
   right and the code is wrong.

**What fact 2 makes false, and what this plan does about it: nothing.** No task
below fixes it, deliberately — the remedy is a product decision about what the
"Pay now" link should mean, not a review one. Do not improvise one mid-run.
Record the following in `HANDOFF.md` as the run's first entry instead:

`wc_scanpay_process_payment()` routes a customer to `renew()` whenever the order
carries `_scanpay_subid` (`src/public/generate-payment-link.php:109-168`), and
for `$paid_renewal` — a customer retrying a failed renewal, *or resubscribing* —
treats the result as a payment. Four consequences follow:

- The customer is misled. They follow "Pay now", update a card, and land on an
  order-received page for an order that is still unpaid, with no error and no
  explanation. The money arrives later, if at all, on WCS's retry schedule —
  which the card update does unblock, because the subscriber rev bumps and
  `WCS_Scanpay_Charge::idempotency_key()` therefore builds a new key.
- A resubscribe takes the same route, so a *new* subscription can be created
  whose initial payment never happens.
- The return holds a PHP worker for ~3.5 s (`wp-scanpay-thankyou.php:125-128`,
  17 rounds) polling for a `transaction_id` that cannot arrive.
- A completion intent is persisted (`:157-163`) for an attempt that is not a
  payment. Usually harmless — `WCS_Scanpay_Charge::charge():308-313` overwrites
  it before the retry charges — but it survives when no retry ever runs.

## Open question — not a task

One thing this review could not settle from the tree. **Do not implement
anything for it.** Copy it into `HANDOFF.md` beside the entry above so it gets
asked.

- **The reconciliation note claims something that is usually false.**
  `WC_Scanpay_Sync::report_incomplete()` tells the merchant "nothing will retry
  the completion" (`class-wc-scanpay-sync.php:385`). But `payment_complete()`
  persists at `class-wc-order.php:184`, *after* the status change, and its own
  `catch ( Exception )` returns `false` without saving — so on most failure
  paths `transaction_id` was never written, `sync()`'s replay gate at `:253` is
  still open, and the next drained revision of that transaction does retry, and
  adds a second copy of the note. Only a failure inside the post-save
  `woocommerce_payment_complete` action makes the sentence true. Two ways out —
  persist before the attempt (one `$wco->save()` moved out of the not-eligible
  branch, which makes the sentence true and the note one-shot, at the cost of one
  extra write per newly paid order), or reword the string. That is a product
  decision, not a review one. The same open gate is why an underpaid order
  collects one "does not cover the order total" note per revision.

## Do not weaken

Money-string arithmetic (`src/library/math.php`), the ping protocol, the sync
flock, the API-key write-once policy, the lock-free subscription charge design.

Standing decisions, so no task reopens them:

- **Performance work is out of scope here.** `docs/performance-review.md` owns
  it, item by item, with a suggested order. Nothing in this plan is justified by
  a cost claim, and none of these tasks is that document's item 1–9. Do not merge
  the two lists.
- **Renewal retry pacing is solved.** `wcs_scanpay_retry_rule()` raises WCS's
  `retry_after_interval` to a ≥25h floor on Scanpay orders. No second mechanism.
- **Charging stays lock-free.** No DB lock, no `nxt`, no key rotation. Task C
  adds one meta write before the charge; it is a stamp, not a lock.
- **The subscription terms checkbox is cart-level consent**, covering whichever
  gateway the customer picks, ours or a third party's, enabled or not. Task B
  changes when it is *enforced*, never whose checkout it covers.
- **The router's three early returns exist to register nothing.** No task here
  turns any of them into a partial plugin load.
- **The Blocks `description` fallback is deliberately `''`** on all three
  methods. An empty description renders nothing, which is the correct
  degradation; only the title fallbacks were aligned, on purpose (run 1, task K).
- **`upgrade.php`'s stored `'Pay by card.'`** stays as it is. Rewriting a value
  live stores already hold is a different decision from fixing a fallback.
- **`get_total( 'edit' )` everywhere else is right.** Task A is not a licence to
  switch the other five call sites to the view context: capture, sync and the
  charge path all read real orders, where the view filter is a third party
  deciding what the stored amount is.
- **`scanpay_meta` is upserted before the order is loaded**, deliberately, so a
  replay restores the row without re-firing `payment_complete()`. Task D guards
  what happens *after* that upsert; it does not reorder it.
- **Settings-field *defaults* stay plain strings.** Task P translates a field
  *title*, which is a label; `__()` still may not wrap a stored value.
- **The reason-less `phpcs:ignore` lines are style debt, not this plan's work.**
  About thirty of them exist, nearly all the `ExceptionNotEscaped` line above a
  `throw`. No configured sniff enforces the rule. Task S deletes exactly one, and
  for a different reason — that it suppresses nothing. No task here sweeps them,
  and no verification step greps for them tree-wide.
- **The two backend facts above are settled**, and neither is in any stub. Do not
  design around the opposite assumption, and do not re-derive either from the
  public documentation — on `renew()` the documentation is right and the code is
  wrong, which is exactly the shape that invites a re-derivation to land the
  wrong way round.

## Order

| Task | Focus | File | Depends on |
| --- | --- | --- | --- |
| A | A payment-method change must not build a priced payload | `public/generate-payment-link.php` | — |
| B | The terms checkbox can be enforced without ever being rendered | `public/wcs-scanpay-checkout-terms.php`, `woocommerce-scanpay.php` | — |
| C | A renewal charge must record the shop it ran under | `library/class-wcs-scanpay-charge.php`, `public/generate-payment-link.php` | A |
| D | Two ways one change can wedge the whole sync loop | `library/class-wc-scanpay-sync.php` | — |
| E | `update_status()` reports failure by return value, not by throwing | `woocommerce-scanpay.php`, `library/class-wc-scanpay-capture.php` | B |
| F | An order with more than ten subscriptions loses the rest | `public/generate-payment-link.php` | A, C |
| G | The mark-completed row action: three defects in one handler | `admin/hooks/wp-ajax-wc-mark-order-status.php`, `admin/hooks/wp-ajax-wc-scanpay-capture.php` | — |
| H | A fresh multisite blog is migrated as if it were 1.x | `upgrade.php`, `install.php` | — |
| I | `get_form_fields()` drops WooCommerce's field filter | `gateways/abstract-wc-gateway-scanpay-base.php`, `admin/settings/fields/*.php` | — |
| J | The admin polling URL assumes pretty permalinks | `admin/orders.php`, `admin/subscriptions.php`, `admin/settings/admin-options.php`, `admin/assets/js/*` | — |
| K | The 2.2.0 migration branch assumes an array | `upgrade.php` | H |
| L | The seq-row seed is the one write nothing checks | `install.php` | H |
| M | The long-poll answers `text/html` | `admin/ajax/wp-scanpay-fetch-meta.php`, `-sub.php` | — |
| N | Our bulk action re-fills a list WooCommerce emptied on purpose | `admin/orders.php` | J |
| O | The subscription meta box reads two keys nothing writes | `admin/subscriptions.php` | J |
| P | One settings-field title never translates | `admin/settings/fields/scanpay.php` | I |
| Q | A filter nothing consumes | `admin/settings.php` | — |
| R | Comments that no longer describe the code | five files | A, C |
| S | Three hygiene gaps the guide already rules on | `gateways/class-wc-gateway-scanpay-{mobilepay,applepay}.php`, `admin/settings/admin-options.php`, `public/generate-payment-link.php`, `library/class-wcs-scanpay-charge.php` | A, C, F, R |
| T | The renewal "Pay now" link tells the customer it charged them | `public/generate-payment-link.php` | A, C, F, R, S |

**A through G are live defects**: A and C move money the wrong way, B and F stop
a customer buying, D can take a shop's whole sync down, E hides two wrong states,
G changes core WooCommerce behaviour for orders we do not own. H through M are
hardening on cold or host-dependent paths, plus one upstream contract (I) we
silently drop. N through S change no behaviour a correctly configured store
would notice.

**T is a live defect too, and it lands last on purpose.** It is not ordered by
severity but by its file: it is the **sixth** task to edit
`public/generate-payment-link.php` — after A, C, F, R (item 5) and S — and it
must read that file as all five left it. Do not promote it, and do not fold it
into any of them.

Seven orderings are not free, all of them "do not edit one file from two
directions": **C, F, R, S and T re-read the file A rewrote**; **K and L touch
files H edits**; **P edits the field file I rewrites**; **N and O edit files J
touches**; **R rewrites comments C and A move**; **E edits
`woocommerce-scanpay.php` after B has added a line to it**; **T rewrites the
block C stamps**. Everything else is disjoint.

`public/generate-payment-link.php` is the one file six tasks queue on, in this
order: **A** (new method-change branch), **C** (the `$paid_renewal` meta write),
**F** (`wc_scanpay_subref()`'s limit), **R** item 5 (the `is_string()` comment at
the head of the file), **S** item 3 (a log string), **T** (the `$subid` branch).
Every one of those anchors sits below the previous task's edit or above it, never
inside it — but the line numbers move, so locate the symbol.

## Task G — The mark-completed row action: three defects in one handler

**Files:** `src/admin/hooks/wp-ajax-wc-mark-order-status.php`;
`src/admin/hooks/wp-ajax-wc-scanpay-capture.php:25` for the third.

This handler is registered at priority 0 ahead of WooCommerce's own
`WC_AJAX::mark_order_status()`, so it sees **every** order-list row action on the
site, for every status and every gateway. Three things follow from that, and the
file gets all three wrong.

**1. The gate runs before the "not ours" return.** Lines `:17-22` answer a failed
capability or nonce check with `wp_send_json_error( 'forbidden', 403 )`, which
dies — before line `:25` has decided whether this request is even about a
completion, and long before `:42` has decided whether the order is ours.

WooCommerce answers a stale nonce with `check_admin_referer()` → `wp_nonce_ays()`,
WordPress's "Are you sure you want to do this? / Please try again" page
(`class-wc-ajax.php:666-682`, `pluggable.php:1394-1397`), and these links are
ordinary browser navigations, not `fetch`. (Only a stale nonce: `:667` is
`current_user_can( … ) && check_admin_referer( … )`, so a *capability* failure
short-circuits and never reaches `wp_nonce_ays()` — WooCommerce just redirects.
Ours answers both with JSON, and the nonce half is the one that matters, because
a capability failure means the row action was never rendered.)

So a shop manager who leaves the Orders list open past the nonce's 24 h lifetime
and clicks a row action on a **PayPal** order gets a white page reading
`{"success":false,"data":"forbidden"}`, with no way to retry. We changed the
failure mode of a core WooCommerce action for an order we do not own. Both row
actions are affected, and "Complete" is the common one: WooCommerce renders
"Processing" for `pending`/`on-hold` and "Complete" for
`pending`/`on-hold`/`processing` (`ListTable.php:1338`, `:1346`). Hoisting the
status test alone would therefore fix the rarer half and leave the frequent one —
which is why the fix reorders the whole gate rather than moving one test.

**2. No trash or terminal-status guard.** The sibling bulk handler documents this
one as mandatory and says why: "'trash' is the last line of defense: the menu
does not offer our actions in the trash view, but the handler must not rely on
that — capturing a trashed order would charge the customer and untrash it"
(`admin/hooks/wp-bulk-actions.php:41-45`). This path relies on the menu. The
nonce is per *action*, not per order — the order id rides in the query string
beside it (`ListTable.php:1340`, `:1348`) — so one valid link is reusable for any
order id, including a trashed one. Not a privilege escalation, since the actor
already has `edit_shop_orders`, but it is a money-moving action on an order the
UI deliberately excluded, and the bulk path already refuses it.

**3. `(string) wp_unslash( $_GET['order_id'] )` warns on an array.**
`wp_unslash()` of an array returns an array, and casting one to string is
`Warning: Array to string conversion` on PHP 8. The rejection itself is correct
(`ctype_digit( 'Array' )` is false), but every `order_id[]=1` request writes to
the error log, and with `display_errors` on it corrupts the response body.
`wp-ajax-wc-scanpay-capture.php:25` has the identical cast on `$_POST['oid']`.
Upstream avoids the shape entirely with `absint( wp_unslash( … ) )`
(`class-wc-ajax.php:669`).

**The fix.**

1. **Split the gate: capability first, nonce last.** `current_user_can(
   'edit_shop_orders' )` keeps its `wp_send_json_error( 'forbidden', 403 )` and
   stays the first statement in the file, so the property
   `wp-ajax-wc-scanpay-capture.php:18-20` documents — an *unauthenticated* caller
   learns nothing about order ids — is untouched. Everything the handler does
   between there and the nonce reads only the caller's own parameters plus one
   order the actor may already edit, so nothing is disclosed that the Orders
   screen does not already show. Then, in order: the `'completed' !== $_GET['status']`
   test, the `order_id` validation, `wc_get_order()`, the `str_starts_with( …,
   'scanpay' )` test — each keeping the exact exit it has today, which is not the
   same exit for all four. Three `return` and fall through to WooCommerce; the
   `order_id` validation **dies**, on `wp_send_json_error( 'invalid_order_id', 400 )`,
   and it keeps dying after the reorder. That leaves exactly one JSON answer
   above the nonce check, and it is deliberate: it is unreachable from a rendered
   row action (WooCommerce builds that URL from `$order->get_id()`), it is not a
   CSRF hole because nothing above the nonce writes, and turning it into a
   `return` would hand a malformed request to `WC_AJAX::mark_order_status()`,
   which answers it with `absint()` — silently completing order 0, or whatever
   `'12abc'` truncates to. Say so in the comment. Only then —

   ```php
   if ( ! check_ajax_referer( 'woocommerce-mark-order-status', false, false ) ) {
       return; // WC_AJAX::mark_order_status() re-checks it and answers wp_nonce_ays().
   }
   ```

   Returning rather than dying is the whole point: WooCommerce's own handler runs
   next and does the nonce check properly, so a stale nonce produces WordPress's
   retry page for every order on the site, ours included. Comment it with that —
   the nonce is verified twice on our path, by us to decide whether to act and by
   WooCommerce to decide whether to refuse, and only WooCommerce's answer is ever
   rendered to a human.
2. After `$wco = wc_get_order( $oid );`, add the bulk handler's guard —
   `if ( in_array( $wco->get_status(), [ 'completed', 'trash' ], true ) ) { return; }`
   — citing `wp-bulk-actions.php:41-45`. The comment must say what differs, not
   only what is shared: there `continue` skips the order outright, here `return`
   hands it to `WC_AJAX::mark_order_status()`, which still completes it. What the
   guard buys is that no *capture* runs on a trashed order — the customer is not
   charged. It does not, and cannot from here, stop WooCommerce untrashing it.
3. **Drop the `(string)` cast; do not merely guard it.** The cast is the whole
   bug: `ctype_digit( [] )` is a plain `false` on PHP 8 with no diagnostic
   (`php -r 'var_dump( ctype_digit( [] ) );'`), and `(string) []` is what emits
   `Array to string conversion`. So bind the unslashed value to a local and test
   it, the same shape in both files:

   ```php
   $raw = wp_unslash( $_GET['order_id'] );
   if ( ! is_string( $raw ) || ! ctype_digit( $raw ) ) {
   ```

   In `wp-ajax-wc-scanpay-capture.php:25` the superglobal is `$_POST['oid']` and
   the line already opens with its own `! isset( … )`, so the binding goes below
   that check and the rest is identical.

   `is_string()` rather than leaning on `ctype_digit`'s own `false`, because the
   next reader must not have to know that rule; and not upstream's
   `absint( wp_unslash( … ) )`, which accepts `'12abc'` as 12 and is exactly the
   leniency the die in step 1 exists to refuse. The `phpcs:ignore` moves onto the
   assignment, where the superglobal is now read; its reason is unchanged.

The nonce check lands between the `str_starts_with` return at `:42-44` and the
`remove_action( 'woocommerce_order_status_completed', … )` at `:46-48`. That
boundary is the invariant the reorder must not break: everything above it only
reads, everything below it changes the request's behaviour or the order.

### Verify

- Quote `class-wc-ajax.php:666-682` and `pluggable.php:1394-1397` and state what
  a stale nonce produces before and after the reorder, for **both** row actions
  and for a Scanpay and a non-Scanpay order — four cases, since the old gate
  answered JSON to all four and the new one answers none of them.
- Confirm `current_user_can()` is still the first statement executed in the file,
  so the `wp-ajax-wc-scanpay-capture.php:18-20` property holds for an
  unauthenticated caller; state plainly which facts an authenticated
  `edit_shop_orders` actor can now learn before the nonce is checked (that an
  order id exists, and whether it is ours) and why the Orders screen already
  shows both.
- Confirm that returning early still lets `WC_AJAX::mark_order_status()` run —
  the action is not removed, only ours declines — and that it re-verifies the
  nonce itself, so declining is not a bypass.
- Walk the reordered file top to bottom and name every statement above the nonce
  check, confirming each one only reads. A single side effect above it — the
  `remove_action`, a status write, a capture — is a CSRF hole, and this is the
  step that catches it.
- `php -r 'var_dump( (string) [] );'` and `php -r 'var_dump( ctype_digit( [] ) );'`,
  both pasted verbatim, so `HANDOFF.md` records that the cast is the sole source
  of the warning.
- Name the one path that still answers JSON above the nonce check
  (`invalid_order_id`), state that it is unchanged from today, and confirm from
  `ListTable.php:1340` / `:1348` that a rendered row action cannot produce it.

### Handoff

- On a shop: let a nonce go stale (or tamper with `_wpnonce`), then click both
  "Processing" and "Complete" on a non-Scanpay order and on a Scanpay one, and
  confirm WordPress's retry page appears in all four instead of raw JSON.
- Trash a Scanpay order, re-issue a valid "Complete" link with its id, and
  confirm **nothing is captured** — no Scanpay capture call, no capture note. The
  order itself will still be completed and untrashed by WooCommerce's handler;
  that is core behaviour for an order we declined, not a regression.

## Task H — A fresh multisite blog is migrated as if it were 1.x

**Files:** `src/upgrade.php` (the head, above the `< 2.0.0` branch);
`src/install.php:100-107` (the comment only).

`register_activation_hook()` fires `activate_{$plugin}` **once**, whatever
`$network_wide` is (`wp-admin/includes/plugin.php:703`). So a network activation
runs `wc_scanpay_activate()` → `install.php` for one blog's `$wpdb->prefix`.
Every other blog — and every blog created afterwards — first meets the plugin at
the loader gate (`woocommerce-scanpay.php:411`) with **both** options absent, and
is sent through `upgrade.php` with `$version = '0.0.0'`: the `< 2.0.0` branch,
which is the 1.x migration.

Two things go wrong there. The `require WC_SCANPAY_DIR . '/install.php'` at
`upgrade.php:17` evaluates `$fresh_install` as **true** on that blog (no
settings, no version) and stamps the version at `install.php:106` — inside the
migration, defeating the "stamped last, so an interrupted upgrade re-runs from
the start" invariant at `upgrade.php:164-165`. Then `:20-36` writes a settings
option derived from a 1.x install that never existed, with hard-coded English
values (`'Pay by card.'`, `'Pay with card through Scanpay.'`) that are not the
gateway defaults.

The failure this opens is the one `install.php:72-76` says the discriminator
exists to prevent: if anything below the stamp throws — the 2.1.3 branch runs
`wcs_get_subscription()` and `save_meta_data()` over every subscription — the
loader logs and keeps its transient, but the version is *already* stamped, so
the gate never fires again, `:100-113` never derives `wc_autocapture`, and
nothing is left to retry it. What an absent `wc_autocapture` then means is worth
stating exactly, because the tree reads that key with two different fallbacks:
`woocommerce-scanpay.php:132`, `admin/orders.php:12` and
`wp-ajax-wc-mark-order-status.php:57` compare against `?? ''` and so return
early — capture on completion, the path that settles a physical order, never
runs. `generate-payment-link.php:78` and `class-wcs-scanpay-charge.php:264`
fall back to `'completed'` instead, so the payload's own `autocapture` flag is
still set for a virtual order. The merchant is therefore left with capture
silently off for everything WooCommerce has to ship, which is the harm.

`install.php:102-104` names `upgrade.php` as one of the callers where the stamp
is "a no-op … which all run on a shop that already has settings, a version, or
both". That is true of the reset endpoint and of the card gateway's first-key
save, and false of this one.

**The fix.**

1. Give `upgrade.php` a fresh-install exit **above the `scanpay_log( 'info', "Upgrading …" )`
   at `:10`** and before the `< 2.0.0` branch: when both `WC_SCANPAY_URI_SETTINGS`
   and `wc_scanpay_version` are absent there is nothing to migrate, so
   `require WC_SCANPAY_DIR . '/install.php';` — which creates this blog's tables
   and stamps the version through its own `$fresh_install` path — and `return`.
   Above the log line, not below it: a blog with no history must not report an
   upgrade "from 0.0.0" it never ran, and that line is the only record a merchant
   or a support case ever sees of this path. `set_time_limit( 60 )` at `:9` may
   stay where it is; three `CREATE TABLE`s do not need it.
2. **`$version` cannot answer the question — do not test it.** `:7` reads the
   option with a `'0.0.0'` default, so an absent version and a stored `'0.0.0'`
   are the same string by the time any branch sees it. The exit has to read the
   options itself:
   `if ( false === get_option( WC_SCANPAY_URI_SETTINGS ) && false === get_option( 'wc_scanpay_version' ) )`
   — the same two reads, in the same order, as `install.php:77`. Both are
   autoloaded, so this costs nothing.

   That makes the fresh-install discriminator live in two files, and they must
   stay in step. Comment it on the `upgrade.php` side by pointing at
   `install.php:72-76`, which is where the *reason* is written down — settings
   absent, not version absent, because 1.x wrote settings and never a version.
   Anyone who "simplifies" this side to a version test alone re-opens exactly the
   bug that comment exists to prevent.
3. Verify the stamp before returning, as `:168-174` does for the normal path:
   `if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION ) { throw new Exception( 'Could not store the new plugin version' ); }`.
   The early return bypasses that tail, and the tail's own comment says why it is
   there — "far better than reporting a version this site does not have". The
   throw lands in the loader's `catch` (`woocommerce-scanpay.php:417-423`), which
   keeps the five-minute transient and retries; `install.php` is idempotent, so
   the retry costs three `SHOW TABLES LIKE` and nothing else.
4. Comment the exit with the mechanism: the activation hook fires once
   (`wp-admin/includes/plugin.php:703`), so on a network activation every blog
   but one arrives here, and the 1.x branch would otherwise write 1.x defaults
   over a blog that has no history.
5. Correct `install.php:102-104` to say that `upgrade.php` returns before
   reaching the 1.x branch, so the stamp there is the fresh-install stamp and
   not a mid-migration one. The current sentence lists `upgrade.php` among the
   callers where the stamp "is a no-op"; after this task it is the opposite —
   the one caller where the stamp is the point.

**Do not** make `wc_scanpay_activate()` loop the network's blogs. `uninstall.php`
has to, because it must reach blogs the plugin is no longer active on; activation
does not, and a per-blog install driven by the loader is the same design the
single-site path already uses.

### Verify

- Quote `wp-admin/includes/plugin.php:703` and state that `$network_wide` is a
  hook argument, not a loop.
- Put the new condition and `install.php:77` side by side in `HANDOFF.md` and
  confirm they test the same two options in the same order. If they have drifted
  by the time you read this, the source wins and the divergence is the finding.
- Walk a fresh blog through the loader with the new exit in place and confirm:
  tables created, version stamped once and re-read, the "Upgrading … from 0.0.0"
  line **not** logged, and the settings option untouched by the 1.x branch —
  after `install.php` it holds a `secret` and nothing else.
- State plainly where `wc_autocapture` comes from on that blog, and do not write
  "the gateway field defaults" without checking: `WC_Settings_API::init_settings()`
  merges them into the gateway *object*, not into the stored option, while the
  three readers above call `get_option()` directly. The stored key appears only
  once the merchant saves the settings form — which they must do to enter an API
  key, so no payment can precede it. Record that reasoning, not the shortcut.
- Confirm the exit cannot fire on a real 1.x site: those have a settings option
  (`install.php:72-76`), so the first condition already fails.
- Confirm the `< 3.0.0` branch's claim at `:127-128` — that it can never see
  `$fresh_install === true` — still holds after the change.

### Handoff

- Only a multisite network settles this. On one: network-activate the plugin,
  then visit a second blog's wp-admin and confirm its
  `woocommerce_scanpay_settings` is absent or gateway-default rather than
  1.x-derived, and that `wc_scanpay_version` is stamped once.
- Create a new blog on that network and confirm the same.

## Task I — `get_form_fields()` drops WooCommerce's field filter

**Files:** `src/gateways/abstract-wc-gateway-scanpay-base.php:62-67`;
`src/admin/settings/fields/scanpay.php`, `scanpay_mobilepay.php`,
`scanpay_applepay.php`.

Upstream, `WC_Settings_API::get_form_fields()` is

```php
return apply_filters( 'woocommerce_settings_api_form_fields_' . $this->id, array_map( array( $this, 'set_defaults' ), $this->form_fields ) );
```

(`abstract-wc-settings-api.php:66-68`). Our override returns `$this->form_fields`
raw, so both halves are gone: the documented way to extend any WooCommerce
gateway's settings — the one WooCommerce's own PayPal gateway uses — silently
does nothing on all three of ours, and `set_defaults()` no longer backfills a
`default` key for a field declared without one. The second half is inert today
(every field carries a `default`) and will not stay inert by itself.

The same method has a second problem. `AGENTS.md` states the bound on the
require-as-a-call idiom: *"a required file never relies on inherited class scope:
bare `$this`, `parent::` or a protected call means it is a method body in the
wrong file"*. All three field files break it twice each, with
`$this->default_title()` and `$this->default_description()` — protected calls
from a data file. It works (an included file inherits the including method's
class scope; verified), so this is a style bound rather than a latent fatal — but
it also makes those files unusable from anywhere else, which is not obvious from
reading them.

**The fix**, both halves in one method:

1. Assign the require's result to a local, stamp the two defaults the gateway
   owns onto it — `$fields['title']['default'] = $this->default_title();` and the
   description twin — then assign to `$this->form_fields`.
2. Return
   `apply_filters( 'woocommerce_settings_api_form_fields_' . $this->id, array_map( [ $this, 'set_defaults' ], $this->form_fields ) )`,
   matching upstream exactly. The lazy load stays: the filter and the map run on
   the return, the require still happens once.
3. Delete both `$this->` calls from each field file, leaving those `default` keys
   out of the returned arrays entirely — do not leave a literal behind, or the
   one-source rule the abstract's docblock states breaks in a way no tool will
   catch.
4. Move that docblock's "one source, shared by `init_gateway_props()` and the
   gateway's own field definitions" sentence onto the injection, which is where
   the sharing now happens.

### Verify

- Quote `abstract-wc-settings-api.php:66-68` and state what the override dropped.
- Diff the built field array before and after for all three gateways —
  `var_export()` under a scratch harness, or key by key in `HANDOFF.md` — and
  confirm `title` and `description` keep the same `default` values.
- Confirm `set_defaults()` is callable from the subclass (check its visibility in
  the stub) and that running it over our fields changes nothing today.
- Confirm no other caller requires `admin/settings/fields/*.php`
  (`grep -rn "settings/fields" src/`), and that the three files no longer contain
  `$this`, `parent::` or `self::`.
- Re-read `init_gateway_props()`'s "?? only fires for a saved array missing the
  key" note and confirm it did not become false.

### Handoff

- On a shop with no saved settings, open each of the three gateway screens and
  confirm Title and Description are pre-filled with the shipped defaults.
- Add a one-line `woocommerce_settings_api_form_fields_scanpay` callback in a
  site plugin and confirm it now takes effect.

## Task J — The admin polling URL assumes pretty permalinks

**Files:** three payload sites — `src/admin/orders.php:105-113`,
`src/admin/subscriptions.php:33-36`,
`src/admin/settings/admin-options.php:130-134` — and **five** TS files, not
three: the fetch sites `admin/assets/js/order.ts:175`, `subs.ts:75` and
`util/compat.ts:22`, plus `admin/assets/js/settings.ts:46`, the only caller of
`getLastSync()`, and `admin/assets/js/types/order.d.ts:12-32`, where the
`OrderData` interface `order.ts` reads is declared. Miss either of the last two
and `pnpm exec tsc` fails; they are named here so that failure is not the first
you hear of them.

All three admin polls are fetched from a hardcoded relative path:

```ts
fetch('../wp-scanpay/fetch?x=ping', { headers: { 'X-Scanpay': secret } })
```

Nothing registers a rewrite rule for `/wp-scanpay/fetch` (`grep add_rewrite src/`
is empty), and nothing needs to: the router dispatches on the `X-Scanpay` header
plus `?x=` alone (`woocommerce-scanpay.php:95-107`), so *any* URL that boots
WordPress works. The path only resolves because WordPress's catch-all front
controller swallows unknown URLs — which on Apache means the `mod_rewrite` block
that WordPress writes **only when a permalink structure is set**. With plain
permalinks the request is a filesystem 404 and PHP never runs: the order meta box
shows its load failure, the subscription box the same, and the settings screen's
"Synchronized N seconds ago" indicator never resolves. On nginx with the usual
`try_files … /index.php` it works either way, which is why this is
host-dependent rather than always broken.

**The fix.** Send the base from PHP and build the URL from it, so the request
goes to a real PHP file rather than through a path that has to be rewritten:

1. Add the base to each of the three payloads that already carry the secret: an
   `'endpoint'` key in `orders.php`'s `$props`, and a `data-endpoint` attribute
   on `subscriptions.php`'s `#wcsp-meta` and `admin-options.php`'s
   `#wcsp-set-alert`. The value is `admin_url( 'admin-ajax.php' )` — `esc_url()`
   in the two attributes, raw in the JSON payload, which is already
   `wp_json_encode`d.

   **Not `home_url( '/' )`.** Both dispatch identically — the router only reads
   the header and `?x=` — but the poll carries a custom `X-Scanpay` request
   header, which makes it a CORS-preflighted request the moment its origin
   differs from the admin screen's. `home_url()` and the admin origin part
   company on ordinary setups: `FORCE_SSL_ADMIN` over an `http` home, or
   `WP_SITEURL` on its own host. WordPress answers no preflight, so that would
   trade a plain-permalink bug for a broken poll on sites where it works today.
   `admin_url()` is same-origin with the screen doing the fetching by
   construction. Say that in the comment, or someone will "simplify" it back.

   Reaching admin-ajax.php costs nothing: it is `wp-load.php` that loads
   plugins, so our dispatch at `woocommerce-scanpay.php:95-107` runs — and the
   endpoint `die()`s — before admin-ajax.php sends its own
   `Content-Type: text/html` header or looks for an `action` parameter.
2. In the TS, build the request as `${endpoint}?x=meta&…` instead of
   `../wp-scanpay/fetch?x=…`, falling back to the current relative path when the
   value is missing so a stale cached script keeps working. How each site gets
   the value is decided here, not in the moment:

   - **`order.ts:175`** reads `data.endpoint` off `window.ScanpayOrderData`, so
     add `endpoint: string;` to the `OrderData` interface in
     `types/order.d.ts:12-32`, beside `secret`, with the same one-line trailing
     comment the neighbours carry. Without it `tsc` fails on the property, and
     the `.d.ts` is the only place that shape is written down.
   - **`subs.ts:75`** reads its own `#wcsp-meta` dataset, like `secret` and
     `subid` above it. Self-contained.
   - **`util/compat.ts:22`** is inside `getLastSync()`, which is a shared helper
     and must **not** reach into the DOM for it. Take the base as a parameter —
     `getLastSync( secret: string, endpoint: string, force = false )` — for the
     same reason `secret` is already a parameter: this file knows nothing about
     which screen called it, and `#wcsp-set-alert` exists on exactly one of them.
     `settings.ts:46` is the sole caller (`grep -rn getLastSync src/`) and passes
     `alertBox.dataset.endpoint ?? ''`; `force` keeps its default, so no other
     call site changes.

   The fallback lives in one place per file, not inside the template literal:
   resolve the base to a local first (`const ep = data.endpoint || '../wp-scanpay/fetch';`)
   so the empty-string and undefined cases collapse into the old behaviour
   together.
3. Re-run `pnpm exec tsc` and `pnpm lint:js`; the `.js` bundles only change once
   `./build.sh` runs esbuild, which is not this task's job.

**Do not** register a rewrite rule instead. A rule means a flush, a flush means
an activation hook that does more than `install.php`, and the endpoint
deliberately does not depend on WordPress's routing at all.

### Verify

- Confirm from the router that the path is irrelevant and only the header and
  `?x=` matter, and that `admin_url( 'admin-ajax.php' ) . '?x=meta'` therefore
  dispatches identically.
- Confirm the plugin really is loaded on an admin-ajax.php request before
  admin-ajax.php sends any header of its own — read `wp-admin/admin-ajax.php` and
  say which line loads the plugins and which sends `Content-Type`. If the order
  is the other way round, this task is blocked, not improvised around.
- Confirm `wp_send_json()` terminates the request on that path too
  (`wp_doing_ajax()` is true, so it takes the `wp_die()` branch), so nothing of
  admin-ajax.php's own output can follow the JSON.
- Confirm all three payload sites and all five TS files are updated. Then
  `grep -rn "wp-scanpay/fetch" src/` must return **exactly three** hits, one per
  fetch site, and every one of them a fallback constant — never a live URL. Any
  other count means a site was missed or a fallback was dropped.
- Confirm the fallback branch compiles and that `tsc` and `pnpm lint:js` are both
  clean. `tsc` is the check that catches a forgotten `types/order.d.ts`; say in
  `HANDOFF.md` that it ran after the `.d.ts` edit, not before.

### Handoff

- On a shop with **plain** permalinks (Settings → Permalinks → Plain), confirm
  the order meta box, the subscription box and the "last sync" indicator all
  work now and did not before.
- Repeat with pretty permalinks to confirm nothing regressed, and once behind a
  page cache to confirm the `nocache_headers()` on the endpoints still hold.

## Task K — The 2.2.0 migration branch assumes an array

**File:** `src/upgrade.php:37-49` — as task H left it.

```php
$old      = get_option( WC_SCANPAY_URI_SETTINGS );
$settings = array_merge( [ … ], $old );
```

`get_option()` answers `false` for an absent option, and `array_merge()` with a
non-array is a `TypeError` on PHP 8 — not a warning, not a skipped merge. Both
neighbouring branches already guard: the `< 2.0.0` one reads every key through
`??` (`:21-35`), and the `< 2.5.0` one opens with
`if ( ! is_array( $settings ) ) { $settings = []; }` (`:101-104`).

The blast radius is the whole file. The throw escapes to the loader's
`catch ( Throwable )` (`woocommerce-scanpay.php:417-423`), which deliberately
keeps the `wc_scanpay_updating` transient — so the store retries the *entire*
migration every five minutes, forever, and never reaches the 2.1.3, 2.5.0 or
3.0.0 branches. `wc_autocapture` is never derived and the obsolete 2.x columns
are never dropped, which under a strict SQL mode fails every `scanpay_meta`
insert and pins the sync cursor.

Reachable only with `wc_scanpay_version` in `[2.0.0, 2.2.0)` and the settings
option absent or scalar — a partially restored database, a `wp option delete`, a
selective staging import. Cold, but cheap to close.

**The fix.** Normalize before the merge, in the shape `:101-104` uses. Keep
`array_merge( [ defaults ], $old )` as it is — stored values must keep winning.
One comment line: what a non-array means here, and that a `TypeError` takes down
the whole upgrade rather than this branch.

### Verify

- `php -r 'array_merge( [], false );'` and paste the exact `TypeError`.
- Read `woocommerce-scanpay.php:410-424` and state what the loader does with a
  throwing `upgrade.php`: the transient is kept on purpose, so the cadence is
  five minutes and the version is never stamped.
- Confirm the three later branches and the version stamp are all downstream of
  the throw, so none of them runs.
- Grep the file for any other unguarded read of the settings option and report
  the result either way.

### Handoff

- Nothing to run on a shop. Optional: set `wc_scanpay_version` to `2.1.0`, delete
  `woocommerce_scanpay_settings`, load wp-admin, and confirm the upgrade
  completes instead of logging `Upgrade failed:` every five minutes.

## Task L — The seq-row seed is the one write nothing checks

**File:** `src/install.php:81-86` — as task H left it.

```php
$seq = $wpdb->get_var( "SELECT seq FROM $seq_tbl WHERE shopid = $shopid" );
if ( null === $seq ) {
    $wpdb->query( "INSERT INTO $seq_tbl (shopid, seq, ping, mtime) VALUES ($shopid, 0, 0, 0)" );
}
```

The three table creations above it all throw on failure. This one does not, and
the row it seeds is what the ping handler requires before it will sync: without it
`wc_scanpay_read_cursor()` answers `shop not configured` with a 500
(`callback/wc-scanpay-ping.php:53-57`). So a failed INSERT gives the merchant a
successful key save, a green settings screen, and a shop that never syncs — the
only symptom being a log line five minutes later, and every five minutes after
that, on the ping side. `get_var()` also returns `null` on a read error, so a
failed SELECT is already indistinguishable from "no row".

**The fix.** Re-read rather than test the INSERT's return: a concurrent
activation loses the duplicate-key race harmlessly, and it is the row's presence
that matters, not who wrote it. When the re-read still finds nothing, log and
throw, like the three table creations above.

**Do not** throw on the INSERT's return value. Two activations racing is benign
and must not fail a merchant's key save.

**Do not** wrap the card gateway's require in a `try`/`catch` to soften the new
throw. `WC_Gateway_Scanpay_Card::process_admin_options():101-103` requires
`install.php` bare, so this throw — like the three `CREATE TABLE` throws already
above it — surfaces as WordPress's "There has been a critical error" page on the
settings save, *after* `parent::process_admin_options()` has already stored the
key. That is loud rather than graceful, and it is the file's existing contract,
not something this task introduces: the same page appears today when a table
cannot be created. Making that path report gracefully means deciding what a
half-completed key save should leave behind, which is a separate change with its
own commit. State the behaviour plainly in `HANDOFF.md` and leave it.

**Do not** extend this to the secret's `update_option()` at `:97` while you are
in the file. That one is unchecked too, but `update_option()` also answers false
for an unchanged value, so its return is not a failure signal — and a re-read
there would have to distinguish "not written" from "written identically". A
different problem, and not this task's.

### Verify

- Walk all four callers and record, for each, **what a throw here actually does**
  — not whether it is "handled", which two of them do not do. The reset endpoint
  wraps its require and answers `install_failed`
  (`wp-ajax-wc-scanpay-reset.php:155-162`); `upgrade.php` throws on to the
  loader's `catch`, which keeps the transient and retries. The other two catch
  nothing: the card gateway's first-key save
  (`class-wc-gateway-scanpay-card.php:101-103`) and the activation hook both end
  in WordPress's critical-error page, and on the first of those the key is
  already stored by then. That is the accepted outcome — see the "Do not" above —
  but it must be written down as what it is, so the next reader does not infer a
  clean error path from this bullet.
- Confirm the reset endpoint's postconditions still pass: it drops the tables and
  re-runs `install.php` with no key stored, so `0 !== $shopid` is false and this
  block does not run at all.
- State what the ping handler does without the row, quoting `:53-57`.

### Handoff

- On a shop: revoke the database user's INSERT right on `wp_scanpay_seq`, save an
  API key, and confirm the save now fails visibly instead of succeeding into a
  shop that never syncs. Expect WordPress's critical-error page, not a settings
  notice, and expect the key to be stored already — record both, and whether the
  merchant can recover at all. Do not assume re-saving the form is enough:
  `validate_apikey_field()` refuses to replace a stored key, so `$new !== $old`
  at `class-wc-gateway-scanpay-card.php:101` is false on the second save and
  `install.php` never re-runs from there — and the loader's upgrade gate is
  closed too, because the version is stamped. If the reset button turns out to be
  the only way back, that is the finding, and it belongs in `HANDOFF.md` rather
  than in a fix here.

## Task M — The long-poll answers `text/html`

**Files:** `src/admin/ajax/wp-scanpay-fetch-meta.php:51-58`,
`src/admin/ajax/wp-scanpay-fetch-sub.php:55-62`.

Both endpoints write keep-alive newlines while they hold the request open, then
finish with `wp_send_json()`. That function sets the content type and the status
code only `if ( ! headers_sent() )` (`wp-includes/functions.php:4593-4598`), and
the first `echo` plus `flush()` has already sent them. So the same endpoint
answers `application/json` on the fast path and PHP's default `text/html` on the
long-poll path. It works today only because `fetch`'s `res.json()` ignores the
content type and `JSON.parse` tolerates the leading whitespace.

**Only the content type is actually lost**, and say so rather than widening the
claim: neither long-poll call passes a status argument, so there is nothing for
the second half of that guard to drop. The 403 at `:19` in both files does pass
one, and it returns before any output. The status half is latent — it becomes real
the day someone adds a status to the tail call — which is a reason to fix the
header once per request, not a defect to report.

**The fix.** Send `header( 'Content-Type: application/json; charset=UTF-8' );`
exactly once per request in both files, with a comment saying why it cannot be
left to `wp_send_json()`. Nothing else changes: the newlines are load-bearing
(they are how a disconnected client is detected).

**Placement, precisely: inside the long-poll branch but *outside* the loop** —
next to `set_time_limit( 30 )` in `-meta.php`, and at the head of the same branch
in `-sub.php`. The first `echo "\n"` lives in the loop body (`-meta.php:51`,
`-sub.php:55`), so a `header()` written literally "before the echo" runs again on
rounds two and three, after `flush()` has sent the headers, and PHP answers each
repeat with `Cannot modify header information — headers already sent`. One
warning per round, in the middle of the response body under `display_errors`,
which is the bug this task exists to remove.

### Verify

- Quote `wp_send_json()`'s `headers_sent()` guard.
- Confirm the header lands before the first byte on the long-poll path and that
  the fast path (no hold) still gets `wp_send_json()`'s own header — no duplicate
  `Content-Type`.
- Confirm by reading the diff that the `header()` call sits outside the loop in
  both files, and state how many times it executes on a three-round poll. Any
  answer but one is the wrong placement.
- Confirm the 403 branches are unaffected: they return before any output.

### Handoff

- `curl -i -H 'X-Scanpay: <secret>' '<shop>/?x=meta&oid=<id>&rev=<current rev>'`
  on a real shop, and confirm the held response now carries
  `Content-Type: application/json`.

## Task N — Our bulk action re-fills a list WooCommerce emptied on purpose

**File:** `src/admin/orders.php`, `wc_scanpay_add_bulk_actions()` at `:29-44` —
as task J left the file.

```php
return [ 'scanpay_capture_complete' => __( 'Capture and complete', … ) ] + $arr;
```

HPOS returns an **empty array** from `get_bulk_actions()` when the user lacks
`edit_others_posts` (`src/Internal/Admin/Orders/ListTable.php:323-327`), and
`WP_List_Table` applies our filter *before* it tests for emptiness
(`class-wp-list-table.php:598` then `:605`). So a role with `edit_shop_orders`
but not `edit_others_shop_orders` — enough to reach the Orders screen — is shown
a bulk dropdown holding exactly one entry, ours, which the executor then rejects
silently at `ListTable.php:1419-1421`. The trash-view guard at `:36` already
establishes the principle: when WooCommerce has withheld its own actions, ours
must not appear either.

**The fix.** Return `$actions` unchanged when it is empty, beside the existing
trash guard, with a comment naming both halves — `get_bulk_actions()`'s cap check
and `WP_List_Table`'s filter-then-test order.

### Verify

- Quote all three upstream lines and confirm the filter runs before the empty
  test.
- Confirm the legacy (CPT) list table has the same shape, or say plainly that it
  does not and that the guard is harmless there.
- Confirm the trash guard and the `mark_completed` rename are untouched.

### Handoff

- On a shop: create a role with `edit_shop_orders` but not
  `edit_others_shop_orders`, open the Orders list, and confirm the bulk dropdown
  no longer offers "Capture and complete".

## Task O — The subscription meta box reads two keys nothing writes

**File:** `src/admin/subscriptions.php:29-40` — as task J left the file.

The box emits `data-payid` and `data-ptime` from the subscription's own meta, and
`subs.ts:58-59` renders "Payment ID" and "Payment date" only when they are
non-empty. Nothing writes either key to a subscription created at checkout. The
sole writer is `generate-payment-link.php:258-259`, which writes them on the
**order**, from `process_payment()` — and WCS copies the parent's meta onto the
subscription earlier in the same request, from `woocommerce_checkout_order_processed`
priority 100 (`class-wc-subscriptions-checkout.php:24`, `:184`), which
`WC_Checkout` fires at `class-wc-checkout.php:1396`, while
`process_order_payment()` only runs at `:1414`. The Blocks path has the same
ordering. Neither key is excluded from the copy — the copy simply happens first.

So both rows are permanently absent from every subscription box, except in the
one narrow case where `generate-payment-link.php` falls through to `new_url()`
with `$wco` being the subscription — which task A has now removed. That is what
makes this inconsistent rather than deliberate.

**The fix.** Fall back to the parent order for both keys, keeping the
subscription's own value when it has one:

1. `$wcs_parent = $wc_sub->get_parent();` once, then for each key take the
   subscription's value and, when it is `''` and a parent exists, the parent's.
2. Comment the ordering: the keys are written in `process_payment()`, after WCS
   has already copied the parent's meta, so they are never copied.

**Do not** start writing these keys onto the subscription from
`generate-payment-link.php` instead. Meta on a subscription is copied onto every
renewal order (`DEFAULT_EXCLUDED_META_KEYS`), which is the failure task A exists
to remove.

### Verify

- Quote the four ordering citations and state plainly that the copy precedes the
  write.
- Confirm `WC_Subscription::get_parent()` returns `false` (not null) when there
  is none, and that the fallback handles it.
- Confirm `subs.ts` needs no change: it reads the same two attributes.

### Handoff

- On a shop: open a subscription created through Scanpay checkout and confirm the
  box now shows Payment ID and Payment date, and that they match the parent
  order's `_scanpay_payid` / `_scanpay_payid_time`.

## Task P — One settings-field title never translates

**File:** `src/admin/settings/fields/scanpay.php:71` — as task I left the file.

```php
'stylesheet' => [
    'title'   => 'Stylesheet',
```

Every other field in all three files wraps `title` in `__()`, and
`grep -n "Stylesheet" src/languages/scanpay-for-woocommerce.pot` finds nothing —
the string is never extracted, while "Card icons" and "Auto-complete" are.
WooCommerce renders `title` twice per checkbox row, in the `<th class="titledesc">`
and in the screen-reader legend (`abstract-wc-settings-api.php:708`, `:712`), so
it is user-visible. `AGENTS.md`'s "settings-field *defaults* stay plain strings"
exemption does not cover it: this is a label, not a stored value.

**The fix.** `'title' => __( 'Stylesheet', 'scanpay-for-woocommerce' ),`. Nothing
else. Leave `src/languages/` alone and record the new msgid in `HANDOFF.md`.

### Verify

- Grep all three field files for a `title` or `label` that is not wrapped, and
  report the full list — this task is only worth a commit if it closes the set.
- Confirm the string is absent from the current `.pot`.
- `pnpm phpcs` clean (the I18n sniff is configured for this text domain).

### Handoff

- On a Danish shop, confirm the row heading is translated once the catalogs are
  regenerated and a translation exists.

## Task Q — A filter nothing consumes

**File:** `src/admin/settings.php:7-12`.

`wc_scanpay_admin_add_version()` puts the plugin version into WC Admin's
`wcSettings` bag on every admin request, under the `scanpay` key. Nothing reads
it. `grep -r "wcSettings\|getSetting" src build` returns one live read,
`checkout.ts:13`, and that is `getSetting( 'scanpay_data' )` — the **Blocks**
payload, which arrives through
`WC_Scanpay_Blocks_Support::get_payment_method_data()` and has nothing to do with
this filter. `admin/assets/js/types/order.d.ts:36` declares `wcSettings: unknown`
and never dereferences it. The version the admin screens do use has two other,
live sources: the `data-version` attribute on `#wcsp-set-alert`
(`admin/settings/admin-options.php:130-134`, read by `settings.ts`) and
`window.ScanpayOrderData`.

Before deleting, apply the `$this->icon` lesson from `AGENTS.md`: a hook that
looks dead can be read by WooCommerce itself. It is not, here — WC Admin merges
extension keys into a JS bag and never interprets an unknown one — but say so in
`HANDOFF.md` with the grep that proves it.

**The fix.** Delete the function and its `add_filter`. Nothing else in the file
references either.

### Verify

- Paste the full output of
  `grep -rn "wcSettings\|getSetting\|admin_shared_settings" src/ build/`.
- Confirm from `src/Internal/Admin/WCAdminSharedSettings.php` that the filter's
  result is passed to JS verbatim and that WooCommerce reads no key of its own
  out of it.
- Confirm `settings.ts` takes the running version from `data-version`.

### Handoff

- Load the Scanpay settings screen and confirm the update banner and the "last
  sync" indicator both still work.
- If anyone at Scanpay uses `wcSettings.scanpay` from a browser console for
  support, this removes it. The version is on the Plugins screen either way.

## Task R — Comments that no longer describe the code

Comments are what verification runs on here, so a drifted one is a failing test.
Six, in five files. **No code changes in this task at all** — if a fix needs one,
it belongs to whichever task owns that behaviour.

Two of those files are shared. Item 5 is in `public/generate-payment-link.php`,
which A, C and F have already edited and S and T edit after this; items 1 and 2
are in `library/class-wcs-scanpay-charge.php`, which C edited and S edits after.
Both anchors sit clear of every one of those edits — that is why R lands here and
not at the end — so keep the diff to comment lines and the `git diff --stat`
below stays the whole check.

1. **`src/library/class-wcs-scanpay-charge.php:109-113`** cites
   `class-wc-scanpay-sync.php:359-367` for the `WC_SCANPAY_URI_SHOPID` write and
   `:346-351` for the `scanpay_subs` upsert. Both drifted about a hundred lines:
   the write is at `:466`, in the meta block at `:465-468` inside the `foreach`
   that runs `:459-490`; the upsert is at `:437-447`; and the cited lines are
   today `payment_complete()`'s
   `catch`/`finally` and `report_incomplete()`'s docblock. `upgrade.php:57-88` is
   short too — that block runs `:57-94`. Cite the symbols rather than the lines.
2. **`src/library/class-wcs-scanpay-charge.php:20-24`** says the hook "memoizes
   the handler for the whole request, so a throw here would be per-request rather
   than per-renewal". The memo at `woocommerce-scanpay.php:345-355` is assigned
   *after* the constructor returns, so a constructor throw leaves `$handler` null
   and the next renewal in the batch constructs again — the failure is
   per-renewal and is reported against each order, which is the opposite. Drop
   that clause; "no order to mark failed" is the half that earns the design.
3. **`src/gateways/abstract-wc-gateway-scanpay-base.php:28-33`** says WooCommerce
   "defaults `$enabled` to `'yes'` and leaves `$title` and `$description`
   undeclared". Both are declared, uninitialized
   (`abstract-wc-payment-gateway.php:68`, `:75`), and
   `WC_Payment_Gateway::init_settings()` (`:247-250`) already normalizes
   `$enabled` — and our constructor calls it immediately before
   `init_gateway_props()`. So the `$enabled` line is defence in depth against
   that upstream method changing, and the `$title`/`$description` half is what
   earns the method. Say that.
4. **`src/gateways/blocks/class-wc-scanpay-blocks-support.php:36`** says
   "Checkout only, so per-request cost is fine". The payload is also built for
   the Cart and Mini Cart blocks: `add_payment_method_script_data()` is hooked to
   both `woocommerce_blocks_checkout_enqueue_data` and
   `woocommerce_blocks_cart_enqueue_data` (`src/Blocks/Payments/Api.php:48-49`),
   and the latter is fired by `BlockTypes/Cart.php:303` and `MiniCart.php:236` —
   so a block theme with a header mini-cart builds it on every page of the store.
   Name the three render paths. Do **not** gate the payload on `is_checkout()`:
   the Cart block enqueues the same bundle and needs it.
5. **`src/public/generate-payment-link.php:20-26`** justifies the `is_string()`
   guard around `get_country_calling_code()` with "a filter can still hand us an
   array". There is no filter: the method applies none and unwraps an array
   itself (`includes/class-wc-countries.php:165-182`); only the `@return
   string|array` docblock remains. Keep the guard, and give it the real reason —
   the WC 3.6 floor and that docblock.
6. **`src/public/wp-scanpay-thankyou.php:50-69`** documents
   `wc_scanpay_thankyou_read()` as returning "null when there is no such order".
   On the legacy CPT path it cannot: the query is a bare aggregate
   (`SELECT MAX( CASE … ) … WHERE post_id = %d`), which MySQL answers with one
   all-`NULL` row, and `?: null` does not convert a non-empty array. The caller
   is still correct — the very next gate rejects an empty `order_key` — but the
   docblock and the `// No such order.` comment describe the HPOS path only. Say
   which branch returns what. Two sites, not one: the `@return` at `:53` and the
   caller's comment at `:102`, which is outside the line range this item names.

### Verify

- Open every cited line in the stubs and in `src/`, and record in `HANDOFF.md`
  what is actually there — this task is worthless if the new numbers are as stale
  as the old ones.
- Confirm `git diff --stat` shows comment lines only.

### Handoff

- Nothing. This task changes no behaviour, and its Verify half is the whole of
  it.

## Task S — Three hygiene gaps the guide already rules on

**Files:** `src/gateways/class-wc-gateway-scanpay-mobilepay.php:26-27`,
`src/gateways/class-wc-gateway-scanpay-applepay.php:73-74`,
`src/admin/settings/admin-options.php:138`,
`src/public/generate-payment-link.php:213-217` — as tasks A, C, F and R left it —
and `src/library/class-wcs-scanpay-charge.php:274-278`, as C and R left that one.

1. **`esc_url()` on the two icon URLs.** Both gateways interpolate
   `WC_SCANPAY_URL` into a `src=""` unescaped; the card gateway escapes the
   identical concatenation (`class-wc-gateway-scanpay-card.php:66`), and
   WooCommerce echoes `get_icon()` raw
   (`templates/checkout/payment-method.php:26`). `WC_SCANPAY_URL` is
   `untrailingslashit( plugins_url( '', __FILE__ ) )`
   (`woocommerce-scanpay.php:35`), and `plugins_url()` runs a third-party filter,
   so it is not a compile-time constant. PHPCS misses it because the value is
   returned rather than echoed. Wrap both, matching the card.
2. **The bare `phpcs:ignore`** at `admin-options.php:138`. It is deleted because
   it suppresses nothing, not because of its missing reason: the annotated
   statement is a method call, not an `echo`, so `WordPress.Security.EscapeOutput`
   cannot fire on it — the echo happens inside `generate_settings_html()`
   (`abstract-wc-settings-api.php:370-374`). A suppression that suppresses
   nothing is the one kind that can never be justified with a reason, which is
   what makes it this task's. Delete it, and confirm `pnpm phpcs` stays clean; if
   it does not, keep it with the reason instead.

   **Do not generalise this into a sweep.** `AGENTS.md` says *"`phpcs:ignore`
   always carries `-- <reason>`"*, and roughly thirty suppressions in the tree do
   not — most of them the `EscapeOutput.ExceptionNotEscaped` line above a
   `throw`, in `class-wc-scanpay-sync.php`, `class-wc-scanpay-client.php`,
   `math.php`, `class-wcs-scanpay-charge.php`, `class-wc-scanpay-capture.php` and
   `woocommerce-scanpay.php`. Nothing enforces the rule — only the DocComment and
   CommentedOutCode sniffs are configured — so they are a style debt, not a
   defect, and annotating them is a separate decision about a separate commit.
   This task touches one file's one line.
3. **A missing space in a log line.** `generate-payment-link.php:213-217`
   concatenates `"…does not match the order total ($wc_total)."` directly onto
   `'The item list will not be available…'`, so the merchant reads
   `(199.00).The item list`. The identical string in
   `class-wcs-scanpay-charge.php:274-278` has the same defect — fix both, and
   record the msgid situation: neither is translated, so no catalog changes.

### Verify

- `grep -n "phpcs:ignore" src/admin/settings/admin-options.php` comes back empty.
  Scoped to that one file on purpose: the tree-wide grep still returns about
  thirty lines, all of them out of scope per the note above, and an empty
  tree-wide result would mean the commit grew far past this task.
- Confirm `esc_url()` leaves both URLs byte-identical in the ordinary case: a
  plugin URL with no query string.
- Confirm the two log strings now render with one space and no double space.
- `pnpm phpcs` clean.

### Handoff

- Render a classic checkout with MobilePay and Apple Pay enabled and confirm both
  icons still load.

## Task T — The renewal "Pay now" link tells the customer it charged them

**File:** `src/public/generate-payment-link.php`, the `$subid` branch at
`:109-168` — as tasks A, C, F, R and S left it. Nothing outside that branch
changes.

`/v1/subscribers/{subid}/renew` **charges nothing**; it returns a link to a page
where the customer updates their payment details. That is settled with Scanpay —
see the section at the top of this file, and do not re-derive it from the
endpoint's name or from what the code below appears to assume.

Every other line in the `$paid_renewal` half of this branch was written on the
opposite assumption. `$paid_renewal` is true for a customer retrying a failed
renewal *and* for one resubscribing, and for both the code treats the link as a
payment attempt:

1. **`:124-129`** computes a completion intent and, under
   `wcs_complete_renewal` + `wc_autocapture = 'completed'`, forces
   `$data['autocapture'] = true` — a capture instruction for a call that
   authorizes nothing.
2. **`:130-150`** appends `scanpay_thankyou` + `scanpay_type=wc` to the success
   URL. Type `wc` selects the paid-order wait, which busy-polls the order's
   `transaction_id` for ~3.5 s across 17 rounds
   (`wp-scanpay-thankyou.php:122-128`) for a transaction that cannot exist. One
   PHP worker held per return, then the order-received page renders for an order
   that is still unpaid — no error, no explanation.
3. **`:157-163`** persists `WC_SCANPAY_URI_COMPLETE` for that non-payment. Mostly
   inert, because `WCS_Scanpay_Charge::charge():308-313` overwrites it before the
   eventual retry charges — but it survives when no retry ever runs, and it is a
   false record in the meantime.

The customer's own account of the transaction is the part that matters: they
followed a link labelled "Pay now", entered card details, and were returned to a
page for an unpaid order. Nothing tells them what actually happened or that the
money will be taken later.

**The fix.** Stop claiming a payment, and say what did happen.

1. Delete the `$complete` computation and the `$data['autocapture'] = true` bump
   at `:124-129`. `$complete` has no other reader in this branch; confirm that
   before deleting, and do **not** also delete `wcs_scanpay_wants_completion()` —
   `class-wcs-scanpay-charge.php:310` still calls it, and the initial-order path
   below still calls it too.
2. Delete the whole `if ( $paid_renewal ) { $data['successurl'] = add_query_arg(
   … ); }` block at `:130-150`. The customer returns to WooCommerce's own
   order-received URL, unmodified, and the router never dispatches
   `wp-scanpay-thankyou.php` for this request at all.
3. In the post-link block at `:157-163`, drop the `WC_SCANPAY_URI_COMPLETE`
   write. **Keep task C's `WC_SCANPAY_URI_SHOPID` stamp and the
   `save_meta_data()` call** — see "Do not" below.
4. In the same block, add a customer-visible order note, wrapped exactly as
   `WC_Scanpay_Sync::report_incomplete():381-394` wraps its own:

   ```php
   try {
       $wco->add_order_note(
           __( 'Your payment details were updated. This renewal has not been charged yet; it will be collected automatically with the new details.', 'scanpay-for-woocommerce' ),
           1
       );
   } catch ( \Throwable $e ) {
       scanpay_log( 'error', "Order #$oid: could not add the payment-details note: " . trim( $e->getMessage() ) );
   }
   ```

   `add_order_note()` runs `woocommerce_new_order_note_data`,
   `wp_insert_comment()` and `woocommerce_order_note_added`
   (`class-wc-order.php:2080-2146`) — all third-party surface, and this runs
   inside `process_payment()`, where an escaping throw reaches the shopper. The
   note is the delivery mechanism because it needs no new router branch, no new
   hook and no new TS: WooCommerce renders customer notes in the order-view
   "Order updates" list and emails them through `woocommerce_new_customer_note`.
5. Replace the comment at `:112-122` with what is now true: `renew()` returns a
   card-registration page and charges nothing, so this branch creates no order
   transaction, waits on nothing, and records no completion intent — and the
   `$data['autocapture']` computed at `:83` rides along inert rather than being
   zeroed, because the payload shape stays stable that way. Say that `renew()`
   charges nothing as a flat fact, not as an inference; it is the one claim here
   that no stub can confirm.

**Do not** remove task C's shopid stamp while you are in that block. It is more
clearly right after this task, not less: with no money moving at link time, the
stamp's only job is to record which shop the subscriber belongs to, so that a
merchant who switches API keys between the card update and the retry gets
`scheduled_charge():117-129`'s deliberate refusal rather than a charge against a
numerically colliding subid in the new shop.

**Do not** build a flow that actually charges. Routing `$paid_renewal` through
`new_url()` with `items` and a `subscriber.ref` would do it and reuses a payload
shape production already sends — but whether the backend reuses an existing
subscriber for the same ref is unanswered, and this plan does not improvise
backend contracts. It is the obvious follow-up; record it in `HANDOFF.md`, do not
implement it.

**Do not** touch the method-change half of this branch. `$paid_renewal` is false
there, none of the three lines above executes, and task A already settled it.

### Verify

- Trace the branch with `$subid > 0` and `$paid_renewal === true` line by line
  after the edit, and state what the payload now carries and what the success URL
  now is — it must be byte-identical to `apply_filters(
  'woocommerce_get_return_url', … )` at `:84`.
- Confirm the router cannot dispatch `wp-scanpay-thankyou.php` for that return:
  `woocommerce-scanpay.php:81` requires all three of `scanpay_thankyou`,
  `scanpay_type` and `key`, and only the last is still in the URL.
- Confirm `$complete` is dead in this branch and live everywhere else. Name each
  remaining caller of `wcs_scanpay_wants_completion()`.
- Confirm the note cannot escape: quote the `try`/`catch` and state what a
  throwing `woocommerce_order_note_added` callback does to the customer now.
- Record the new msgid verbatim in `HANDOFF.md`. This is the second of the two
  msgids this run adds; P is the other.

### Handoff

- On a shop with WCS: fail a renewal, follow the customer's "Pay now" link,
  update the card, and confirm the return page renders immediately (no ~3.5 s
  hang), that the order is still `failed`/`pending`, and that the new note is
  visible to the customer.
- Confirm the note's promise actually holds on that shop: that WCS has a retry
  scheduled and that it collects with the updated card. If the merchant has
  retries disabled, the note is wrong for them — report that rather than
  rewording it here, because the answer is the same product decision this task
  deliberately did not take.
- Confirm no `_scanpay_complete` is left on the renewal order after the card
  update, and that `_scanpay_shopid` still is.
- Ask Scanpay whether `/v1/new` with `items` and a `subscriber.ref` naming an
  existing subscriber charges *and* refreshes that subscriber's card, rather than
  creating a second one. That answer is what unblocks a real "Pay now".
