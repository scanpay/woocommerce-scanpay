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
