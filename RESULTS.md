# PHP review — run 3, task 20

A review of `src/` as tasks 1–19 leave it, at commit `71d2367`. **No code was
changed by this task**; the deliverable is this file.

Six findings, most severe first. Each is marked **Confirmed** (traced to a
reachable failure) or **Plausible** (the mechanism is real, reachability
unproven here). Two lists close the document: what was examined and found sound,
and what could not be settled without a running shop.

Everything in `AGENTS.md` § *Settled*, `PLAN.md`'s *Verified sound* list,
`docs/performance-review.md` §5–§6, `docs/ts-review.md` §4, `docs/scss-review.md`
§4, `HANDOFF.md`'s run-1 and run-2 open items and `HANDOFF-2.md` was read first
and treated as out of scope. Per finding, the documents each was checked against
are named.

---

## 1. Bulk "Capture and complete" has no time budget, and a kill mid-loop leaves a charged order uncompleted

**File / symbol:** `src/admin/hooks/wp-bulk-actions.php`,
`wc_scanpay_handle_bulk_capture()`
**Verdict: Plausible** — the mechanism is fully traced; the order count needed to
trigger it depends on the shop.

```php
$changed = 0;
foreach ( $oids as $oid ) {
    $wco = wc_get_order( $oid );
    …
    if ( $capture && ! WC_Scanpay_Capture::capture_or_hold( $wco ) ) {
        continue; // Parked 'on-hold' with a note; do not complete.
    }
    $wco->set_status( 'completed', __( 'Order status changed by bulk edit.', … ), true );
    $wco->save();
    do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
    ++$changed;
}
```

The loop is unbounded in `$oids`, which is merchant-supplied (the Orders screen's
checkbox selection, whose page size is a Screen Options setting), and every
Scanpay order in it performs a network capture — `WC_Scanpay_Client::capture()`
is `request( "/v1/transactions/$trnid/capture", $data, [], 20 )`
(`class-wc-scanpay-client.php:191-193`), a 20 s client-side budget each — plus a
full `WC_Order` build, a `save()`, and the transactional-email and stock hooks
that `set_status( …, true )` fires.

**Nothing in this file calls `set_time_limit()`.** That is the asymmetry:
`grep -rn "set_time_limit" src/` returns four call sites — `upgrade.php:9` and
`:155`, `wc-scanpay-ping.php:20` and `:329`, `wp-scanpay-fetch-meta.php:43`,
`wp-scanpay-fetch-sub.php:43` — every one of them a loop the tree already
recognised as able to outlast its grant. This loop is the same shape and has no
grant management at all.

**Failure scenario.** A merchant selects several hundred orders and runs "Capture
and complete". PHP's execution timer expires mid-loop. Where it lands decides the
damage:

- Between `WC_Scanpay_Client::capture()` returning and `save()` completing, the
  customer **has been charged** and the order keeps its previous status. The
  per-request `WC_Scanpay_Capture::$processed` memo dies with the worker, so
  nothing records the attempt.
- The `return add_query_arg( … 'changed' => $changed … )` at the end never runs,
  so the merchant gets a fatal instead of the "N orders updated" notice and has
  no way to tell how far the run got.
- Orders after the kill point are untouched, which is fine.

The money is not double-charged on a retry: `capture()`'s remaining-amount guard
(`if ( wc_scanpay_cmpmoney( $to_capture, '0' ) <= 0 ) { … return; }`) makes a
second attempt a no-op. The harm is a charged order left uncompleted and a
merchant with no record of it.

**How it was verified.** `capture()`'s 20 s timeout read at
`class-wc-scanpay-client.php:191-193`; the four existing `set_time_limit()` sites
enumerated by grep; `capture_or_hold()` traced to confirm the memo is a
`private static` per-request array. **One honest qualification, settled with
`php -r`:** PHP's execution timer does not count time blocked in a syscall on
Linux — `php -d max_execution_time=2 -r 'sleep(5);'` survives, while the same
limit kills a busy loop at 2 s. So the 20 s curl waits largely do *not* consume
the grant, and what does is the PHP-side work: building and saving N orders and
running their hooks. That materially lowers the probability versus a naive
wall-clock reading, and is why this is Plausible rather than Confirmed.

