# PHP review follow-up plan

<!-- markdownlint-disable MD024 MD036 -->
<!-- Repeated task templates and compact verification labels are intentional. -->

13 task IDs remain (1–20; **13 was withdrawn**, see Settled decisions). Task 6
is split into 6b–6d, so there are **15 commits total**, each titled
`<summary> (task N)`. No `scanpay:` prefix — see Conventions in `CLAUDE.md`.
Task numbers are stable identifiers; never renumber, and leave the gaps. They are
unique *within this plan only* — the closed 35-task backlog already used 1–34, so
`git log --grep 'task N'` is ambiguous. Disambiguate by date, not by grep.

**Landed** — sections removed, numbers retired, do not reuse:

| Task | Commit | Note |
| --- | --- | --- |
| 5 | `bc55253` | Fully verified; its runtime list was empty by design. |
| 6a | `0344234` | Handles are now `wc-scanpay-order` / `wc-scanpay-subs`. |
| 16 | `aef5527` | Its Task 15 constraint moved into Task 15 — see there. |
| 3 | `62610f5` | Validation no longer gated on the enabled state. |
| 8 | `7dbc26b` | `wc_scanpay_item_needs_processing()` now takes `$order_id`. |
| 17 | `2049556` | `orders` added to the flush list. |
| 2 | `d274f3b` | **Landed with the opposite scope** — see below. |

Only 5 is *verified*. The rest are **implemented, not verified** — their runtime
lists were never exercised, and no WordPress install exists here to do it. Treat
them as unproven on a real site until someone runs those lists.

**Task 2 reversed its own item 4 on the way in, and later tasks must read the
result, not the plan.** The plan said to make classic enforcement exactly
card-only and not broaden subscription support to the wallets; the commit does
the opposite, on the ground that the checkbox is a *cart-level consent*: it now
covers whichever gateway the customer picks, third-party ones included, and stays
active while our own gateways are disabled. Three consequences:

- The shared predicate is `wcs_scanpay_terms_url()` in
  `woocommerce-scanpay.php:164` and returns `''` or a URL — not the page-ID-or-`0`
  helper in `src/library/functions.php` that the plan specified.
- The Blocks payload carries `$data['terms']` **outside** `$data['methods']` and
  outside the `enabled` gate (`class-wc-scanpay-blocks-support.php:48-70`).
- The fragmented Blocks terms sentence is already gone: both renderers now share
  one `I accept the %s.` msgid. Task 6c no longer owns that fix.

Premises below were verified against `src/` on 2026-07-20 and the anchors
re-checked on 2026-07-26. Where a task says "verified", it means the cited
file:line was read and confirmed, not inferred.

## Working rules

- Edit authored files under `src/` only. `build/` is a generated artifact.
- One task per commit. After committing, clear context; in the fresh context
  reread `AGENTS.md` and this plan, then confirm the working tree and the
  previous commit before starting.
- Line numbers below are verified anchors, not durable identifiers. Earlier tasks
  will move them. Locate the cited symbol or text again before editing; never
  patch a later task by stale line number alone. `d274f3b` (Task 2) already
  rewrote large parts of `woocommerce-scanpay.php` and
  `public/assets/js/checkout.ts`; anchors into those two files were re-checked on
  2026-07-26, anchors elsewhere date from 2026-07-20.
- Do not weaken: money-string arithmetic (`src/library/math.php`), the ping
  protocol, the sync flock, the API-key write-once policy, or the lock-free
  subscription charge design.

## What you can and cannot verify

**Stub paths differ per stub — check the symlink before concluding a file is
missing.** `.stubs/woocommerce` was re-pointed on 2026-07-26 to the plugin root
(`…/woocommerce/plugins/woocommerce`), so a WooCommerce citation like
`includes/class-wc-order.php` now resolves **directly** at
`.stubs/woocommerce/includes/class-wc-order.php` — the older `plugins/woocommerce/`
infix in earlier notes is wrong and returns "no such file". WCS is still a
monorepo symlink: those citations live under
`.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/`. The
WooCommerce stub currently reports version `11.1.0-dev`, so it settles "what does
current WooCommerce do", never "what does the 3.6 minimum do".

**There is no WordPress installation here.** No live site, no database, no
browser, no multisite network, no Redis. You cannot observe an order status
change, send a ping, render a checkout, or watch memory. Any claim that requires
those is not yours to make.

What you *do* have:

| Tool | Use |
| --- | --- |
| `.stubs/woocommerce`, `.stubs/wordpress`, `.stubs/woocommerce-subscriptions` | Real upstream source. Read it to settle any "what does WooCommerce do here" question instead of guessing. |
| `php -l` | Syntax only. |
| `pnpm phpcs` / `pnpm phpcbf` | WordPress + WooCommerce-Core + PHPCompatibilityWP. |
| `pnpm lint:js`, `node_modules/.bin/tsc` | TS lint (no type-check) and type-check (`noEmit`). |
| `php -r` | Settle PHP semantics questions (operator behavior, `isset` vs `array_key_exists`, string casts) empirically rather than from memory. To load `src/library/math.php` directly, `define( 'ABSPATH', … )` first — the file `exit()`s otherwise, and the naive one-liner silently produces no output. |
| `git log` / `git show` / tags | Released-version archaeology. Tags `v2.0.0`–`v2.9.1` are the real 2.x schema and behavior. |
| `rg` | Invariant assertions — call sites, filter registrations, handle collisions, absence of a pattern. |

So each task's verification is split:

- **Static** — what you must do and report on. Reading upstream source counts as
  verification. Tracing a code path and stating the reachable outcome counts.
- **Runtime** — what only a real install can settle. **Do not perform, do not
  simulate, do not claim.** Report it in your reply to the user, as the list of
  what still needs exercising on a real site. A task whose runtime list is
  untouched is *implemented*, not *verified* — say so plainly, to the user.

**Keep verification status out of the commit message.** Commit messages describe
the change and its rationale, not what the author did or did not get to test —
that caveat is stale the moment someone runs the tests, but it lives in the
history forever. The handoff belongs in the conversation.

Fabricating a runtime result, or reporting a task as verified because the static
half passed, is worse than leaving it open.

### After every task

```sh
pnpm phpcs
find src -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l
```

Plus `pnpm lint:js` and `node_modules/.bin/tsc` for any task touching `.ts`.

## Task dependencies

Most tasks are independent. These are not; implement in the order given. A
safe linearization of every remaining commit is:

`14 → 4 → 15 → 9 → 10 → 19 → 20 → 1 → 7 → 18 → 11 → 6b → 12 → 6c → 6d`

This is not a priority ranking. It is one conflict-minimizing order that
satisfies all dependencies and deliberately leaves the translation sweep and
catalog regeneration until no remaining task can add user-facing copy.

**It also groups by file, which the dependency edges alone do not force.** Tasks
10, 19 and 20 all rewrite `library/class-wcs-scanpay-charge.php`, and 1, 7 and 18
all rewrite the `payment_complete()` / payment-link region — so each trio lands
contiguously rather than interleaved. The concrete payoff: Task 20 item 5 has to
repair a pre-guard comment that Task 10 relocates, and with 20 immediately after
10 that repair is a one-liner instead of an archaeology exercise.

| Group | Order | Why |
| --- | --- | --- |
| i18n | 6b → tasks adding UI copy → 6c → 6d | 6c's **TypeScript** half needs 6b's pipeline (its PHP half does not). Run 6c after Tasks 7, 10, 12, 19, and 20 so its sweep includes their copy; 6d is the final remaining commit. (6a and 2 landed; the handle collision and the fragmented terms sentence are gone.) |
| Sync completion | 1 → 7 | Both rewrite the same `payment_complete()` call site in `WC_Scanpay_Sync::sync()`. **Task 1 lands the whole `try`/`finally` skeleton** (install the filter, remove it in `finally`); Task 7 fills in the `catch` and the reporting. Do not have 1 write a bare filter pair that 7 then restructures. |
| Renewal charge | {10, 19} → 20; 1 independent | Only two edges are load-bearing, and both point at 20: its reporter must absorb the free-payment failure Task 10 item 4 introduces and the ownership failure Task 19 introduces. 10, 19 and 1 may land in any order relative to each other — 1 touches the meta write around `charge()`, not the pre-guards — but land 20 straight after 10 and 19 anyway, per the file-grouping note above. |
| Customer-paid renewal | 1 → 18 | **Shared discriminator, not a true dependency.** Both need `$is_request_to_change_payment` in the same branch and their edits are otherwise disjoint. Task 1 introduces it; Task 18 reuses it rather than re-specifying it. Landing 1 first avoids introducing it twice. |
| Gateway metadata | 11 → 12 | 11 rewrites the gateway getters; 12 builds on that surface. Weak, but avoids a second rewrite. Note the visible consequence once 11 lands: disabling all three Scanpay gateways removes them from classic checkout while the subscription terms checkbox still renders, because Task 2 made that consent cart-level. Intended — do not file it as a regression. |
| Classic Apple Pay i18n | 6b → 12 | Task 12's unsupported-browser notice is authored in TypeScript and needs the JS translation pipeline. |
| 2.x schema | 14 | 14's migration is version-gated, so a site already stamped `3.0.0` never runs it. Uninstall is the only unconditional cleanup — that half landed as 16. |

## Settled decisions — do not re-flag

`CLAUDE.md` already closes most of them — its "Verified sound" list covers
ping/sync, `math.php`, the flock, client TLS, capture money math, the secret
auth, the admin `Scanpay` branding, the live `$this->icon` property, and the
Blocks compatibility declaration; its Architecture section covers the lock-file
location, the lock-free charge design and the idempotency key as a rate limiter,
and the five-minute keepalive. Two more are specific to this plan:

- **Empty `card_icons` is not a bug** (the withdrawn Task 13). A stored `''` is
  coerced to `[]` by `WC_Settings_API::get_option()`'s `$empty_value` argument
  (`abstract-wc-settings-api.php:310-312`) before the `(array)` cast, so the
  broken `images/.svg` element is unreachable. Task 11 item 4 adds an
  `array_filter` for consistency only — do not call it a fix.
- **Fail-loud governs malformed *Scanpay* payloads, not WooCommerce-side
  completion failures.** Task 7 deliberately catches and continues on the
  `payment_complete()` path; that does not contradict the fail-loud rule, which
  is about backend protocol violations. Do not file either as a violation of the
  other.

---

## Task 1: Make the subscription auto-complete settings control the WC order status

### Problem

`wcs_complete_initial` and `wcs_complete_renewal` (`admin/settings/fields/scanpay.php:99`,
`:106`) have labels promising WooCommerce order completion, but only set the
Scanpay `autocapture` payload flag — `public/generate-payment-link.php:165-167`
and `library/class-wcs-scanpay-charge.php:172-174`.

Sync then calls `payment_complete()` (`library/class-wc-scanpay-sync.php:335`)
with no completion override, so WooCommerce picks its normal status from
`needs_processing()`. Physical subscription orders land in `processing` with the
setting on. Virtual/downloadable orders complete anyway, which masks the defect.

There is a second omission in the early subscriber-renewal branch
(`generate-payment-link.php:108-119`): a customer paying a failed renewal through
`renew()` bypasses the `wcs_complete_initial` payload override, and nothing in
that branch applies `wcs_complete_renewal`. The label says renewal orders, not
only Action Scheduler charges, so both renewal entry points belong to this task.
Task 18 later adds thank-you routing to the same branch.

`wc_autocapture` values are `off` / `completed` / `on`, default `completed`
(`fields/scanpay.php:79-88`).

**Task 8 landed first, so this task inherits its result rather than the reverse.**
`wc_scanpay_item_needs_processing()` now takes `( bool, WC_Product, int )` and
returns `false` **only for Scanpay orders**, which is what
`$wco->needs_processing()` reads at `src/public/generate-payment-link.php:76`.
The filter is registered in **two** places — the card gateway constructor
(`class-wc-gateway-scanpay-card.php:32-33`) and the sync constructor
(`class-wc-scanpay-sync.php:62-63`). `WC_Scanpay_Sync` is not loaded on a
checkout request, so the gateway registration is what covers the checkout path;
do not conclude that path is unfiltered. The status matrix below still assumes a
physical order — that assumption is unchanged — but the masking described above
no longer extends to other gateways.

### Fix

