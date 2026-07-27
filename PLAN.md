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
| 9 | A site-wide menu removal contradicts our stated policy | `woocommerce-scanpay.php` |
| 10 | Three registrations in the router that state nothing | `woocommerce-scanpay.php` |
| 11 | The cURL extension is required and declared nowhere | `woocommerce-scanpay.php`, `gateways/abstract-wc-gateway-scanpay-base.php` |
| 12 | The checkout stylesheet's enqueue states none of its invariants | `gateways/class-wc-gateway-scanpay-card.php` |
| 13 | Two settings fields with an inert tooltip and no label | `admin/settings/fields/scanpay.php` |
| 14 | The Plugins-screen link escapes nothing | `admin/settings.php` |
| 15 | `wc_scanpay_money_equals()` has no callers | `library/math.php` + 3 call sites |
| 16 | `wc_scanpay_subref()` takes `object` | `public/generate-payment-link.php` |
| 17 | Four local inconsistencies | `admin/orders.php`, `admin/ajax/wp-scanpay-fetch-{meta,sub}.php` |
| 18 | Comment audit against the documented standard | all 35 PHP files |
| 19 | i18n audit, English source and Danish catalog | `src/languages/`, every `__()` site |
| 20 | Fresh full review → `RESULTS.md` | all 35 PHP files |

