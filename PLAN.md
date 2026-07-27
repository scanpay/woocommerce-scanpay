# PHP review follow-up plan

<!-- markdownlint-disable MD024 -->

Fifteen tasks, **A** through **O**, in the order they must be done. One commit
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

**Never run `./build.sh` bare.** It is `set -e` and prompts for a deploy at
`build.sh:75`, so EOF exits non-zero and looks like a failed build. Use
`printf 'n\n' | ./build.sh`. Answering `y` rsyncs to a live test server.

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
(`.stubs/woocommerce/includes/abstracts/abstract-wc-payment-gateway.php`); WCS
ones sit under
`.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/`. The
WooCommerce stub is `11.1.0-dev`, so it settles "what does current WooCommerce
do", never "what does the 3.6 minimum do". Released 2.x behaviour is in tags
`v2.0.0`–`v2.9.1`. `php -r` settles PHP semantics empirically — to load
`src/library/math.php` directly, `define( 'ABSPATH', … )` first, or the file
`exit()`s and prints nothing.

## Do not weaken

Money-string arithmetic (`src/library/math.php`), the ping protocol, the sync
flock, the API-key write-once policy, the lock-free subscription charge design.

Two standing decisions, so no task reopens them:

- **Renewal retry pacing is solved.** `wcs_scanpay_retry_rule()`
  (`woocommerce-scanpay.php:286-298`, filter `wcs_get_retry_rule_raw`) raises
  WCS's `retry_after_interval` to a ≥25h floor on Scanpay orders, because the
  idempotency key's day bucket only advances after 24h and an earlier retry
  would replay the cached decline. Tasks E, F and G say "WCS owns retry
  scheduling" — still true; the filter stores nothing. Do not add a second one.
- **The subscription terms checkbox is cart-level consent**, covering whichever
  gateway the customer picks — third-party ones included, and while our own are
  disabled. Its predicate is `wcs_scanpay_terms_url()`
  (`woocommerce-scanpay.php:150`), and the Blocks payload carries
  `$data['terms']` outside `$data['methods']` and outside the `enabled` gate
  (`class-wc-scanpay-blocks-support.php:42-64`). Once Task K lands, disabling
  all three gateways removes them from classic checkout while the checkbox still
  renders. Intended — not a regression.

## Order

| Task | Focus | Depends on |
| --- | --- | --- |
| A | `upgrade.php`, `install.php` — 2.x schema migration | — |
| B | `wp-ajax-wc-scanpay-reset.php` — reset exclusivity | A |
| C | `uninstall.php` — multisite cleanup | — |
| D | `class-wc-scanpay-capture.php` — capture outcomes | — |
| E | `class-wcs-scanpay-charge.php` — zero renewal amounts | — |
| F | `class-wcs-scanpay-charge.php` — shop ownership | — |
| G | `class-wcs-scanpay-charge.php`, `woocommerce-scanpay.php` — failure containment | E, F |
| H | sync, payment link, charge — auto-complete settings | — |
| I | `class-wc-scanpay-sync.php` — completion failure context | H |
| J | `generate-payment-link.php` — renewal thank-you wait | H |
| K | `gateways/` — gateway state and filters | — |
| L | `build.sh`, admin enqueues — JS translation pipeline | — |
| M | `public/assets/js/` — Apple Pay in classic checkout | K, L |
| N | PHP + TS sweep — remaining strings | L, and every task adding copy |
| O | `src/languages/` — regenerate catalogs | N |

E, F and G share one file, as do H, I and J; landing each group contiguously
avoids rewriting the same region twice. G item 5 repairs a comment E relocates,
which is a one-liner only if G follows E directly.

---

## Task D: Preserve capture failure outcomes for the whole request

`WC_Scanpay_Capture::capture()` records the order in its static `$processed` map
at `class-wc-scanpay-capture.php:52` — before the shop-ID check (`:54-58`), the
metadata SELECT (`:60-74`), the void check (`:75-77`), the money math (`:78-97`)
and the API call (`:99-105`).

If any of those throws, `capture_or_hold()` returns `false` and parks the order
on hold (`:120-132`). A second call in the same request sees only that the ID is
present, returns at `:47-50`, and `capture_or_hold()` falls through to
`return true` at `:123`. The map means "attempted" but reads as "succeeded".

**The bulk path is reachable, traced end to end.** WooCommerce builds the ID list
as `array_reverse( array_map( 'absint', (array) $_REQUEST['id'] ) )`
(`src/Internal/Admin/Orders/ListTable.php:1441`) with no `array_unique`, so a
duplicated `id[]` survives. Dispatch reaches our handler through the
custom-action filter at `:1504`: WC's own switch at `:1477` tests
`false !== strpos( $action, 'mark_' )`, a substring match, so
`scanpay_mark_completed` enters that branch — but `substr( $action, 5 )` yields
`y_mark_completed`, no registered status, so it falls through. The plugin's loop
does not dedupe (`admin/hooks/wp-bulk-actions.php:39`) and its skip list is only
`completed`/`trash` (`:44`) — so an order parked on-hold by a failed first
iteration passes, the duplicate gets `true`, and `:50` marks an uncaptured order
completed.

### Fix

1. Replace the presence-only map with an explicit per-order outcome
   (`array<int, bool>`): `true` = captured successfully or correctly determined
   nothing remained; `false` = attempt failed and was handled through the
   failure path.

   Either `isset()` or `array_key_exists()` is correct for a `bool` map.
   `array_key_exists()` becomes *necessary* only if the map later holds `null`
   — if you pick it for that reason, say so in the comment.

2. Move memoization to `capture_or_hold()`: check the map before calling the
   primitive, store `true` only after it returns, store `false` immediately on
   entry to the catch. Never set a success marker before failure-prone work.

   - **Remove the primitive's own check at `:47-50`.** The wrapper owns the map;
     leaving both is two sources of truth.
   - **A non-Scanpay order records nothing, and the wrapper is what decides
     that.** Move the `wc_scanpay_is_scanpay_order()` guard up out of
     `capture():41-43` into `capture_or_hold()`, ahead of the map: such an order
     returns `true` — what the call sites already read — without an entry,
     because no attempt was made and neither defined meaning of `true` fits it.
     The wrapper cannot infer this from the primitive, which is `void`: an early
     return and a successful capture look identical from outside. Do not give
     `capture()` a return value to solve that; the fail-loud primitive stays
     `void`.

3. On a repeat call, return the recorded outcome. A prior failure keeps
   returning `false`, with no second API request and no duplicate notes or
   status updates. Keep that guarantee even if parking the order on hold throws:
   contain a `Throwable` from `update_status()`, log it, and return the already
   recorded `false`. The capture failed regardless of whether WooCommerce could
   persist the fallback status.

4. Preserve successful dedup: after one successful capture, later calls return
   `true` without another request.

5. Keep the fail-loud private primitive and the on-hold public wrapper. Do not
   weaken the remaining-amount calculation, action index, or Scanpay's
   server-side concurrency check.

### Verify

- No `true` write to the map precedes the failure-prone primitive, and the catch
  stores `false` before attempting log/note/status side effects.
- Trace the bulk loop with a duplicated ID and state the outcome of both
  iterations under the new code.
- Each failure mode records `false` exactly once and attempts at most one status
  transition. One case already behaves: a malformed API key throws in
  `self::init()` at `:51`, before the memo write at `:52`.
- The four `capture_or_hold()` call sites (`woocommerce-scanpay.php:130`,
  `admin/hooks/wp-ajax-wc-scanpay-capture.php:44`, `wp-bulk-actions.php:47`,
  `wp-ajax-wc-mark-order-status.php:68`) still read the boolean the same way.
  Precisely: three read it; `woocommerce-scanpay.php:130` discards it
  deliberately, because the order is already `completed` there. Do not "fix"
  that call site into checking a return it has no action for.

### Handoff

Force each failure mode and confirm two calls yield `false, false` with one
attempt and at most one transition; make the on-hold write throw and confirm the
same result with a diagnostic log; a success yields `true, true` with one API
request; a legitimate no-op yields `true` without a request; a duplicate ID
through the bulk handler cannot complete the order.

---

## Task E: Validate zero renewal amounts against the order total

`WCS_Scanpay_Charge::scheduled_charge()` calls `payment_complete()` immediately
when the float `$amount` from WCS is `<= 0`
(`class-wcs-scanpay-charge.php:77-81`) — above the normalization at `:85-86`, the
`wc_scanpay_is_money()` guard at `:91-95` and the `wc_scanpay_cmpmoney()`
mismatch check at `:96-100`.

A zero or negative hook amount therefore marks a positive-total renewal paid
without charging, bypassing the method's own policy that a scheduler/order-total
disagreement must fail loudly. WCS normally passes the renewal total, but the
hook can be invoked off-schedule by third-party code or a store-manager
workflow.

### Fix

1. Move normalization and validation of both amounts above any zero/negative
   early return:

   - Normalize the hook float once with `wc_format_decimal()` — WooCommerce core
     (`includes/wc-formatting-functions.php:289`), not a plugin helper.
   - Read the order total as a string in `edit` context.
   - Validate both with `wc_scanpay_is_money()` (`library/math.php:23`).
   - Compare with `wc_scanpay_cmpmoney()` (`library/math.php:165`).

   The float signature is imposed by WCS; every decision after receiving it uses
   the normalized money string.

2. Apply the existing invalid-amount and mismatch failure behaviour before
   deciding the renewal is free. A non-positive scheduler amount against a
   positive order total must mark the renewal failed and never call
   `payment_complete()`.

