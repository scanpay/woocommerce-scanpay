# PHP review follow-up plan — run 3

<!-- markdownlint-disable MD024 -->

Twenty tasks, **1** through **20**, in execution order. One commit each, titled
`<summary> (task N)`. No `scanpay:` prefix.

Tasks 1–17 come from a full read of all 35 PHP files in `src/` (~5 600 lines) with
`pnpm phpcs` clean, so each is a defect or a drift no sniff can see. Tasks 18–20
are standing sweeps and must run last, in order, against the tree the first 17
leave behind. Order is by file, then by severity within a file; tasks 1–5 are live
defects, so stopping early costs least there.

## Run protocol

Unattended, one agent, one pass. Implement every task yourself, in order. Do not
parallelize, do not delegate implementation, do not start a task before the
previous is committed, never two tasks in one commit. Read-only search agents are
fine.

Per task:

1. Read `AGENTS.md`, then `HANDOFF-2.md` (an earlier task in this run may have
   blocked this one), then this file's header — everything above `## Tasks` — and
   `## Task N`. Skip the other task sections; they are not your commit. Runs 1–2
   live in `HANDOFF.md`; only task 20 needs it, so do not read it otherwise.
2. Confirm a clean tree and that the previous task's commit is `HEAD`. For task 1:
   `git log -1 --oneline -- PLAN.md`.
3. Implement, in `src/` only. `build/` is generated.
4. `pnpm phpcs` (autofix `pnpm phpcbf`) and
   `find src -type f -name '*.php' -print0 | sort -z | xargs -0 -n1 php -l`; add
   `pnpm lint:js` and `pnpm exec tsc` if any `.ts` changed, `pnpm lint:style` if
   any `.scss` changed. All clean as written — any diagnostic is yours.
5. Do the **Verify** bullets; write them and the **Handoff** list to `HANDOFF-2.md`
   (gitignored, never `git add`). Append; head each entry
   `## Run 3 · Task N — <summary>`.
6. Delete the task's section from this file, and its row from the task table.
7. `git add -A` and commit. Keep verification status out of the message.
8. Next task in a fresh context (new session or `/clear`), from step 1.

When task 20 lands, this file is its header alone. Never push, never open a PR.

**Never run `./build.sh` bare** — it is `set -e` and prompts for a deploy, so EOF
looks like a failed build. Use `printf 'n\n' | ./build.sh`. Answering `y` rsyncs
to a live server.

**Blocked** = the source contradicts a task's premise in a way that changes the
fix, or step 4 cannot come clean. Do not improvise a different design, do not
commit a partial task: restore the tree, record it in `HANDOFF-2.md` under
`## Blocked: task N`, leave the section, move to the next independent task.
"Hard" is not "blocked".

## Locating code

Every task cites an **Anchor**: a literal string unique in the file. Grep for it.
Line numbers appear only as a hint and drift as earlier tasks land — when a number
and the source disagree, the source wins and the anchor is the address.

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

### Facts already checked — re-read the citation, do not re-derive the conclusion

