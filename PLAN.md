# PHP review follow-up plan — run 3

<!-- markdownlint-disable MD024 -->

Twenty tasks, **1** through **20**, in execution order. One commit each, titled
`<summary> (task N)`. No `scanpay:` prefix.

Tasks 1–17 come from a full read of all 34 PHP files in `src/` (5 599 lines);
`pnpm phpcs` was clean before that read, so each is a defect or a drift no sniff
can see. Tasks 18–20 are standing sweeps and must run last, in order, against the
tree the first 17 leave behind.

Order is by file, then by severity within a file, so no file is opened twice
except where noted. Tasks 1–5 are live defects; stopping early costs the least
there.

## Run protocol

Unattended, one agent, one pass. Implement every task yourself, in order. Do not
parallelize, do not delegate implementation, do not start a task before the
previous is committed, never two tasks in one commit. Read-only search agents are
fine.

Per task:

1. Read `AGENTS.md`, then `HANDOFF.md` (an earlier task may have blocked this
   one), then `## Task N` here. Do not read this whole file.
2. Confirm a clean tree and that the previous task's commit is `HEAD`. For task 1:
   `git log -1 --oneline -- PLAN.md`.
3. Implement, in `src/` only. `build/` is generated.
4. `pnpm phpcs` (autofix `pnpm phpcbf`) and
   `find src -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l`; add
   `pnpm lint:js` and `pnpm exec tsc` if any `.ts` changed, `pnpm lint:style` if
   any `.scss` changed. All clean as written — any diagnostic is yours.
5. Do the **Verify** bullets; write them and the **Handoff** list to `HANDOFF.md`
   (gitignored, never `git add`). Append; head each entry
   `## Run 3 · Task N — <summary>`.
6. Delete the task's section from this file.
7. `git add -A` and commit. Keep verification status out of the message.
8. Next task in a fresh context (new session or `/clear`), from step 1.

When task 20 lands, this file is its header alone. Never push, never open a PR.

**Never run `./build.sh` bare** — it is `set -e` and prompts for a deploy, so EOF
looks like a failed build. Use `printf 'n\n' | ./build.sh`. Answering `y` rsyncs
to a live server.

**Line numbers here were correct when written; earlier tasks move them.** Locate
the cited symbol. When a number disagrees with the source, the source wins.

**Blocked** = the source contradicts a task's premise in a way that changes the
fix, or step 4 cannot come clean. Do not improvise a different design, do not
commit a partial task: restore the tree, record it in `HANDOFF.md` under
`## Blocked: task N`, leave the section, move to the next independent task.
"Hard" is not "blocked".

## Evidence rules

There is no WordPress install here — no site, database, browser or network. So:

- **Verify** — do it and report it. Reading upstream source counts; so does
  tracing a path and stating the reachable outcome.
- **Handoff** — only a real shop can settle it. Do not perform, simulate or claim.

Fabricating a handoff result, or calling a task verified because its static half
passed, is worse than leaving it open.

Upstream source is in `.stubs/` — read it, never guess. If absent, the run is
blocked. Paths: `.stubs/woocommerce/includes/`, `…/includes/abstracts/`,
`…/templates/`, `…/src/Blocks/`, `…/src/Internal/`;
`.stubs/wordpress/wp-includes/` and `…/wp-admin/includes/`;
`.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/`. Stub
versions are WC `11.1.0-dev`, WCS `7.2.1`, WP `7.1-alpha` — they answer "what does
current WordPress/WooCommerce do", never "what does our 3.6 / 6.3 floor do", which
`docs/requirements.md` owns. `php -r` settles PHP semantics; to load
`src/library/math.php` directly, `define( 'ABSPATH', … )` first or it `exit()`s
silently.

Facts already read out of `.stubs/` during the review. Re-read the citation; do
not re-derive the conclusion:

| Task | Fact | Where |
| --- | --- | --- |
| 1, 4 | The tree already treats "a third party's hook can throw out of `add_order_note()`" as live, in three places, each naming `woocommerce_new_order_note_data`, `wp_insert_comment`, `woocommerce_order_note_added` | `class-wc-scanpay-sync.php:296-303`, `:407-411`, `generate-payment-link.php:176-183` |
| 4 | The tree already treats "`scanpay_log()` can throw" as live: `wcs_scanpay_fail_renewal()` wraps its own log call for exactly that | `woocommerce-scanpay.php:352-358` |
| 2, 6 | `WC_Data::get_prop()` applies `{hook_prefix}{prop}` **only** in the `view` context | `abstracts/abstract-wc-data.php`, `get_prop()` |
| 6 | `WC_Abstract_Order::get_status()` substitutes `apply_filters( 'woocommerce_default_order_status', 'pending' )` for an empty status **in `view` only** | `abstracts/abstract-wc-order.php`, `get_status()` |
| 13 | `get_tooltip_html()` takes `$tip` from `$data['description']` when `desc_tip === true` and returns `''` when empty — `desc_tip` with no `description` renders nothing | `abstracts/abstract-wc-settings-api.php` |
| 13 | `generate_checkbox_html()` echoes `$data['title']` into `<th scope="row">` unconditionally — no title means an empty header cell | `abstracts/abstract-wc-settings-api.php` |
| 3 | `wp-settings.php` loads plugins at `:579` and `pluggable.php` at `:610`, so `current_user_can()` does not exist at router dispatch | `.stubs/wordpress/wp-settings.php` |
| — | `empty( $x->prop )` on a null `$x` emits no diagnostic — `empty()`/`isset()` suppress "Attempt to read property on null" | `php -r 'error_reporting(E_ALL); $a=null; var_dump(empty($a->b));'` |

## Do not weaken

Money-string arithmetic (`src/library/math.php`), the ping protocol, the sync
flock, the API-key write-once policy, the lock-free charge design.

Standing decisions. No task reopens any:

- **Performance is out of scope.** `docs/performance-review.md` owns it and
  already covers: the duplicated payment payload (§5.1), the AJAX auth preamble
  (§5.2), `wc_scanpay_thankyou_read()` as a shared helper (§5.3), the two shop-id
  idioms and open-coded `wc_scanpay_is_scanpay_order()` (§5.4), the
  `$subid_col`/`$subid_val` splice and `install.php`'s three CREATE blocks (§5.5),
  `get_pages()` in the card constructor (§1.3), the long-poll worker hold (§4.2),
  the thank-you busy-wait (§6.3), `preg_match` in `wc_scanpay_is_money()` (§6.1)
  and `math.php`'s internals (§6.2). **Do not merge the lists.** Task 8 is not
  §-material: it is about a migration that cannot *finish*, not one that is slow.
- **The router's three early returns register nothing, deliberately.** The
  thank-you args suppress the whole bootstrap on any URL. Re-derived twice now;
  still not a defect. No task turns one into a partial plugin load.
- **The admin-AJAX secret stays unscoped.** Settled: order-editing access is
  all-or-nothing on a WooCommerce shop, so a per-order token gates nothing the
  holder cannot already read, and the endpoints dispatch before `pluggable.php`.
  Written up in `AGENTS.md` and at the dispatch gate. No HMAC, no capability
  check, no rate limit on the `?x=` endpoints.
