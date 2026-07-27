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