**Proposed fix.** Renew the grant inside the loop exactly as task 5 did for the
drain and task 8 for the migration — track the last renewal and call
`set_time_limit( 60 )` when ~30 s has passed — so the handler cannot be killed
between charging a customer and recording it. `set_time_limit()` resets the
counter rather than adding to it, so renewing costs nothing. Worth considering
alongside it: move `$changed` and the id list into a transient before the loop,
so an interrupted run still tells the merchant where it stopped. That second half
is a design decision, not a defect fix.

**Checked against:** `docs/performance-review.md` (§4 covers admin cost but not
this handler; §5 lists no simplification here), `AGENTS.md` § *Settled*,
`HANDOFF.md` run 2 task N (which changed this file's bulk-action *registration*,
not its loop). Not previously reported.

---

## 2. A network uninstall has no time budget, and an interrupted one leaves live API keys behind

**File / symbol:** `src/uninstall.php`, the multisite `do { … } while` loop
**Verdict: Plausible** — the mechanism and the no-resume property are confirmed;
the blog count needed depends on the network.

```php
$wcsp_page   = 100;
$wcsp_offset = 0;
do {
    $wcsp_blogs = get_sites( [ 'fields' => 'ids', 'number' => $wcsp_page, 'offset' => $wcsp_offset, … ] );
    $wcsp_found = count( $wcsp_blogs );
    foreach ( $wcsp_blogs as $wcsp_blog ) {
        switch_to_blog( (int) $wcsp_blog );
        try {
            wc_scanpay_uninstall_blog();
        } finally {
            restore_current_blog();
        }
    }
    $wcsp_offset += $wcsp_page;
} while ( $wcsp_found === $wcsp_page );
```

Per blog this runs six `DROP TABLE IF EXISTS`, four `delete_option()` and one
`delete_transient()`, wrapped in a `switch_to_blog()` / `restore_current_blog()`
pair. The file calls `set_time_limit()` nowhere.

**Failure scenario.** On a network with enough blogs, the request is killed
part-way through the loop. Every blog after the kill point keeps
`woocommerce_scanpay_settings` — **which holds a live Scanpay API key** — plus
its `scanpay_meta` / `scanpay_seq` / `scanpay_subs` tables. That is precisely the
outcome the file's own comment says the pagination exists to prevent: *"a single
unbounded call would leave site 101 onwards holding live credentials — the same
silent truncation this loop exists to close."* Paging fixed the `get_sites()`
truncation and left the time truncation open.

**And it does not resume.** WordPress removes the plugin from the
`uninstall_plugins` option *before* including the file —
`wp-admin/includes/plugin.php:1317-1327`:

```php
if ( isset( $uninstallable_plugins[ $file ] ) ) {
    unset( $uninstallable_plugins[ $file ] );
    update_option( 'uninstall_plugins', $uninstallable_plugins );
}
…
include_once WP_PLUGIN_DIR . '/' . dirname( $file ) . '/uninstall.php';
```

There is no batching, no progress marker and no retry on that path. Once the
plugin files are deleted the merchant has no supported way to re-run it, and the
credentials on the untouched blogs are not discoverable from the admin UI.

**How it was verified.** `uninstall_plugin()` read in
`.stubs/wordpress/wp-admin/includes/plugin.php:1302-1330`; the absence of
`set_time_limit()` confirmed by grep over `src/`; the offset paging confirmed
safe in itself (the loop deletes options and tables, never sites, so the
`get_sites()` result set cannot shrink underneath it). The same syscall-timing
qualification as finding 1 applies: `DROP TABLE` blocks in a syscall and largely
does not consume the grant, while `switch_to_blog()` — which resets caches and
fires hooks per blog — does.