Files opened by more than one task: `class-wc-scanpay-capture.php` (1, 2, 3),
`class-wc-scanpay-sync.php` (6, 7), `woocommerce-scanpay.php` (9, 10, 11),
`class-wcs-scanpay-charge.php` (4, then 15's call site),
`generate-payment-link.php` (16, and 15's call site).

---

## Task 9 — A site-wide menu removal contradicts our stated policy

**File:** `src/woocommerce-scanpay.php`
**Anchor:** `function scanpay_remove_wc_payments_menu()` (~`:547-557`)

`admin/settings.php` states a policy and follows it, above
`wc_scanpay_admin_footer_text()`:

> Hide WooCommerce's promotional footer text, but only on the plugin's own
> settings screens. Blanking `admin_footer_text` globally is a site-wide UI
> change and is flagged by the WordPress.org plugin review.

The menu removal does the opposite, unconditionally, to a menu WooCommerce owns: a
bare `remove_menu_page( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' )`
on `admin_menu` at priority 999. Every admin loses WooCommerce's top-level Payments
entry on every admin page load, configured or not. Second problem: the slug is
matched by exact string, and `from=PAYMENTS_MENU_ITEM` is telemetry, not a route —
if WooCommerce changes it this silently becomes a no-op and nobody learns.

**This task does not decide the UI question — it makes code and policy agree.**

**Decided: keep the behaviour, document it.** Do not re-derive the history and do
not reopen the choice. It arrived in commit `8340b9f`, "Remove WooCommerce Payments
\"Payments\" admin menu entry for a cleaner UI", and the maintainer has confirmed
it stays. The docblock already gives the UI rationale (the slug points at the same
screen that already holds the gateway list, so keeping both makes the setup path
ambiguous). What is missing is not intent but disclosure.

Extend the docblock with the three things it is silent about:

1. This is a deliberate **site-wide** change to another plugin's menu, applied to
   every admin on every admin page load — the one place the plugin does what the
   `admin_footer_text` comment refuses to do, and expected to trip the same
   WordPress.org review that comment cites.
2. The slug is matched as a literal string and `from=PAYMENTS_MENU_ITEM` is a
   telemetry parameter, not a route: if WooCommerce changes it, this silently
   becomes a no-op and the entry returns with no error and no log line.
3. **Priority 999 is required, not decorative** — `remove_menu_page()` can only
   remove an entry that is already registered, and WooCommerce adds its own menus
   across priorities 9 to 70 (`class-wc-admin-menus.php:39-59`), with WooCommerce
   Admin later still. 999 means "after every menu registration". Verified in
   `.stubs/`; state the range so the next reader does not lower it.

**Verify**

- Confirm the slug against WooCommerce's own `add_menu_page()` call in `.stubs/`
  and record whether it still matches at 11.1.0-dev. If it does not, say so — the
  removal is already a no-op today and that is the finding, not a reason to change
  the code here.
- Quote the WC menu priorities backing point 3.
- `git diff` touches comment lines only: this task changes no executable code.

**Handoff**

- WooCommerce's Payments entry is still absent, and the Scanpay settings screen is
  still reachable from WooCommerce → Settings → Payments.

---

## Task 10 — Three registrations in the router that state nothing

**File:** `src/woocommerce-scanpay.php` (after task 9)

Three hygiene defects in one file, one commit: two renames/changes and one comment.

**1. `wp_scanpay_allowed_redirect_hosts()` uses WordPress's prefix.**
Anchor: `function wp_scanpay_allowed_redirect_hosts` (~`:140`), registered by
`add_filter( 'allowed_redirect_hosts', 'wp_scanpay_allowed_redirect_hosts' );`
(~`:473`). `AGENTS.md` names the namespace as `wc_scanpay_` / `wcs_scanpay_`. This
is the only `wp_scanpay_*` function in `src/`, and `wp_` is core's — a future core
function of that name is a fatal redeclare in a plugin with no autoloader to
arbitrate. Rename to `wc_scanpay_allowed_redirect_hosts` and update the single
`add_filter`.

`scanpay_log()` and `scanpay_remove_wc_payments_menu()` are outside both prefixes
too. Leave them: `scanpay_` collides with nothing upstream, `scanpay_log()` has 24
call sites, and the second may no longer exist after task 9. Record the decision.

**2. The scheduled-charge hook registers at priority 3.**
Anchor: `'woocommerce_scheduled_subscription_payment_scanpay', 'wcs_scanpay_scheduled_charge', 3, 2`
(~`:480`). It carries no comment, and the hook is gateway-suffixed (`…_scanpay`) so
nothing else plausibly listens — which argues for `10`, not against it.

The history has already been read: the `3` traces back to the repository's earliest
commits and has never been deliberately changed (the only commit touching that
registration since is `3f99c49`, a function-name typo fix). Treat it as legacy.

**Change it to `10`.** Keep the `2` — the callback takes `$amount` and `$wco`.

**3. `woocommerce_order_status_completed` at priority 5 is load-bearing and says
so nowhere.** Anchor:
`'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5, 2`
(~`:475`). Add a comment only — do not change the number. WooCommerce registers on
that same hook, all at the default 10: `queue_transactional_email` /
`send_transactional_email` (`class-wc-emails.php:140-146`),
`wc_downloadable_product_permissions` (`wc-order-functions.php:494`),
`wc_maybe_reduce_stock_levels` (`wc-stock-functions.php:125`),
`wc_update_total_sales_counts` and `wc_update_coupon_usage_counts`. Priority 5
means the capture is attempted — and, on failure, the order parked `on-hold` —
before WooCommerce mails the customer "completed" and grants download permissions
for goods that were never paid for. WooCommerce's own PayPal gateway captures at
the default 10, i.e. after both; ours is deliberately stricter. Verify each
citation in `.stubs/` before writing the comment.

**Scope bound.** The tree's remaining non-default priorities have been assessed and
are **out of scope**: the four `add_meta_boxes_*` at 9 (WordPress fires the generic
`add_meta_boxes`, where WooCommerce registers, entirely before the `_{screen}`
variant we hook, so 9 orders us against almost nothing), the two `woocommerce_init`
at 99, the two `wp_ajax_wc_scanpay_*` at 0 (our own action names — nothing else
listens), the two `handle_bulk_actions-*` at 0 and `init`/`admin_init` at 0
(defensive, same family as the documented `wp_ajax_woocommerce_mark_order_status`
at 0). None is wrong; none is load-bearing. **Do not comment them** — this commit
changes two registrations and comments a third.

**Verify**

- `grep -rn "wp_scanpay_" src/ build/` returns nothing after the rename (`build/`
  is generated; if stale, say so rather than editing it).
- `git diff` touches exactly two `add_action`/`add_filter` lines, the function
  declaration, and the comment added above the priority-5 registration — whose own
  number must be unchanged.
- Quote each `.stubs/` citation behind item 3 rather than copying the list from
  this section.
- Nothing outside `src/` (`readme.txt`, `docs/`) names the old function.

**Handoff**

- The redirect to `betal.scanpay.dk` still succeeds from `process_payment()` (a
  broken filter shows as `wp_safe_redirect()` falling back to `wp-admin/`).
- A scheduled renewal still fires `wcs_scanpay_scheduled_charge()`.

---

## Task 11 — The cURL extension is required and declared nowhere

**Files:** `src/woocommerce-scanpay.php`,
`src/gateways/abstract-wc-gateway-scanpay-base.php` (after tasks 9 and 10)
**Anchor:** `} catch ( Exception ) {` in `process_admin_options()` (~`:167-198`)

`WC_Scanpay_Client` is built on ext-curl unconditionally (`private \CurlHandle $ch;`
and `curl_init()` in the constructor). Without the extension that is
`Error: Call to undefined function curl_init()`. Nothing declares the dependency:
the plugin header states `Requires PHP` and `Requires Plugins` but no extension,
and `composer.json` is `require-dev` only by design.

The failure is worse than needed in one place.
`WC_Gateway_Scanpay_Base::process_admin_options()` validates a new key inside
`try { … } catch ( Exception )` — `Error` is not an `Exception`, so on a curl-less
host saving the settings form is a white-screen fatal rather than the "Invalid
Scanpay API key" notice the code was written to show.

**Fix.** Two parts, one commit:

1. **Declare it** in the plugin header comment (WordPress has no header field for
   extensions, so a prose line is the honest place) and in the requirements section
   of `src/readme.txt` — that file exists; read it and match its formatting.
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
  could run; list every `curl_*` call in `src/` (they are all in
  `class-wc-scanpay-client.php`).
- Confirm `Error` does not extend `Exception` (`php -r`), so the existing catch
  genuinely misses it.
- Confirm the header line you add contains no `{{ … }}` placeholder, so
  `./build.sh`'s substitution pass is unaffected.

**Handoff**

- Only a curl-less host can settle the fatal. State what changed and what a
  merchant on such a host now sees.

---

## Task 12 — The checkout stylesheet's enqueue states none of its invariants

**File:** `src/gateways/class-wc-gateway-scanpay-card.php`
**Anchor:** `if ( 'yes' === ( $this->settings['stylesheet'] ?? 'yes' ) ) {` (~`:34`)

That condition alone gates
`add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_styles' ] )`, and
nothing consults `$this->enabled`. **This task previously called for adding
`'yes' === $this->enabled &&` to that condition. Do not do that — it is a
regression**, and the reasoning is recorded here so it is not re-proposed:

- `checkout.css` is **not** the card gateway's stylesheet. It sizes the payment
  icons of all three gateways, which all wrap them in the same
  `<span class="wcsp-methods">` (`docs/scss-review.md` §1.1). Gating it on the card
  gateway's own `enabled` would strip the styling from a MobilePay-only or
  Apple-Pay-only shop.
- The enqueue lives in the card constructor because the card settings are the
  primary/shared option, and it runs unconditionally because
  `WC_Payment_Gateways::init()` does `$gateway = new $gateway();` for every class
  the `woocommerce_payment_gateways` filter returns, with no `enabled` test in the
  loop — that filtering happens later, in `get_available_payment_gateways()`.
  Verified in `.stubs/`; re-read it rather than trusting this paragraph.
- `WC_Gateway_Scanpay_ApplePay::__construct()` is **not** the sibling to copy. It
  gates `enqueue_checkout_script` — a script that serves only Apple Pay — on its
  own `enabled`. A per-gateway asset and a shared one do not take the same guard.

So the residual defect is narrower than "a disabled gateway loads CSS": a shop with
**all three** Scanpay gateways off still ships `checkout.css`. That is not worth two
extra option reads per front-end request to close, and "performance is out of scope"
stands.

**Fix.** Comment only — change no condition. Record at the enqueue what the code
now silently assumes: that this stylesheet serves all three gateways, that the
constructor therefore runs whatever `enabled` says (cite
`WC_Payment_Gateways::init()`), and that gating it on `$this->enabled` would break
a MobilePay-only shop. Keep the existing comment about reading `$this->settings`
directly — it explains the `??` and the avoided `get_option()`, and both survive.

**Leave the `wc_scanpay_item_needs_processing` filter on the next lines alone.** It
must stay unconditional: it is scoped to Scanpay orders by
`wc_scanpay_is_scanpay_order()`, and a shop that disables the gateway still has
historical Scanpay orders whose completion behaviour must not change. Say this in
`HANDOFF-2.md` or the next reader will "finish" the task wrongly.

**Verify**

- Quote the `new $gateway()` loop in `WC_Payment_Gateways::init()` and state that
  no `enabled` check precedes it.
- Quote the three gateways' `<span class="wcsp-methods">` icon wrappers, showing
  the stylesheet is shared.
- `git diff` touches comment lines only: this task changes no executable code.

**Handoff**

- None: a comment has no runtime surface. If a shop check is wanted anyway, the one
  worth running is that a MobilePay-only shop still renders sized icons at
  checkout — the case the abandoned fix would have broken.

---

## Task 13 — Two settings fields with an inert tooltip and no label

**File:** `src/admin/settings/fields/scanpay.php`
**Anchors:** `'wcs_complete_initial' =>` (~`:93`) and `'wcs_complete_renewal' =>`
(~`:100`)

These two are the only fields in the three field files with `desc_tip` and **no**
`description`, and the only two with no `title`. Both facts have a consequence:
`get_tooltip_html()` returns `''` for an empty description, so the flag renders
nothing — dead configuration that reads like a feature; and
`generate_checkbox_html()` echoes `$data['title']` into `<th scope="row">`
unconditionally, so each renders an empty header cell. Visually they look grouped
under the preceding "Auto-complete" row (`wc_complete_virtual`, which does have a
title); semantically they are two unlabelled rows, which is what a screen reader
gets. `WC_Settings_API` has no `checkboxgroup` support (that is
`woocommerce_admin_fields()`), so grouping cannot fix it.

**Fix.** Give both a `title`, and either a real `description` or no `desc_tip`.

Two new msgids — field labels, not stored values, so `__()` is correct here
(`AGENTS.md` bars it on *defaults*, which these are not). Pick wording that
distinguishes the two rows from the `wc_complete_virtual` row above; reuse its
exact string only if you also state why three rows sharing a header is right.
Prefer writing the description over dropping `desc_tip`: these two settings decide
whether an order is force-completed on sync, the least obvious behaviour on the
screen.

Do not touch `'default' => 'no'` on either, or the other fields whose `desc_tip`
already pairs with a real `description`.

**Verify**

- Quote `get_tooltip_html()` and `generate_checkbox_html()` showing the empty-`$tip`
  return and the unconditional `<th>` echo.
- `grep -n "desc_tip" src/admin/settings/fields/*.php` — eleven occurrences; every
  one now has a sibling `description`.
- List the msgids added, verbatim, for task 19.

**Handoff**

- Both rows render a label and a working help tip, and toggling either still
  stores `yes`/`no`.

---

## Task 14 — The Plugins-screen link escapes nothing

**File:** `src/admin/settings.php`
**Anchor:** `array_unshift( $links, '<a href="' . $url . '">'` (~`:75`)

`$url` comes from `admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' )`
and the label from `__( 'Settings', … )`; the two are concatenated straight into an
`<a href="…">`. Neither value is escaped. `admin_url()` runs the `admin_url` filter and `__()` runs
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
**Anchor:** `function wc_scanpay_money_equals( string $a, string $b ): bool`
(~`:187`)

It is called from nowhere. The three places asking whether two money strings are
equal spell it as a comparison, all `wc_scanpay_cmpmoney( … ) !== 0`:

- `class-wcs-scanpay-charge.php`, `scheduled_charge()`: `cmpmoney( $amt_str, $tot_str ) !== 0`
- `class-wcs-scanpay-charge.php`, `charge()`: `$sum !== $wc_total && cmpmoney( $sum, $wc_total ) !== 0`
- `generate-payment-link.php`: the same `$sum !== $wc_total &&` shape

(The tree's other `cmpmoney()` calls test `<`, `>` or `<= 0` and are not equality
tests. Leave them.)

So either the function is the better spelling those three should use, or it is
dead. It cannot be neither.

**Decided: adopt it.** Replace the three tests with `! wc_scanpay_money_equals( … )`.
Do not delete the function instead — that fork is closed.

Both functions call the same `wc_scanpay_dighomogenize()`; after it, `cmpmoney()`
adds `strcmp()` plus up to three sign branches where `money_equals()` does two
`===`. Measured here over 1.6 M calls across four representative pairs,
`money_equals()` is consistently **2–4 % faster** — about 14 ns per call, so the
speed is real but immaterial at three call sites. Adopt it for the intent it
states; the benchmark only settles that it costs nothing. Do not re-run it.

The two sites guarding with `$sum !== $wc_total &&` keep that fast path unless you
say why not. `AGENTS.md` no longer enumerates the money-API function names beside
`src/library/math.php` (commit `685a78e` trimmed the list), so this needs no guide
edit — confirm that, then leave the guide alone.

**Do not** change `math.php`'s implementation either way. The file is settled and
`docs/performance-review.md` §6.2 says so.

**Verify**

- `grep -rn "money_equals\|cmpmoney" src/` before and after: three equality tests
  become `money_equals()`, and the `<`/`>`/`<= 0` comparisons are untouched.
- Re-confirm the two agree on the cases the sites see. Already checked here — they
  agree on identical strings, on `'1250.0'` vs `'1250.00'`, on unequal amounts and
  on `'0'` vs `'-0.00'` — so this is a cheap re-run, not a derivation. `php -r`,
  loading `math.php` after `define( 'ABSPATH', … )`.

**Handoff**

- A renewal whose WCS amount matches the order total still charges; one that
  disagrees still fails with the mismatch note.

---

## Task 16 — `wc_scanpay_subref()` takes `object`

**File:** `src/public/generate-payment-link.php`
**Anchor:** `function wc_scanpay_subref( int $oid, object $wco ): ?string` (~`:34`)

It uses the loosest hint PHP has on a parameter that is always a
`WC_Abstract_Order`: either the `WC_Order` from `wc_get_order()` or, on the
payment-method-change path, the `WC_Subscription` WCS hands the gateway. The body
calls `$wco->get_status()`, which `object` does not promise. `AGENTS.md`: "A typed
parameter is the preferred guard — a `TypeError` beats a defensive `if`."

**Fix.** `WC_Abstract_Order $wco`. `WC_Order` is too narrow — it would `TypeError`
on the method-change path. Add no comment: the type is the statement.

**Verify**

- Quote the declarations proving `WC_Subscription extends WC_Order extends
  WC_Abstract_Order`.
- Confirm both call sites (`$data['subscriber'] = [ 'ref' => wc_scanpay_subref(…)`
  and `$subref = wc_scanpay_subref(…)`) still type-check by naming what each
  passes.
- Confirm `wc_scanpay_process_payment()`'s own `$wco` is not re-typed — it comes
  from `wc_get_order()`, whose return is checked immediately after, and that guard
  stays.

**Handoff**

- Both a normal subscription checkout and a payment-method change still produce a
  `wcs[]…` subscriber ref.

---

## Task 17 — Four local inconsistencies

**Files:** `src/admin/orders.php`, `src/admin/ajax/wp-scanpay-fetch-meta.php`,
`src/admin/ajax/wp-scanpay-fetch-sub.php`

Four small changes, one commit, no behaviour change. Do all four or none.

1. **`'meta' => $meta ?? null,` in `admin/orders.php`** (~`:119`). `$wpdb->get_row()`
   already returns `null` when there is no row, so the coalesce cannot fire. Drop
   to `'meta' => $meta`.
2. **The two long-poll endpoints spell termination differently.** `-sub.php` writes
   `wp_send_json( … ); die();` after the secret check and bare `die;` after the
   shopid and subid checks; `-meta.php` writes bare `wp_send_json( … )` at the
   matching two. `wp_send_json()` terminates either way, so both are correct and
   one is redundant — but a reader cannot tell which without checking core. Take
   the bare form, delete the three redundant `die`s, and comment at the first site
   that `wp_send_json()` terminates. **Do not pre-empt
   `docs/performance-review.md` §5.2**, which folds these files' shared auth
   preamble into one `admin/ajax/auth.php`; only make the two agree in place.
3. **`$sec = $sec + $sec;` in `-sub.php`** (~`:61`). Write `$sec *= 2;`. The comment
   above already says "0.5s, 1s, 2s, 4s, 8s", so the doubling should read as
   doubling.
4. **A null `rev` never breaks the poll — say so, do not code around it.**
   `scanpay_subs.rev` is nullable (`rev INT unsigned,` in `install.php`'s
   `CREATE TABLE $subs_tbl`, ~`:61` — unlike `scanpay_meta.rev`, which is
   `NOT NULL`) and the exit test is `$sub['rev'] > $rev`; `null > 0` is false, so
   such a row would poll the full 15.5 s. It cannot arise:
   `WC_Scanpay_Sync::subscriber()` is the only writer, and it validates
   `if ( ! is_int( $rev ) || $rev <= 0 )` with a throw before building the
   statement (`class-wc-scanpay-sync.php`, ~`:427-430`), so a stored rev is always
   ≥ 1. `docs/ts-review.md` §4 reached the same conclusion from the browser side.
   **Write the comment, not a break condition** — model it on
   `wc_scanpay_read_cursor()`'s note for `scanpay_seq.ping`, and cite the validating
   guard by symbol. Re-read that guard before you write it; do not take it from here.

**Leave the keep-alive `echo "\n"` alone**, though item 3 edits the line beside it
in `-sub.php`. `docs/ts-review.md` §4 settled it: the body arrives as `"\n\n\n{…}"`
and `JSON.parse` skips leading whitespace per specification, so the browser side is
unaffected. It is not a fifth item.

**Verify**

- Quote `wp_send_json()` showing both termination branches.
- Confirm `subscriber()` is the only `INSERT` into `scanpay_subs`
  (`grep -rn "scanpay_subs" src/` returns one INSERT) and that its `rev` is
  validated as a positive int before the statement is built.
- Confirm none of the four alters a response body or status code.

**Handoff**

- Both meta boxes still poll and render; `?x=meta` and `?x=sub` still answer JSON
  with the same shape.

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