| Task | Fact | Where |
| --- | --- | --- |
| 1, 4 | The tree already treats "a third party's hook can throw out of `add_order_note()`" as live in three places, each naming `woocommerce_new_order_note_data`, `wp_insert_comment`, `woocommerce_order_note_added` | `WC_Scanpay_Sync::sync()`, `WC_Scanpay_Sync::report_incomplete()`, `wc_scanpay_process_payment()` |
| 1, 4 | Exactly four `add_order_note()` call sites exist; the three above are contained, `WC_Scanpay_Capture::capture()` is not | `grep -rn add_order_note src/` |
| 4 | The tree already treats "`scanpay_log()` can throw" as live: `wcs_scanpay_fail_renewal()` wraps its own log call, and its catch body is `return;` | `woocommerce-scanpay.php`, `wcs_scanpay_fail_renewal()` |
| 4 | **A catch block holding only a comment fails `pnpm phpcs`**: `Generic.CodeAnalysis.EmptyStatement.DetectedCatch`, "Empty CATCH statement detected". Confirmed by running it | `phpcs -s` on a probe file |
| 2 | `WC_Order_Refund::get_amount( $context = 'view' )` is a plain `get_prop()`, so the context argument is honoured | `includes/class-wc-order-refund.php:95` |
| 2, 6 | `WC_Data::get_prop()` applies `{hook_prefix}{prop}` **only** in the `view` context | `abstracts/abstract-wc-data.php`, `get_prop()` |
| 6 | `WC_Abstract_Order::get_status()` substitutes `apply_filters( 'woocommerce_default_order_status', OrderStatus::PENDING )` for an empty status **in `view` only** | `abstracts/abstract-wc-order.php:482-494` |
| 16 | `WC_Subscription extends WC_Order extends WC_Abstract_Order`; `WC_Order_Refund extends WC_Abstract_Order` directly | the three class files in `.stubs/` |
| 13 | `get_tooltip_html()` takes `$tip` from `$data['description']` when `desc_tip === true` and returns `''` when empty — `desc_tip` with no `description` renders nothing | `abstracts/abstract-wc-settings-api.php` |
| 13 | `generate_checkbox_html()` echoes `$data['title']` into `<th scope="row">` unconditionally — no title means an empty header cell | `abstracts/abstract-wc-settings-api.php` |
| 3 | `wp-settings.php` loads plugins at `:579` and `pluggable.php` at `:610`, so `current_user_can()` does not exist at router dispatch | `.stubs/wordpress/wp-settings.php` |
| 11, 19 | wp-cli is `./vendor/bin/wp` (2.12.0, a `require-dev` package) and `build.sh` calls it there; header substitution touches `{{ … }}` placeholders only | `build.sh` |
| 12 | `WC_Payment_Gateways::init()` runs `$gateway = new $gateway();` for every class the `woocommerce_payment_gateways` filter returns — no `enabled` test in that loop, so every gateway constructor runs on every request | `includes/class-wc-payment-gateways.php`, `init()` |
| 17 | `WC_Scanpay_Sync::subscriber()` throws on `! is_int( $rev ) \|\| $rev <= 0` before building its INSERT, so a stored `scanpay_subs.rev` is always ≥ 1 | `class-wc-scanpay-sync.php`, ~`:427-430` |
| — | `empty( $x->prop )` on a null `$x` emits no diagnostic — `empty()`/`isset()` suppress "Attempt to read property on null" | `php -r 'error_reporting(E_ALL); $a=null; var_dump(empty($a->b));'` |

## Do not weaken

Money-string arithmetic (`src/library/math.php`), the ping protocol, the sync
flock, the API-key write-once policy, the lock-free charge design.

Standing decisions. No task reopens any:

- **The stylesheets are out of scope, and `docs/scss-review.md` owns them.** No task
  edits a `.scss` file. Its §4 settled that `checkout.css` deliberately reaches
  MobilePay-only shops — which is what rewrote task 12 — and its §1.1 establishes
  that the stylesheet sizes all three gateways' icons through the shared
  `.wcsp-methods` wrapper. Anything about icon sizing, `:has()`, `!important` or the
  duplicated warning palette belongs there, not in a finding here.
- **The TypeScript layer is out of scope, and `docs/ts-review.md` owns it.** No task
  edits a `.ts` file. Its **§4 is settled** — the same standing as
  `performance-review.md` §5–§6 — and two of its entries bound tasks here: the
  keep-alive `"\n"` written before `wp_send_json()` in both `?x=` endpoints
  (`JSON.parse` skips leading whitespace; bounds task 17) and `subs.ts`'s `rev=0`
  reasoning, which establishes that `scanpay_subs.rev` is always ≥ 1 (bounds task
  17 item 4). Neither is reopened.
  Its **§1–§3 are open findings, not settled ones**, and several name PHP files as
  the fix site (`subscriptions.php`'s meta-box shell, `orders.php`'s inline script,
  the shared `'I accept the %s.'` msgid). Task 20 may legitimately reach the same
  conclusions from the PHP side — but it must say which of its findings already
  appear there rather than presenting them as new, and it must not propose a `.ts`
  change as the fix.
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
- **The tree's non-default hook priorities have been assessed; only two are
  load-bearing** — `woocommerce_order_status_completed` at 5 and `admin_menu` at
  999, which tasks 10 and 9 comment. The rest are defensive or inert and are
  deliberately left bare. Do not comment or change them.