3. Replace `$amount <= 0.0` with the money helpers after validation. **The
   replacement is `wc_scanpay_cmpmoney( $amt_str, '0' ) <= 0`, not
   `wc_scanpay_is_zero()`.** `$amount <= 0.0` is a *non-positive* test, and the
   matching-negative row below depends on it staying one. `is_zero()` is a
   strict zero test: `wc_scanpay_is_zero( '-0.00' )` is `true` but
   `wc_scanpay_is_zero( '-50.00' )` is `false`, so swapping it in would narrow
   the branch and send a matched proration credit on to `$this->client->charge()`.
   `cmpmoney` covers both: `cmpmoney( '-0.00', '0' )` and
   `cmpmoney( '-50.00', '-50.00' )` are `0`, `cmpmoney( '-50.00', '0' )` is `-1`.
   Preserve handling for genuine zero-total renewals and for supported
   non-positive proration/credit orders where the normalized total matches.
   `wc_scanpay_is_money()` already accepts negatives — `/^-?[0-9]+(\.[0-9]+)?$/D`
   (`math.php:24`) — so no special-casing outside the helpers.

4. Check `payment_complete()`'s boolean in the free-renewal branch. If
   completion cannot be persisted, log and surface a failed renewal so WCS can
   handle it.

5. Keep positive matched renewals on the existing idempotent charge path. Add no
   retry or lock state to `scanpay_subs`.

### Verify

- No path reaches `payment_complete()` or the API before both amounts are
  validated and compared.
- Enumerate the table below from the code and state each outcome. Rows 2–3 are
  decided by the **mismatch guard**, not by a new zero check — say which guard
  fires, or the table looks like it needs code it does not.
- Confirm the helper behaviour on the negative and zero cases with `php -r`
  against `math.php` directly; these are pure functions and fully testable here.
  In particular confirm the non-positive predicate is `cmpmoney( …, '0' ) <= 0`.

| Hook amount | Order total | Expected |
| --- | --- | --- |
| `0.00` | `0.00` | complete, no API charge |
| `0.00` | `100.00` | failed; no `payment_complete()`, no API |
| `100.00` | `0.00` | failed; no charge |
| matching positive | same | normal charge |
| matching negative/proration | same | no-charge completion |
| invalid normalized amount or total | any | clean failure, no uncaught `InvalidArgumentException` |

### Handoff

The same matrix end to end, plus a free-renewal `payment_complete()` returning
`false` surfacing to WCS.

---

## Task F: Verify shop ownership before charging a subscription renewal

`WC_Scanpay_Capture` derives the shop ID from the API key, refuses to run with a
malformed key (`class-wc-scanpay-capture.php:23-25`) and throws on mismatch
(`:54-58`). The renewal charge path has no equivalent:
`WCS_Scanpay_Charge::__construct()` (`class-wcs-scanpay-charge.php:10-19`)
accepts the key without deriving a shop ID, and `scheduled_charge()` reads only
`_scanpay_subid` (`:65`).

After a merchant switches shops, subscriptions still carry the old shop's
subscriber IDs. Reset drops `scanpay_subs`, so `idempotency_key()` initially
throws "subscriber does not exist" (`:51-54`) and the renewal is safely marked
failed. Once the new shop's sync repopulates the table, a numerically colliding
subscriber ID lets it find a revision and the charge goes to
`/v1/subscribers/<subid>/charge` under the new shop's credentials. The API path
carries no shop ID — the authenticated key resolves it. **Scanpay subscriber IDs
are namespaced per shop** (confirmed with the backend team), so a numerically
colliding ID across two shops is a real charge against a different customer's
stored card, not a hypothetical one. Capture already defends against this class
of mistake with a cheap local check; the renewal path must too.

### Fix

1. Derive the shop ID the same way capture does
   (`(int) strstr( $apikey, ':', true )`). If it is not a positive integer, mark
   the renewal failed with a clear "invalid API key configured" log line and
   order note before any API request, rather than an opaque 401.

   **Report the failure from `scheduled_charge()`, not the constructor.**
   `__construct()` has no order context — `$wco` first appears in
   `scheduled_charge( float, WC_Order )` — and the hook memoizes the handler in
   `static $handler` (`woocommerce-scanpay.php:265-272`), so a constructor throw
   is per-request, not per-renewal. Derivation may live in the constructor;
   reporting may not.

2. Read `_scanpay_shopid` next to the existing subid read and branch on **three**
   states:

   - **Present and matching:** proceed as today.
   - **Present and different:** log an error naming both shop IDs, mark the
     renewal failed, contact no API. WCS owns retry scheduling.
   - **Absent or zero:** log a warning naming the subscription and **proceed
     with the charge**. Do not mark it failed.

3. The absent case must not fail loud, because it is reachable on legitimate
   1.x-migrated stores:

   - `find_subs_from_ref()` (`class-wc-scanpay-sync.php:97-101`) returns `[]`
     unless the Scanpay `ref` starts with `wcs[]`. 1.x created subscribers with
     `'ref' => strval( $orderid )`, so a replayed 1.x-era subscriber matches no
     subscription and the `add_meta_data( WC_SCANPAY_URI_SHOPID, … )` calls at
     `:367` and `:386` never run.
   - The `scanpay_subs` upsert at `:346-351` runs **before** the
     `find_subs_from_ref()` call at `:359`, hence independently of that ref
     parsing. So the idempotency key resolves while the meta stamp is absent.
   - `upgrade.php:57-88` (the 2.1.3 block, which runs for 1.x sites) writes
     `WC_SCANPAY_URI_SUBID` at `:83` and never `WC_SCANPAY_URI_SHOPID`.

   A 1.x-migrated store can therefore hold a chargeable subscription with a
   valid subid, a present `scanpay_subs` row and no shopid stamp. Failing loud
   would stop those renewals with no merchant-visible cause. Document this at
   the check so it is not "tightened" later.

   **A `shopid` column on `scanpay_subs` looks cheaper and does not work — do
   not re-propose it.** `idempotency_key()` already runs
   `SELECT rev FROM scanpay_subs WHERE subid = …`, so carrying a shop ID there
   would appear free. But it defends against nothing: after a shop switch the
   *new* shop's sync upserts that same `subid` row with its own shop ID, so a
   numerically colliding subscriber would present the current shop's ID and
   pass. The guard has to compare against what the **subscription** was created
   under, which only the order/subscription meta records.

4. Keep the guard read-only. No locks, retry state, key rotation or coordination
   in `scanpay_subs`.

5. Record at the check, in a comment, that subscriber IDs are namespaced per
   shop — so a later reader cannot "simplify" the branch away on the assumption
   that they are globally unique. The mismatch case is preventing a real
   cross-shop charge, not guarding a theoretical one.

Note the correct statement of the sync behaviour: `subscriber()` stamps
`_scanpay_shopid` onto subscriptions **resolved from a `wcs[]` ref**, not onto
every synchronized subscription.

### Verify

- The three-way branch exists and the absent/zero case proceeds.
- No API call precedes the check on the mismatch path.
- The failure reporting is in `scheduled_charge()` where `$wco` exists.
- Re-derive the 1.x reachability argument from the four cited locations and
  state whether it still holds — if `find_subs_from_ref()` has changed, this
  task's central premise changes with it.
- The capture path is untouched, and capture still throws on absent/zero shopid
  (`self::$shopid` is guaranteed positive by `:23-25`, so absent meta casting to
  `0` always trips `:55`). The asymmetry is deliberate and must be recorded at
  the check.

### Handoff

Matching shop ID; a different shop ID (simulated switch and resync); no meta at
all — **the charge must still proceed**, this is the 1.x regression guard; a 1.x
fixture built explicitly (bare order-ID ref, subid from `upgrade.php`, a
`scanpay_subs` row, no shopid); shop ID `0`; an empty or malformed key.

---

## Task G: Contain every renewal-charge failure at the Action Scheduler boundary

The `catch ( \Throwable $e )` in `WCS_Scanpay_Charge::charge()`
(`class-wcs-scanpay-charge.php:199`) states its own purpose at `:200-203`: an
escaping Error or Exception "would otherwise escape to Action Scheduler and leave
the renewal neither charged nor marked failed."

Yet the payload build (`:107-129`), the per-item `wc_scanpay_addmoney()`
summation (`:149`), the autocapture decision (`:157-159`) and the
`wc_scanpay_cmpmoney()` comparison (`:161`) all run before the `try` at `:177`.
Both money helpers throw `InvalidArgumentException` on non-money input.

The containment is contractual, not structural — and weaker than "safe because
the caller pre-validates": `scheduled_charge()` validates the scheduled amount
and the order total, but the **per-line** values feeding `addmoney()` are never
pre-validated. `wc_format_decimal()` returns `''` for null/empty input
(`wc-formatting-functions.php:290-294`) and `get_line_total()` passes through the
`woocommerce_order_amount_line_total` filter, so a third-party filter returning
`null` throws outside the `try` today.

Widening `charge()`'s `try` does not achieve the goal, because **the escape
surface is the hook, not the method**. `wcs_scanpay_scheduled_charge()`
(`woocommerce-scanpay.php:265-272`) wraps nothing, so these still reach Action
Scheduler:

- The lazy `require` (`:268`) and `new WCS_Scanpay_Charge()` (`:269`).
- Everything in `scheduled_charge()` outside `charge()` — `wc_format_decimal()`,
  `get_total()`, the pre-guards and the free-renewal `payment_complete()`.
- The three `update_status( 'failed', … )` calls in `scheduled_charge()` (`:68`,
  `:93`, `:98`) and the one inside `charge()`'s own catch (`:206`) — each a
  database write that can itself throw, escaping from within the handler meant
  to contain the failure.

### Fix

1. Add one prefixed no-throw renewal-failure reporter in
   `woocommerce-scanpay.php` (for example `wcs_scanpay_fail_renewal()`),
   available to both the hook and the charge class. It accepts the order, a raw
   diagnostic for `scanpay_log()` and a separate translated merchant-facing
   status reason.

   - Attempt `update_status( 'failed', … )` once inside its own `try`/`catch`.
   - Emit one log entry after that attempt. If the status write threw, append
     that secondary diagnostic to the same entry rather than logging once before
     the write and again from an outer catch.
   - Contain a logger `Throwable` too. If WooCommerce's logger is itself broken
     there is nowhere safer to report it, but it still must not escape.
   - Never echo a raw backend/DB exception into the translated order reason.

   Route every failure exit in this flow through the reporter — invalid
   subscriber, invalid amount, scheduler/total mismatch, a free-renewal
   completion that could not be persisted, shop-ownership mismatch, and the
   charge failure itself. That removes the current "log, then call an unguarded
   `update_status()`" pattern.