1. Add one policy helper deciding whether an attempt requests a forced
   `completed` status. It is **request-time policy**, called from
   `public/generate-payment-link.php` and `library/class-wcs-scanpay-charge.php`.
   Sync never calls it and never reads these settings — it reads only the value
   item 2 persists.

   - A renewal charge honors `wcs_complete_renewal`.
   - A customer-paid renewal link also honors `wcs_complete_renewal`. Distinguish
     it from a pure payment-method change with the already-used guarded
     `WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment`
     flag; a method change is not a paid order attempt and gets no completion
     intent. **Task 18 reuses this same discriminator** — introduce it once,
     here, in a form 18 can consume.
   - **A resubscribe order is a third case in this branch, not a second.**
     `wcs_create_order_from_subscription()` copies subscription meta for
     `resubscribe_order` as well as `renewal_order`, so a resubscribe order also
     carries `_scanpay_subid` and reaches
     `generate-payment-link.php:108-119`. Decide and state explicitly whether it
     takes `wcs_complete_renewal` or is treated as an initial order; do not leave
     it to fall through whichever branch happens to catch it.
   - An initial payment link honors `wcs_complete_initial`, but only when WCS is
     active and the order is an initial parent subscription order.
   - Ordinary one-time orders: never.
   - Zero-total/free-trial parents keep completing via the subscriber path
     (`class-wc-scanpay-sync.php:419-425`), which this task does not touch.

   **Do not mirror the payload expression.** Renewal autocapture is
   `$is_virtual || 'yes' === wcs_complete_renewal`
   (`class-wcs-scanpay-charge.php:172`), where `$is_virtual` folds in
   `wc_complete_virtual`. Copying that disjunct would force-complete every
   virtual renewal with the setting off.

   The payload still must honor the setting independently of the completion
   decision. In the customer-paid `renew()` branch, raise the final
   `$data['autocapture']` to true when `wcs_complete_renewal` requests completion
   and `wc_autocapture` is `completed` (it is already true for `on`, and must
   remain false for `off`). **The raise applies only when the discriminator says
   paid renewal** — the same block is also the pure method-change path, which
   leaves `$data['autocapture']` at its `generate-payment-link.php:80` value.
   Do not confuse "request Scanpay capture" with "force WooCommerce completed";
   the helper owns only the latter policy — but the *decision* it returns is
   conditioned on the former, which is what item 2 collapses into a single stored
   flag.

2. Couple completion to the capture mode of the actual attempt:

   - `wc_autocapture = completed` or `on`: may force `completed`.
   - `wc_autocapture = off`: never — do not complete a deliberately uncaptured
     physical order.

   **Persist exactly one boolean, not two.** Write a single private prefixed meta
   key meaning "this attempt asked WooCommerce to force `completed`", and write it
   as true only when the completion intent from item 1 **and** the final payload
   `autocapture` boolean are both true at request time. Nothing downstream reads
   the two apart: the filter is installed only when both hold, so storing them
   separately buys no information and costs a consistency invariant plus a
   malformed-state surface (one field hand-edited, the other not) on the sync
   path. Any value other than an explicit true — absent, empty, `'0'`, a string,
   an array — is no force-complete request.

   Use the WC order API so it works under HPOS and legacy storage. Sync consumes
   the persisted flag, never live settings — otherwise a merchant toggling
   `wc_autocapture` mid-payment-window retroactively changes an accepted request.
   The flag belongs to the first successful/in-flight attempt; a retry must not
   overwrite it.

   **When to write differs by path:**

   - New payment link: write it *after* `$client->new_url()` returns, alongside
     the existing `PAYID`/`PTIME`/`SHOPID` writes at
     `generate-payment-link.php:183-186` and in that same `save_meta_data()`
     call — no extra order write. Not before: no payment can exist without a
     returned link, so a pre-write only strands the flag on a failed
     attempt, which the do-not-overwrite rule then pins to the order, locking a
     later retry to the dead attempt's settings.
   - Customer-paid renewal link: retain the returned value from
     `$client->renew()` instead of returning inline, then persist the flag
     *after* `renew()` succeeds and before returning the redirect. This branch
     has no existing metadata save, so one `save_meta_data()` is expected. A
     pure method change writes nothing.
   - Renewal charge: write it **between the authoritative already-paid guard
     (which `return`s at `class-wcs-scanpay-charge.php:201-204`) and the
     `$this->client->charge()` call (`:213`), inside the existing `try` opened
     at `:192`**. That call is a real charge, so the flag must already be
     durable if the process dies mid-request. Not before the `try`: that would
     persist it on an order the guard then declines to charge. Note a
     `Throwable` from the write is swallowed by the `catch` at `:214` and marks
     the renewal failed — that is the correct outcome, but be deliberate about it.

   **The flag must not propagate through the WCS data copier.**
   `WC_Subscriptions_Data_Copier` copies any non-excluded custom meta from a
   subscription onto every renewal order. Combined with the do-not-overwrite
   rule, a key that ever reaches the subscription would pin the first attempt's
   settings onto all future renewals. Today it happens not to — WCS creates the
   subscription on `woocommerce_checkout_order_processed`, before
   `process_payment()` runs, so the write at `generate-payment-link.php:183-186`
   lands after the copy — but that is timing, not design. Verify the write
   follows subscription creation, or exclude the key via
   `wc_subscriptions_object_data`.

   Scanpay autocapture is pseudo-atomic by API contract: when requested, capture
   completes before the payment operation returns, and the authorization and
   capture are exposed in one seq event. Sync therefore never observes an
   authorization-only state for an autocaptured payment. When forcing
   `completed` fires `wc_scanpay_order_status_completed()`, the already-synced
   captured total makes the existing remaining-amount guard in
   `class-wc-scanpay-capture.php:88-93` return without a second capture request.
   No capture skip or recovery machinery is needed.

   `wcs_complete_initial` never applies to the `renew()` branch:
   customer-paid renewals use `wcs_complete_renewal`, while pure method changes
   have no completion policy. Task 18 subsequently rewrites the same branch's
   success URL, which is why the dependency is explicit above.

3. Force the status via `woocommerce_payment_complete_order_status`, which
   WooCommerce applies with `( $status, $order_id, $order )`
   (`class-wc-order.php:174`).

   - Install it immediately before `payment_complete()` — which is **inside** the
     `if ( empty( $wco->get_transaction_id( 'edit' ) ) )` block opened at
     `class-wc-scanpay-sync.php:289`, not around `sync()`.

     Scoped, not registered standing, and the reason is not tidiness: this filter
     is not only consulted when *setting* a status. WooCommerce also applies it
     read-only to ask "is this order already in its payment-complete status",
     gating a `set_date_paid()` backfill. The broadest such site is
     `maybe_set_date_paid()` (`class-wc-order.php:355`, filter at `:366`), reached
     from `set_status()` on every save. It is **not** unconditional — the whole
     body sits behind `if ( ! $this->get_date_paid( 'edit' ) )` (`:357`) — but
     "every order saved before it has a paid date" is still a large set that has
     nothing to do with this task. Three further sites fire only for pre-WC-3.0
     orders, each gated on
     `version_compare( $this->get_version( 'edit' ), '3.0', '<' )`:
     `class-wc-order.php:970`, `class-wc-order-data-store-cpt.php:203`,
     `OrdersTableDataStore.php:3035-3036`. The `date_paid` one alone justifies the
     narrow window: a standing filter returning `completed` would flip that
     `has_status()` comparison for every unpaid Scanpay order being saved,
     including ones this task never intended to touch. Keep the window as narrow
     as the single `payment_complete()` call.
   - **The filter is legitimately re-consulted inside its own window.**
     `payment_complete()` → `set_status()` → `maybe_set_date_paid()` applies it
     again for the same order ID. This is benign — the status is already
     `completed` and sync has set `date_paid` — so do **not** add a fire-once
     guard to suppress it. Match on order ID only.
   - Match the order ID so a nested hook cannot complete a different order.
   - Remove it in a `finally`.
   - Do not call `payment_complete()` and then bump `processing` → `completed`:
     that emits both sets of hooks and emails.

4. Keep all existing validation (currency, shop ownership, authorized amount,
   transaction ownership, eligibility) ahead of the forced status.

5. Update the comments at both `autocapture` assignments to say Scanpay
   settlement and WooCommerce completion are related but separate.

### Verify

**Static**

- The filter is installed inside the `:289` block, matches on order ID, and is
  removed in a `finally` on every path including a `false` return or throw. The
  `try`/`finally` skeleton is written here in a shape Task 7 can add a `catch`
  to without restructuring it.
- Exactly **one** meta key is written, and sync's decision is a single explicit
  true check against it — grep confirms no second companion key.
- The persisted flag is read by sync, and written at the point item 2 specifies
  for each path (after `new_url()`, after a customer-paid `renew()`, before
  `charge()`, and never for a method change). Grep confirms the policy helper
  has no sync-side caller and no live read of `wcs_complete_initial`,
  `wcs_complete_renewal`, or `wc_autocapture` remains on the sync path.
- Confirm in `.stubs/woocommerce-subscriptions` that WCS's own
  `maybe_autocomplete_order`
  (`vendor/woocommerce/subscriptions-core/includes/class-wc-subscriptions-order.php:1175`,
  registered at `:73`) bails immediately unless the *incoming* status is
  `processing` (`:1176-1179`) — the zero-subtotal check at `:1189` is a second,
  later guard. Since this task forces `completed`, the `processing` check is what
  makes the two compatible at any hook priority.
- Confirm nothing writes to `scanpay_subs`.

**Runtime (hand off)**

The status matrix below, plus: a one-time physical order is unaffected;
virtual/downloadable behavior is unchanged; a free-trial parent still completes;
changing the live setting mid-flight does not reinterpret the attempt;
`woocommerce_payment_complete` fires once; processing and completed emails are
not both sent; the completed hook observes the already-captured total and sends
no second capture request.

**The matrix assumes a physical order** — one where `needs_processing()` is
true. For a virtual or downloadable order (or any order under
`wc_complete_virtual`), WooCommerce's own default is already `completed`
(`class-wc-order.php:174`), so every row reading `processing` reads `completed`
instead and this task changes nothing. That is the masking described in the
Problem; test the matrix with a physical subscription product or it will appear
to pass before the fix.

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

Also flag for the human: the forced status is set inside
`payment_complete()` → `set_status()` → `save()`, so
`woocommerce_order_status_completed` fires *during* that call, and a capture
failure there calls `update_status( 'on-hold' )` — a nested status change inside
an in-flight save. This path exists today only for virtual orders; the task
extends it to physical subscription orders.

### Done when

Both settings change the final status as their labels promise, scoped to the
matching initial/renewal flow (including scheduled and customer-paid renewals);
method changes remain non-payment operations; manual capture never completes an
uncaptured physical order; sync never reinterprets the *completion intent* from
later settings. (`wc_complete_virtual` remains a live read in
`WC_Scanpay_Sync::__construct()` at `class-wc-scanpay-sync.php:62-63`, by design
— this task does not change that.)

---

## Task 4: Make reset exclusive with sync, and check its destructive operations

### Problem

`admin/hooks/wp-ajax-wc-scanpay-reset.php` clears the key, drops three tables
(`:59-61`), recreates them (`:63-71`), and reports success (`:73-74`) — with no
`Scanpay_Flock` coordination and no check of any `DROP` result.

A running sync worker holds the old key and shop ID in memory and can insert
old-shop rows into the recreated tables. And because `install.php` gates creation
on `SHOW TABLES LIKE` (`:12`, `:30`, `:55`), a failed drop means the surviving
table is found, creation is skipped, and success is still returned.

### Fix

1. Read and retain the old shop ID **before** touching settings — after `:54`
   unsets `apikey` it is unrecoverable.

2. If it is valid, acquire that shop's `Scanpay_Flock` and hold it across the
   whole reset.

   Load `library/class-scanpay-flock.php` explicitly with `require_once` before
   constructing it. The normal admin bootstrap loads the gateway classes but
   does **not** load the flock; only the ping path currently does that.

   - Busy → retryable JSON error, no changes. Reset needs exclusive deletion;
     contention is not a handoff here.
   - Open failure → log the concrete error, return a server error.
   - Release explicitly on every path *before* `wp_send_json_*()`; do not rely on
     the destructor after `exit`.

   The class already supports this — nothing to add. `acquire()` is non-blocking
   (`LOCK_EX | LOCK_NB`, `class-scanpay-flock.php:46`), returns `false` on
   contention, and throws `RuntimeException` when the file cannot be opened
   (`:41-45`) — exactly the three-way distinction needed. `release()` is public
   and idempotent (`:59-65`). A second `acquire()` on a held lock returns `false`
   (the behavior follows from `:41-51`: the second `fopen()` creates a new open
   file description, which conflicts with the first even in-process; `:29-31` is
   the docblock asserting it, not the mechanism), so do not double-acquire.

   Scope, stated honestly: the ping worker re-reads the cursor under *every*
   acquire (`wc-scanpay-ping.php:281`, loop at `:261`/`:364`, handoff re-read at
   `:359`) and exits 500 "shop not configured" once the `scanpay_seq` row is gone
   (`:48-59`), so a worker re-acquiring after reset fails safe. But the heartbeat
   and busy-handoff writes at `:212` and `:243` happen **without** the lock;
   racing a reset they either hit a dropped table (500, self-heals via the
   keepalive) or the recreated empty table (0 rows, a 200 that acks a stranded
   ping). Both acceptable — the data was deliberately deleted — but do not claim
   the lock covers every writer.

   `Scanpay_Flock` only coordinates workers sharing a lock-file filesystem.
   Document that multi-node deployments need `WP_TEMP_DIR` on shared storage.
   Do not introduce a second distributed-lock design.