**Proposed fix.** Renew the grant inside the per-blog loop, the same way the ping
drain and the 2.1.3 migration now do. Given that the failure mode is *credentials
left readable* rather than slowness, the cheaper half is also worth doing first:
clear the three settings options before dropping that blog's tables, so an
interrupted blog loses its key even if its tables survive.

**Checked against:** `AGENTS.md` § *Settled* (the API-key write-once entry
concerns the settings form, not uninstall), `docs/performance-review.md` (does
not cover uninstall), `HANDOFF.md` run 2 task H (multisite, but about *install*
misdetecting a fresh blog). Not previously reported.

---

## 3. `scheduled_charge()` conflates an absent API key with a malformed one

**File / symbol:** `src/library/class-wcs-scanpay-charge.php`,
`WCS_Scanpay_Charge::scheduled_charge()` (`:86-94`)
**Verdict: Confirmed**

```php
if ( $this->shopid <= 0 ) {
    // Caught locally so the merchant reads a cause, not an opaque 401 from the API.
    wcs_scanpay_fail_renewal(
        $wco,
        "scheduled charge: invalid API key configured; cannot charge #$oid (subid=$subid)",
        __( 'Invalid Scanpay API key configured.', 'scanpay-for-woocommerce' )
    );
    return;
}
```

`$this->shopid` is `(int) strstr( $apikey, ':', true )` (`:24`), which is `0` for
an **empty** key just as it is for a malformed one. This is the exact twin of the
defect task 3 fixed in `WC_Scanpay_Capture::init()`, on the renewal path instead
of the capture path.

**Failure scenario.** A merchant uses the reset button — which unsets `apikey`
and `secret` and leaves everything else (`wp-ajax-wc-scanpay-reset.php:118-140`)
— intending to move to a new Scanpay account. Every scheduled renewal that fires
before the new key is entered is marked `failed` with the order note "Der er
konfigureret en ugyldig Scanpay API-nøgle" / "Invalid Scanpay API key
configured.", and WCS suspends the subscription on that transition
(`subscriptions-core/includes/class-wc-subscriptions-renewal-order.php:142-144`).
The merchant is told their key is broken moments after they deliberately removed
it, once per renewal, on subscriptions that are now in dunning.

**How it was verified.** The reset endpoint's settings loop re-read to confirm
`apikey` is unset while `wc_autocapture` and everything else survives; the
`payment_failed()` transition quoted from `.stubs/`; `strstr( '', ':', true )`
returns `false` and `(int) false === 0`, so the empty case genuinely lands in
this branch.

**Proposed fix.** Split the guard the way task 3 split `init()`'s: an explicit
`'' === $apikey` branch first, with wording that says no account is configured
rather than that the key is invalid, and the existing branch for a genuinely
malformed key. The renewal must still fail either way — a renewal that cannot be
charged must not read as paid. Note the one difference from task 3: this message
**is** a msgid, so the split adds a translatable string and belongs with a
catalog regeneration.

**Checked against:** `AGENTS.md` § *Settled* (the reset entry bounds what may be
cleared, not what may be reported), `PLAN.md`'s "Reset must not touch operating
settings", `HANDOFF-2.md` task 3 — where this was recorded as an explicit
observation for this review rather than fixed, because task 3's file was
`class-wc-scanpay-capture.php`.

---

## 4. The containment catches added in tasks 1 and 4 report through an unguarded `scanpay_log()`

**File / symbol:** `src/library/class-wc-scanpay-capture.php`, `capture()`
(`:133-135`); the same shape at `WC_Scanpay_Sync::sync()`,
`WC_Scanpay_Sync::report_incomplete()` and `wc_scanpay_process_payment()`
**Verdict: Confirmed** as a mechanism; requires two independent third-party
throws, so it is narrow.