**Verified sound, do not re-audit:** ping/sync, `math.php`, the flock, client TLS,
capture money math, secret auth. This list used to live in `AGENTS.md` and was
trimmed out of it in commit `685a78e`; it is kept here because task 20 is the run
that would otherwise re-derive all six.

## Tasks

| # | Focus | File |
| --- | --- | --- |
| 18 | Comment audit against the documented standard | all 35 PHP files |
| 19 | i18n audit, English source and Danish catalog | `src/languages/`, every `__()` site |
| 20 | Fresh full review → `RESULTS.md` | all 35 PHP files |

Files opened by more than one task: `class-wc-scanpay-capture.php` (1, 2, 3),
`class-wc-scanpay-sync.php` (6, 7), `woocommerce-scanpay.php` (9, 10, 11),
`class-wcs-scanpay-charge.php` (4, then 15's call site),
`generate-payment-link.php` (16, and 15's call site).

---

## Task 18 — Comment audit against the documented standard

**Files:** all 35 PHP files in `src/`

Comments are about 37 % of the tree — roughly 2 100 lines of ~5 600. Both figures
drift with every commit; neither is a checksum. `AGENTS.md` makes them
load-bearing: "Comments are the compensation, and what verification runs on: a
change is checked against the invariants they state, so a comment that has drifted
is a broken test."
This task audits them against the **Comments** section of `AGENTS.md`, which is the
specification — read it first and apply it, not this summary.

Go file by file in `find src -name '*.php' | sort` order. One commit.

**What to change.** Apply `AGENTS.md`'s Comments section as written — restatement,
change logs and `@param`/`@return` ceremony go; file-header, inline and length
rules hold. Three points it does not cover:

- **Drift** is the priority: a comment that no longer describes the code, cites a
  symbol that moved or was renamed, or states an invariant the code no longer
  holds. Fix the comment to match the code. If the *code* looks wrong instead, that
  is a finding for task 20, not a fix here.
- **`phpcs:ignore` without `-- <reason>`.** 32 of the tree's 44 lack one. Add the
  real reason, derived from the code, not "suppress sniff". Where you cannot state
  one, the suppression is the finding: record it for task 20 rather than inventing
  a justification. The 5 `phpcs:disable` are outside the guide's literal wording
  and 4 carry no `--`, but three of those (the `?x=` endpoints') are already
  explained by the file docblock directly above them — do not duplicate that into
  an inline reason; `wp-scanpay-thankyou.php`'s is the one genuinely bare.
- **Keep `@return array{…}`** where the shape is not obvious, `@throws` for what a
  caller must catch, `@internal` outside a module's surface.

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
the built tree on every run, deterministically, through `./vendor/bin/wp i18n`.
**Never hand-edit `scanpay-for-woocommerce.pot`**: it is generated, and an edit is
lost on the next build. `.po` msgstrs are hand-written and are what this task
changes.

Audit three things, in this order.

**1. English source strings.** They are the msgids, so changing one orphans its
translation — do it only where the string is wrong, not merely improvable. Check:
they read as a sentence to a merchant or customer; placeholders are positional
(`%1$s`) wherever there is more than one; every placeholder has a
`/* translators: … */` comment above the call naming each one; and no string
concatenates a translated fragment with another, which cannot be translated
correctly.

**2. Danish translations.** Full orthography — æ, ø, å, never an ASCII
substitution. One agreed WooCommerce term each for order, subscription, payment,
capture and refund, used throughout. Placeholders preserved exactly, including
positional numbers, which may legitimately reorder in Danish. Register matched to
the audience: merchant-facing admin strings and customer-facing order notes are not
the same voice.

One msgid deserves a named check: **`'I accept the %s.'`** is declared twice, in
`wcs-scanpay-checkout-terms.php` and `class-wc-scanpay-blocks-support.php`, and its
`%s` carries the terms link in both checkouts. `docs/ts-review.md` §1.4 established
that the two degrade differently if a translation drops it — classic goes through
`sprintf()` and loses the link cleanly, Blocks splits on `'%s'` and renders the link
text stranded at the end of the sentence. The Danish msgstr keeps it today
(`"Jeg accepterer %s."`); confirm it still does and do not reword it away.

**3. Coverage.** Every user-visible string reaches a translation function, and
nothing that must not be translated does. Three rules bind here. From `AGENTS.md`:
**settings-field defaults stay plain strings**, because `__()` cannot localize a
stored value; and exception messages are deliberately untranslated — they reach the
merchant raw through `sprintf( __( 'Scanpay capture failed: %s' ),
$e->getMessage() )`, whose translators comment says so. Third, from
`docs/ts-review.md` §2.5 and the comment now at the enqueue site: **`checkout.ts`
translates nothing on purpose**, so its bundle carries neither `wp-i18n` nor
`wp_set_script_translations()` — every string it renders was translated PHP-side
and travels in the `get_payment_method_data()` payload. Do not "fix" any of the
three; a `__()` added to that bundle renders English with no warning.

**The catalog is not PHP-only.** 40 of the 115 msgids carry `#:` references into
the *compiled* `admin/assets/js/*.js` and `public/assets/js/*.js` — `wp i18n
make-pot` runs over the built tree, so the `.ts` sources' `__()` calls are in
scope for reading, never for editing. No task in this plan edits a `.ts` file.

**One known-bad msgid is out of your reach, deliberately.** The catalog holds
`" Could not delete the data: %s"` with a leading space — a real defect (leading and
trailing spaces are invisible in a PO file and translators drop them), but its
source is `settings.ts` and its fix is markup or CSS, already written up in
`docs/ts-review.md` §3.3. Do not edit the `.ts`, do not paper over it in the Danish
msgstr, and above all do not hand-edit the `.pot`. Note it in `HANDOFF-2.md` as
found-and-owned-elsewhere, and leave both catalogs' entries as they are.

Task 13 adds two msgids; they are in scope here.

**Fix.** Update `src/languages/*.po` only, plus source strings in `src/` where item
1 found a real fault. Leave `.pot` alone; leave `build/` alone.

**Verify**

- Run `printf 'n\n' | ./build.sh` and confirm the regenerated `.pot` matches the
  committed one except for strings you deliberately changed. A diff in the `msgid`
  set means a source string moved when it should not have. A diff confined to `#:`
  reference lines does **not**: those track file and line in the compiled `.js`, so
  any earlier `.ts` edit shifts them. If that is all you see, say so and commit the
  regenerated catalogs rather than reverting them.
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

**Files:** all 35 PHP files in `src/`; output to `RESULTS.md` at the repo root

A new, thorough review of the tree as tasks 1–19 leave it. This is a *review*, not
a fix: change no code. The deliverable is `RESULTS.md`.

**Before reading any source**, read all of `AGENTS.md` (especially **Settled — do
not "fix" these**, and note its standing rule that anything else odd is answered by
a comment at the line itself — read that comment before flagging), this file's
header including **Verified sound, do not re-audit**,
`docs/performance-review.md` (its §5 and §6 record what was measured and
deliberately left alone), `docs/ts-review.md` and `docs/scss-review.md` (their §4s do
the same for the TypeScript and stylesheet layers, and several of their entries
land on PHP files — the `?x=` endpoints, the Blocks enqueue site, the card
gateway's stylesheet enqueue), `docs/requirements.md`, `HANDOFF-2.md`,
and `HANDOFF.md` (runs 1-2). Everything
those establish is out of scope. A review that re-discovers a settled decision has
produced noise, and this is the third run to face that risk — three of the previous
review's candidate findings died on those documents and one died on a `php -r`
check.

**Method.** Read every file in full — not greps against a hypothesis. Per finding,
before writing it down: quote the code at its file and symbol; state the concrete
failure (inputs or state → wrong output, wrong money, wrong status, fatal, data
loss — a finding with no reachable failure is not one); settle the upstream half
against `.stubs/` and the language half with `php -r`; then try to refute it, and
check whatever would make it false.

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