2. Put the outermost `try { … } catch ( \Throwable $e )` in
   `wcs_scanpay_scheduled_charge()`, around the require, construction and
   `scheduled_charge()` call. Its catch invokes the reporter once and returns
   normally. One honest exception: a **missing**
   `class-wcs-scanpay-charge.php` raises a fatal compile error, not a
   `Throwable`, which no `catch` can contain. Out of scope — add no machinery
   for it.

   **Change `require` to `require_once` at `:268`.** Today a constructor throw
   kills the request. Once the outer catch swallows it, the next Action
   Scheduler action *in the same request* re-enters the hook with `$handler`
   still null and re-requires the file — a fatal class redeclaration. Action
   Scheduler batches multiple actions per request, so this is reachable, and the
   outer catch is what makes it reachable.

3. Widen `charge()`'s `try` to the whole method body as defence in depth. Its
   catch invokes the same reporter and does **not** rethrow, so the hook-level
   catch cannot report the same failure twice. Keep targeted context in the
   diagnostic passed to the reporter.

4. Reduce `charge()` to private — `scheduled_charge()` (`:101`) is its only
   caller repo-wide — and tighten its `object $wco` parameter (`:105`) to
   `WC_Order`.

5. Preserve `scheduled_charge()`'s specific pre-guards and targeted messages,
   but express their failure exits through the reporter.

   **Update the pre-guard comment above `scheduled_charge()`'s
   `wc_scanpay_is_money()` check** (at `:87-90` before the zero-amount task moved
   it up). It justifies the guard with "this runs outside `charge()`'s try, so a
   corrupt local total would otherwise escape to Action Scheduler" — which items
   2 and 3 make false. That task relocated the comment verbatim rather than
   rewriting it, so if this one does not fix it the plan ships a stale
   rationale. The widened catches are backstops, not
   replacements: with a functioning database and logger every failure produces
   one failed-status transition and one log entry; if the status write fails,
   no transition and one composite log entry, never a second attempt.

6. Do not change the success path, payload contents, idempotency-key
   construction or the lock-free design. The zero-amount task moved
   `scheduled_charge()`'s pre-guards above its zero-return rather than removing
   them, so `charge()`'s containment stays contractual either way and the
   per-line gap above is untouched by it.

### Verify

- Enumerate every statement in the hook function and in `scheduled_charge()` and
  show each is now inside a handler. This is the substance of the task and is
  fully checkable by reading.
- The reporter itself cannot throw, each failure path calls it at most once, and
  no path can reach two `update_status()` attempts or two log calls.
- Every status write is inside the reporter's own catch.
- `require_once` replaced `require`.
- `charge()` is private and typed `WC_Order`, and no external caller exists.
  Note `src/callback/wc-scanpay-ping.php:288` is `$sync->charge()` — a different
  class — so do not misread it as a second caller.

### Handoff

Drive each newly covered path through the **hook**, not the method: make the
require/constructor throw, `get_total()` throw, the free-renewal
`payment_complete()` throw, and `update_status( 'failed', … )` itself throw — in
every case the hook must return normally and Action Scheduler must see no
Throwable. Confirm the status-write failure yields one composite log rather than
a second reporting pass. Then run a normal successful renewal and a normal
declined renewal, with one transition attempt and one log entry per failure.

---

## Task H: Make the subscription auto-complete settings control the WC order status

`wcs_complete_initial` and `wcs_complete_renewal`
(`admin/settings/fields/scanpay.php:95`, `:102`) have labels promising
WooCommerce order completion, but only set the Scanpay `autocapture` payload
flag — `public/generate-payment-link.php:167-169` and
`library/class-wcs-scanpay-charge.php:157-159`.

Sync then calls `payment_complete()` (`library/class-wc-scanpay-sync.php:299`)
with no completion override, so WooCommerce picks its normal status from
`needs_processing()`. Physical subscription orders land in `processing` with the
setting on. Virtual/downloadable orders complete anyway, which masks the defect.

There is a second omission in the early subscriber-renewal branch
(`generate-payment-link.php:109-119`): a customer paying a failed renewal through
`renew()` bypasses the `wcs_complete_initial` payload override, and nothing in
that branch applies `wcs_complete_renewal`. The label says renewal orders, not
only Action Scheduler charges, so both renewal entry points belong here.

`wc_autocapture` values are `off` / `completed` / `on`, default `completed`
(`fields/scanpay.php:77-86`). `wc_scanpay_item_needs_processing()` takes
`( bool, WC_Product, int )` and returns `false` **only for Scanpay orders**,
which is what `$wco->needs_processing()` reads at `generate-payment-link.php:77`.
It is registered in **two** places — the card gateway constructor
(`class-wc-gateway-scanpay-card.php:32-33`) and the sync constructor
(`class-wc-scanpay-sync.php:53-55`). `WC_Scanpay_Sync` is not loaded on a
checkout request, so the gateway registration covers the checkout path; do not
conclude that path is unfiltered.

### Fix

1. Add one policy helper deciding whether an attempt requests a forced
   `completed` status. It is **request-time policy**, called from
   `public/generate-payment-link.php` and `library/class-wcs-scanpay-charge.php`.
   Sync never calls it and never reads these settings — it reads only the value
   item 2 persists.

   - A renewal charge honors `wcs_complete_renewal`.
   - A customer-paid renewal link also honors `wcs_complete_renewal`.
     Distinguish it from a pure payment-method change with the already-used
     guarded `WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment`
     flag; a method change is not a paid order attempt and gets no completion
     intent. **Task J reuses this same discriminator** — introduce it once, here,
     in a form J can consume.
   - **A resubscribe order is a third case in this branch, and it takes
     `wcs_complete_renewal`.** `wcs_create_order_from_subscription()` copies
     subscription meta for `resubscribe_order` as well as `renewal_order`, so a
     resubscribe order also carries `_scanpay_subid` and reaches
     `generate-payment-link.php:109-119`. Decided, not left to fall through: the
     payment mechanically *is* a `renew()` against the existing subscriber, and
     `wcs_complete_initial` has only ever applied to the `new_url()` sign-up
     path. So the discriminator has exactly one job in this branch — separate a
     pure payment-method change from everything else — and no
     `wcs_order_contains_resubscribe()` call is needed. State the decision in a
     comment, or the next reader will re-open it.
   - An initial payment link honors `wcs_complete_initial`, but only when WCS is
     active and the order is an initial parent subscription order.
   - Ordinary one-time orders: never.
   - Zero-total/free-trial parents keep completing via the subscriber path
     (`class-wc-scanpay-sync.php:383-390`), untouched here.

   **Do not mirror the payload expression.** Renewal autocapture is
   `$is_virtual || 'yes' === wcs_complete_renewal`
   (`class-wcs-scanpay-charge.php:157`), where `$is_virtual` folds in
   `wc_complete_virtual`. Copying that disjunct would force-complete every
   virtual renewal with the setting off.

   The payload still must honor the setting independently of the completion
   decision. In the customer-paid `renew()` branch, raise the final
   `$data['autocapture']` to true when `wcs_complete_renewal` requests
   completion and `wc_autocapture` is `completed` (already true for `on`, and it
   must remain false for `off`). **That raise applies only when the
   discriminator says paid renewal** — the same block is also the pure
   method-change path, which leaves `$data['autocapture']` at its
   `generate-payment-link.php:81` value.

2. Couple completion to the capture mode of the actual attempt: `completed` or
   `on` may force `completed`; `off` never — do not complete a deliberately
   uncaptured physical order.

   **Persist exactly one boolean, not two.** Write a single private meta key
   `_scanpay_complete`, meaning "this attempt asked WooCommerce to force
   `completed`", true
   only when the completion intent from item 1 **and** the final payload
   `autocapture` boolean are both true at request time. Nothing downstream reads
   them apart: the filter is installed only when both hold, so storing them
   separately buys no information and costs a consistency invariant plus a
   malformed-state surface on the sync path. Any value other than an explicit
   true — absent, empty, `'0'`, a string, an array — is no force-complete
   request.

   Use the WC order API so it works under HPOS and legacy storage, and declare
   it as `WC_SCANPAY_URI_COMPLETE` next to the other key constants
   (`woocommerce-scanpay.php:28-31`). Sync consumes the persisted flag, never
   live settings — otherwise a merchant toggling `wc_autocapture` mid-payment-
   window retroactively changes an accepted request.

   **The flag describes the latest attempt, and `add_meta_data( …, true )` is
   already that — do not mistake `$unique` for "do not overwrite".** WC's
   `$unique` means *at most one row for this key*: the implementation
   `delete_meta_data( $key )`s existing meta before appending
   (`abstract-wc-data.php:489-502`), so a retry replaces the stored value. That
   is what the neighbouring `PAYID`/`PTIME`/`SHOPID` writes already do: a
   payment link lives 15 minutes (`generate-payment-link.php:83`), so after a
   retry the newest link is the one the customer pays. Do not hand-roll a
   read-then-skip guard preserving the first attempt's value, which would pin
   the order to the least likely link.

   **When to write differs by path:**

   - New payment link: *after* `$client->new_url()` returns, alongside the
     existing `PAYID`/`PTIME`/`SHOPID` writes at `:186-188` and in that same
     `save_meta_data()` call (`:189`) — no extra order write. Not before: no
     payment can exist without a returned link.
   - Customer-paid renewal link: retain the returned value from
     `$client->renew()` instead of returning inline, then persist *after*
     `renew()` succeeds and before returning the redirect. This branch has no
     existing metadata save, so one `save_meta_data()` is expected. A pure
     method change writes nothing.
   - Renewal charge: **between the authoritative already-paid guard (which
     `return`s at `class-wcs-scanpay-charge.php:186-189`) and the
     `$this->client->charge()` call (`:198`), inside the existing `try` opened
     at `:177`**. That call is a real charge, so the flag must be durable if the
     process dies mid-request. Not before the `try`: that would persist it on an
     order the guard then declines to charge. A `Throwable` from the write is
     swallowed by the `catch` at `:199` and marks the renewal failed — the
     correct outcome, but be deliberate about it.

   **Keep the flag off the subscription.** `WC_Subscriptions_Data_Copier` copies
   any non-excluded custom meta from a subscription onto every renewal order, so
   a key that ever reached the subscription would seed every renewal order with
   a stale value before the renewal path writes its own. Today it happens not to
   arise: WCS creates the subscription on
   `woocommerce_checkout_order_processed`, before `process_payment()` runs, so
   the write at `:186-188` lands after the copy. That is timing, not design —
   verify the write follows subscription creation, or exclude the key via
   `wc_subscriptions_object_data`.

   Scanpay autocapture is pseudo-atomic by API contract: when requested, capture
   completes before the payment operation returns, and authorization and capture
   are exposed in one seq event, so sync never observes an authorization-only
   state for an autocaptured payment. When forcing `completed` fires
   `wc_scanpay_order_status_completed()`, the already-synced captured total
   makes the remaining-amount guard in `class-wc-scanpay-capture.php:94-97`
   return without a second capture request. No capture skip or recovery
   machinery is needed.