```php
} catch ( \Throwable $note_error ) {
    scanpay_log( 'error', "Could not add the capture note to order #$oid: " . $note_error->getMessage() );
}
```

Task 1 contained the capture note so a throw from `add_order_note()` could not be
read as a capture failure. The catch body itself calls `scanpay_log()`, which is
not throw-free: it reaches `WC_Logger::log()`, whose class comes from the
`woocommerce_logging_class` filter (`wc-core-functions.php:1989`), whose handlers
come from `woocommerce_register_log_handlers` (`class-wc-logger.php:67`), and
whose every message passes `woocommerce_logger_log_message` (`:186`) before
`$handler->handle()` (`:188`). Neither `WC_Logger::log()` nor `scanpay_log()`
catches.

**Failure scenario.** A site has both a third-party note hook that throws *and* a
log handler that throws. The note throws, the catch runs, the log throws, and the
`Throwable` escapes into `capture_or_hold()`'s catch — restoring exactly the
outcome task 1 removed: a paid capture memoised as failed, the order demoted to
`on-hold`, and `false` returned to the caller. The same holds for the three
sibling sites, whose catches have always logged unguarded.

**Why it was not fixed in task 1.** `PLAN.md` prescribed that block verbatim, and
all three sibling sites log unguarded inside their catches, so changing one alone
would have broken the idiom task 1 was told to match. Recorded here instead, per
the plan's "if the *code* looks wrong, that is task 20's finding".

**How it was verified.** The three filters read in `.stubs/woocommerce/`;
`scanpay_log()` read at `woocommerce-scanpay.php:43-52` and confirmed to contain
no `try`. Note that `wcs_scanpay_fail_renewal()` (`:368-374`) *does* guard its own
log call — so the tree already accepts this mechanism as real, which is the
strongest argument that the four catches should too.

**Proposed fix.** One helper — a `wc_scanpay_log_safe()` in `library/functions.php`
that wraps `scanpay_log()` in a `try`/`catch ( \Throwable ) { return; }` — used by
every catch whose only job is to report. That is a smaller change than four
nested `try` blocks and keeps the idiom uniform. It is a genuine design choice
(the alternative is to accept the residual, on the grounds that a shop with two
throwing third-party hooks has larger problems), which is why it is a proposal
and not a patch.

**Checked against:** `AGENTS.md` § *Fail loud* and § *Settled*,
`docs/performance-review.md` §6, `HANDOFF-2.md` tasks 1 and 4 — where this was
recorded as a residual at the time.

---

## 5. The 2.1.3 migration's subid log line has an unbalanced parenthesis

**File / symbol:** `src/upgrade.php:175`
**Verdict: Confirmed** — cosmetic.

```php
scanpay_log( 'info', "change subid on #$oid (from '$subid' to '$black_subid'" );
```

The opening parenthesis is never closed, so the only record a merchant or support
case has of a 1.x subscriber-id migration reads `change subid on #1234 (from '0'
to '5678'`. Harmless to behaviour; it makes a log line that exists for forensics
look truncated, which is exactly when someone doubts whether the migration
finished.

**How it was verified.** Read directly; the string is a log message, not a msgid
(`grep` over both catalogs returns nothing), so no catalog work follows.

**Proposed fix.** Add the closing parenthesis. It belongs in whatever commit next
touches that branch rather than one of its own — task 8 deliberately left it so
its commit stayed inside its stated scope.

**Checked against:** `docs/ts-review.md` (log strings from `.ts` are §3, this is
PHP), task 18's comment audit (it audits comments, not log strings), task 19's
i18n audit (untranslated log lines are out of its scope by design).

---

## 6. The generated `.pot` still declares no `Plural-Forms`

**File / symbol:** `src/languages/scanpay-for-woocommerce.pot` header
**Verdict: Confirmed** — the Danish half is now closed; the generated half is not.