- **Reset must not touch operating settings**, `wc_autocapture` above all. The
  button both hands the store to a new account *and* rebuilds the tables after a
  fault with the same key, so clearing a setting there would silently disable
  auto-capture on a repair. Bounds task 3.
- **Renewal retry pacing is solved** by `wcs_scanpay_retry_rule()`'s ≥25 h floor.
  No second mechanism.
- **Charging stays lock-free.** No DB lock, no `nxt`, no key rotation.
- **The subscription terms checkbox is cart-level consent**, covering whichever
  gateway the customer picks, ours or a third party's, enabled or not.
- **The Blocks `description` fallback is deliberately `''`** on all three methods.
- **`upgrade.php`'s stored `'Pay by card.'`** stays.
- **`get_total( 'edit' )` everywhere is right.** Tasks 2 and 6 extend that rule to
  the last view-context reads; they are not a licence to move anything back.
- **`scanpay_meta` is upserted before the order is loaded**, so a replay restores
  the row without re-firing `payment_complete()`.
- **Settings-field *defaults* stay plain strings.** `__()` may not wrap a stored
  value. Task 13 translates field *titles*, which are labels.
- **The two backend facts settled with Scanpay stand**: `/v1/new` with a
  `subscriber.ref` and no `items` creates a subscriber and nothing else;
  `/v1/subscribers/{subid}/renew` charges nothing. Do not re-derive either from
  the public documentation.

## Tasks

| # | Focus | File |
| --- | --- | --- |
| 1 | A completed capture can be recorded as a failure | `library/class-wc-scanpay-capture.php` |
| 2 | The last money read in the view context sets a capture amount | `library/class-wc-scanpay-capture.php` |
| 3 | A cleared API key reads as a broken one | `library/class-wc-scanpay-capture.php` |
| 4 | A collected renewal can be marked failed | `library/class-wcs-scanpay-charge.php` |
| 5 | The drain's time limit is renewed by round count, not time | `callback/wc-scanpay-ping.php` |
| 6 | Three status guards read through a third-party filter | `admin/hooks/wp-bulk-actions.php`, `admin/hooks/wp-ajax-wc-mark-order-status.php`, `library/class-wc-scanpay-sync.php` |
| 7 | `WC_Scanpay_Sync::$settings` is public for no reader | `library/class-wc-scanpay-sync.php` |
| 8 | The 2.1.3 migration cannot finish on a large shop | `upgrade.php` |
| 9 | A site-wide menu removal contradicts our stated policy | `woocommerce-scanpay.php` |
| 10 | Two registrations in the router that state nothing | `woocommerce-scanpay.php` |
| 11 | The cURL extension is required and declared nowhere | `woocommerce-scanpay.php`, `gateways/abstract-wc-gateway-scanpay-base.php` |
| 12 | A disabled card gateway still loads its checkout stylesheet | `gateways/class-wc-gateway-scanpay-card.php` |
| 13 | Two settings fields with an inert tooltip and no label | `admin/settings/fields/scanpay.php` |
| 14 | The Plugins-screen link escapes nothing | `admin/settings.php` |
| 15 | `wc_scanpay_money_equals()` has no callers | `library/math.php` + 3 call sites |
| 16 | `wc_scanpay_subref()` takes `object` | `public/generate-payment-link.php` |
| 17 | Four local inconsistencies | `admin/orders.php`, `admin/ajax/wp-scanpay-fetch-{meta,sub}.php` |
| 18 | Comment audit against the documented standard | all 34 PHP files |
| 19 | i18n audit, English source and Danish catalog | `src/languages/`, every `__()` site |
| 20 | Fresh full review → `RESULTS.md` | all 34 PHP files |