3. Force the status via `woocommerce_payment_complete_order_status`, which
   WooCommerce applies with `( $status, $order_id, $order )`
   (`class-wc-order.php:174`).

   - Install it immediately before `payment_complete()` — **inside** the
     `if ( empty( $wco->get_transaction_id( 'edit' ) ) )` block opened at
     `class-wc-scanpay-sync.php:253`, not around `sync()`. Scoped, not standing,
     because the filter is not only consulted when *setting* a status:
     `maybe_set_date_paid()` (`class-wc-order.php:355`, filter at `:366`)
     applies it read-only on every order saved before it has a paid date, plus
     three pre-WC-3.0 sites (`class-wc-order.php:970`,
     `class-wc-order-data-store-cpt.php:210`, `OrdersTableDataStore.php:3037`).
     A standing filter returning `completed` would flip that comparison for
     every unpaid Scanpay order being saved.
   - **Do not add a fire-once guard.** Inside the window `date_paid` is always
     already set — `payment_complete()` writes it unconditionally at
     `class-wc-order.php:162-163` before its `set_status()` at `:182`, and sync
     usually at `class-wc-scanpay-sync.php:287` — so the filter fires exactly
     once and a guard would carry state against nothing.
   - Match the order ID. That is the whole guard, and it stops a *nested* hook —
     a third-party callback completing some other order while ours is in flight
     — from picking up the forced status.
   - Remove it in a `finally`.
   - Do not call `payment_complete()` and then bump `processing` → `completed`:
     that emits both sets of hooks and emails.

4. Keep all existing validation (currency, shop ownership, authorized amount,
   transaction ownership, eligibility) ahead of the forced status.

5. Update the comments at both `autocapture` assignments to say Scanpay
   settlement and WooCommerce completion are related but separate. The comment
   at `generate-payment-link.php:164-166` ends "*(The auto-completion its label
   promises is not implemented yet -- PLAN.md task 1.)*" — quoted verbatim, and
   naming this task under its old number. That pointer is this task's own TODO
   and goes out with the fix.