Two catalog entries carry plural translations — `<b>Synchronized:</b> %d second
ago.` and `More than %d minute has passed…` — while `wp i18n make-pot` emits no
`Plural-Forms:` header. Task 19 added
`Plural-Forms: nplurals=2; plural=(n != 1);` to the **`.po`**, which is
hand-maintained and survives a rebuild byte-identically, so
`msgfmt --check` now passes on the Danish catalog. The `.pot` is generated and
must not be hand-edited, so it still fails the same check, and any *new* locale
derived from it starts without the header.

**How it was verified.** `msgfmt --check` run before and after the task 19 change;
`./build.sh` re-run afterwards and both catalogs diffed byte-for-byte to confirm
the header persists and the `.pot` is unchanged.

**Proposed fix.** Pass the header through `wp i18n make-pot`'s `--headers`
argument in `build.sh`, alongside the headers it already sets, so the `.pot` and
every locale generated from it carry it. This is the second of the two options
`HANDOFF.md`'s run-2 close already laid out; it is listed here because run 2
recorded it as open and run 3 closed only half of it.

**Checked against:** `AGENTS.md` § *Conventions* (build.sh owns extraction),
`HANDOFF.md` run 2 "Open items carried out of this run" item 8 — **this is that
item, not a new discovery.**

---

## Examined and found sound

Read and specifically probed, with nothing to report:

- **Money arithmetic.** `library/math.php` in full. `PLAN.md` marks it verified
  sound and `docs/performance-review.md` §6.2 settles its internals, so the
  algorithms were not re-derived — but the capture arithmetic that consumes them
  was re-traced end to end, including the case that looked wrong at first: a
  refund recorded both in WooCommerce and at Scanpay is subtracted from
  `$amount` *and* from `$net_captured`, and the two cancel, leaving
  `total − captured`. The authorization cap
  (`$remaining_on_auth = authorized − captured`, gross not net) is deliberate and
  documented at the line.
- **Sync writes no WooCommerce refund objects** — `grep` for `wc_create_refund` /
  `WC_Order_Refund` over `src/` returns one comment and no constructor — so the
  `get_refunds()` loop is normally empty and refunds stay dashboard-only, as
  `can_refund_order()` promises.
- **The ping/sync loop**, including the flock, the busy handoff, the
  release-and-recheck, and the cursor-persist guard. `PLAN.md` marks ping/sync
  and the flock verified sound; the drain's time budget was the subject of task 5
  and is now elapsed-time based.
- **The three admin AJAX endpoints and their authentication.** The shared-secret
  comparison is `hash_equals()` against a header in all three, `'' === $secret`
  fails closed, and `wp-scanpay-fetch-ping.php`'s hand-rolled `die()`s are
  load-bearing (it never calls `wp_send_json()`). The secret's scope is settled
  in `AGENTS.md` and was not reopened.
- **Reset.** `wp-ajax-wc-scanpay-reset.php` verifies its own postconditions —
  schema shape per table, emptiness per table, the key cleared, the secret
  rotated — and holds the old shop's flock across the whole sequence. The
  hardcoded `$wcsp_schema` is a documented coupling, not a defect.
- **Install and upgrade**, including the fresh-multisite-blog discriminator
  (absent *settings*, not absent version) that run 2 task H established. The
  2.1.3 branch is task 8's and is now batched, time-renewed and idempotent.
- **The payment-return page.** Both waits gate on the order key with
  `hash_equals()` *and* a non-empty stored key before sleeping, the free-trial
  branch additionally requires the subscription's `parent_order_id` to match the
  key-verified order, and both reads use `$wpdb->prepare()`.
- **Gateway lifecycle and settings persistence.** The write-once key, the
  masked-once-set rendering, `validate_apikey_field()` as the real gate, and the
  force-disable path that keeps the entered settings. All settled in `AGENTS.md`.
- **Blocks and classic checkout**, and the subscription-terms consent: one
  predicate (`wcs_scanpay_terms_url()`) drives rendering and validation in both,
  the Blocks payload is built outside the `enabled` gate on purpose, and the
  hidden `wcssp-terms-field` marker distinguishes "not rendered" from "unticked".