Files opened by more than one task, in order: `class-wc-scanpay-capture.php`
(1, 2, 3), `class-wc-scanpay-sync.php` (6, 7), `woocommerce-scanpay.php`
(9, 10, 11), `class-wcs-scanpay-charge.php` (4, then 15's call site),
`generate-payment-link.php` (16, and 15's call site). Tasks 18–20 read everything
and must run after all of the above.

---

## Task 1 — A completed capture can be recorded as a failure

**File:** `src/library/class-wc-scanpay-capture.php`

`capture()` ends by writing the "Scanpay capture of %s completed." note
(`:106-114`). Its only caller invokes it inside a `try` whose `catch` means *the
capture failed*:

```php
try {
    self::capture( $wco );
    self::$processed[ $oid ] = true;
    return true;
} catch ( \Throwable $e ) {
    self::$processed[ $oid ] = false;
    // … log + update_status( 'on-hold', "Scanpay capture failed: …" )
    return false;
}
```

`add_order_note()` runs `woocommerce_new_order_note_data`, `wp_insert_comment()`
and `woocommerce_order_note_added`. A `Throwable` from any of them arrives after
`WC_Scanpay_Client::capture()` has returned — **after the money moved** — and
produces: a memo of `false`, a note reading "Scanpay capture failed: <the note's
own error>", the order demoted from `completed` to `on-hold`, and `false` returned
to `wp-ajax-wc-mark-order-status.php:116` and `wp-bulk-actions.php:47`, which
therefore do not complete the order. The customer has been charged; nothing
retries, because from Scanpay's side nothing went wrong.

**Fix.** Contain the note where it is written, in the tree's existing idiom:

```php
try {
    $wco->add_order_note( sprintf( /* … */ ), 0, true );
} catch ( \Throwable $note_error ) {
    scanpay_log( 'error', "Could not add the capture note to order #$oid: " . $note_error->getMessage() );
}
```

Keep the `__()` string, its `sprintf()` arguments, its translators comment and
both positional arguments (`0, true`) byte-identical — no msgid changes here.
Comment the new `try` with what it buys: the money has already moved by this line,
so a throw must not read as a capture failure. Cite the three sibling sites by
symbol, not line number.

**Do not** hoist the note into `capture_or_hold()`. `capture()` returns `void` and
its early return at `:94-97` ("nothing left to capture") must stay noteless;
hoisting would either note that case too or need a second signal, and `capture()`
is documented as having none.

**Verify**

- Re-read the three sibling `try`/`catch` sites; confirm shape and comment style
  match.
- Trace `capture_or_hold()` and state that every remaining throw source inside its
  `try` occurs *before* `WC_Scanpay_Client::capture()` returns.
- `grep -n 'add_order_note' src/` and list each site with whether it is contained.
  Two are legitimately not — `wcs_scanpay_fail_renewal()` writes through
  `update_status()`, `wp-bulk-actions.php` through `set_status()`; neither calls
  `add_order_note()`. Report, do not "fix".

**Handoff**

- A successful capture whose note write throws (mu-plugin on
  `woocommerce_order_note_added`) leaves the order `completed`, returns true, and
  logs one error line.
- The ordinary path still adds exactly one capture note.

---

## Task 2 — The last money read in the view context sets a capture amount

**File:** `src/library/class-wc-scanpay-capture.php` (after task 1)

```php
$amount = (string) $wco->get_total( 'edit' );
foreach ( $wco->get_refunds() as $refund ) {
    $amount = wc_scanpay_submoney( $amount, (string) $refund->get_amount() );   // :82
}
```

`$refund->get_amount()` is the only money read in the tree still in the `view`
context, so a `woocommerce_order_refund_get_amount` callback decides how much this
capture subtracts — i.e. how much the customer is charged. Every other read here
is explicit, including the total on the line above.

**Fix.** `(string) $refund->get_amount( 'edit' )`, with a short trailing comment:
`'edit'` because this sets a capture amount and the view filter would let a third
party move it. Do not restate what `get_prop()` does.

**Verify**

- `grep -rn "get_amount()\|get_total()\|get_line_total(" src/`; judge each
  remaining view-context money read in one line. `get_line_total()` stays: it runs
  `woocommerce_order_amount_line_total` by design and both call sites already wrap
  it in a `try` that names it.
- Read `WC_Order_Refund::get_amount()`; confirm it is a plain `get_prop()` with no
  override, so the context argument is honoured.
- Confirm the sign is unchanged: `get_amount()` returns positive and
  `wc_scanpay_submoney()` removes it.

**Handoff**

- An order with one partial refund still captures `total − refund − net_captured`.

---

## Task 3 — A cleared API key reads as a broken one

**File:** `src/library/class-wc-scanpay-capture.php` (after tasks 1 and 2)

`init()` collapses two situations into one message:

```php
$apikey = (string) ( $settings['apikey'] ?? '' );
$shopid = (int) strstr( $apikey, ':', true );
if ( $shopid <= 0 ) {
    throw new \RuntimeException( 'Invalid Scanpay API key configured' );
}
```

The empty case is the common one and is not a fault. Reset clears `apikey` and
`secret` and drops the tables but leaves `wc_autocapture` — normally `'completed'`
— and `wc_scanpay_order_status_completed()` gates on that alone. So completing any
historical Scanpay order after a reset throws here and parks it `on-hold` with
"Scanpay capture failed: Invalid Scanpay API key configured", telling the merchant
their key is broken moments after they deliberately removed it. On a bulk
completion of forty orders, forty times.

**The on-hold status is correct and stays.** Settled with Scanpay: an order whose
payment cannot be captured must not read as completed, or the merchant ships
believing the money was taken. Only the message is wrong.

**Fix.** Split the guard, both branches still throwing:

```php
if ( '' === $apikey ) {
    throw new \RuntimeException( 'No Scanpay API key is configured; this order cannot be captured' );
}
if ( $shopid <= 0 ) {
    throw new \RuntimeException( 'Invalid Scanpay API key configured' );
}
```

Wording is yours within three constraints. It reaches the merchant untranslated
via `sprintf( __( 'Scanpay capture failed: %s' ), … )`, whose translators comment
already says the reason is not translated — so it must read as a sentence to a
human, in the clipped style of its siblings (`'Transaction has been voided'`,
`'No payment details found on order'`). It must fit **both** reasons the key is
absent: a merchant who moved to a new Scanpay account, and one midway through
rebuilding the tables with the same key. And it must not tell the merchant to fix
the key, because in neither case is the key broken.

Comment the new branch: an absent key is the expected state after a reset, a
malformed one is a misconfiguration, and conflating them tells a merchant to
repair something they removed on purpose.

**Two things this task must not do**, both settled:

- **Do not touch `wc_autocapture` in the reset endpoint.** The button also
  rebuilds the tables after a fault with the same key; clearing the setting there
  would silently disable auto-capture on a repair — the failure mode
  `install.php`'s `$fresh_install` comment exists to prevent.
- **Do not add a `_transaction_id` discriminator.** "Transaction id set but no
  `scanpay_meta` row" looks like "history, nothing to capture" and is not: during a
  rebuild the row is temporarily gone while the authorization is live and
  re-syncable, so completing without capturing would be exactly what the on-hold
  status prevents.

**Verify**

- Both branches reachable, both throw, `capture_or_hold()`'s catch unchanged — the
  status write must not move.
- No new msgid: these are exception messages. State it so it is not read as
  catalog work.
- `init()`'s only callers are `self::capture()` and via it `capture_or_hold()`;
  confirm, and confirm `self::$processed[ $oid ]` is set the same way on both
  branches.
- Re-read the reset endpoint's settings loop; record which keys survive a reset so
  the premise is evidenced, not taken from this section.

**Handoff**

- After a reset with no new key, completing an old Scanpay order still parks it
  `on-hold`, and the note names the missing account rather than an invalid key.
- With a genuinely malformed key stored, the note still reads "Invalid Scanpay API
  key configured".
- A shop with a working key is unaffected on every capture path.

---

## Task 4 — A collected renewal can be marked failed

**File:** `src/library/class-wcs-scanpay-charge.php`

`charge()` wraps its body in `try`/`catch ( \Throwable )` (`:210`, `:339`) whose
catch calls `wcs_scanpay_fail_renewal()`, writing the order's `failed` status. The
last two statements inside that `try`:

```php
$res = $this->client->charge( $subid, $data, $idem );
scanpay_log( 'info', "charged order #$oid: charge {$res['id']} (subid=$subid)" );
```

`scanpay_log()` reaches `WC_Logger::log()`, which dispatches to handlers a third
party can register via `woocommerce_register_log_handlers`. A throw there lands in
the catch **after the customer has been charged** and marks the renewal `failed`.
WCS calls `payment_failed()` only from a `failed` transition
(`class-wc-subscriptions-renewal-order.php:143-145`), so the subscription is
suspended and put into dunning over a renewal that was collected. The idempotency
key limits this to one real charge; it does not stop the status write, and the
retry it dedupes is the very thing that would otherwise reconcile the order.

**Fix.** Nothing after `client->charge()` returns may read as a charge failure.
Contain the log call where it is written, matching task 1 and
`wcs_scanpay_fail_renewal()`:

```php
$res = $this->client->charge( $subid, $data, $idem );
try {
    scanpay_log( 'info', "charged order #$oid: charge {$res['id']} (subid=$subid)" );
} catch ( \Throwable $log_error ) {
    // Nowhere left to report this: the money has moved, and treating it as a
    // charge failure would fail a renewal the customer paid.
}
```

Keep the log string byte-identical (run 2's task S settled its wording) and keep
the comment above `$res` explaining that this is the only store-side record of the
charge. A `$charged = true` flag checked by the catch also works; prefer the
containment — one exit, no new state.

**Verify**

- Read `WC_Logger::log()` and `wc_get_logger()`; state which hook lets a third
  party install a handler and that nothing between it and `scanpay_log()` catches.
- Trace `charge()`; confirm no statement between `client->charge()` returning and
  the end of the `try` can throw.
- Confirm `scheduled_charge()`'s early returns are untouched — they run before any
  request and must keep failing the renewal.

**Handoff**

- With a throwing log handler, a successful renewal charge leaves the order paid,
  the subscription active, and no `failed` transition.
- The ordinary path still writes exactly one "charged order #… " line.

---

## Task 5 — The drain's time limit is renewed by round count, not time

**File:** `src/callback/wc-scanpay-ping.php`

```php
$start = microtime( true );          // :242 — currently only feeds a debug log
$n = 0;                              // :244
// … in the drain loop:
if ( $target > $seq ) {
    if ( ++$n > 5 ) {                // :310
        set_time_limit( 60 );
        wc_scanpay_memory_usage_debug();
        wc_scanpay_flush_order_runtime_cache();
        $n = 0;
    }
}
$elapsed = microtime( true ) - $start;                                  // :324
```

Round count is not a proxy for elapsed time. One round is a `/v1/seq` call — whose
client-side budget is `WC_Scanpay_Client::request()`'s default `$timeout = 40` —
plus a full page of changes, each able to build a `WC_Order` and write to the
database. Six rounds can exceed the 60 s last granted, and PHP kills the worker
mid-drain.

Where it dies matters: `sync()` upserts `scanpay_meta` and calls
`payment_complete()` per change, but the cursor `UPDATE` runs only after the whole
page (`:296-306`). A kill between them leaves orders paid and the cursor unmoved,
so the next ping replays the page — orders already carrying a `transaction_id` are
skipped at `:261`, so the replay is wasted work ending the same way. The
five-minute keepalive then retries indefinitely; the shop cannot make progress.

**Fix.** Renew on elapsed time. Track the moment of the last renewal (reuse
`$start` or add `$limit_renewed` seeded from it — whichever keeps the existing
debug line measuring from the top of the drain), and renew when ~30 s has passed,
resetting it.

Keep `wc_scanpay_memory_usage_debug()` and `wc_scanpay_flush_order_runtime_cache()`
on their present cadence or move them to the same trigger — but if the round
counter stays for the cache flush, comment why the cadences differ: the flush is a
memory bound and rounds are a fair proxy for allocation; the time limit is a
wall-clock bound and rounds are not. That asymmetry is the task and belongs in the
file.

**Do not** raise the constant instead. A larger grant moves the cliff without
removing it.

**Verify**

- State the arithmetic: five rounds at the client's 40 s ceiling against a 60 s
  grant.
- Confirm `set_time_limit()` resets the counter rather than adding, so calling it
  more often is free.
- Confirm the drain still calls it at least once per iteration's worst case, and
  that the heartbeat and busy paths above the loop are untouched.

**Handoff**

- A backfill of several thousand changes completes without a worker being killed,
  and the log shows elapsed time advancing to "Sync completed to seq …".

---

## Task 6 — Three status guards read through a third-party filter

**Files:** `src/admin/hooks/wp-bulk-actions.php`,
`src/admin/hooks/wp-ajax-wc-mark-order-status.php`,
`src/library/class-wc-scanpay-sync.php`

Three decisions read `get_status()` in the `view` context, where
`woocommerce_order_get_status` is a third party's answer:

| Site | Code | Its own comment |
| --- | --- | --- |
| `wp-bulk-actions.php:44` | `in_array( $wco->get_status(), [ 'completed', 'trash' ], true )` | "the last line of defense … capturing a trashed order would charge the customer and untrash it" |
| `wp-ajax-wc-mark-order-status.php:79` | same guard | the row-action nonce is per *action*, so one valid link is reusable for any id, "a trashed one included" |
| `class-wc-scanpay-sync.php:490` | `'pending' !== $parent->get_status()` | gates writing `completed` onto a subscription's parent order |

A guard whose comment calls it a last line of defence should not read through a
filter. Sites 1 and 2 are the security-shaped ones.

**Fix.** `get_status( 'edit' )` at all three.

Site 3 needs one extra sentence and a comment saying so: in `view`,
`WC_Abstract_Order::get_status()` substitutes
`apply_filters( 'woocommerce_default_order_status', 'pending' )` for an empty
status, so an order with no status currently reads as `'pending'` and takes the
branch; under `'edit'` it reads `''` and does not. That is correct — an order with
no status is not a pending one, and the branch writes `completed` — but it is a
behaviour change and must be recorded as one. Sites 1 and 2 have no such nuance.

**Do not** touch `generate-payment-link.php:60`
(`$wco->get_status() === 'pending'`). It picks a query filter for
`wc_get_orders()`, not a guard, and the substitution there is harmless. Say so in
`HANDOFF.md` so it is not reopened.

**Verify**

- Quote the `'view' === $context` branch performing the substitution.
- `grep -rn "get_status()" src/` after the change: only
  `generate-payment-link.php:60` remains.
- Confirm neither context adds or strips a `wc-` prefix on `WC_Order` — that
  prefix is in the database column, not the prop — so the compared strings are
  unchanged.

**Handoff**

- "Capture and complete" over a selection including a trashed order still skips
  it; the row action on a trashed order still declines.
- A subscription parent with a zero total is still completed by `subscriber()`.

---

## Task 7 — `WC_Scanpay_Sync::$settings` is public for no reader

**File:** `src/library/class-wc-scanpay-sync.php` (after task 6)

`public array $settings;` (`:12`) is read once, in the constructor's
`wc_complete_virtual` test at `:53`, and written once beside it. Nothing outside
the class touches it — the only construction site (`wc-scanpay-ping.php:206`)
passes the array in and never reads it back. Its two neighbours, holding the same
kind of derived configuration, are private. The class is `final`.

**Fix.** `private array $settings;`

Then decide whether the property earns its place at all: if the constructor
remains its only reader, a local variable is smaller, and `AGENTS.md`'s "a class
only earns its place when it holds a resource or state across calls" applies to
fields as much as classes. Record which you took and why. If in doubt keep the
property — the visibility fix is the task.

**Verify**

- `grep -rn '\->settings' src/`; confirm no reader outside the class.
- `pnpm phpcs` still passes. PHPCS does not check visibility, so a clean run is
  the whole static signal.

**Handoff**

- None. A visibility change with no runtime surface; say so rather than inventing
  a shop check.

---

## Task 8 — The 2.1.3 migration cannot finish on a large shop

**File:** `src/upgrade.php`

```php
$args    = [ 'type' => 'shop_subscription', 'status' => 'all', 'return' => 'ids',
             'meta_key' => '_scanpay_subscriber_id', 'limit' => -1 ];
$wc_subs = wc_get_orders( $args );                                    // :100-137
foreach ( $wc_subs as $oid ) {
    $wc_sub = wcs_get_subscription( $oid );
    // … up to two scanpay_meta lookups per row …
}
```

Three properties compound: `'limit' => -1` loads every matching id then builds a
full `WC_Subscription` per row; the two in-loop lookups are
`SELECT id FROM …scanpay_meta WHERE subid = … ORDER BY id DESC LIMIT 1`, and
`scanpay_meta`'s only key is `PRIMARY KEY (orderid)` (`install.php:43`), so each is
a full scan; and the whole file runs under one `set_time_limit( 60 )` at `:9`.

A shop with enough 1.x-era subscriptions cannot complete this branch in its grant.
The loader makes that permanent rather than slow: the `wc_scanpay_updating`
transient is kept on failure and the version is stamped **last** (`:207-209`), so
the migration restarts from zero every five minutes forever and the plugin never
reaches its current version.

`docs/performance-review.md` queues a `wp_cache_flush()` inside this loop, noting
"it runs once per upgrade". That premise is what this task challenges.

**Fix.** Do items 1 and 2; do 3 only if it stays clearly readable.

1. **Batch by id.** Replace `'limit' => -1` with `'limit' => 500`, `'offset' => …`,
   `'orderby' => 'ID'`, `'order' => 'ASC'`, looping until a short page returns.
   `uninstall.php:74-99` already uses this shape for `get_sites()`; follow its
   comment ("a short page is the last one").
2. **Renew the grant inside the loop**, as task 5 does for the drain: every N rows
   or every ~30 s.
3. **Hoist the lookups**: one
   `SELECT subid, MAX( id ) FROM …scanpay_meta GROUP BY subid` before the loop
   answers every comparison from an array, turning 2N scans into one. It changes
   what the branch reads, and correctness outranks scan count — if you skip it,
   say why.

The branch must stay **idempotent and restartable**. It already is (the
`$black_subid > $subid` test re-derives its own precondition each run) and
batching must not break that. **Do not** add a progress marker or resume cursor —
an interrupted run re-reading fixed rows is correct, and a new option is a new
thing to migrate.

**Verify**

- Confirm the `wc_get_orders()` arguments you pass are honoured by both data
  stores — in particular `'offset'` and `'orderby' => 'ID'` alongside `'meta_key'`.
- State that the branch is still idempotent, quoting the `$black_subid > $subid`
  test.
- Confirm from `install.php`'s DDL that `scanpay_meta` has no index on `subid`, and
  record whether you did item 3.

**Handoff**

- On a shop with several thousand 1.x subscriptions the upgrade completes in one
  request, the version is stamped and the transient is gone.
- Subscriptions whose 1.x subid is newer still get it; those whose current subid
  already carries the newer transaction still do not.

---

## Task 9 — A site-wide menu removal contradicts our stated policy

**File:** `src/woocommerce-scanpay.php`

`admin/settings.php:81-92` states a policy and follows it:

> Hide WooCommerce's promotional footer text, but only on the plugin's own
> settings screens. Blanking `admin_footer_text` globally is a site-wide UI
> change and is flagged by the WordPress.org plugin review.

`woocommerce-scanpay.php:534-537` does the opposite, unconditionally, to a menu
WooCommerce owns:

```php
function scanpay_remove_wc_payments_menu() {
    remove_menu_page( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' );
}
add_action( 'admin_menu', 'scanpay_remove_wc_payments_menu', 999 );
```

Every admin loses WooCommerce's top-level Payments entry on every admin page load,
configured or not. Second problem: the slug is matched by exact string, and
`from=PAYMENTS_MENU_ITEM` is telemetry, not a route — if WooCommerce changes it
this silently becomes a no-op and nobody learns.

**This task does not decide the UI question — it makes code and policy agree.**
Check `HANDOFF.md` and `git log -p -- src/woocommerce-scanpay.php` first: this is
the one place in this plan where reading history is warranted, because the
question is "was this wanted" and the tree cannot answer it.

- **If history shows Scanpay asked for it:** keep the behaviour and rewrite the
  docblock to say plainly that this is a deliberate site-wide change to another
  plugin's menu, why it is worth it, that it is expected to trip the review the
  sibling comment cites, and that the slug is fragile.
- **Otherwise:** delete the function and the `add_action`, and record that the
  duplicate entry returns.

Either way the comment must stop being silent about the conflict.

**Verify**

- Report which option and what evidence decided it.
- If kept: confirm the slug against WooCommerce's own `add_menu_page()` call and
  record whether it still matches at 11.1.0-dev.
- If dropped: `grep -rn "remove_menu_page\|admin_menu" src/` and confirm nothing
  depends on the entry being gone (`admin-options.php:82`'s back-arrow links to
  `wc-settings&tab=checkout` directly and does not).

**Handoff**

- WooCommerce's Payments entry is present or absent as chosen, and the Scanpay
  settings screen is still reachable from WooCommerce → Settings → Payments.

---

## Task 10 — Two registrations in the router that state nothing

**File:** `src/woocommerce-scanpay.php` (after task 9)

Two hygiene defects in one file, one commit.

**1. `wp_scanpay_allowed_redirect_hosts()` uses WordPress's prefix** (`:124`,
registered `:457`). `AGENTS.md` names the namespace as `wc_scanpay_` /
`wcs_scanpay_`. This is the only `wp_scanpay_*` function in `src/`, and `wp_` is
core's — a future core function of that name is a fatal redeclare in a plugin with
no autoloader to arbitrate. Rename to `wc_scanpay_allowed_redirect_hosts` and
update the single `add_filter`.

`scanpay_log()` and `scanpay_remove_wc_payments_menu()` are outside both prefixes
too. Leave them: `scanpay_` collides with nothing upstream, `scanpay_log()` has 24
call sites, and the second may no longer exist after task 9. Record the decision.

**2. The scheduled-charge hook registers at priority 3** (`:464`). Every other
non-default priority in the tree carries a comment saying why —
`wc_scanpay_order_status_completed` at 5, `bulk_actions-edit-shop_order` at 20,
`wp_ajax_woocommerce_mark_order_status` at 0, `admin_footer_text` at 11, the meta
boxes at 9, `scanpay_remove_wc_payments_menu` at 999. This one carries none, and
the hook is gateway-suffixed (`…_scanpay`) so nothing else plausibly listens —
which argues for `10`, not against it.

Change to `10` unless `git log -p` shows the 3 was deliberate; if it was, keep it
and write the reason. `AGENTS.md` is explicit that a comment is what a change is
verified against, so an unexplained priority is an untested invariant either way.
Keep the `2` — the callback takes `$amount` and `$wco`.

**Verify**

- `grep -rn "wp_scanpay_" src/ build/` returns nothing after the rename (`build/`
  is generated; if stale, say so rather than editing it).
- `grep -rn "add_action\|add_filter" src/ | grep -v ", 10"` — every remaining
  non-default priority has a comment.
- Nothing outside `src/` (`README.txt`, `docs/`) names the old function.

**Handoff**

- The redirect to `betal.scanpay.dk` still succeeds from `process_payment()` (a
  broken filter shows as `wp_safe_redirect()` falling back to `wp-admin/`).
- A scheduled renewal still fires `wcs_scanpay_scheduled_charge()`.

---

## Task 11 — The cURL extension is required and declared nowhere

**Files:** `src/woocommerce-scanpay.php`,
`src/gateways/abstract-wc-gateway-scanpay-base.php` (after tasks 9 and 10)

`WC_Scanpay_Client` is built on ext-curl unconditionally (`private \CurlHandle $ch;`
and `curl_init()` in the constructor). Without the extension that is
`Error: Call to undefined function curl_init()`. Nothing declares the dependency:
the plugin header states `Requires PHP` and `Requires Plugins` but no extension,
and `composer.json` is `require-dev` only by design.

The failure is worse than needed in one place.
`WC_Gateway_Scanpay_Base::process_admin_options()` validates a new key inside
`try { … } catch ( Exception )` (`:167-198`) — `Error` is not an `Exception`, so on
a curl-less host saving the settings form is a white-screen fatal rather than the
"Invalid Scanpay API key" notice the code was written to show.

**Fix.** Two parts, one commit:

1. **Declare it** in the plugin header comment (WordPress has no header field for
   extensions, so a prose line is the honest place) and in `README.txt`'s
   requirements section if one exists — check before assuming.
2. **Fail legibly** in `process_admin_options()`: add an explicit
   `function_exists( 'curl_init' )` check before constructing the client, with a
   `WC_Admin_Settings::add_error()` naming the missing extension. Prefer this over
   widening the catch to `\Throwable`, which would also swallow programming errors
   the current catch deliberately lets through. Comment that the check exists
   because the client is unusable without the extension and `Error` is not an
   `Exception`.

**Do not** add a runtime guard to `wc_scanpay_plugins_loaded()` that disables the
plugin — a large behaviour change on a condition nobody has reported, and the
payment paths already surface transport failures to the customer.

**Verify**

- Confirm `curl_init()` is the only ext-curl entry point reached before a guard
  could run; list every `curl_*` call in `src/`.
- Confirm `Error` does not extend `Exception` (`php -r`), so the existing catch
  genuinely misses it.
- Confirm the header/README change does not disturb the `{{ VERSION }}`-style
  substitution `./build.sh` performs on that header.

**Handoff**

- Only a curl-less host can settle the fatal. State what changed and what a
  merchant on such a host now sees.

---

## Task 12 — A disabled card gateway still loads its checkout stylesheet

**File:** `src/gateways/class-wc-gateway-scanpay-card.php`

```php
if ( 'yes' === ( $this->settings['stylesheet'] ?? 'yes' ) ) {      // :34
    add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_styles' ] );
}
```

Nothing consults `$this->enabled`, and the setting defaults to `'yes'` — so a shop
with Scanpay switched off still ships `public/assets/css/checkout.css` to every
customer, styling payment methods that are not ours. Three files away
`WC_Gateway_Scanpay_ApplePay::__construct():17` does the opposite and says why:
"so this hooks nothing on a store that does not offer Apple Pay". That comment's
precondition holds here too — the base constructor calls `init_settings()` then
`init_gateway_props()` before the subclass body resumes, so `$this->enabled` is
already `'yes'`/`'no'` at `:34`.

**Fix.**

```php
if ( 'yes' === $this->enabled && 'yes' === ( $this->settings['stylesheet'] ?? 'yes' ) ) {
```

Keep the existing comment about reading `$this->settings` directly — it explains
the `??` and the avoided `get_option()`, and both survive. Add a clause pointing at
the Apple Pay gateway as the sibling this now matches.

**Leave the `wc_scanpay_item_needs_processing` filter on the next lines alone.** It
must stay unconditional: it is scoped to Scanpay orders by
`wc_scanpay_is_scanpay_order()`, and a shop that disables the gateway still has
historical Scanpay orders whose completion behaviour must not change. Say this in
`HANDOFF.md` or the next reader will "finish" the task wrongly.

**Verify**

- Confirm the ordering claim: `init_settings()` at base `:11`,
  `init_gateway_props()` at `:12`, both before the card constructor continues.
- `enqueue_checkout_styles()`'s own `is_checkout()` guard unchanged.
- `grep -rn "wp_enqueue_style\|wp_enqueue_script" src/` — state that no other asset
  is enqueued without an enabled or screen gate.

**Handoff**

- With Scanpay disabled, `checkout.css` is absent from the checkout page; with it
  enabled and `stylesheet` on, present.

---

## Task 13 — Two settings fields with an inert tooltip and no label

**File:** `src/admin/settings/fields/scanpay.php`

`wcs_complete_initial` (`:96`) and `wcs_complete_renewal` (`:103`) are the only
fields in the three field files with `desc_tip` and **no** `description`, and the
only two with no `title`. Both facts have a consequence: `get_tooltip_html()`
returns `''` for an empty description, so the flag renders nothing — dead
configuration that reads like a feature; and `generate_checkbox_html()` echoes
`$data['title']` into `<th scope="row">` unconditionally, so each renders an empty
header cell. Visually they look grouped under the preceding "Auto-complete" row;
semantically they are two unlabelled rows, which is what a screen reader gets.
`WC_Settings_API` has no `checkboxgroup` support (that is
`woocommerce_admin_fields()`), so grouping cannot fix it.

**Fix.** Give both a `title`, and either a real `description` or no `desc_tip`.

Two new msgids — field labels, not stored values, so `__()` is correct here
(`AGENTS.md` bars it on *defaults*, which these are not). Pick wording that
distinguishes the two rows from the `wc_complete_virtual` row above; reuse its
exact string only if you also state why three rows sharing a header is right.
Prefer writing the description over dropping `desc_tip`: these two settings decide
whether an order is force-completed on sync, the least obvious behaviour on the
screen.

Do not touch `'default' => 'no'` on either, or the four fields whose `desc_tip`
pairs with a real `description`.

**Verify**

- Quote `get_tooltip_html()` and `generate_checkbox_html()` showing the empty-`$tip`
  return and the unconditional `<th>` echo.
- `grep -n "desc_tip" src/admin/settings/fields/*.php` — every occurrence now has a
  sibling `description`.
- List the msgids added, verbatim, for task 19.

**Handoff**

- Both rows render a label and a working help tip, and toggling either still
  stores `yes`/`no`.

---

## Task 14 — The Plugins-screen link escapes nothing

**File:** `src/admin/settings.php`

```php
$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' );
array_unshift( $links, '<a href="' . $url . '">' . __( 'Settings', … ) . '</a>' );   // :75
```

Neither value is escaped. `admin_url()` runs the `admin_url` filter and `__()` runs
`gettext` — both third-party surface, and both the reason the MobilePay and Apple
Pay gateways escape their own concatenations, with the comment "PHPCS misses it
because the value is returned". Same shape, same blind spot. The value is returned
into `plugin_action_links_*`, which WordPress echoes raw.

**Fix.** `esc_url( $url )` and `esc_html__( 'Settings', 'scanpay-for-woocommerce' )`.

`esc_html__()` rather than wrapping `__()` keeps the msgid extractable and
identical, so this adds no catalog work. Add a short trailing comment naming the
reason (a returned value, so the escape sniff cannot see it).

**Verify**

- Confirm `plugin_action_links_{$plugin_file}`'s result is echoed unescaped by
  `WP_Plugins_List_Table`.
- Confirm the msgid is unchanged, so `src/languages/*.pot` needs no regeneration.
- `grep -rn "'<a href=\|\"<a href=" src/` — report any other unescaped link
  construction. `admin-options.php` builds several and escapes all; if that holds,
  say so.

**Handoff**

- The "Settings" link still points at the Scanpay section and still renders.

---

## Task 15 — `wc_scanpay_money_equals()` has no callers

**File:** `src/library/math.php` and three call sites

`wc_scanpay_money_equals( string $a, string $b ): bool` (`:187-190`) is called from
nowhere. The three places asking whether two money strings are equal spell it as a
comparison: `class-wcs-scanpay-charge.php:162` and `:270`, and
`generate-payment-link.php:264`, all `wc_scanpay_cmpmoney( … ) !== 0`.

So either the function is the better spelling those three should use, or it is
dead. It cannot be neither: `AGENTS.md` lists it as part of the money API, which is
why it must be decided rather than quietly deleted.

**Fix.** Adopt it — replace the three tests with `! wc_scanpay_money_equals( … )`.
It states the intent and is cheaper (no `strcmp`, no sign branches). Two of the
sites guard with `$sum !== $wc_total &&` as a fast path on identical strings; keep
that, or drop it and say why.

Removing it instead is acceptable only if adoption changes behaviour at any of the
three sites — and then `AGENTS.md`'s money-API list must be edited in the same
commit, or the guide becomes wrong.

**Do not** change `math.php`'s implementation either way. The file is settled and
`docs/performance-review.md` §6.2 says so.

**Verify**

- `grep -rn "money_equals\|cmpmoney" src/` before and after.
- Confirm `wc_scanpay_money_equals()` and `wc_scanpay_cmpmoney( … ) === 0` agree on
  what the three sites see — including `'0'` vs `'0.00'` and a negative zero.
  `php -r`, loading `math.php` after `define( 'ABSPATH', … )`.

**Handoff**

- A renewal whose WCS amount matches the order total still charges; one that
  disagrees still fails with the mismatch note.

---

## Task 16 — `wc_scanpay_subref()` takes `object`

**File:** `src/public/generate-payment-link.php`

`function wc_scanpay_subref( int $oid, object $wco ): ?string` (`:34`) uses the
loosest hint PHP has on a parameter that is always a `WC_Abstract_Order`: either
the `WC_Order` from `wc_get_order()` or, on the payment-method-change path, the
`WC_Subscription` WCS hands the gateway. The body calls `$wco->get_status()`, which
`object` does not promise. `AGENTS.md`: "A typed parameter is the preferred guard —
a `TypeError` beats a defensive `if`."

**Fix.** `WC_Abstract_Order $wco`. `WC_Order` is too narrow — it would `TypeError`
on the method-change path. Confirm the chain in `.stubs/` rather than trusting this
paragraph. Add no comment: the type is the statement.

**Verify**

- Quote the declarations proving `WC_Subscription extends WC_Order extends
  WC_Abstract_Order`.
- Confirm both call sites (`:215`, `:287`) still type-check by naming what each
  passes.
- Confirm `wc_scanpay_process_payment()`'s own `$wco` is not re-typed — it comes
  from `wc_get_order()`, whose return is checked at `:77-81`, and that guard stays.

**Handoff**

- Both a normal subscription checkout and a payment-method change still produce a
  `wcs[]…` subscriber ref.

---

## Task 17 — Four local inconsistencies

**Files:** `src/admin/orders.php`, `src/admin/ajax/wp-scanpay-fetch-meta.php`,
`src/admin/ajax/wp-scanpay-fetch-sub.php`

Four small changes, one commit, no behaviour change. Do all four or none.

1. **`admin/orders.php:119` — `'meta' => $meta ?? null`.** `$wpdb->get_row()`
   already returns `null` when there is no row, so the coalesce cannot fire. Drop
   to `'meta' => $meta`.
2. **The two long-poll endpoints spell termination differently.** `-sub.php` writes
   `wp_send_json( … ); die();` at `:19-20` and `die;` at `:29`, `:34`; `-meta.php`
   writes bare `wp_send_json( … )` at `:28`, `:32`. `wp_send_json()` terminates
   either way, so both are correct and one is redundant — but a reader cannot tell
   which without checking core. Take the bare form, delete the redundant `die`s,
   and comment at the first site that `wp_send_json()` terminates. **Do not
   pre-empt `docs/performance-review.md` §5.2**, which folds these files' shared
   auth preamble into one `admin/ajax/auth.php`; only make the two agree in place.
3. **`-sub.php:61` — `$sec = $sec + $sec;`.** Write `$sec *= 2;`. The comment above
   already says "0.5s, 1s, 2s, 4s, 8s", so the doubling should read as doubling.
4. **`-sub.php:57` — a null `rev` never breaks the poll.** `scanpay_subs.rev` is
   nullable (`install.php:57`) and the exit test is `$sub['rev'] > $rev`;
   `null > 0` is false, so such a row polls the full 15.5 s. Either add it to the
   break condition, or comment why a null rev is unreachable — as
   `wc_scanpay_read_cursor()` does for `scanpay_seq.ping`.
   `WC_Scanpay_Sync::subscriber()` is the only writer and always supplies a rev, so
   the comment is probably the truthful fix. Check, then choose.

**Verify**

- Quote `wp_send_json()` showing both termination branches.
- Confirm `subscriber()` is the only `INSERT` into `scanpay_subs`
  (`grep -rn "scanpay_subs" src/`) and that its `rev` is validated as a positive
  int before the statement is built.
- Confirm none of the four alters a response body or status code.

**Handoff**

- Both meta boxes still poll and render; `?x=meta` and `?x=sub` still answer JSON
  with the same shape.

---

## Task 18 — Comment audit against the documented standard

**Files:** all 34 PHP files in `src/`

~2 059 of 5 599 lines are comments. `AGENTS.md` makes them load-bearing: "Comments
are the compensation, and what verification runs on: a change is checked against
the invariants they state, so a comment that has drifted is a broken test." This
task audits them against the **Comments** section of `AGENTS.md`, which is the
specification — read it first and apply it, not this summary.

Go file by file in `find src -name '*.php' | sort` order. One commit.

**What to change**

- **Drift.** A comment that no longer describes the code, cites a symbol that
  moved or was renamed, or states an invariant the code no longer holds. Fix the
  comment to match the code; if the *code* looks wrong, that is a finding for task
  20, not a fix here.
- **Restatement.** A comment that says what the next line says. Delete.
- **Change log.** "Changed in 2.5", "was previously…". Delete — `git log` owns it.
- **Ceremony.** `@param`/`@return` blocks that only repeat types the signature
  states. Delete. Keep `@return array{…}` where the shape is not obvious, `@throws`
  for what a caller must catch, `@internal` outside a module's surface.
- **Length.** "Dense means precise, not long: two exact sentences beat a
  paragraph." Where a comment argues the same point twice, or narrates a
  derivation, compress to the conclusion and the reason.
- **Form.** File headers `/** … */` between `<?php` and `declare`, one paragraph,
  plus a `Contract:` list where the file speaks a wire protocol. Inline `//` on its
  own line above the code, or trailing for a short aside; full sentences, English,
  ending in a period.
- **`phpcs:ignore` without `-- <reason>`.** 32 of the 44 in the tree lack one; the
  guide requires it. Add the reason — the real one, derived from the code, not
  "suppress sniff". Where you cannot state a reason, the suppression is the
  finding: record it for task 20 rather than inventing a justification.

**What must survive verbatim in substance**

The long comments in this tree are not padding — most encode a decision that cost
someone a debugging session, and several were written by earlier tasks in this very
plan. **Never delete a comment that states why something is deliberate, what
failure a line prevents, what a third party may do to a value, or why an obvious
simplification is wrong.** Shorten the prose, keep the fact and the reason. If you
cannot shorten it without losing either, leave it.

Specifically protected, non-exhaustive: the `Settled` reasoning at the router's
dispatch gates; every "do not simplify this away" note; the containment comments in
`class-wc-scanpay-capture.php`, `class-wcs-scanpay-charge.php` and
`class-wc-scanpay-sync.php`; the upstream citations by file and line; and the
`'edit'`-context rationales.

**Do not** rewrite comments into a house voice of your own, translate any of them,
or touch a single line of executable code. If a comment cannot be made true without
a code change, that is task 20's finding.

**Verify**

- Report the count of comments changed and deleted, per file.
- `grep -rn "phpcs:ignore" src/ | grep -v -- "--"` returns nothing, or the
  remainder is listed with why it could not be justified.
- `pnpm phpcs` clean — it enforces docblock shape and commented-out code, so a
  clean run is the structural half of this task.
- `git diff --stat` shows changes only to comment lines. State this explicitly:
  the diff must contain no executable change.

**Handoff**

- None: comments have no runtime surface. Instead, list every comment whose
  underlying claim you could not verify from the tree or `.stubs/`, so task 20
  starts from them.

---

## Task 19 — i18n audit, English source and Danish catalog

**Files:** `src/languages/`, and every `__()` / `_e()` / `esc_html__()` site

The catalog holds 115 msgids; `da_DK` is the only translation and carries no fuzzy
entries. `./build.sh` owns extraction — it re-derives `.pot` and updates `.po` from
the built tree on every run, deterministically. **Never hand-edit
`scanpay-for-woocommerce.pot`**: it is generated, and an edit is lost on the next
build. `.po` msgstrs are hand-written and are what this task changes.

Audit three things, in this order.

**1. English source strings.** They are the msgids, so changing one orphans its
translation — do it only where the string is wrong, not merely improvable. Check:
they read as a sentence to a merchant or customer; placeholders are positional
(`%1$s`) wherever there is more than one; every placeholder has a
`/* translators: … */` comment above the call naming each one; and no string
concatenates a translated fragment with another, which cannot be translated
correctly.

**2. Danish translations.** Full orthography — æ, ø, å, and correct use of them;
never an ASCII substitution. Consistent WooCommerce terminology across the
catalog (settle on one word each for order, subscription, payment, capture,
refund and use it throughout). Placeholders preserved exactly, including their
positional numbers, which may legitimately reorder in Danish. Register consistent
with the audience: merchant-facing admin strings and customer-facing order notes
are not the same voice.

**3. Coverage.** Every user-visible string reaches a translation function, and
nothing that must not be translated does. Two rules bind here, both from
`AGENTS.md`: **settings-field defaults stay plain strings**, because `__()` cannot
localize a stored value; and exception messages are deliberately untranslated —
they reach the merchant raw through `sprintf( __( 'Scanpay capture failed: %s' ),
$e->getMessage() )`, whose translators comment says so. Do not "fix" either.

Task 13 adds two msgids; they are in scope here.

**Fix.** Update `src/languages/*.po` only, plus source strings in `src/` where item
1 found a real fault. Leave `.pot` alone; leave `build/` alone.

**Verify**

- Run `printf 'n\n' | ./build.sh` and confirm the regenerated `.pot` matches the
  committed one except for strings you deliberately changed. A diff anywhere else
  means a source string moved when it should not have.
- Report every msgid added, changed or removed, and every msgstr rewritten, with
  the reason in one line each.
- Confirm no msgid is a concatenation and every multi-placeholder string is
  positional with a translators comment.
- Confirm no settings-field `'default'` and no exception message became
  translated.

**Handoff**

- On a Danish shop: checkout, the settings screens, the order and subscription
  meta boxes, and the order notes a payment writes all render in Danish with no
  raw msgid and no broken placeholder.

---

## Task 20 — Fresh full review → `RESULTS.md`

**Files:** all 34 PHP files in `src/`; output to `RESULTS.md` at the repo root

A new, thorough review of the tree as tasks 1–19 leave it. This is a *review*, not
a fix: change no code. The deliverable is `RESULTS.md`.

**Before reading any source**, read, in order: `AGENTS.md` (all of it, especially
**Settled — do not "fix" these** and **Verified sound, do not re-audit**), this
file's **Do not weaken** section, `docs/performance-review.md` (its §5 and §6
record what was measured and deliberately left alone), `docs/requirements.md`, and
`HANDOFF.md`. Everything those establish is out of scope. A review that
re-discovers a settled decision has produced noise, and this is the third run to
face that risk — three of the previous review's candidate findings died on those
documents and one died on a `php -r` check.

**Method.** Read every file in full — not greps against a hypothesis. For each
finding, before writing it down:

- Locate the exact file, symbol and line, and quote the code.
- State the concrete failure: inputs or state → wrong output, wrong money, wrong
  status, fatal, or data loss. A finding with no reachable failure is not one.
- Verify the upstream half against `.stubs/`, and PHP semantics with `php -r`.
  Never assert what WordPress, WooCommerce or WCS does from memory.
- Try to refute it. Note what would make it false, and check that too.

Cover at least: money arithmetic and every path that moves money; the ping and
sync loop, including failure and replay behaviour; capture, charge and refund
reconciliation; the admin AJAX endpoints and their authentication; install,
upgrade, reset and uninstall, including multisite; the gateway lifecycle and
settings persistence; the Blocks and classic checkout paths; the subscription
terms consent; and error handling and containment boundaries everywhere.

**`RESULTS.md` format.** Findings first, ordered most severe first. Per finding:
a heading naming the defect, the file and symbol, the quoted code, the failure
scenario, what you did to verify it, and a proposed fix in one paragraph — no
patch. Mark each **Confirmed** (traced to a reachable failure) or **Plausible**
(the mechanism is real, reachability unproven), and say which. Close with two
lists: what you examined and found sound, and what you could not settle statically
and why.

**Do not** open a pull request, do not fix anything, and do not add findings to
`PLAN.md` — `RESULTS.md` is the whole output. If a finding is severe enough to act
on immediately, say so in its entry and stop there.

**Verify**

- Confirm every file in `find src -name '*.php'` was read in full; list them.
- Confirm every finding cites a `.stubs/` path or a `php -r` result where it
  depends on upstream or language behaviour.
- Confirm no finding restates a settled decision; state which documents you
  checked each against.
- `git status` shows `RESULTS.md` added and no file under `src/` modified.

**Handoff**

- The list of findings that need a running shop to confirm, so they are not lost
  when this plan ends.