3. Check each drop's result and stop on `false`. They are **already three
   separate statements** (`:59-61`) — keep them that way. Do not consolidate them
   into a multi-table `DROP`: MySQL DDL atomicity is per table even in 8.0.

4. Do not return success merely because `install.php` did not throw. Verify: the
   key is absent, all three gateways disabled, all three tables exist **with the
   v3 column set** (`SHOW COLUMNS`, reusing Task 14's inspection helper), they
   hold no old rows, and no sequence row was seeded while the key is empty.

   **Existence alone is not enough, and Task 14 makes that concrete.** The
   failure model above is precisely that a failed `DROP` leaves a survivor which
   `SHOW TABLES LIKE` then finds, so creation is skipped. After Task 14 lands,
   that survivor can be a *2.x-shaped* table (`scanpay_meta.method NOT NULL`, no
   default) — so an existence-only postcondition lets a "successful" reset
   silently reintroduce the strict-mode insert failure Task 14 exists to remove,
   on a site that had already been migrated. Task 14 precedes Task 4 in the
   stated order, so this is live, not hypothetical.

   Two `$wpdb` contract points an implementer will otherwise get wrong:

   - For `create|alter|truncate|drop`, `$wpdb->query()` returns `$this->result`
     (effectively `true`), never a row count (`class-wpdb.php:2308-2309`). So
     `false ===` is the correct test here and in Task 14 item 4, matching
     `install.php`'s existing `true !== $res` idiom. `if ( ! $res )` would be
     wrong on an `insert|delete|update` query.
   - `DROP TABLE IF EXISTS` returns `true` for an **absent** table. A truthy drop
     result is therefore not evidence of deletion — only the postcondition above
     is. Item 3's "stop on `false`" is necessary but not sufficient.

   Do not treat a bare `false` from `update_option()` as proof of failure:
   WordPress also returns `false` when the requested value is already stored.
   Check the reread postcondition instead. Database `DROP`, `CREATE`/inspection,
   and postcondition failures remain real failures.

5. Distinct failures for lock contention, lock setup, settings persistence, drop
   failure, and recreation/postcondition failure. DDL is not transactionally
   recoverable across all hosts, so an error may still need merchant
   intervention — fail honestly and log.

6. Preserve the existing guard: `current_user_can( 'manage_woocommerce' )` plus
   `check_ajax_referer( 'wc-scanpay-reset', 'nonce', false )` (`:28-33`). There is
   **no shared-secret check on this endpoint** — the secret guards the
   `?x=meta|ping|sub` fetch endpoints. Do not add one while "preserving" it.
   Also preserve gateway disabling (`:43-57`) and `install.php` behavior — note
   reset does not re-stamp a version (`install.php:80` requires **both** an
   absent settings option *and* an absent `wc_scanpay_version`) and keeps the
   existing secret (`:93`).

### Verify

**Static**

- Trace every exit path and confirm each releases the lock before responding.
- Confirm no `wp_send_json_*()` precedes a release, and no path double-acquires.
- Confirm the old shop ID is read before `:54`.
- Confirm the flock class is loaded on this admin path before construction.
- Confirm each `DROP` result is captured and short-circuits.
- Confirm unchanged settings cannot be misreported as a persistence failure;
  the reread, not `update_option()`'s boolean alone, decides it.

**Runtime (hand off)**

Hold the flock from another process and reset; reset with no active sync;
simulate a failed `DROP`; run sync and reset concurrently; reconfigure a key
after reset and replay from seq 0 (existing paid orders must rebuild metadata
without re-firing `payment_complete()`); multi-node `WP_TEMP_DIR` exclusion, or
an explicit record that independent temp dirs do not provide it.

### Done when

A successful reset is exclusive with sync within the documented filesystem scope;
any failure to delete or recreate produces an error plus a diagnostic log; the
charge path stays lock-free.

---

## Task 6 (split): Internationalization

Three changes with different risk profiles, in order (6a landed 2026-07-20). Two
scope decisions apply throughout:

- Rewriting awkward English source copy is **out of scope** — it is not
  internationalization, and it invalidates existing translations.
- Operational diagnostics stay untranslated. See 6c for the rule.

## Task 6b: Add JavaScript translation plumbing to the build

### Problem

No admin script can be translated. All three enqueues pass `[]` deps
(`settings.php:53`, `orders.php:101`, `subscriptions.php:63`), none calls
`wp_set_script_translations()`, and `build.sh` runs only `i18n make-mo` (`:49`)
and `i18n make-php` (`:52`) — no `make-json`, so no Jed catalog exists.

**The blocking constraint is extraction.** `wp i18n make-pot` does not parse
TypeScript. Every `#:` reference in `src/languages/scanpay-for-woocommerce.pot`
points at a `.php` file, none at `.ts` or `.js` — though note that is
corroboration, not proof: `src/` contains no `.js` at all (only eight `.ts`), so
the extractor was never offered a JS file. The conclusion stands on make-pot
having no TypeScript parser. (Re-confirmed after `c56a45e` regenerated the POT on
2026-07-26; that regeneration changed only line references and fixed nothing this
task or 6d owns.)

`pnpm i18n:po` (`package.json:48`) runs `make-pot` and scans `src`; `pnpm
i18n:pot` (`:49`) runs `update-po`. **The two script names are inverted relative
to what they suggest** — do not wire step 3 below to `i18n:pot`. So every admin string
6c wants would extract to nothing. The catalog must come from the **built**
JavaScript.

### Fix

1. Extract from built, **pre-minify** JavaScript — minified output loses the line
   references that make a POT reviewable and can mangle the call shape make-pot
   matches. Make this concrete: compile unminified bundles to their final
   release-relative paths in `build/`, extract, then overwrite those same files
   with minified bundles. No extraction-only file or directory should survive
   the build.

   **Both esbuild loops need this.** `build.sh:37-40` builds the admin scripts
   (`--minify` at `:39`) — the very files 6c targets — and `:43-46` builds the
   public ones (`--minify` at `:45`). Both glob top-level `*.ts` only.

2. Make the ordering explicit. Today POT generation lives in `package.json`
   scanning `src`, while MO/PHP generation lives in `build.sh` against
   `$BUILD/languages` — two places, no enforced order. Use one build-root
   extraction so PHP and JavaScript references share the same release-relative
   namespace:

   1. rsync authored files to `build/`;
   2. emit unminified JS bundles at
      `build/{admin,public}/assets/js/<entry>.js`;
   3. run `make-pot` against the plugin-shaped `build/` root, writing the
      authoritative POT under `src/languages/`;
   4. update the authored PO files under `src/languages/`;
   5. resync the updated language sources into `build/languages/`;
   6. generate MO, PHP, and JSON catalogs in `build/languages/` (use
      `make-json --no-purge` so the shipped PO is not destructively stripped);
   7. overwrite the unminified bundles with the normal minified output.

   Two consequences to state rather than discover:

   - **A `{{ VERSION }}` token inside a translatable string silently defeats the
     catalog.** `build.sh:54` substitutes in `*.php`, `*.js`, `*.txt` under
     `$BUILD` — but **not** `*.json`. Task 6c will wrap the outdated-plugin banner
     at `settings.ts:152-154`, whose string *contains* `{{ VERSION }}`. The
     shipped `.js` therefore gets substituted while the Jed `.json` keeps the raw
     token, so at runtime `__()` is called with `…<i>3.0.0</i>…` while the catalog
     is keyed on `…<i>{{ VERSION }}</i>…`: the lookup misses and the string renders
     **untranslated**. Note the failure mode — it is not "Danish shows a literal
     `{{ VERSION }}`", it is "Danish silently stays English", which is far easier
     to ship without noticing.

     Adding `*.json` to that loop does fix the mismatch, but the better fix is to
     keep build tokens out of translatable strings entirely: make the msgid
     `…<i>%s</i>…` and fill it at runtime with `wp.i18n.sprintf`, taking the
     version from data the script already receives. That keeps the msgid stable
     across releases (a build-token msgid changes every version bump, orphaning
     the translation) and removes a token translators can silently corrupt.
   - **This makes `./build.sh` mutate tracked files.** Step 4 writes
     `src/languages/*.po`, which today's build never touches. A dirty tree after
     a build is expected from here on; say so, or the next person reverts it.

   Keep `pnpm i18n:po` / `pnpm i18n:pot` as thin entry points into the same
   extraction/update commands, or replace them with one canonical script. Do
   not leave a second source-only pipeline that can generate a different POT.
   Pass deterministic custom headers to `make-pot`: set
   `Report-Msgid-Bugs-To` to the real plugin support URL and
   `POT-Creation-Date` to an empty value. WP-CLI otherwise stamps the current
   second, so the "second extraction has no diff" check in 6d is impossible.

3. Add `wp i18n make-json` and confirm the emitted filenames match what WordPress
   computes: `load_script_textdomain()` derives the JSON name from an md5 of the
   plugin-relative script path (for example
   `admin/assets/js/settings.js`), so the path recorded at extraction must be
   the path the enqueued `src` resolves to in the release tree. This is why item
   2 extracts from the plugin-shaped build root rather than an arbitrarily named
   temp directory.

4. Declare `wp-i18n` as a dependency at all three enqueue sites and call
   `wp_set_script_translations()` with the plugin's languages directory — which
   must agree with `load_plugin_textdomain`'s hardcoded
   `'scanpay-for-woocommerce/languages'` (`woocommerce-scanpay.php:412`).

   The three handles are `wc-scanpay-settings` (`settings.php:53`),
   `wc-scanpay-order` (`orders.php:101`), and `wc-scanpay-subs`
   (`subscriptions.php:63`) — 6a renamed the latter two off the shared
   `wcsp-meta`, which is why the handle→catalog mapping is now well-defined.
   `wcsp-meta` survives as a *style* handle on two screens; it is not a script
   and takes no translations.

5. Consume `wp.i18n` as a window global. Be precise about the pattern this
   mirrors: `checkout.ts:11-12` does plain `window.wp.element` / `window.wp.data`
   property access, typed by an `interface Window` (`types/checkout.d.ts:7-17`),
   with the global
   guaranteed by the `wp-element` script dependency declared in PHP
   (`class-wc-scanpay-blocks-support.php:29`, the dependency array). It is not an
   esbuild
   `--external`/`--global-name` mapping — `build.sh` passes only
   `--bundle --minify`. Items 4 and 5 are two halves of one mechanism. Do not
   bundle a second translation runtime.

6. Land with at least one real admin string wrapped and translated in the Danish
   PO, so static extraction and JSON generation exercise the full pipeline.
   Browser loading is still a runtime handoff; do not call the task verified
   merely because the JSON exists.

### Verify

**Static**

- Run `./build.sh` **without deploying** and inspect the release tree: MO, PHP,
  and JSON catalogs present. Answer `n` at the deploy prompt.
- Confirm the proof msgid appears in the POT, Danish PO, and the JSON catalog.
- Compute `md5('admin/assets/js/settings.js')` yourself and confirm a JSON
  filename matches. Also inspect that JSON's `source` path. This is arithmetic
  on paths — do it, don't defer it.
- Confirm the unminified extraction copy is not in the release tree.

**Runtime (hand off)** — switch WordPress to Danish and confirm the proof string
renders translated in the browser on the screen that enqueues it. File existence
is not proof the catalog loads.

### Done when

The release build contains a path-correct JSON catalog for a real translated
admin string, POT extraction covers PHP and built JS in one defined pipeline,
and no second translation runtime is bundled. The task remains implemented, not
runtime-verified, until that string is observed in a Danish browser session.

## Task 6c: Internationalize the remaining user-facing strings

### Problem

Substantial user-facing text is hard-coded, especially in admin TypeScript:
sync/reset/update notices (`settings.ts:48,61,67,75,76,104`), order meta-box
messages (`order.ts:122,127,130,134,173`), subscription meta-box labels and
warnings (`subs.ts:56,57,62,64,79,83,93`). Some PHP payment errors, accessibility
labels, order notes, and status-change reasons too.

**Those line numbers are a sample, not the work list.** All were re-verified
accurate, but they under-cover their own prose — uncited: `order.ts:55,60,81,86`
(including the `'Capture'` button), `subs.ts:30,50,58`,
`settings.ts:49,57,62,68,105-107,111,116,117,127`, and the outdated-plugin
banner in **two** places — `types/meta.ts:80` and `settings.ts:152-154`, which
are separate copies of one message and must end up as one translatable string,
not two. Drive the sweep from the classification rule, not the list.

**The fragmented Blocks terms sentence is no longer part of this task.** Task 2
(`d274f3b`) replaced the `'before'`/`'link'`/`'after'` construction with a single
`I accept the %s.` msgid shared verbatim by the classic renderer
(`wcs-scanpay-checkout-terms.php:21-25`) and the Blocks payload
(`class-wc-scanpay-blocks-support.php:62-67`), split on `%s` in `checkout.ts`.
Leave it alone; the remaining sweep is the admin TypeScript and the PHP strings
below.

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
   translator comments; `_n()` for count-dependent seconds/minutes; rebuild the
   Blocks terms line as one reorderable translation (keeping interpolation safe
   for a JSON payload — the classic path's raw `<a>` is not transferable);
   preserve the deliberately plain checkout title/description setting *defaults*
   (`__()` cannot localize a stored value); do not translate brand names, card
   identifiers, or currency codes to inflate coverage.

3. Keep PHP-provided Blocks strings in PHP unless moving one materially improves
   interpolation or plurals. Never maintain two sources for one sentence.

4. The **PHP half does not depend on 6b** — it extracts through `pnpm i18n:po`
   today. Only the `.ts` sweep is blocked. The PHP half may be prepared
   independently, but Task 6c is still one commit and is not done or landed
   until 6b exists and the TypeScript sweep is included.

   Resolve one inconsistency while here: `admin/orders.php:158` wraps the meta-box
   title in `__( 'Scanpay', … )` while `admin/subscriptions.php:64` passes a bare
   `'Scanpay'`. Per item 2, make both bare.

### Verify

**Static**

- Re-run `pnpm i18n:po` and confirm the new PHP strings appear in the POT.
- Grep PHP and TS for remaining human-facing literals and **account for every
  deliberate exclusion in writing** — this is the substance of the task and is
  fully doable statically.
- Confirm no `scanpay_log()` call, internal exception, or AJAX error code was
  wrapped.

**Runtime (hand off)** — Danish across all three settings pages, both meta boxes,
classic and Blocks checkout, order notes; confirm plurals and placeholder
ordering render correctly and dynamic API/DB details are still escaped as text.

### Done when

Every maintained customer- or merchant-facing string is translatable, no sentence
is assembled from independently translated fragments, and operational logs stay
untranslated.

## Task 6d: Regenerate the translation catalogs

### Problem

`src/languages/scanpay-for-woocommerce-da_DK.po` predates the rewrite — **39 of
its 45 source references are stale** (six name a file that still exists, all
`woocommerce-scanpay.php`: five plugin-header entries plus one recorded at
`:250`, a line that no longer holds that string): `includes/form-fields.php`,
`gateways/class-wc-scanpay-gateway-applepay.php`,
`gateways/class-wc-scanpay-gateway-mobilepay.php`, `includes/admin-options.php`,
`hooks/class-wc-scanpay-blocks-support.php`, `gateways/class-wc-scanpay-gateway.php`.
Only `woocommerce-scanpay.php` still resolves. It also carries two
`POT-Creation-Date` headers (`:10`, `:11`). The POT has a metadata artifact from
being generated against `src` rather than the plugin root:
`Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/src`
(`scanpay-for-woocommerce.pot:6`).

`c56a45e` regenerated the POT on 2026-07-26. It fixed **none** of the above: the
`plugin/src` header and the timestamped `POT-Creation-Date` both survive, and the
Danish PO was not touched at all. Do not read that commit as partial credit for
this task.

### Fix

1. Run **last across the whole plan**, not merely last within Task 6. Tasks 7,
   10, 12, 19, and 20 can add or change merchant/customer-facing copy; 6c
   sweeps after them, and 6d regenerates only when no remaining task can stale
   the catalogs again.
2. Regenerate the POT through 6b's pipeline so it covers PHP and built JS, then
   **verify** the headers rather than setting them again: 6b item 2 already
   requires passing `Report-Msgid-Bugs-To` and a blank `POT-Creation-Date` to
   `make-pot`. If either is still wrong here, the bug is in 6b's pipeline — fix it
   there. Do not introduce a second place that stamps POT headers; reproducibility
   is more useful than a timestamp that changes on every extraction.
3. Update the Danish PO: merge against the new POT, remove obsolete pre-rewrite
   entries and the duplicate header, translate all maintained user-facing
   strings. Preserve product names and technical terms where translating would
   mislead.
4. Re-run extraction and confirm no further diff. A second run showing changes
   means the pipeline is nondeterministic — fix that first.

### Verify

**Static** — fresh extraction produces an empty diff; no stale path survives in
either catalog; the release tree carries MO, PHP, and JSON artifacts.

**Runtime (hand off)** — spot-check the Danish catalog against the screens 6c
exercised.

### Done when

Both catalogs describe the current source with no stale entries, and extraction
is deterministic.

---

## Task 7: Give a failed WooCommerce payment completion its Scanpay context

### Problem

`WC_Scanpay_Sync::sync()` upserts the authoritative `scanpay_meta` row and then
calls `payment_complete()` (`class-wc-scanpay-sync.php:335`) without checking its
return. WooCommerce catches failures, returns `false`, and no exception reaches
the ping handler — so the loop treats the change as processed and advances
`scanpay_seq.seq`. Scanpay can hold a successful payment while the order stays
pending, failed, cancelled, or on hold, and the keepalive cannot repair it
because the cursor already acked the change.

**The failure is not silent.** Verified in `.stubs/woocommerce`
(`includes/class-wc-order.php:191-205`): the catch block already writes a
`wc_get_logger()->error()` entry *and* adds a merchant-visible order note —
`Payment complete event failed.` plus the exception message.

What that note lacks is **Scanpay context**: it does not say Scanpay holds a
successful payment, does not name the transaction or charge, and does not say the
order needs manual reconciliation. Read cold it looks like a generic WooCommerce
hiccup, not a money-collected-but-order-unpaid condition. That gap is what this
task closes.

One genuine silence remains: WooCommerce catches `Exception`, **not** `Throwable`.
A callback raising an `Error` escapes `payment_complete()` entirely, is neither
logged nor noted, and propagates to the ping handler as a 500 — pinning the
cursor and producing exactly the shop-wide stall item 2 rejects. This task must
catch that escaping `Throwable` and handle it with the same
log-note-and-advance policy as a `false` result.

Two consequences are **correct behavior** and must not be "repaired":

- The `scanpay_meta` row making the renewal path skip a charge is right. Scanpay
  genuinely holds a payment; the guard at `class-wcs-scanpay-charge.php:190-204`
  declining to charge again prevents a double charge.
- The row must not be deleted or rolled back. It is the authoritative record of
  `captured`/`refunded`/`voided` and carries the `rev` used for change ordering.

### Fix

1. Capture the result and wrap the call in `catch ( \Throwable $e )`.

   - On `false`, WooCommerce has already logged and added its generic note;
     append Scanpay context.
   - On an escaping `Throwable` (normally an `Error`, because WooCommerce catches
     `Exception` internally), log the raw diagnostic and add the same actionable
     Scanpay note.

   In both cases the note names the transaction/charge label, the order ID, and
   the fact that Scanpay holds a successful payment the order does not reflect.
   Then **return normally and let the cursor advance**.

   Reporting is best effort and must not recreate the stall: contain a
   `Throwable` from `add_order_note()` itself, log that secondary failure, and
   still return normally. Do not allow the recovery note to become a new reason
   to pin the cursor.

   Follow the underpayment branch earlier in the same block
   (`class-wc-scanpay-sync.php:312-316`), whose comment at `:310-311` states the
   file's rule: *"we log + note + return (never throw)."* The
   `payment_complete()` call is at `:335`, some twenty lines further down.

2. **Do not throw.** That would invert an invariant this file documents at
   `:186-187`, `:283-285`, `:302-304`, and `:310-311`, and convert one stuck order
   into a shop-wide outage. The failure is not knowably transient: a third-party
   callback on `woocommerce_pre_payment_complete` that throws deterministically —
   a broken CRM or ERP integration is the ordinary case — returns `false` on every
   replay. Throwing pins the cursor, the same seq page replays forever, and every
   other order stops synchronizing with no exit condition. The keepalive cannot
   break it because the failure is in our handler, not in ping delivery.

3. Preserve the save-before-call behavior for ineligible statuses. WooCommerce
   returns `true` for that deliberate no-transition branch *when no callback
   throws* — it still fires
   `woocommerce_payment_complete_order_status_{status}` (`class-wc-order.php:187-189`),
   so a throwing callback yields `false` there too. Item 1 handles it; no special
   case needed.

4. Add no retry, cron, recovery-table, or cursor-rollback machinery. The order
   needs merchant intervention; the plugin's job is to make that visible.

5. Make the note actionable: payment succeeded at Scanpay, WooCommerce could not
   complete the order, reconcile manually — not an echoed exception string. Keep
   the raw diagnostic in `scanpay_log()`. Our note lands **alongside**
   WooCommerce's on the `false` path, not instead of it, so write it to
   complement: carry the Scanpay-side facts and let WooCommerce's carry the
   exception text. On the escaping-`Throwable` path there is no WooCommerce note,
   so ours is the only merchant-facing record. Coordinate wording with 6c.

6. Keep Task 1's scoped override compatible: any filter installed around
   `payment_complete()` must still be removed in a `finally` when it returns
   `false` or throws (Task 1 item 3 is the canonical statement of that rule).

   **Task 1 already built the `try`/`finally` here — extend it, do not rewrite
   it.** If Task 1 landed as specified, this task adds a `catch` and the
   reporting to an existing structure. If you find yourself restructuring Task 1's
   block, re-read its item 3 first; one of the two implementations is wrong.

   **Ordering matters, and the two tasks collide if you ignore it.** `catch` runs
   *before* `finally`, so a note added from the catch body would execute with
   Task 1's `woocommerce_payment_complete_order_status` filter still installed.
   Report *after* removal: capture the result, let the filter come off, then log
   and note outside the `try`/`finally`.

### Verify

**Static**

- Confirm the `false` branch cannot throw and cannot skip the cursor advance.
- Confirm an escaping `Throwable` also cannot escape through the reporting path
  or skip the cursor advance.
- Confirm the `scanpay_meta` write still precedes the call and is not rolled back.
- Re-read `class-wc-order.php:138-209` in `.stubs/` — through the `return true;`,
  which this bullet asks you to characterize — and state precisely which failure
  modes produce `false` versus an escaping `Error`, and which of those your
  handler covers.
- Confirm no new retry/cron/recovery/rollback code was added.

**Runtime (hand off)**

Stub `payment_complete()` to return `false` and confirm log + note + cursor
advance + 200. Attach `woocommerce_pre_payment_complete` callbacks that throw
an `Exception` (WooCommerce converts it to `false`) and an `Error` (escapes
WooCommerce); in both cases confirm **later orders in the same page and in
subsequent pings keep synchronizing** — a wedged cursor here is the regression
this design exists to prevent. Make `add_order_note()` fail on the recovery path
and confirm that also cannot wedge the cursor. Exercise the ineligible-status
branch. Confirm a renewal for such an order is still declined by the
double-charge guard. Read the resulting note as a merchant would.

### Done when

A `false` result always produces a Scanpay log entry and a note naming the
Scanpay-side facts WooCommerce's generic note omits; a deterministic third-party
hook failure — `Exception` or `Error` — can never stall sync for other orders;
the reporting path cannot stall it either; the `scanpay_meta` row is preserved;
no new recovery mechanism exists.

---

## Task 9: Preserve capture failure outcomes for the whole request

### Problem

`WC_Scanpay_Capture::capture()` records the order in its static `$processed` map
at `class-wc-scanpay-capture.php:50` — before the shop-ID check (`:52-56`), the
metadata SELECT (`:59-73`), the void check (`:75-77`), the money math (`:79-95`),
and the API call (`:97-103`).

If any of those throws, `capture_or_hold()` returns `false` and parks the order
on hold (`:119-131`). A second call in the same request sees only that the ID is
present, returns at `:45-48`, and `capture_or_hold()` falls through to
`return true` at `:122`. The map means "attempted" but reads as "succeeded".

**The bulk path is reachable, traced end to end.** WooCommerce builds the ID list
as `array_reverse( array_map( 'absint', (array) $_REQUEST['id'] ) )`
(`src/Internal/Admin/Orders/ListTable.php:1441`) with no `array_unique`, so a
duplicated `id[]` survives. Dispatch reaches our handler through the custom-action
filter at `:1504`, and it does so by accident worth recording: WC's own switch at
`:1476` tests `false !== strpos( $action, 'mark_' )`, a **substring** match, so
`scanpay_mark_completed` enters that branch — but `substr( $action, 5 )` yields
`y_mark_completed`, which is no registered status, so `$action_handled` stays
`false` and it falls through. The plugin's loop does not dedupe
(`admin/hooks/wp-bulk-actions.php:44`) and its skip list is only
`completed`/`trash` (`:49`) — so an order parked on-hold by a failed first
iteration passes, the duplicate gets `true`, and `:55` marks an uncaptured order
completed.

(The "another integration calls the primitive twice" scenario has no in-repo
instance — `capture()` is private and both the bulk handler and the AJAX
intercept `remove_action()` the completed hook. The bulk path alone justifies
this task.)

### Fix

1. Replace the presence-only map with an explicit per-order outcome
   (`array<int, bool>`): `true` = captured successfully or correctly determined
   nothing remained; `false` = attempt failed and was handled through the failure
   path.

   Either `isset()` or `array_key_exists()` is correct for a `bool` map and the
   choice does not affect behavior. `array_key_exists()` becomes *necessary* only
   if the map later holds `null` (e.g. an "in progress" state) — if you pick it
   for that reason, say so in the comment.

2. Move memoization to `capture_or_hold()`, or otherwise store the outcome only
   after the primitive returns or throws. The wrapper should check the outcome
   map before calling the primitive, store `true` only after the primitive
   returns, and store `false` immediately on entry to its catch. Never set a
   success marker before failure-prone work.

   Two consequences to specify rather than leave to the implementer:

   - **Remove the primitive's own check at `:45-48`.** The wrapper owns the map;
     leaving both is two sources of truth.
   - **A non-Scanpay order records nothing.** `capture()` returns at `:41-43`
     before any attempt, and neither defined meaning of `true` fits it. The
     wrapper must not memoize a return it never attempted.

3. On a repeat call, return the recorded outcome. A prior failure keeps returning
   `false`, with no second API request and no duplicate notes or status updates.

   Keep that guarantee even if the attempt to park the order on hold throws:
   contain a `Throwable` from `update_status()`, log it, and return the already
   recorded `false`. The capture failed regardless of whether WooCommerce could
   persist the fallback status; a later call must not retry or report success.

4. Preserve successful dedup: after one successful capture, later calls return
   `true` without another request.

5. Keep the fail-loud private primitive and the on-hold public wrapper. Do not
   weaken the remaining-amount calculation, action index, or Scanpay's
   server-side concurrency check.

### Verify

**Static**

- Confirm no `true` write to the map precedes the failure-prone primitive, and
  the catch stores `false` before attempting log/note/status side effects.
- Trace the bulk loop with a duplicated ID and state the outcome of both
  iterations under the new code.
- Confirm each failure mode records `false` exactly once and attempts at most one
  status transition. Note one case already behaves: a malformed API key throws in
  `self::init()` at `:49`, *before* the memo write at `:50`.
- Confirm the four `capture_or_hold()` call sites
  (`woocommerce-scanpay.php:141`, `admin/hooks/wp-ajax-wc-scanpay-capture.php:46`,
  `wp-bulk-actions.php:52`, `wp-ajax-wc-mark-order-status.php:72`) still read the
  boolean the same way. Precisely: **three read it; `woocommerce-scanpay.php:141`
  discards it deliberately** (the order is already `completed` there). Do not
  "fix" that call site into checking a return it has no action for.

**Runtime (hand off)**

Force each failure mode and confirm two calls yield `false, false` with one
attempt and at most one transition; make the on-hold write throw and confirm the
same `false, false` result with a diagnostic log; a success yields `true, true`
with one API request; a legitimate no-op yields `true` without a request; a
duplicate ID through the bulk handler cannot complete the order.

### Done when

A failed capture can never become a success by re-encountering the order,
successful captures stay deduplicated, fallback-status persistence cannot erase
the recorded failure, and no failure path completes an uncaptured order.

---

## Task 10: Validate zero renewal amounts against the order total

### Problem

`WCS_Scanpay_Charge::scheduled_charge()` calls `payment_complete()` immediately
when the float `$amount` from WCS is `<= 0`
(`class-wcs-scanpay-charge.php:84-88`) — above the normalization at `:92-93`, the
`wc_scanpay_is_money()` guard at `:98-102`, and the `wc_scanpay_cmpmoney()`
mismatch check at `:103-107`.

A zero or negative hook amount therefore marks a positive-total renewal paid
without charging, bypassing the method's own policy that a scheduler/order-total
disagreement must fail loudly. WCS normally passes the renewal total, but the
hook can be invoked off-schedule by third-party code or a store-manager workflow.

### Fix

1. Move normalization and validation of both amounts above any zero/negative
   early return:

   - Normalize the hook float once with `wc_format_decimal()` — WooCommerce core
     (`includes/wc-formatting-functions.php:289`), not a plugin helper.
   - Read the order total as a string in `edit` context.
   - Validate both with `wc_scanpay_is_money()` (`library/math.php:26`).
   - Compare with `wc_scanpay_cmpmoney()` (`library/math.php:167`).

   The float signature is imposed by WCS; every decision after receiving it uses
   the normalized money string.

2. Apply the existing invalid-amount and mismatch failure behavior before
   deciding the renewal is free. A non-positive scheduler amount against a
   positive order total must mark the renewal failed and never call
   `payment_complete()`.

3. Replace `$amount <= 0.0` with the money helpers after validation —
   `wc_scanpay_is_zero()` already exists and is the natural replacement for the
   zero test (verified: `wc_scanpay_is_zero( '-0.00' )` is `true`). Preserve
   handling for genuine zero-total renewals and supported non-positive
   proration/credit orders where the normalized total matches.
   `wc_scanpay_is_money()` already accepts negatives — its pattern is
   `/^-?[0-9]+(\.[0-9]+)?$/D` (`math.php:27`) — so no special-casing outside the
   helpers. (The regex is deliberate here despite the project's ctype
   preference; the `D` modifier is needed to reject a trailing newline. See the
   docblock at `math.php:15-25`.)

4. Check `payment_complete()`'s boolean in the free-renewal branch. If completion
   cannot be persisted, log and surface a failed renewal so WCS can handle it.

5. Keep positive matched renewals on the existing idempotent charge path. Add no
   retry or lock state to `scanpay_subs`.

### Verify

**Static**

- Confirm no path reaches `payment_complete()` or the API before both amounts are
  validated and compared.
- Enumerate the branch table below from the code and state each outcome.
- Confirm `wc_scanpay_is_money()`/`wc_scanpay_cmpmoney()` behavior on the
  negative and zero cases with `php -r` against `math.php` directly — these are
  pure functions and fully testable here.

Rows 2–3 are decided by the **mismatch guard**, not by a new zero check — say
which guard fires when you enumerate them, or the table looks like it needs code
it does not.

| Hook amount | Order total | Expected |
| --- | --- | --- |
| `0.00` | `0.00` | complete, no API charge |
| `0.00` | `100.00` | failed; no `payment_complete()`, no API |
| `100.00` | `0.00` | failed; no charge |
| matching positive | same | normal charge |
| matching negative/proration | same | no-charge completion |
| invalid normalized hook amount or order-total string | any | clean failure, no uncaught `InvalidArgumentException` |

**Runtime (hand off)** — the same matrix end to end, plus a free-renewal
`payment_complete()` returning `false` surfacing to WCS.

### Done when

Every renewal amount is validated against the order total before payment or
completion, a positive-total renewal can never be marked paid by a zero scheduler
amount, and genuine free renewals still complete without contacting Scanpay.

---

## Task 11: Restore WooCommerce's gateway state and extension contracts

### Problem

`WC_Gateway_Scanpay_Base::__construct()` (`abstract-wc-gateway-scanpay-base.php:8-13`)
calls `init_settings()` but never initializes the inherited public `$enabled`,
`$title`, or `$description`. `WC_Payment_Gateway` defaults `$enabled` to `'yes'`
and declares `$title`/`$description` with no defaults
(`abstract-wc-payment-gateway.php:61,68,75`).

The `$enabled` omission is functional, not metadata-only: inherited
`is_available()` reads the property (`abstract-wc-payment-gateway.php:346-357`),
so classic checkout can offer gateways whose saved `enabled` setting is `no`.
Blocks does not mask this in classic checkout; its separate payload builder
reads the option directly.

Classic checkout works only because the base overrides `get_title()` (`:40-45`)
and `get_description()` (`:52-54`) and re-reads settings. Other consumers read the
title/description properties directly — verified in `.stubs/woocommerce`:
`Version3/class-wc-rest-payment-gateways-controller.php:39-40`,
`Version2/…-v2-controller.php:260-261`,
`src/Internal/RestApi/Routes/V4/…/AbstractPaymentGatewaySettingsSchema.php:527-528`,
`includes/class-wc-tracker.php:1006`. They report `null`. (Not everything is
affected — `WC_Payment_Gateways::get_payment_gateway_name_by_id()` at
`class-wc-payment-gateways.php:294` prefers the getter (`:301-302`) and falls
back to `->title` only at `:304` — which is why this has gone unnoticed.)

`WC_Payment_Gateways::get_available_payment_gateways()`
(`class-wc-payment-gateways.php:333-345`) filters on `is_available()` **alone** —
there is no separate `enabled` check anywhere ahead of it — which is what makes
the `$enabled` omission reach classic checkout.

The custom getters also bypass public contracts:

- `get_title()` omits `woocommerce_gateway_title` and current WooCommerce's title
  sanitization (`abstract-wc-payment-gateway.php:373-385`).
- `get_description()` omits `woocommerce_gateway_description` and `wp_kses_post()`
  (`:392-406`).
- All three `get_icon()` implementations (`card:42-54`, `mobilepay:21-24`,
  `applepay:21-24`) omit `woocommerce_gateway_icon` (`:413-424`).
- `get_transaction_url()` (`base:62-66`) omits `woocommerce_get_transaction_url`
  (`:295-313`).

The `is_admin()` `Scanpay` title is **not** a defect — see "Verified sound" in
`CLAUDE.md`.

### Fix

1. Initialize all three inherited properties after `init_settings()`:
   `$enabled` as `'yes'` only when the stored value is exactly `'yes'` (otherwise
   `'no'`), and `$title`/`$description` as strings so the properties, getters,
   REST responses, availability, and strict return types agree.

   **Factor this into a small method, not inline constructor code.**
   `admin/settings/process-admin-options.php` re-runs `$this->init_settings()`
   at `:34` after saving and force-disables via
   `$this->settings['enabled'] = 'no'` at `:67`. Initializing in the constructor
   *only* leaves the properties stale for the rest of that admin request — so the
   saved value and `$gateway->enabled` disagree on exactly the save request the
   REST controllers and the Payments list read from. Call the method from the
   constructor **and** after both of those points, so the properties never lag
   `$this->settings` within a request.

   Do not read these through `get_option()` with a default:
   `WC_Settings_API::get_option()` force-loads the form fields when the key is
   missing (`abstract-wc-settings-api.php:304-307`), and the card fields file runs
   `get_pages()` (`admin/settings/fields/scanpay.php:11`). Read
   `$this->settings['title'] ?? $default` directly.

   **State the scope honestly — avoiding it here does not remove the hazard.**
   The card gateway's *constructor* already calls `get_option( 'stylesheet' )`
   (`class-wc-gateway-scanpay-card.php:29`) and
   `get_option( 'wc_complete_virtual' )` (`:32`) on every request that builds the
   gateways, front end included, and `get_icon()` adds `card_icons` (`:43`). So
   the plugin has five such call sites, not three, and two of them run before
   checkout renders anything. Two facts keep this proportionate rather than
   alarming: the miss only happens for a key genuinely absent from the saved
   option (`init_settings()` merges field defaults when the option is not an
   array, and `upgrade.php` backfills `stylesheet` and `wc_complete_virtual` on
   every 2.x path), and both `$this->settings[$key]` and `$this->form_fields`
   memoize, so the worst case is **one** fields load — and one `get_pages()` —
   per request, not five. Reading the properties directly is still right; just do
   not claim it eliminates a per-request `get_pages()` that `:29` can still
   trigger on its own.

   Preserve these two consequences explicitly:

   - **This changes an explicitly empty saved title.** A missing key already
     resolves to the field default because current `get_option()` loads the lazy
     fields first. But a stored `''` is then replaced by the current
     `$empty_value` argument `'Scanpay'` (`base:44`), so all three gateways render
     the brand. Initializing the property directly preserves the stored empty
     string, matching WooCommerce's normal gateway-property behavior. That is a
     visible edge-case change; say so in the commit message and cover it in the
     runtime matrix. Missing keys still fall back to `'Pay by card'`
     (`fields/scanpay.php:40`), `'MobilePay'`, and `'Apple Pay'`.
   - The constructor and lazy field definitions need the same six defaults.
     Make each subclass expose its checkout title/description defaults through
     one protected getter or equivalent single source, and have both the base
     constructor and that gateway's fields file consume it. Do not duplicate
     six literals with comments asking future edits to keep them synchronized.
     The fields files are required only from `get_form_fields()`, so `$this` is
     available. Note `init_settings()` already merges field defaults when the
     option is not an array (`abstract-wc-settings-api.php:284-287`), so
     `?? $default` only fires for a saved array missing the key.

   Do not extend this to `card_icons` — `class-wc-gateway-scanpay-card.php:43`
   keeps `get_option( 'card_icons', [] )` because item 4 relies on that call's
   `$empty_value` coercion. Per the scope note above, it shares one memoized
   fields load with the constructor's two calls; if that cost is ever judged
   unacceptable, fix all five together rather than picking off one.

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
   `base:41-43` documents. Keep the cast or drop the `: string` return type; not
   neither.

   Preserve standard signatures: `woocommerce_gateway_title`,
   `…_description`, and `…_icon` receive the value and the gateway ID, not the
   instance.

4. Apply `woocommerce_gateway_icon` to each completed icon fragment, building and
   escaping the plugin-owned HTML before the filter. Cast the filter result to
   string before returning from each `: string` override, for the same
   third-party callback reason as `get_title()`.

   While rewriting `WC_Gateway_Scanpay_Card::get_icon()`, filter out empty entries
   so classic and Blocks share one normalization rule
   (`array_values( array_filter( … ) )`, matching
   `class-wc-scanpay-blocks-support.php:79`). This is **defensive consistency,
   not a bug fix** — see Settled decisions. Do not describe it as fixing a broken
   checkout image.

5. Apply `woocommerce_get_transaction_url` with `( $url, $order, $gateway )`
   before returning, and cast its result to string. Keep encoding the path
   components and return an empty URL when the identifiers are absent. The
   override must stay: the parent's
   `view_transaction_url` template carries only the transaction ID and cannot
   encode the per-order shop ID.

   Treat the empty-identifier guard as **hardening, not a live fix**. The
   `dashboard.scanpay.dk//` string is constructible in isolation but unreachable
   through WooCommerce: both core call sites gate on a non-empty
   `get_transaction_id()`
   (`includes/admin/list-tables/class-wc-admin-list-table-orders.php:420-424`,
   `includes/admin/meta-boxes/class-wc-meta-box-order-data.php:243-248`), and
   `class-wc-scanpay-sync.php:294` refuses to write a transaction ID unless the
   shop ID matches.

   Align the meta read context while here: `base:63` reads the shop ID in default
   `view` context while `base:64` uses `edit`. Every other reader uses `edit`
   (`admin/orders.php:106`, `class-wc-scanpay-capture.php:52`,
   `class-wc-scanpay-sync.php:294`).

6. Do not replace the lazy form-field loading or the shared primary settings.

7. Add a one-line comment at the `is_admin()` branch stating the branding is
   deliberate and why. The docs half is already done — it is in the "Verified
   sound" list in both `CLAUDE.md` and `AGENTS.md`; do not add it twice.

8. **Keep Blocks deliberately separate and record why.**
   `class-wc-scanpay-blocks-support.php:43`, `:73-74`, `:79` read `title`,
   `description`, and `card_icons` straight from settings and never call the
   getters — bypassing both the `$empty_value` coercion and every filter this task
   restores. Do not route Blocks through these classic getters: their filters may
   return HTML, while `checkout.ts` passes the payload to React as text, so markup
   would render literally rather than as classic-checkout HTML. The JSON encoder
   plus React text rendering already prevents raw settings from becoming
   executable markup.

   Add a code comment at the Blocks payload builder stating that the
   HTML-oriented classic gateway filters are intentionally not applied there.
   Keep the existing `array_filter()` icon normalization. A future Blocks filter
   contract can be added separately if WooCommerce exposes one; do not invent one
   in this task.

### Verify

**Static**

- Confirm `$title`/`$description` are non-null strings after construction on all
  three gateways, by reading the constructor chain.
- Confirm `$enabled` reflects the saved setting on all three gateways and
  inherited `is_available()` can no longer offer a disabled gateway.
- Confirm each restored filter is applied with the upstream argument signature —
  check each against `.stubs/woocommerce/…/abstract-wc-payment-gateway.php`.
- Confirm the `(string)` casts survive on both `get_title()` branches.
- Confirm every newly filtered value returned from a `: string` override is
  cast after `apply_filters()`.
- Confirm no new `get_option()` call was introduced on a checkout path.
- Confirm each title/description default has one source shared by constructor
  initialization and the corresponding lazy fields file.
- Confirm item 8's Blocks decision is recorded as a **code comment** at the
  Blocks payload builder — not only in the commit message, where a future reader
  of `class-wc-scanpay-blocks-support.php` will never find it.

**Runtime (hand off)**

`GET /wp-json/wc/v3/payment_gateways/<id>` and the CLI returning configured
enabled/title/description values rather than default-yes/null state; classic
checkout with each gateway enabled and disabled; missing versus explicitly empty
titles; each filter callback firing and being honored; safe vs disallowed HTML
in title/description; the admin selector still reading `Scanpay`.

**Explicitly unverifiable here:** item 2 defers to "the sanitization appropriate
to the installed WooCommerce version", and `.stubs/woocommerce` is one working
copy, not a version matrix. The WooCommerce 3.6 end must be exercised against a
real install before this item counts as verified — say so rather than implying
coverage.

### Done when

Every gateway exposes the saved enabled state plus populated string
title/description properties; disabled gateways stay out of classic checkout;
the standard filters work without weakening output safety; and the admin
branding is preserved, filterable, and documented so it is not re-flagged.
Blocks remains a plain-text payload by an explicit, documented decision.

---

## Task 12: Hide Apple Pay on unsupported classic checkouts

### Problem

Blocks gives `scanpay_applepay` a method-specific `canMakePayment` requiring
`ApplePaySession.canMakePayments() === true`
(`public/assets/js/checkout.ts:22-26`, wired at `:65`). Classic checkout has no
equivalent — there is **no `is_available()` override anywhere in `src/`**, so
`WC_Gateway_Scanpay_ApplePay` inherits WooCommerce's default
(`abstract-wc-payment-gateway.php:346-357`: enabled plus a max-amount check) and
is offered in every browser on every device.

Selecting it redirects to the hosted window with `go=applepay`
(`class-wc-gateway-scanpay-applepay.php:35`), where the shopper cannot complete.

### Fix

1. Add a small authored TypeScript entry point for classic checkout. Do not put
   generated JavaScript in `src/`.

   **No `build.sh` change is required.** Both esbuild loops glob top-level `*.ts`
   (`build.sh:37-40`, `:43-46`) and `:30` rsyncs with `--exclude='*.ts'`, so a new
   file directly in `src/public/assets/js/` is compiled and shipped
   automatically and its source is not.

   **These anchors are pre-6b, and 6b lands immediately before this task** —
   it rewrites both esbuild loops. Re-locate them by text rather than line. The
   invariant that survives 6b is what matters here: top-level `*.ts` only, and
   `--exclude='*.ts'` on the rsync. The trap: only *top-level* files are
   entry points — anything under a subdirectory is silently never compiled.
   `tsconfig.json:19-21` already includes `src/public/assets/js/**/*` and
   `eslint.config.mjs` targets `src/**/*.ts`, so tooling needs no change.

2. Enqueue only on classic checkout/order-pay requests where Apple Pay is
   enabled. Do not load it for Blocks.

   **`is_checkout()` is not sufficient** — it is `true` on a Blocks checkout page
   too, so the obvious guard would load this on exactly the surface item 6 says to
   leave alone. After Task 11, gate on the initialized `$this->enabled`. Treat
   `is_checkout_pay_page()` as classic, even when the configured checkout page
   contains a block. For the ordinary checkout page, guard
   `WC_Blocks_Utils` with `class_exists()` for the WooCommerce 3.6 end and call
   `WC_Blocks_Utils::has_block_in_page( wc_get_page_id( 'checkout' ),
   'woocommerce/checkout' )` with its actual two-argument signature. Do not call
   the method with no page argument.

   The comparable existing pattern is
   `class-wc-gateway-scanpay-card.php:30` and its `enqueue_checkout_styles()`
   callback (hook `wp_enqueue_scripts` from the constructor); all three gateways
   are always registered (`woocommerce-scanpay.php:104-106`, `:356-362`), so that
   hook point is available — it just needs the stronger guard.

   Use the handle `wc-scanpay-applepay`, matching 6a's `wc-scanpay-*` scheme.
   Declare `jquery` and `wp-i18n` as dependencies: classic checkout's fragment
   lifecycle is a jQuery event, and the sole-gateway notice is translated. Call
   `wp_set_script_translations()` for the new handle and use the same languages
   path established by 6b. Note 6b item 4 wires only the **three admin** enqueue
   sites; this is the first *public* translated script, so it must be added to
   that mechanism rather than assumed covered by it. (6b item 2 already emits
   `build/public/assets/js/` unminified for extraction, so the catalog side is
   in place.)

   The DOM surface, so it is not guessed: the row is
   `li.payment_method_scanpay_applepay` inside `ul.wc_payment_methods`, with
   radio `#payment_method_scanpay_applepay`.

3. Probe exactly what Blocks probes: `window.ApplePaySession?.canMakePayments() === true`.

   - True → leave it available.
   - False or absent → remove/hide the row and prevent it being submitted through
     the normal UI.
   - Already selected, or selected after a fragment refresh → select another
     visible gateway and notify WooCommerce.
   - If Apple Pay is the sole rendered gateway, hide/uncheck it, show a
     translated accessible "not available on this device/browser" notice, and
     disable the normal place-order submission until a fragment refresh
     provides another method. Do not leave an invisible checked input or a live
     Place order button.

   This is a browser-capability UI gate. Client-side code cannot prove capability
   to the server or stop a crafted request, so do not describe it as a
   server-side authorization boundary. The hosted window stays the final
   authority.

4. Apply once on DOM ready and again on classic checkout's `updated_checkout`
   jQuery event — the payment-method list is replaced on address, shipping, and
   coupon changes. Note `/order-pay/` does not fire fragment refreshes, so the
   second hook is inert there.

5. Avoid user-agent inference and `canMakePaymentsWithActiveCard()` — the former
   is unreliable, the latter is async and would require a provisioned card rather
   than a capable environment.

6. Keep `process_payment()` and the Blocks implementation unchanged.

### Verify

**Static**

- Confirm the new file is top-level in `src/public/assets/js/` and that
  `./build.sh` emits its `.js` into the release tree without shipping the `.ts`.
- Confirm the enqueue guard excludes Blocks by an actual block check, not
  `is_checkout()` alone.
- Confirm the probe expression matches `checkout.ts:26` exactly.
- Confirm the new handle depends on `jquery` and `wp-i18n`, has script
  translations attached, and the unsupported sole-gateway branch cannot submit
  a hidden Apple Pay input.

**Runtime (hand off)**

Safari on a supported Apple device; Chrome/Firefox and environments without
`ApplePaySession`; Apple Pay preselected then a fragment refresh; address,
shipping, and coupon changes; Apple Pay as the sole enabled gateway (notice
shown, submission disabled); Blocks retaining its `canMakePayment` behavior and
not loading the new asset.

### Done when

Apple Pay is offered only where `canMakePayments()` succeeds through the normal
UI in both checkouts, fragment refreshes cannot reintroduce it, and card/MobilePay
availability is unchanged.

---

## Task 14: Migrate the released 2.x SQL schema before stamping version 3

### Problem

`upgrade.php:12` gates on versions below `2.0.0` (the `require` itself is at
`:17`), and
`install.php` only creates a table when it is entirely absent — it gates on
`SHOW TABLES LIKE` (`:12`, `:30`, `:55`), never inspecting existing definitions.
A 2.9.1 site matches no branch in `upgrade.php` and reaches `:107`, which stamps
`3.0.0` after doing nothing.

The released schema really does differ. Verified via `git show v2.9.1:src/install.php`:

- `scanpay_meta` has `method VARCHAR(64) NOT NULL` — **no DEFAULT** — while the v3
  insert (`class-wc-scanpay-sync.php:266`) no longer supplies `method`.
- `scanpay_subs` still has `retries`, `nxt`, `method_id`, and `idem`, though v3's
  charge concurrency is lock-free and nothing reads them.

Do not confuse the two tables: `scanpay_subs.method` is legitimate in v3
(`install.php:60`, written at `class-wc-scanpay-sync.php:383-386`). Only
`scanpay_meta.method` is the orphan.

Because the column is `NOT NULL` with no default, a strict SQL mode makes the
insert fail outright (MySQL 1364) rather than degrade to `''`. WordPress strips
those modes on its own connection
(`.stubs/wordpress/wp-includes/class-wpdb.php:644-651` — the list runs through
`STRICT_ALL_TABLES` and `TRADITIONAL`, applied at `:975`), but
that is filterable via `incompatible_sql_modes` and does not apply to a
replacement db drop-in. On such a site every `scanpay_meta` insert fails and the
cursor cannot advance past that change.

### Fix

1. Add an idempotent, version-gated v2→v3 migration in `upgrade.php` before the
   final version update. Run it for every installed version below `3.0.0` that
   can already have the tables. Before inspecting columns, require
   `install.php` for the whole `< 3.0.0` branch, not only the existing `< 2.0.0`
   branch, so a missing current table is created from the v3 schema. On an
   upgrade this cannot stamp the version early: existing settings or an existing
   version make `install.php`'s `$fresh_install` false. Keep `install.php` as the
   single source of the fresh v3 schema.

   A pre-2.0.0 site now requires `install.php` twice (it is already required at
   `upgrade.php:17`). That is harmless — the file is idempotent — and it is
   **not** an invitation to restructure the `< 2.0.0` branch to avoid the
   apparent duplicate; doing so breaks the 1.x legacy-table drop at `:14-15`.

2. Inspect columns before altering: `SHOW COLUMNS` with the trusted prefixed table
   name and a prepared `LIKE`. Drop `scanpay_meta.method` and the four obsolete
   `scanpay_subs` columns only when present. Do not rely on
   `DROP COLUMN IF EXISTS` — keep compatibility with the oldest MySQL/MariaDB the
   WordPress minimum supports.

3. Preserve every live row and cursor. Do not drop/recreate any table, do not
   reset `seq`/`ping`/`mtime`/totals/revisions/method data, and do not add retry,
   lock, or idempotency state back to `scanpay_subs`.

4. Treat every schema read or write failure as an upgrade failure: check
   `$wpdb->last_error` after inspection, check each `ALTER TABLE` result and throw
   descriptively, and leave `wc_scanpay_version` unchanged unless the complete
   migration and all older migrations succeed. Check the final version
   `update_option()`/reread too; this branch starts from a different version, so
   a failed write is not an "unchanged value" no-op. Preserve the loader's
   five-minute throttle and last-write version stamp.

5. Keep it rerunnable after interruption — a retry must accept a mixture where
   some columns are already gone.

### Verify

**Static**

- Diff `git show v2.9.1:src/install.php` against `src/install.php` and enumerate
  every column difference, so the migration's column list is derived rather than
  assumed. (Note the released schema also carries redundant UNIQUE constraints
  v3 dropped in `5f4468b`; harmless, and out of scope for a column migration —
  but say so rather than leaving it unexplained.)
- Confirm the migration is idempotent by inspection: every `ALTER` is guarded by
  a presence check.
- Confirm no code path stamps the version before the migration returns.
- Confirm `install.php` still produces exactly the v3 schema for a fresh install.

**Runtime (hand off)**

Build a 2.9.1 fixture with representative rows, run the upgrade, confirm rows
survive and the five columns are gone; rerun for a no-op; start from a partially
migrated fixture; force an `ALTER` failure and confirm the version stays put and
a later request retries; retain a strict SQL mode and confirm a new transaction
and charge both insert.

### Done when

Every supported 2.x upgrade reaches the same functional column set as a fresh v3
install without dropping data, sync inserts no longer depend on WordPress
disabling strict modes, and the version is stamped only after a complete
rerunnable migration.

---

## Task 15: Remove Scanpay data from every site during a multisite uninstall

### Problem

All Scanpay state is site-scoped — `$wpdb->prefix`, `get_option()`,
`set_transient()` — and `uninstall.php` cleans only the blog active when
WordPress includes it. There is no `is_multisite()`, `get_sites()`, or
`switch_to_blog()` in the file.

Deleted from Network Admin, every other blog keeps its tables, API key, polling
secret, gateway settings, version option, and upgrade transient after the files
are gone. That violates the uninstall contract and leaves live credentials and
payment metadata in the database.

### Fix

1. Extract the existing per-blog cleanup into a local function: drop all current
   and supported legacy tables for the active prefix; delete the three gateway
   settings options; delete `wc_scanpay_version` and the `wc_scanpay_updating`
   transient. Keep the `WP_UNINSTALL_PLUGIN` guard (`uninstall.php:6`) ahead of
   everything.

   `uninstall.php` is loaded directly by WordPress; do not depend on
   `WC_SCANPAY_URI_SETTINGS`, gateway classes, or helper functions from the main
   plugin file being defined. Keep the literal option names local to the
   uninstall helper.

   **The helper now carries six drops** — the three current tables plus **three**
   legacy ones, `scanpay_queue` having been added by Task 16 (`aef5527`) alongside
   `woocommerce_scanpay_queuedcharges` and `woocommerce_scanpay_seq`. Every one of them must move *inside* the helper.
   Extracting only the drops that predate 16 leaves `scanpay_queue` at top level,
   where a network uninstall drops it on one blog and leaves it on every other —
   which is precisely the gap this task exists to close, reopened for one table.
   Its comment block explains why it must not be deleted again; carry that with it.

2. Single-site: call it once, behavior unchanged.

3. Multisite: request site IDs rather than `WP_Site` objects and run the helper
   for every blog that could hold data.

   - **Paginate IDs explicitly.** `get_sites()` defaults to
     `'number' => 100`
     (`.stubs/wordpress/wp-includes/class-wp-site-query.php:194`), which would
     silently keep data on site 101 and beyond — the same silent truncation this
     task exists to fix. Request `'fields' => 'ids'` in fixed-size pages and
     advance the offset until a short page. This avoids both truncation and
     loading an unbounded network into memory.
   - `switch_to_blog()` before each cleanup, `restore_current_blog()` in a
     `finally` after every switch.
   - Do not assume the plugin is currently active on a blog.
   - Do not delete network options — the plugin creates none.

4. Derive table names from `$wpdb->prefix` only *after* the switch, or the helper
   repeatedly targets the original site's tables.

5. Do not load orders, subscriptions, or WooCommerce. Depend only on the
   multisite and database APIs available to `uninstall.php`.

6. **Per-order meta is deliberately out of scope.** Uninstall leaves
   `WC_SCANPAY_URI_SUBID` / `PAYID` / `PTIME` / `SHOPID` on orders across every
   blog. That is order history, not credentials, and removing it would require
   the unbounded order sweep item 5 forbids. Do not add one to satisfy the
   "Done when" wording below.

### Verify

**Static**

- Confirm every `switch_to_blog()` is paired with a `restore_current_blog()` in a
  `finally`, including the failure path.
- Confirm the prefix is read inside the loop, not hoisted.
- Confirm pagination cannot stop at the default 100-site boundary and requests
  IDs rather than `WP_Site` objects.
- Enumerate every option the plugin writes and confirm the helper covers each —
  the literal `woocommerce_scanpay_settings`, the per-gateway `get_option_key()`
  (`admin/settings/process-admin-options.php:87`), `wc_scanpay_version`, and the
  transient. This is a complete static check.

**Runtime (hand off)**

A two-blog network with distinct keys and rows; uninstall from network context;
confirm both blogs are clean, the original context is restored, and an injected
failure still restores; repeat single-site; confirm unrelated options and
prefixed tables are untouched.

### Done when

A network uninstall leaves no site-scoped Scanpay credentials, settings,
transients, or tables on any blog; every switch is paired with a restoration; and
single-site behavior is unchanged.

---

## Task 18: Run the thank-you sync wait after customer-paid subscriber renewals

### Problem

`wc_scanpay_process_payment()` builds the filtered return URL at
`generate-payment-link.php:82`, then at `:108-113` returns
`[ 'result' => 'success', 'redirect' => $client->renew( $subid, $data ) ]` as soon
as the order carries a nonzero `WC_SCANPAY_URI_SUBID`. The `add_query_arg` that
appends `scanpay_thankyou`, `scanpay_type`, and `scanpay_ref` is at `:171-178` —
unreachable from that branch.

The `_scanpay_subid` branch is not limited to a payment-method change. WCS copies
custom subscription metadata onto renewal orders — `wcs_copy_order_meta()` via
`WC_Subscriptions_Data_Copier::copy()`, and `_scanpay_subid` is **not** in
`DEFAULT_EXCLUDED_META_KEYS` (`includes/class-wc-subscriptions-data-copier.php:20-45`)
— and the plugin writes it to the subscription at `class-wc-scanpay-sync.php:403`.
So a customer paying a failed renewal enters the same branch, and that renew
request carries the renewal order ID.

Without the routing arguments the bounded thank-you wait is never installed, so
WooCommerce can render the order-received page before the ping has written the
transaction ID, method title, and paid status — the same race the wait already
closes for `new_url()` payments. The wait would work here: sync sets the
transaction ID (`class-wc-scanpay-sync.php:318`) on the order named by the
payload's `orderid`, which is the renewal order (`generate-payment-link.php:79`).

### Fix

1. Distinguish the uses before returning. **There are three, not two** — a
   resubscribe order also carries `_scanpay_subid` (see Task 1 item 1) and enters
   this branch; state explicitly which treatment it gets rather than letting it
   fall through.

   - A pure WCS payment-method change updates the stored method and returns to
     the WCS-filtered My Account URL. It may create no order transaction and must
     not wait for one.
   - A customer-paid renewal creates payment data for that renewal and needs the
     same wait as an ordinary paid order.

   The discriminator already exists and is already used in this file:
   `WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment` is a
   public static set from `$_GET['change_payment_method']`
   (`class-wc-subscriptions-change-payment-gateway.php:83-87`), read at
   `generate-payment-link.php:29-31`. Reuse the existing
   `class_exists( …, false )` guard from `:29`; do not reference the class bare.

   **Task 1 lands first and already introduces this discriminator into this
   branch — reuse what it built, do not re-introduce it.** The two tasks
   otherwise touch disjoint points (18 adds routing arguments *before* `renew()`;
   1 persists intent *after* it returns), so this is the only coupling.

2. For a paid renewal, add the routing arguments to `$data['successurl']` before
   `renew()`: `scanpay_thankyou` identifying the renewal order and
   `scanpay_type=wc` selecting the paid-order wait. The WooCommerce order key is
   already present in the filtered URL, so the handler's ownership gate still
   applies. Do not invent a subscriber reference — `scanpay_ref` is read only by
   the `wcs_free` branch (`wp-scanpay-thankyou.php:165-179`); the `wc`/`wcs` wait
   never reads it.

3. Preserve the WCS-filtered success URL for a pure method change. No transaction
   wait, no fixed delay.

4. Reuse `wp-scanpay-thankyou.php` and its existing HPOS/legacy reads, order-key
   check, Scanpay method check, bounded backoff and give-up (`:132-133`), and the
   type dispatch at `:139-142`, which hooks the `wc` and `wcs` types. Add no second polling
   implementation, ping retry, or recovery mechanism.

5. Preserve the friendly exception handling around `renew()`, the subscriber ID,
   the payload's billing/shipping data, the link lifetime, and Task 1's
   `wcs_complete_renewal` autocapture/completion-intent persistence after a
   successful `renew()`.

6. Keep one-time payments, initial paid subscriptions, and free-trial parents on
   their existing `wc`, `wcs`, and `wcs_free` paths.

Note one pre-existing quirk to leave alone deliberately: in the change-payment
case `$data['orderid']` (`:79`) is the **subscription** ID, not an order ID.

### Verify

**Static**

- Confirm the branch at `:108` now distinguishes the two cases and that only the
  renewal path gains routing arguments.
- Confirm the success URL retains the order key.
- Confirm Task 1's completion metadata is still written after a successful
  customer-paid `renew()` and never for a method change.
- Confirm `wp-scanpay-thankyou.php` is unmodified apart from anything item 4
  genuinely requires.
- Read
  `.stubs/woocommerce-subscriptions/vendor/woocommerce/subscriptions-core/includes/class-wc-subscriptions-data-copier.php`
  to reconfirm `_scanpay_subid` is not in `DEFAULT_EXCLUDED_META_KEYS`
  (`:20-45`) — the whole premise rests on it.
- Confirm the `class_exists( …, false )` guard is present so a WCS-less site
  cannot fatal.

**Runtime (hand off)**

Pay a failed renewal and inspect the renew payload's success URL; delay the ping
and confirm the handler waits under both HPOS and legacy storage; return after
sync and confirm the first probe returns without sleeping; exercise a
payment-method change and confirm it keeps the My Account URL and does not wait;
exercise a transient `renew()` failure; re-test the three existing paths.

### Done when

Every customer-paid renewal that creates payment data installs the existing
authenticated wait, pure method changes keep their immediate return, and the fix
adds no retry or recovery state.

---

## Task 19: Verify shop ownership before charging a subscription renewal

### Problem

`WC_Scanpay_Capture` derives the shop ID from the API key, refuses to run with a
malformed key (`class-wc-scanpay-capture.php:22-25`), and throws on mismatch
(`:52-56`). The renewal charge path has no equivalent:
`WCS_Scanpay_Charge::__construct()` (`class-wcs-scanpay-charge.php:10-20`, the key
reaching the client at `:19`) accepts the key without deriving a shop ID, and
`scheduled_charge()` reads only `_scanpay_subid` (`:72`).

After a merchant switches shops, subscriptions still carry the old shop's
subscriber IDs. Reset drops `scanpay_subs`, so `idempotency_key()` initially
throws "subscriber does not exist" (`:52-55`) and the renewal is safely marked
failed. Once the new shop's sync repopulates the table, a numerically colliding
subscriber ID lets it find a revision and the charge goes to
`/v1/subscribers/<subid>/charge` under the new shop's credentials. The API path
carries no shop ID — the authenticated key resolves it. If subscriber IDs are
namespaced per shop, that is a real charge against a different customer's stored
card; if globally unique, the API rejects it. The plugin should not depend on an
undocumented backend property when capture already defends against this class of
mistake with a cheap local check.

### Fix

1. Derive the shop ID the same way capture does
   (`(int) strstr( $apikey, ':', true )`). If it is not a positive integer, mark
   the renewal failed with a clear "invalid API key configured" log line and order
   note before any API request, rather than an opaque 401.

   **Report the failure from `scheduled_charge()`, not the constructor.**
   `__construct()` has no order context — `$wco` first appears in
   `scheduled_charge( float, WC_Order )` — and the hook memoizes the handler in
   `static $handler` (`woocommerce-scanpay.php:286-293`), so a constructor throw
   is per-request, not per-renewal. Derivation may live in the constructor;
   reporting may not.

2. Read `_scanpay_shopid` next to the existing subid read and branch on **three**
   states:

   - **Present and matching:** proceed as today.
   - **Present and different (non-zero mismatch):** log an error naming both shop
     IDs, mark the renewal failed, contact no API. WCS owns retry scheduling.
   - **Absent or zero:** log a warning naming the subscription and **proceed with
     the charge**. Do not mark it failed.

3. The absent case must not fail loud, because it is reachable on legitimate
   1.x-migrated stores. Verified against current code:

   - `find_subs_from_ref()` (`class-wc-scanpay-sync.php:118-122`) returns `[]`
     unless the Scanpay `ref` starts with `wcs[]`. 1.x created subscribers with
     `'ref' => strval( $orderid )`, so a replayed 1.x-era subscriber matches no
     subscription and the `add_meta_data( WC_SCANPAY_URI_SHOPID, … )` calls at
     `:404` and `:422` never run.
   - The `scanpay_subs` upsert at `:382-387` runs **before** the
     `find_subs_from_ref()` call at `:395`, hence independently of that ref
     parsing (though `subscriber()` does return early at `:360-363` on a missing
     or empty `ref` — a 1.x ref is non-empty, so the conclusion holds). The
     idempotency key therefore resolves while the meta stamp is absent.
   - `upgrade.php:55-86` (the 2.1.3 block, which runs for 1.x sites) writes
     `WC_SCANPAY_URI_SUBID` at `:81` and never `WC_SCANPAY_URI_SHOPID` — grep
     confirms the string appears nowhere in that file.

   So a 1.x-migrated store can hold a chargeable subscription with a valid subid,
   a present `scanpay_subs` row, and no shopid stamp. Failing loud would stop
   those renewals with no merchant-visible cause. Document this at the check so it
   is not "tightened" later.

   Optional hardening if the warning proves noisy: read the shop ID from the
   subscription via `wcs_get_subscriptions_for_order()` instead of the copied
   renewal-order meta. That removes the dependency on WCS's copier and on copy
   timing, but costs a lookup per renewal — a follow-up, not part of this task.

   **A `shopid` column on `scanpay_subs` looks cheaper and does not work — do not
   re-propose it.** `idempotency_key()` already runs
   `SELECT rev FROM scanpay_subs WHERE subid = …`, so carrying a shop ID there
   would appear free and would sidestep the missing-meta asymmetry entirely. But
   it defends against nothing: after a shop switch the *new* shop's sync upserts
   that same `subid` row with its own shop ID, so a numerically colliding
   subscriber would present the current shop's ID and pass. The guard has to
   compare against what the **subscription** was created under, which only the
   order/subscription meta records. That is why this task reads meta despite the
   1.x gap, and why the check cannot be moved into the table.

4. Keep the guard read-only. No locks, retry state, key rotation, or coordination
   in `scanpay_subs`.

5. If the backend team confirms subscriber IDs are globally unique, keep the check
   anyway for symmetry with capture and record the confirmation in a comment.

Note the correct statement of the sync behavior: `subscriber()` stamps
`_scanpay_shopid` onto subscriptions **resolved from a `wcs[]` ref**, not onto
every synchronized subscription.

### Verify

**Static**

- Confirm the three-way branch exists and that the absent/zero case proceeds.
- Confirm no API call precedes the check on the mismatch path.
- Confirm the failure reporting is in `scheduled_charge()` where `$wco` exists.
- Re-derive the 1.x reachability argument from the four cited locations and state
  whether it still holds — if `find_subs_from_ref()` has changed, this task's
  central premise changes with it.
- Confirm the capture path is untouched, and that capture still throws on
  absent/zero shopid (`self::$shopid` is guaranteed positive by `:23-25`, so
  absent meta casting to `0` always trips `:53`) — the asymmetry is deliberate
  and must be recorded at the check.

**Runtime (hand off)**

Matching shop ID; a different shop ID (simulated switch and resync); no meta at
all — **the charge must still proceed**, this is the 1.x regression guard; a 1.x
fixture built explicitly (bare order-ID ref, subid from `upgrade.php`, a
`scanpay_subs` row, no shopid); shop ID `0`; an empty or malformed key.

**Unanswerable here:** whether Scanpay subscriber IDs are namespaced per shop.
That is a backend property — ask, do not assume.

### Done when

No renewal can be charged for a subscription recorded under a *different, known*
shop; no legitimate renewal is blocked by an absent stamp; the deliberate
asymmetry with capture is recorded at the check; and the charge path stays
lock-free.

---

## Task 20: Contain every renewal-charge failure at the Action Scheduler boundary

### Problem

The `catch ( \Throwable $e )` in `WCS_Scanpay_Charge::charge()`
(`class-wcs-scanpay-charge.php:214`) states its own purpose at `:215-218`: an
escaping Error or Exception "would otherwise escape to Action Scheduler and leave
the renewal neither charged nor marked failed."

Yet the payload build (`:120-142`), the per-item `wc_scanpay_addmoney()` summation
(`:164`), the autocapture decision (`:172-174`), and the `wc_scanpay_cmpmoney()`
comparison (`:176`) all run before the `try` at `:192`. Both money helpers throw
`InvalidArgumentException` on non-money input.

The containment is contractual, not structural. And it is weaker than "safe
because the caller pre-validates": `scheduled_charge()` validates the scheduled
amount and the order total, but the **per-line** values feeding `addmoney()` are
never pre-validated. `wc_format_decimal()` returns `''` for null/empty input
(`wc-formatting-functions.php:290-294`) and `get_line_total()` passes through the
`woocommerce_order_amount_line_total` filter, so a third-party filter returning
`null` throws outside the `try` today.

Widening `charge()`'s `try` does not achieve the goal, because **the escape
surface is the hook, not the method**. `wcs_scanpay_scheduled_charge()`
(`woocommerce-scanpay.php:286-293`) wraps nothing, so these still reach Action
Scheduler:

- The lazy `require` (`:289`) and `new WCS_Scanpay_Charge()` (`:290`).
- Everything in `scheduled_charge()` outside `charge()` — `wc_format_decimal()`,
  `get_total()`, the pre-guards, and the free-renewal `payment_complete()`.
- The three `update_status( 'failed', … )` calls in `scheduled_charge()`
  (`:75`, `:100`, `:105`) and the one inside `charge()`'s own catch (`:221`) —
  each a database write that can itself throw, escaping from within the handler
  meant to contain the failure.

### Fix

1. Add one prefixed no-throw renewal-failure reporter in
   `woocommerce-scanpay.php` (for example `wcs_scanpay_fail_renewal()`), available
   to both the hook and the charge class. It accepts the order, a raw diagnostic
   for `scanpay_log()`, and a separate translated merchant-facing status reason.

   - Attempt `update_status( 'failed', … )` once inside its own
     `try`/`catch`.
   - Emit one log entry after that attempt. If the status write threw, append
     that secondary diagnostic to the same entry rather than logging once before
     the write and again from an outer catch.
   - Contain a logger `Throwable` too. If WooCommerce's logger itself is broken,
     there is nowhere safer to report it; it still must not escape to Action
     Scheduler.
   - Never echo a raw backend/DB exception into the translated order reason.

   Route the existing invalid-subscriber, invalid-amount, mismatch, free-payment
   failure, charge failure, and Task 19 shop-ownership failures through this
   reporter. That removes the current pattern of "log, then call an unguarded
   `update_status()`".

2. Put the outermost `try { … } catch ( \Throwable $e )` in
   `wcs_scanpay_scheduled_charge()`, around the require, construction, and
   `scheduled_charge()` call. Its catch invokes the no-throw reporter once and
   returns normally. This contains every hook-level path by construction, with
   one honest exception: a **missing** `class-wcs-scanpay-charge.php` raises a
   fatal compile error, not a `Throwable`, which no `catch` can contain. That is
   out of scope — do not add machinery for it.

   **Change `require` to `require_once` at `:289`.** Today a constructor throw
   kills the request. Once the outer catch swallows it, the next Action Scheduler
   action *in the same request* re-enters the hook with `$handler` still null and
   re-requires the file — a fatal class redeclaration. Action Scheduler batches
   multiple actions per request, so this is reachable, and the outer catch is
   what makes it reachable.

3. Widen `charge()`'s `try` to the whole method body as defense in depth. Its
   catch invokes the same no-throw reporter and does **not** rethrow, so the
   hook-level catch cannot report the same failure a second time. Keep targeted
   context in the diagnostic passed to the reporter.

4. Reduce `charge()` to private — `scheduled_charge()` (`:108`) is its only
   caller repo-wide — and tighten its `object $wco` parameter (`:118`) to
   `WC_Order`, matching `scheduled_charge()` and the rest of the library.

5. Preserve `scheduled_charge()`'s specific pre-guards and targeted messages,
   but express their failure exits through the reporter from item 1.

   **Update the pre-guard comment at `class-wcs-scanpay-charge.php:94-97`.** It
   currently justifies the guard with "this runs outside `charge()`'s try, so a
   corrupt local total would otherwise escape to Action Scheduler" — which items
   2 and 3 make false. Task 10 relocates that comment verbatim, so if neither
   task touches it the plan ships a stale rationale. The widened
   catches are backstops, not replacements. With a functioning database and
   logger, every failure produces one failed-status transition and one log
   entry; if the status write fails, it produces no transition and one composite
   log entry, never a second transition attempt.

6. Do not change the success path, payload contents, idempotency-key construction,
   or the lock-free design.

7. Sequence against Task 10, which rewrites the same pre-guard region. The premise
   survives Task 10 either way — Task 10 *moves* the pre-guards above the
   zero-return rather than removing them, so `charge()`'s containment stays
   contractual, and the per-line gap is untouched by it.

### Verify

**Static**

- Enumerate every statement in the hook function and in `scheduled_charge()` and
  show each is now inside a handler. This is the substance of the task and is
  fully checkable by reading.
- Confirm the reporter itself cannot throw, each failure path calls it at most
  once, and no path can reach two `update_status()` attempts or two log calls.
- Confirm every status write is inside the reporter's own catch.
- Confirm `require_once` replaced `require`.
- Confirm `charge()` is private and typed `WC_Order`, and grep the repo to
  reconfirm no external caller. Note `src/callback/wc-scanpay-ping.php:307` is
  `$sync->charge()` — a different class — so do not misread it as a second
  caller.

**Runtime (hand off)**

Drive each newly covered path through the **hook**, not the method: make the
require/constructor throw, `get_total()` throw, the free-renewal
`payment_complete()` throw, and `update_status( 'failed', … )` itself throw — in
every case the hook must return normally and Action Scheduler must see no
Throwable. Confirm the status-write failure yields one composite log rather than
a second reporting pass. Then run a normal successful renewal and a normal
declined renewal, with one transition attempt and one log entry per failure.

### Done when

No Throwable can escape `wcs_scanpay_scheduled_charge()` from any path — hook
body, `scheduled_charge()`, `charge()`, or a failing status write inside a catch;
every failure marks the renewal failed where WCS can see it, or logs why it could
not; no failure is reported twice; and the containment no longer depends on
callers pre-validating amounts.