6. **A full resynchronization must not re-complete anything.** A merchant can
   reset and replay from seq 0; the forced status must not turn a refunded or
   cancelled order back into a completed one. Two mechanisms already carry that,
   and item 3 must not weaken either:

   - **The transaction-ID guard is what makes replay a no-op.** Reset clears the
     key, disables the gateways and drops the three tables
     (`wp-ajax-wc-scanpay-reset.php:41-59`); it writes nothing order-scoped, so
     every previously synced order keeps its transaction ID and
     `class-wc-scanpay-sync.php:253` skips the whole block. This is the concrete
     reason item 3 installs the filter *inside* that block.
   - **A refunded order cannot be forced.** `refunded` is absent from
     `PAYMENT_COMPLETE_STATUSES` (`class-wc-scanpay-sync.php:17`, mirroring
     WooCommerce's own list), and `woocommerce_payment_complete_order_status` is
     applied **only** inside `payment_complete()`'s `has_status( $valid )` branch
     (`class-wc-order.php:158-174`), so on the ineligible path the filter is
     installed and removed without ever firing. Do not move the forcing to a
     `set_status()` call, which would lose that protection.

   **`cancelled` is eligible and must still not be forced.** It *is* in
   `PAYMENT_COMPLETE_STATUSES`, so a cancelled order reaching the block does
   transition — today to `processing` on a physical order, which leaves the
   merchant something to review. Forcing `completed` there would auto-fulfil an
   order that hold-stock or the merchant deliberately ended. Restrict the forced
   status to a current status of `pending`, `on-hold` or `failed`. This is
   reachable on a *first* sync — a payment landing after a hold-stock
   cancellation — not only on a replay.

   Do not give the flag a TTL, a transaction binding, or a "was this the attempt
   that paid" check. The transaction-ID guard already answers that question.

### Verify

- The filter is installed inside the `:253` block, matches on order ID, and is
  removed in a `finally` on every path including a `false` return or throw. The
  `try`/`finally` skeleton is written in a shape Task I can add a `catch` to
  without restructuring it.
- Exactly **one** meta key is written, and sync's decision is a single explicit
  true check against it — grep confirms no second companion key.
- The persisted flag is read by sync and written at the point item 2 specifies
  for each path, through `add_meta_data( …, true )` so a retry replaces rather
  than duplicates it. Grep confirms the policy helper has no sync-side caller
  and no live read of `wcs_complete_initial`, `wcs_complete_renewal` or
  `wc_autocapture` remains on the sync path.
- The filter is unreachable for an order that already carries a transaction ID,
  so a replay from seq 0 installs it for no already-synced order.
- The forced status is restricted to a current `pending`, `on-hold` or `failed`;
  state what a `cancelled` and a `refunded` order do under the new code.
- Confirm in `.stubs/woocommerce-subscriptions` that WCS's own
  `maybe_autocomplete_order`
  (`includes/class-wc-subscriptions-order.php:1175`, registered at `:73`) bails
  immediately unless the *incoming* status is `processing` (`:1176-1179`). Since
  this task forces `completed`, that check is what makes the two compatible at
  any hook priority.
- Nothing writes to `scanpay_subs`.

### Handoff

The matrix below **assumes a physical order** — one where `needs_processing()`
is true. For a virtual or downloadable order (or any order under
`wc_complete_virtual`) WooCommerce's own default is already `completed`
(`class-wc-order.php:174`), so every `processing` row reads `completed` instead
and this task changes nothing. Test with a physical subscription product or it
will appear to pass before the fix.

| Flow | Setting | `wc_autocapture` | Expected status |
| --- | --- | --- | --- |
| Initial | No | `completed` | `processing` |
| Initial | Yes | `completed` | `completed` |
| Initial | Yes | `on` | `completed` |
| Initial | Yes | `off` | `processing` |
| Scheduled renewal | No | `completed` | `processing` |
| Scheduled renewal | Yes | `completed` | `completed` |
| Scheduled renewal | Yes | `on` | `completed` |
| Scheduled renewal | Yes | `off` | `processing` |
| Customer-paid renewal | No | `completed` | `processing` |
| Customer-paid renewal | Yes | `completed` | `completed` |
| Customer-paid renewal | Yes | `on` | `completed` |
| Customer-paid renewal | Yes | `off` | `processing` |

Plus: a one-time physical order is unaffected; virtual/downloadable behaviour is
unchanged; a free-trial parent still completes; a resubscribe order behaves as
the customer-paid renewal rows above; changing the live setting
mid-flight does not reinterpret the attempt; `woocommerce_payment_complete`
fires once; processing and completed emails are not both sent; the completed
hook observes the already-captured total and sends no second capture request.
Then reset and replay from seq 0 across completed, refunded and cancelled orders
and confirm no status changes; and let a payment land after a hold-stock
cancellation and confirm the order does not reach `completed`.

Also flag for the human: the forced status is set inside `payment_complete()` →
`set_status()` → `save()`, so `woocommerce_order_status_completed` fires
*during* that call, and a capture failure there calls `update_status( 'on-hold' )`
— a nested status change inside an in-flight save. This path exists today only
for virtual orders; the task extends it to physical subscription orders.

---

## Task I: Give a failed WooCommerce payment completion its Scanpay context

`WC_Scanpay_Sync::sync()` upserts the authoritative `scanpay_meta` row and then
calls `payment_complete()` (`class-wc-scanpay-sync.php:299`) without checking its
return. WooCommerce catches failures, returns `false`, and no exception reaches
the ping handler — so the loop treats the change as processed and advances
`scanpay_seq.seq`. Scanpay can hold a successful payment while the order stays
pending, failed, cancelled or on hold, and the keepalive cannot repair it because
the cursor already acked the change.

**The failure is not silent.** `includes/class-wc-order.php:191-205`: the catch
block already writes a `wc_get_logger()->error()` entry *and* adds a
merchant-visible order note — `Payment complete event failed.` plus the exception
message. What it lacks is **Scanpay context**: it does not say Scanpay holds a
successful payment, does not name the transaction or charge, and does not say the
order needs manual reconciliation. Read cold it looks like a generic WooCommerce
hiccup, not a money-collected-but-order-unpaid condition.

One genuine silence remains: WooCommerce catches `Exception`, **not**
`Throwable`. A callback raising an `Error` escapes `payment_complete()` entirely,
is neither logged nor noted, and propagates to the ping handler as a 500 —
pinning the cursor and producing exactly the shop-wide stall item 2 rejects.

Two consequences are **correct behaviour** and must not be "repaired": the
`scanpay_meta` row making the renewal path skip a charge is right — Scanpay
genuinely holds a payment, and the guard at
`class-wcs-scanpay-charge.php:175-189` prevents a double charge; and the row must
not be deleted or rolled back, being the authoritative record of
`captured`/`refunded`/`voided` and carrying the `rev` used for change ordering.

### Fix

1. Capture the result and wrap the call in `catch ( \Throwable $e )`.

   - On `false`, WooCommerce has already logged and added its generic note;
     append Scanpay context.
   - On an escaping `Throwable` (normally an `Error`), log the raw diagnostic
     and add the same actionable Scanpay note.

   In both cases the note names the transaction/charge label, the order ID and
   the fact that Scanpay holds a successful payment the order does not reflect.
   Then **return normally and let the cursor advance**.

   Reporting is best effort and must not recreate the stall: contain a
   `Throwable` from `add_order_note()` itself, log that secondary failure, and
   still return normally. Follow the underpayment branch earlier in the same
   block (`:276-280`), whose comment at `:274-275` states the file's rule:
   *"Log + note + return, never throw."*

2. **Do not throw.** This does not contradict `AGENTS.md`'s fail-loud rule,
   which governs malformed *Scanpay* payloads, not WooCommerce-side completion
   failures. Throwing would invert an invariant this file documents at
   `:162-163`, `:247-249`, `:266-268` and `:274-275`, and convert one stuck order
   into a shop-wide outage. The failure is not knowably transient: a third-party
   callback on `woocommerce_pre_payment_complete` that throws deterministically —
   a broken CRM or ERP integration is the ordinary case — returns `false` on
   every replay. Throwing pins the cursor, the same seq page replays forever, and
   every other order stops synchronizing with no exit condition. The keepalive
   cannot break it because the failure is in our handler, not in ping delivery.

3. Preserve the save-before-call behaviour for ineligible statuses. WooCommerce
   returns `true` for that deliberate no-transition branch *when no callback
   throws* — it still fires
   `woocommerce_payment_complete_order_status_{status}`
   (`class-wc-order.php:187-189`), so a throwing callback yields `false` there
   too. Item 1 handles it; no special case needed.

4. Add no retry, cron, recovery-table or cursor-rollback machinery. The order
   needs merchant intervention; the plugin's job is to make that visible.

5. Make the note actionable: payment succeeded at Scanpay, WooCommerce could not
   complete the order, reconcile manually — not an echoed exception string. Keep
   the raw diagnostic in `scanpay_log()`. Our note lands **alongside**
   WooCommerce's on the `false` path, not instead of it, so write it to
   complement. On the escaping-`Throwable` path there is no WooCommerce note, so
   ours is the only merchant-facing record. Coordinate wording with Task N.

6. **The auto-complete task already built the `try`/`finally` here — extend it,
   do not rewrite it.** What it left behind, so you can recognize it: inside the
   `if ( empty( $wco->get_transaction_id( 'edit' ) ) )` block, a scoped
   `woocommerce_payment_complete_order_status` filter installed immediately
   before `payment_complete()`, matched on the order ID, and removed in a
   `finally` on every path. This task adds only a `catch` and the reporting. If
   you find yourself restructuring that block, stop: one of the two
   implementations is wrong, and the earlier task's full text is in
   `git log -p -- PLAN.md`.

   **Ordering matters.** `catch` runs *before* `finally`, so a note added from
   the catch body would execute with that filter still installed. Report *after*
   removal: capture the result, let the filter come off, then log and note
   outside the `try`/`finally`.

### Verify

- The `false` branch cannot throw and cannot skip the cursor advance.
- An escaping `Throwable` also cannot escape through the reporting path or skip
  the cursor advance.
- The `scanpay_meta` write still precedes the call and is not rolled back.
- Re-read `class-wc-order.php:138-209` in `.stubs/` — through the `return true;`
  — and state precisely which failure modes produce `false` versus an escaping
  `Error`, and which of those your handler covers.
- No new retry/cron/recovery/rollback code was added.

### Handoff

Stub `payment_complete()` to return `false` and confirm log + note + cursor
advance + 200. Attach `woocommerce_pre_payment_complete` callbacks that throw an
`Exception` (WooCommerce converts it to `false`) and an `Error` (escapes
WooCommerce); in both cases confirm **later orders in the same page and in
subsequent pings keep synchronizing** — a wedged cursor is the regression this
design exists to prevent. Make `add_order_note()` fail on the recovery path and
confirm that also cannot wedge the cursor. Exercise the ineligible-status branch.
Confirm a renewal for such an order is still declined by the double-charge guard.
Read the resulting note as a merchant would.

---

## Task J: Run the thank-you sync wait after customer-paid subscriber renewals

`wc_scanpay_process_payment()` builds the filtered return URL at
`generate-payment-link.php:82`, then at `:109-114` returns
`[ 'result' => 'success', 'redirect' => $client->renew( $subid, $data ) ]` as
soon as the order carries a nonzero `WC_SCANPAY_URI_SUBID`. The `add_query_arg`
that appends `scanpay_thankyou`, `scanpay_type` and `scanpay_ref` is at
`:174-181` — unreachable from that branch.

The `_scanpay_subid` branch is not limited to a payment-method change. WCS copies
custom subscription metadata onto renewal orders — `wcs_copy_order_meta()` via
`WC_Subscriptions_Data_Copier::copy()`, and `_scanpay_subid` is **not** in
`DEFAULT_EXCLUDED_META_KEYS`
(`includes/class-wc-subscriptions-data-copier.php:20-45`) — and the plugin writes
it to the subscription at `class-wc-scanpay-sync.php:366`. So a customer paying a
failed renewal enters the same branch, and that renew request carries the renewal
order ID.

Without the routing arguments the bounded thank-you wait is never installed, so
WooCommerce can render the order-received page before the ping has written the
transaction ID, method title and paid status — the same race the wait already
closes for `new_url()` payments. The wait would work here: sync sets the
transaction ID (`class-wc-scanpay-sync.php:282`) on the order named by the
payload's `orderid`, which is the renewal order
(`generate-payment-link.php:80`).

### Fix

1. Distinguish the uses before returning. **There are three, not two** — a
   resubscribe order also carries `_scanpay_subid`, because
   `wcs_create_order_from_subscription()` copies subscription meta for
   `resubscribe_order` as well as `renewal_order`, so it enters this branch too.
   It is treated exactly as a customer-paid renewal: routing arguments and the
   wait. Same decision the auto-complete task made for the completion intent.

   - A pure WCS payment-method change updates the stored method and returns to
     the WCS-filtered My Account URL. It may create no order transaction and
     must not wait for one.
   - A customer-paid renewal creates payment data for that renewal and needs the
     same wait as an ordinary paid order.

   The discriminator already exists and is already used in this file:
   `WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment` is a
   public static set from `$_GET['change_payment_method']`
   (`class-wc-subscriptions-change-payment-gateway.php:83-87`), read at
   `generate-payment-link.php:28-31` — inside `wc_scanpay_subref()`, a different
   function, so copy the guarded read rather than calling it. Keep the
   `class_exists( …, false )` guard; do not reference the class bare.
   **The auto-complete task landed first and already put this discriminator in
   this branch — reuse what it built instead of adding a second read.** The two
   changes otherwise touch disjoint points: this one adds routing arguments
   *before* `renew()`, that one persists `_scanpay_complete` *after* it returns.

2. For a paid renewal, add the routing arguments to `$data['successurl']` before
   `renew()`: `scanpay_thankyou` identifying the renewal order and
   `scanpay_type=wc` selecting the paid-order wait. The WooCommerce order key is
   already in the filtered URL, so the handler's ownership gate still applies.
   Do not invent a subscriber reference — `scanpay_ref` is read only by the
   `wcs_free` branch (`wp-scanpay-thankyou.php:153-161`).

3. Preserve the WCS-filtered success URL for a pure method change. No
   transaction wait, no fixed delay.

4. Reuse `wp-scanpay-thankyou.php` and its existing HPOS/legacy reads, order-key
   check, Scanpay method check, bounded backoff and give-up (`:120-122`), and the
   type dispatch at `:127-130`. Add no second polling implementation, ping retry
   or recovery mechanism.

5. Preserve the friendly exception handling around `renew()`, the subscriber ID,
   the payload's billing/shipping data, the link lifetime, and the existing
   `wcs_complete_renewal` autocapture raise plus the `_scanpay_complete` write
   after a successful `renew()`.

6. Keep one-time payments, initial paid subscriptions and free-trial parents on
   their existing `wc`, `wcs` and `wcs_free` paths.

Note one pre-existing quirk to leave alone deliberately: in the change-payment
case `$data['orderid']` (`:80`) is the **subscription** ID, not an order ID.

### Verify

- The branch at `:109` now distinguishes the cases and only the renewal path
  gains routing arguments.
- The success URL retains the order key.
- The `_scanpay_complete` write still happens after a successful customer-paid
  `renew()` and never for a method change.
- `wp-scanpay-thankyou.php` is unmodified apart from anything item 4 genuinely
  requires.
- Re-read
  `.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/includes/class-wc-subscriptions-data-copier.php`
  to reconfirm `_scanpay_subid` is not in `DEFAULT_EXCLUDED_META_KEYS`
  (`:20-45`) — the whole premise rests on it.
- The `class_exists( …, false )` guard is present so a WCS-less site cannot
  fatal.

### Handoff

Pay a failed renewal and inspect the renew payload's success URL; delay the ping
and confirm the handler waits under both HPOS and legacy storage; return after
sync and confirm the first probe returns without sleeping; exercise a
payment-method change and confirm it keeps the My Account URL and does not wait;
pay a resubscribe order and confirm it gets the same routing arguments and wait
as a renewal; exercise a transient `renew()` failure; re-test the three existing
paths.

---

## Task K: Restore WooCommerce's gateway state and extension contracts

`WC_Gateway_Scanpay_Base::__construct()` (`abstract-wc-gateway-scanpay-base.php:8-13`)
calls `init_settings()` but never initializes the inherited public `$enabled`,
`$title` or `$description`. `WC_Payment_Gateway` defaults `$enabled` to `'yes'`
and declares `$title`/`$description` with no defaults
(`abstract-wc-payment-gateway.php:61,68,75`).

The `$enabled` omission is functional, not metadata-only: inherited
`is_available()` reads the property (`abstract-wc-payment-gateway.php:346-357`)
and `WC_Payment_Gateways::get_available_payment_gateways()`
(`class-wc-payment-gateways.php:333-345`) filters on `is_available()` **alone**,
with no separate enabled check anywhere ahead of it — so classic checkout can
offer gateways whose saved `enabled` setting is `no`. Blocks does not mask this;
its separate payload builder reads the option directly.

Classic checkout works only because the base overrides `get_title()` (`:39-44`)
and `get_description()` (`:47-49`) and re-reads settings. Other consumers read
the properties directly and report `null` —
`Version3/class-wc-rest-payment-gateways-controller.php:39-40`,
`Version2/…-v2-controller.php:260-261`,
`src/Internal/RestApi/Routes/V4/…/AbstractPaymentGatewaySettingsSchema.php:527-528`,
`includes/class-wc-tracker.php:1006`. (Not everything is affected:
`get_payment_gateway_name_by_id()` at `class-wc-payment-gateways.php:294` prefers
the getter and falls back to `->title` only at `:304`, which is why this has gone
unnoticed.)

The custom getters also bypass public contracts: `get_title()` omits
`woocommerce_gateway_title` and current WooCommerce's title sanitization
(`abstract-wc-payment-gateway.php:373-385`); `get_description()` omits
`woocommerce_gateway_description` and `wp_kses_post()` (`:392-406`); all three
`get_icon()` implementations (`card:43-55`, `mobilepay:17-20`, `applepay:17-20`)
omit `woocommerce_gateway_icon` (`:413-424`); `get_transaction_url()`
(`base:56-60`) omits `woocommerce_get_transaction_url` (`:295-313`).

The `is_admin()` `Scanpay` title is **not** a defect — see "Verified sound" in
`AGENTS.md`.

### Fix

1. Initialize all three inherited properties after `init_settings()`: `$enabled`
   as `'yes'` only when the stored value is exactly `'yes'` (otherwise `'no'`),
   and `$title`/`$description` as strings, so properties, getters, REST
   responses, availability and strict return types agree.

   **Factor this into a small method, not inline constructor code.**
   `admin/settings/process-admin-options.php` re-runs `$this->init_settings()`
   at `:30` after saving and force-disables via `$this->settings['enabled'] =
   'no'` at `:62`. Initializing in the constructor only leaves the properties
   stale for the rest of that admin request — so the saved value and
   `$gateway->enabled` disagree on exactly the save request the REST controllers
   and the Payments list read from. Call the method from the constructor **and**
   after both of those points.

   Do not read these through `get_option()` with a default:
   `WC_Settings_API::get_option()` force-loads the form fields when the key is
   missing (`abstract-wc-settings-api.php:304-307`), and the card fields file
   runs `get_pages()` (`admin/settings/fields/scanpay.php:11`). Read
   `$this->settings['title'] ?? $default` directly. Avoiding it here does not
   remove the hazard: the card gateway's *constructor* already calls
   `get_option( 'stylesheet' )` (`class-wc-gateway-scanpay-card.php:29`) and
   `get_option( 'wc_complete_virtual' )` (`:32`) on every request that builds
   the gateways, front end included, and `get_icon()` adds `card_icons` (`:44`).
   Two facts keep this proportionate: the miss only happens for a key genuinely
   absent from the saved option, and both `$this->settings[$key]` and
   `$this->form_fields` memoize, so the worst case is one fields load per
   request. Reading the properties directly is still right; just do not claim it
   eliminates a per-request `get_pages()`.

   Preserve two consequences explicitly:

   - **This changes an explicitly empty saved title.** A missing key already
     resolves to the field default because `get_option()` loads the lazy fields
     first. But a stored `''` is then replaced by the `$empty_value` argument
     `'Scanpay'` (`base:43`), so all three gateways render the brand.
     Initializing the property directly preserves the stored empty string,
     matching WooCommerce's normal gateway-property behaviour. Say so in the
     commit message and cover it in the handoff. Missing keys still fall back to
     `'Pay by card'` (`fields/scanpay.php:39`), `'MobilePay'` and `'Apple Pay'`.
   - The constructor and lazy field definitions need the same six defaults. Make
     each subclass expose its checkout title/description defaults through one
     protected getter or equivalent single source, consumed by both the base
     constructor and that gateway's fields file. Do not duplicate six literals
     with comments asking future edits to keep them synchronized. The fields
     files are required only from `get_form_fields()`, so `$this` is available.
     `init_settings()` already merges field defaults when the option is not an
     array (`abstract-wc-settings-api.php:284-287`), so `?? $default` only fires
     for a saved array missing the key.

   Do not extend this to `card_icons` — `class-wc-gateway-scanpay-card.php:44`
   keeps `get_option( 'card_icons', [] )` because item 4 relies on that call's
   `$empty_value` coercion.

2. Delete the `get_description()` override. Once the property is initialized the
   parent supplies the filter and version-appropriate sanitization.

3. Shrink `get_title()` to the branding decision alone:

   ```php
   return is_admin()
       ? (string) apply_filters( 'woocommerce_gateway_title', 'Scanpay', $this->id )
       : (string) parent::get_title();
   ```

   **Both branches keep the `(string)` cast.** `parent::get_title()` returns an
   `apply_filters()` result, unconstrained by third-party callbacks, so under
   `strict_types` a non-string return becomes a `TypeError` on a method
   WooCommerce calls *while rendering checkout* — the hazard the comment at
   `base:40-42` documents. Keep the cast or drop the `: string` return type; not
   neither. Preserve standard signatures: `woocommerce_gateway_title`,
   `…_description` and `…_icon` receive the value and the gateway ID, not the
   instance.

4. Apply `woocommerce_gateway_icon` to each completed icon fragment, building and
   escaping the plugin-owned HTML before the filter. Cast the filter result to
   string before returning from each `: string` override, for the same
   third-party callback reason as `get_title()`.

   While rewriting `WC_Gateway_Scanpay_Card::get_icon()`, filter out empty
   entries so classic and Blocks share one normalization rule
   (`array_values( array_filter( … ) )`, matching
   `class-wc-scanpay-blocks-support.php:73`). This is **defensive consistency,
   not a bug fix**: a stored `''` is coerced to `[]` by `get_option()`'s
   `$empty_value` argument (`abstract-wc-settings-api.php:310-312`) before the
   `(array)` cast, so the broken `images/.svg` element is already unreachable.
   Do not describe it as fixing a broken checkout image.

5. Apply `woocommerce_get_transaction_url` with `( $url, $order, $gateway )`
   before returning, and cast its result to string. Keep encoding the path
   components and return an empty URL when the identifiers are absent. The
   override must stay: the parent's `view_transaction_url` template carries only
   the transaction ID and cannot encode the per-order shop ID.

   Treat the empty-identifier guard as **hardening, not a live fix**. The
   `dashboard.scanpay.dk//` string is constructible in isolation but unreachable
   through WooCommerce: both core call sites gate on a non-empty
   `get_transaction_id()`
   (`includes/admin/list-tables/class-wc-admin-list-table-orders.php:420-424`,
   `includes/admin/meta-boxes/class-wc-meta-box-order-data.php:243-248`), and
   `class-wc-scanpay-sync.php:258` refuses to write a transaction ID unless the
   shop ID matches.

   Align the meta read context while here: `base:57` reads the shop ID in
   default `view` context while `base:58` uses `edit`. Every other reader uses
   `edit` (`admin/orders.php:95`, `class-wc-scanpay-capture.php:54`,
   `class-wc-scanpay-sync.php:258`).

6. Do not replace the lazy form-field loading or the shared primary settings.

7. Add a one-line comment at the `is_admin()` branch stating the branding is
   deliberate and why. The docs half is already in `AGENTS.md`; do not add it
   again.

8. **Keep Blocks deliberately separate and record why.**
   `class-wc-scanpay-blocks-support.php:67`, `:68`, `:73` read `title`,
   `description` and `card_icons` straight from settings and never call the
   getters. Do not route Blocks through these classic getters: their filters may
   return HTML, while `checkout.ts` passes the payload to React as text, so
   markup would render literally. The JSON encoder plus React text rendering
   already prevents raw settings from becoming executable markup.

   Add a code comment at the Blocks payload builder stating that the
   HTML-oriented classic gateway filters are intentionally not applied there.
   Keep the existing `array_filter()` icon normalization. A future Blocks filter
   contract can be added separately if WooCommerce exposes one; do not invent
   one here.

### Verify

- `$title`/`$description` are non-null strings after construction on all three
  gateways, by reading the constructor chain.
- `$enabled` reflects the saved setting on all three, and inherited
  `is_available()` can no longer offer a disabled gateway.
- Each restored filter is applied with the upstream argument signature — check
  each against
  `.stubs/woocommerce/includes/abstracts/abstract-wc-payment-gateway.php`.
- The `(string)` casts survive on both `get_title()` branches, and every newly
  filtered value returned from a `: string` override is cast after
  `apply_filters()`.
- No new `get_option()` call was introduced on a checkout path.
- Each title/description default has one source shared by constructor
  initialization and the corresponding lazy fields file.
- Item 8's Blocks decision is recorded as a **code comment** at the Blocks
  payload builder — not only in the commit message, where a future reader of
  `class-wc-scanpay-blocks-support.php` will never find it.

### Handoff

`GET /wp-json/wc/v3/payment_gateways/<id>` and the CLI returning configured
enabled/title/description values rather than default-yes/null state; classic
checkout with each gateway enabled and disabled; missing versus explicitly empty
titles; each filter callback firing and being honored; safe vs disallowed HTML in
title/description; the admin selector still reading `Scanpay`.

**Explicitly unverifiable here:** item 2 defers to "the sanitization appropriate
to the installed WooCommerce version", and `.stubs/woocommerce` is one working
copy, not a version matrix. The WooCommerce 3.6 end must be exercised against a
real install before that item counts as verified.

---

## Task L: Add JavaScript translation plumbing to the build

No admin script can be translated. All three enqueues pass `[]` deps
(`settings.php:42`, `orders.php:90`, `subscriptions.php:59`), none calls
`wp_set_script_translations()`, and `build.sh` runs only `i18n make-mo` (`:49`)
and `i18n make-php` (`:52`) — no `make-json`, so no Jed catalog exists.

**The blocking constraint is extraction.** `wp i18n make-pot` does not parse
TypeScript. Every `#:` reference in `src/languages/scanpay-for-woocommerce.pot`
points at a `.php` file — though that is corroboration, not proof: `src/`
contains no `.js` at all, so the extractor was never offered one. The conclusion
stands on make-pot having no TypeScript parser.

`pnpm i18n:po` (`package.json:48`) runs `make-pot` and scans `src`; `pnpm
i18n:pot` (`:49`) runs `update-po`. **The two script names are inverted relative
to what they suggest** — do not wire item 3 to `i18n:pot`. So every admin string
Task N wants would extract to nothing. The catalog must come from the **built**
JavaScript.

### Fix

1. Extract from built, **pre-minify** JavaScript — minified output loses the line
   references that make a POT reviewable and can mangle the call shape make-pot
   matches. Extraction therefore needs unminified bundles sitting at their final
   release-relative paths, which today's build never produces — it minifies
   straight to the output path. **Both esbuild loops are affected:** `build.sh:37-40` builds the admin scripts
   (`--minify` at `:39`) and `:43-46` the public ones (`--minify` at `:45`).
   Both glob top-level `*.ts` only.

2. Make the ordering explicit, and extract on **every** build. Today POT
   generation lives in `package.json` scanning `src` while MO/PHP generation
   lives in `build.sh` against `$BUILD/languages` — two places, no enforced
   order, and nothing stops a release shipping catalogs that predate the strings
   inside it. Collapse them into one pipeline in `build.sh`, so the authored
   catalogs under `src/languages/` are re-derived every time and cannot go
   stale:

   1. wipe and rsync `src` → `build`;
   2. sass, then **unminified** JS bundles at
      `build/{admin,public}/assets/js/<entry>.js`;
   3. run `make-pot` against the plugin-shaped `build/` root, writing the
      authoritative POT under `src/languages/`, so PHP and JavaScript references
      share one release-relative namespace;
   4. update the authored PO files under `src/languages/`;
   5. resync the updated language sources into `build/languages/`;
   6. generate MO, PHP and JSON catalogs in `build/languages/` (use
      `make-json --no-purge` so the shipped PO is not destructively stripped);
   7. overwrite the unminified bundles with the normal minified output, so no
      extraction-only file survives into the release tree;
   8. the existing `{{ … }}` token substitution (`build.sh:54`), last.

   **Writing into `src/` on every build is only safe because the extraction is
   deterministic, so make it deterministic deliberately.** With
   `Report-Msgid-Bugs-To` fixed and `POT-Creation-Date` empty — pass both through
   `make-pot --headers='{"Report-Msgid-Bugs-To":"…","POT-Creation-Date":""}'` —
   `make-pot` emits a byte-identical POT when no string changed, and
   `wp i18n update-po` then reports `1 file unchanged` and does not rewrite the
   PO at all. Both verified by running the pair twice against this tree. So a
   build touches `src/languages/` only when the strings really moved, and a dirty
   `src/languages/` afterwards is a *signal*, not noise: this task changed copy,
   and the catalogs belong in its commit. Drop the deterministic headers and
   every single build churns two tracked files — which is why they are not
   optional.

   Three consequences to state rather than discover:

   - **The substitution loop stays last**, after both the minified bundles and
     the catalogs. Move it earlier and the POT header (`Project-Id-Version:
     Scanpay for WooCommerce {{ VERSION }}`) churns on every release; leave the
     minified overwrite after it and the shipped `.js` keeps a raw
     `{{ VERSION }}`.

   - **A `{{ VERSION }}` token inside a translatable string silently defeats the
     catalog.** `build.sh:54` substitutes in `*.php`, `*.js` and `*.txt` under
     `$BUILD` — but **not** `*.json`. Task N will wrap the outdated-plugin banner
     at `settings.ts:152-154`, whose string *contains* `{{ VERSION }}`. The
     shipped `.js` gets substituted while the Jed `.json` keeps the raw token, so
     `__()` is called with `…<i>3.0.0</i>…` while the catalog is keyed on
     `…<i>{{ VERSION }}</i>…`: the lookup misses and the string renders
     **untranslated**. The failure mode is not "Danish shows a literal
     `{{ VERSION }}`", it is "Danish silently stays English".

     Adding `*.json` to that loop fixes the mismatch, but the better fix is to
     keep build tokens out of translatable strings: make the msgid `…<i>%s</i>…`
     and fill it at runtime with `wp.i18n.sprintf`. **The version is not
     available to the script as data today**, so this is not a pure TypeScript
     edit: `settings.ts` gets it only from the `{{ VERSION }}` token, and
     `#wcsp-set-alert` (`admin/settings/admin-options.php:138-140`) carries
     `data-secret` and `data-shopid` and nothing else. Add a `data-version`
     attribute there — that file is `.php`, so `build.sh:54` already substitutes
     it. The payoff: the msgid stays stable across releases, instead of changing
     on every version bump and orphaning the translation.
   - **`update-po` keeps removed strings as `#~` obsolete entries.** Building
     repeatedly in the middle of a rename accumulates them in the Danish PO.
     Harmless, and the final catalog task prunes them — but do not read them as
     a fault in the pipeline and do not add machinery to suppress them.

   Keep `pnpm i18n:po` / `pnpm i18n:pot` as thin entry points into that one
   pipeline, or drop them now that the build owns extraction. Either way, do not
   leave a second source-only pipeline that can generate a different POT — that
   is the two-places problem this item exists to end.

3. Add `wp i18n make-json` and confirm the emitted filenames match what
   WordPress computes: `load_script_textdomain()` derives the JSON name from an
   md5 of the plugin-relative script path (for example
   `admin/assets/js/settings.js`), so the path recorded at extraction must be the
   path the enqueued `src` resolves to in the release tree. This is why item 2
   extracts from the plugin-shaped build root rather than a temp directory.

4. Declare `wp-i18n` as a dependency at all three enqueue sites and call
   `wp_set_script_translations()` with the plugin's languages directory — which
   must agree with `load_plugin_textdomain`'s hardcoded
   `'scanpay-for-woocommerce/languages'` (`woocommerce-scanpay.php:389`). The
   handles are `wc-scanpay-settings` (`settings.php:42`), `wc-scanpay-order`
   (`orders.php:90`) and `wc-scanpay-subs` (`subscriptions.php:59`). `wcsp-meta`
   survives as a *style* handle on two screens; it is not a script and takes no
   translations.

5. Consume `wp.i18n` as a window global, mirroring `checkout.ts:11-12`: plain
   `window.wp.element` / `window.wp.data` property access, typed by an
   `interface Window` (`types/checkout.d.ts:7-17`), with the global guaranteed by
   the script dependency declared in PHP
   (`class-wc-scanpay-blocks-support.php:26`). It is not an esbuild
   `--external`/`--global-name` mapping — `build.sh` passes only
   `--bundle --minify`. Items 4 and 5 are two halves of one mechanism. Do not
   bundle a second translation runtime.

6. Land with at least one real admin string wrapped and translated in the Danish
   PO, so extraction and JSON generation exercise the full pipeline.

### Verify

- Run `printf 'n\n' | ./build.sh` and inspect the release tree: MO, PHP and JSON
  catalogs present.
- Run it a **second** time and confirm `git status` does not move. A byte-stable
  POT and a skipped PO write are the whole reason a build may touch
  `src/languages/`, so prove it here rather than assume it.
- The proof msgid appears in the POT, the Danish PO and the JSON catalog.
- Compute `md5('admin/assets/js/settings.js')` yourself and confirm a JSON
  filename matches. Also inspect that JSON's `source` path. This is arithmetic on
  paths — do it, do not defer it.
- The unminified extraction copy is not in the release tree.

### Handoff

Switch WordPress to Danish and confirm the proof string renders translated in the
browser on the screen that enqueues it. File existence is not proof the catalog
loads.

---

## Task M: Hide Apple Pay on unsupported classic checkouts

Blocks gives `scanpay_applepay` a method-specific `canMakePayment` requiring
`ApplePaySession.canMakePayments() === true`
(`public/assets/js/checkout.ts:22-26`, wired at `:65`). Classic checkout has no
equivalent — there is **no `is_available()` override anywhere in `src/`** — so
`WC_Gateway_Scanpay_ApplePay` inherits WooCommerce's default
(`abstract-wc-payment-gateway.php:346-357`: enabled plus a max-amount check) and
is offered in every browser on every device. Selecting it redirects to the hosted
window with `go=applepay` (`class-wc-gateway-scanpay-applepay.php:30`), where the
shopper cannot complete.

### Fix

1. Add a small authored TypeScript entry point for classic checkout. Do not put
   generated JavaScript in `src/`.

   **No `build.sh` change is required.** Both esbuild loops glob top-level `*.ts`
   and `:30` rsyncs with `--exclude='*.ts'`, so a new file directly in
   `src/public/assets/js/` is compiled and shipped automatically and its source
   is not. The translation-pipeline task touched both loops (they now also serve
   an unminified extraction pass), so re-locate them by text rather than line;
   the surviving invariant is what matters here — top-level `*.ts` only, and
   `--exclude='*.ts'` on the rsync. The trap: only *top-level* files are
   entry points, and anything under a subdirectory is silently never compiled.
   `tsconfig.json:19-21` already includes `src/public/assets/js/**/*` and
   `eslint.config.mjs` targets `src/**/*.ts`, so tooling needs no change.

2. Enqueue only on classic checkout/order-pay requests where Apple Pay is
   enabled. Do not load it for Blocks.

   **`is_checkout()` is not sufficient** — it is `true` on a Blocks checkout page
   too, so the obvious guard would load this on exactly the surface item 6 says
   to leave alone. Gate on `$this->enabled`, which the gateway-state task now
   initializes from the saved setting (before it, the inherited property was
   WooCommerce's unconditional `'yes'` and would have gated nothing). Treat
   `is_checkout_pay_page()` as classic, even when the configured checkout page
   contains a block. For the ordinary checkout page, guard `WC_Blocks_Utils` with
   `class_exists()` for the WooCommerce 3.6 end and call
   `WC_Blocks_Utils::has_block_in_page( wc_get_page_id( 'checkout' ),
   'woocommerce/checkout' )` with its actual two-argument signature. Do not call
   the method with no page argument.

   The comparable existing pattern is `class-wc-gateway-scanpay-card.php:30` and
   its `enqueue_checkout_styles()` callback (hook `wp_enqueue_scripts` from the
   constructor); all three gateways are always registered
   (`woocommerce-scanpay.php:100-102`, `:333-339`), so that hook point is
   available — it just needs the stronger guard.

   Use the handle `wc-scanpay-applepay`. Declare `jquery` and `wp-i18n` as
   dependencies: classic checkout's fragment lifecycle is a jQuery event, and the
   sole-gateway notice is translated. Call `wp_set_script_translations()` for the
   new handle, with the same languages path the admin handles use — it must agree
   with `load_plugin_textdomain`'s hardcoded `'scanpay-for-woocommerce/languages'`
   (`woocommerce-scanpay.php:389`). The translation-pipeline task wired only the
   **three admin** enqueue sites (`wc-scanpay-settings`, `wc-scanpay-order`,
   `wc-scanpay-subs`); this is the first *public* translated script, so it must be
   added to that mechanism rather than assumed covered by it — including its JSON
   catalog, whose filename is the md5 of `public/assets/js/<entry>.js`.

   The DOM surface, so it is not guessed: the row is
   `li.payment_method_scanpay_applepay` inside `ul.wc_payment_methods`, with
   radio `#payment_method_scanpay_applepay`.

3. Probe exactly what Blocks probes:
   `window.ApplePaySession?.canMakePayments() === true`.

   - True → leave it available.
   - False or absent → remove/hide the row and prevent it being submitted
     through the normal UI.
   - Already selected, or selected after a fragment refresh → select another
     visible gateway and notify WooCommerce.
   - If Apple Pay is the sole rendered gateway, hide/uncheck it, show a
     translated accessible "not available on this device/browser" notice, and
     disable the normal place-order submission until a fragment refresh provides
     another method. Do not leave an invisible checked input or a live Place
     order button.

   This is a browser-capability UI gate. Client-side code cannot prove capability
   to the server or stop a crafted request, so do not describe it as a
   server-side authorization boundary. The hosted window stays the final
   authority.

4. Apply once on DOM ready and again on classic checkout's `updated_checkout`
   jQuery event — the payment-method list is replaced on address, shipping and
   coupon changes. Note `/order-pay/` does not fire fragment refreshes, so the
   second hook is inert there.

5. Avoid user-agent inference and `canMakePaymentsWithActiveCard()` — the former
   is unreliable, the latter is async and would require a provisioned card rather
   than a capable environment.

6. Keep `process_payment()` and the Blocks implementation unchanged.

### Verify

- The new file is top-level in `src/public/assets/js/` and `./build.sh` emits its
  `.js` into the release tree without shipping the `.ts`.
- The enqueue guard excludes Blocks by an actual block check, not `is_checkout()`
  alone.
- The probe expression matches `checkout.ts:26` exactly.
- The new handle depends on `jquery` and `wp-i18n`, has script translations
  attached, and the unsupported sole-gateway branch cannot submit a hidden Apple
  Pay input.

### Handoff

Safari on a supported Apple device; Chrome/Firefox and environments without
`ApplePaySession`; Apple Pay preselected then a fragment refresh; address,
shipping and coupon changes; Apple Pay as the sole enabled gateway (notice shown,
submission disabled); Blocks retaining its `canMakePayment` behaviour and not
loading the new asset.

---

## Task N: Internationalize the remaining user-facing strings

Substantial user-facing text is hard-coded, especially in admin TypeScript:
sync/reset/update notices (`settings.ts:48,61,67,75,76,104`), order meta-box
messages (`order.ts:122,127,130,134,173`), subscription meta-box labels and
warnings (`subs.ts:56,57,62,64,79,83,93`). Some PHP payment errors, accessibility
labels, order notes and status-change reasons too.

**Those line numbers are a sample, not the work list.** They under-cover their
own prose — uncited: `order.ts:55,60,81,86` (including the `'Capture'` button),
`subs.ts:30,50,58`, `settings.ts:49,57,62,68,105-107,111,116,117,127`, and the
outdated-plugin banner in **two** places, `types/meta.ts:80` and
`settings.ts:152-154`. Drive the sweep from the classification rule, not the
list.

**Those two banners stay two msgids** — they look like duplicates and are not.
The settings one names the running version
(`Your Scanpay extension (<i>%s</i>) needs to be updated to …`), the meta-box one
does not (`Your scanpay plugin is <b>outdated</b>. Please update to …`), and only
the settings screen has a version to render: `#wcsp-set-alert`
(`admin/settings/admin-options.php:138-140`) is
where the translation-pipeline task put `data-version`. Merging them would mean
rewriting one of the two English sentences, which the scope rule below forbids.
Translate both, separately.

Two scope decisions apply throughout: rewriting awkward English source copy is
**out of scope** — it is not internationalization, and it invalidates existing
translations — and operational diagnostics stay untranslated.

The Blocks terms sentence is **not** part of this task: both renderers already
share a single `I accept the %s.` msgid, split on `%s` in `checkout.ts`
(`wcs-scanpay-checkout-terms.php:21-25`,
`class-wc-scanpay-blocks-support.php:59-61`). Leave it alone.

### Fix

1. Classify every authored string at its presentation boundary.

   Translate: checkout and settings copy, validation failures, buttons,
   confirmations, notices, accessible labels, meta-box text, order notes,
   human-facing status-change reasons, and generic UI context around a raw
   backend error (leaving the raw diagnostic alone).

   Do **not** translate: `scanpay_log()` messages, internal exceptions used for
   logs or control flow, backend/API response text, database diagnostics, stable
   identifiers, payment-method IDs, protocol values, table/column names, and
   machine-readable AJAX codes such as `capture_failed`.

   If an internal failure can leak into the UI, keep the diagnostic in the log
   and present a separate translated message at the boundary.

2. Use the `scanpay-for-woocommerce` domain and the right APIs: escaped helpers
   where output is rendered directly; placeholders over concatenation with
   translator comments; `_n()` for count-dependent seconds/minutes; preserve the
   deliberately plain checkout title/description setting *defaults* (`__()`
   cannot localize a stored value); do not translate brand names, card
   identifiers or currency codes to inflate coverage.

3. Keep PHP-provided Blocks strings in PHP unless moving one materially improves
   interpolation or plurals. Never maintain two sources for one sentence.

4. The **PHP half does not depend on the translation pipeline** — it extracts
   through `pnpm i18n:po` today. Only the `.ts` sweep is blocked on it. This is
   still one commit and is not done until the TypeScript sweep is included.

   Resolve one inconsistency while here: `admin/orders.php:146` wraps the
   meta-box title in `__( 'Scanpay', … )` while `admin/subscriptions.php:60`
   passes a bare `'Scanpay'`. Per item 2, make both bare.

### Verify

- Re-run `pnpm i18n:po` and confirm the new PHP strings appear in the POT.
- Grep PHP and TS for remaining human-facing literals and **account for every
  deliberate exclusion in writing** — this is the substance of the task and is
  fully doable statically.
- No `scanpay_log()` call, internal exception or AJAX error code was wrapped.

### Handoff

Danish across all three settings pages, both meta boxes, classic and Blocks
checkout, and order notes; confirm plurals and placeholder ordering render
correctly and dynamic API/DB details are still escaped as text.

---

## Task O: Regenerate the translation catalogs

`src/languages/scanpay-for-woocommerce-da_DK.po` predates the rewrite — **39 of
its 45 source references are stale** (the six that resolve all name
`woocommerce-scanpay.php`: five plugin-header entries plus one recorded at
`:250`, a line that no longer holds that string). The dead paths are
`includes/form-fields.php`,
`gateways/class-wc-scanpay-gateway-applepay.php`,
`gateways/class-wc-scanpay-gateway-mobilepay.php`, `includes/admin-options.php`,
`hooks/class-wc-scanpay-blocks-support.php`,
`gateways/class-wc-scanpay-gateway.php`. It also carries two `POT-Creation-Date`
headers (`:10`, `:11`). The POT carries a metadata artifact from being generated
against `src` rather than the plugin root:
`Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/src`
(`scanpay-for-woocommerce.pot:6`).

### Fix

1. Regenerate the POT by running the build, which now extracts, so it covers
   PHP and built JS; then **verify** the headers rather than setting them
   again: that pipeline already passes `Report-Msgid-Bugs-To` and a blank
   `POT-Creation-Date` to `make-pot`. If either is still wrong here, the bug is
   in the pipeline — fix it there. Do not introduce a second place that stamps
   POT headers.
2. Update the Danish PO: merge against the new POT, remove obsolete pre-rewrite
   entries and the duplicate header, translate all maintained user-facing
   strings. Preserve product names and technical terms where translating would
   mislead.
3. Re-run extraction and confirm no further diff. A second run showing changes
   means the pipeline is nondeterministic — fix that first.

### Verify

Fresh extraction produces an empty diff; no stale path survives in either
catalog; the release tree carries MO, PHP and JSON artifacts.

### Handoff

Spot-check the Danish catalog against the screens the string sweep covered: all
three settings pages, both meta boxes, classic and Blocks checkout, order notes.