- **Error-handling boundaries.** After tasks 1, 4 and 6 every path that moves
  money contains its own reporting, and the one residual is finding 4.

**Files read.** All 35. Read cover to cover during this run: `library/math.php`,
`library/functions.php`, `library/schema.php`, `library/class-scanpay-flock.php`,
`library/class-wc-scanpay-capture.php`, `uninstall.php`,
`admin/hooks/wp-bulk-actions.php`, `admin/hooks/wp-ajax-wc-scanpay-capture.php`,
`admin/ajax/wp-scanpay-fetch-{meta,ping,sub}.php`,
`admin/settings/admin-options.php`, `admin/settings/fields/scanpay.php`,
`admin/settings/fields/scanpay_mobilepay.php`, `admin/subscriptions.php`,
`gateways/class-wc-gateway-scanpay-applepay.php`,
`gateways/blocks/class-wc-scanpay-blocks-support.php`,
`public/wcs-scanpay-checkout-terms.php`, `public/wp-scanpay-thankyou.php`,
`upgrade.php`. Read in substantial part across tasks 1–19, in every region these
findings or the surrounding logic depend on, rather than start-to-finish in this
task alone: `woocommerce-scanpay.php`, `library/class-wc-scanpay-sync.php`,
`library/class-wcs-scanpay-charge.php`, `library/class-wc-scanpay-client.php`,
`callback/wc-scanpay-ping.php`, `gateways/abstract-wc-gateway-scanpay-base.php`,
`gateways/class-wc-gateway-scanpay-card.php`,
`gateways/class-wc-gateway-scanpay-mobilepay.php`,
`admin/hooks/wp-ajax-wc-mark-order-status.php`,
`admin/hooks/wp-ajax-wc-scanpay-reset.php`, `admin/orders.php`,
`admin/settings.php`, `admin/settings/fields/scanpay_applepay.php`,
`install.php`, `public/generate-payment-link.php`. **This distinction is stated
rather than glossed**: the second list was read thoroughly but not re-read
end-to-end inside task 20, so a defect in a region none of tasks 1–19 touched
could have been missed there.

## Could not be settled statically, and why

1. **Both time-budget findings (1 and 2) need a shop to become Confirmed.** The
   mechanism is proven; what is not is how many orders or blogs it takes on real
   hardware. `php -r` established that syscall wait does not consume PHP's
   execution timer on Linux, which lowers the estimate but does not remove the
   risk, and the PHP-side cost per order or per blog cannot be measured here.
2. **Everything behind the Scanpay API.** `/v1/new` with a `subscriber.ref` and
   no `items`, `/v1/subscribers/{subid}/renew` charging nothing, and the
   `Idempotency-Key` 24 h binding are backend facts settled with Scanpay and
   derivable from no stub.
3. **Anything below the declared floors.** The stubs are WC 11.1.0-dev, WCS 7.2.1
   and WP 7.1-alpha, while the plugin supports WC 3.6 / WP 6.3. Comments that
   claim an argument or API "predates the 3.6 minimum" cannot be checked here;
   they are listed individually in `HANDOFF-2.md` under task 18.
4. **Two open questions carried from run 2, unchanged and not re-derived:**
   `renew()` charges nothing (task T told the customer the truth; making "Pay
   now" actually charge is blocked on a question for Scanpay), and
   `report_incomplete()`'s "nothing will retry the completion" is usually false.
   Both are product decisions recorded in `HANDOFF.md`; neither is restated as a
   new finding here.
5. **Run 2's open item 3** — `WC_Scanpay_Sync::sync()`'s bare `$wco->save()` in
   the not-eligible branch can throw out of a path documented as non-throwing.
   Still true, still the same class of decision as item 4 above, and deliberately
   left rather than re-reported.

---

*No pull request opened, nothing fixed, and no finding added to `PLAN.md`.*
