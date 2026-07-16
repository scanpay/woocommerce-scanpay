# SCSS review — Scanpay for WooCommerce (`dev` / v3.0.0)

Scope: `src/admin/assets/css/meta.scss`, `src/admin/assets/css/settings.scss`,
`src/public/assets/css/checkout.scss`. Every selector was cross-checked against
the markup that actually renders it (`order.ts`, `subs.ts`, `types/meta.ts`,
`orders.php`, `subscriptions.php`, `admin-options.php`, `settings.ts`,
`checkout.ts`, gateway PHP). Dead-rule claims were grep-verified against
`*.php`/`*.ts`, including dynamic class-string construction.

**Context:** after the meta-box rewrite (tasks 1–3), `order.ts`/`subs.ts` render
figures via `buildTable()` (which emits only `.wcsp-meta-li` /
`.wcsp-meta-li-title` / `.wcsp-meta-li-value`), alerts via `showWarning`/
`showError` (`.wcsp-meta-alert` + `.wcsp-meta-alert-{error,warning,info}`), and
the order foot (`.wcsp-meta-acts` / `-left` / `-refund` + `#wcsp-capture`). They
emit **no** card-brand or status-color classes — the whole `.wcsp-meta-li-card*`
/ `.wcsp-meta-li-status*` family is orphaned.

---

## Major — dead rule blocks orphaned by the rewrite

Confirmed 0 references in any `.php`/`.ts` (literal or concatenated).

- **`meta.scss:64-79`** — Status text-color classes never emitted:
  `.wcsp-meta-li-status-fully-refunded`, `.wcsp-meta-li-status-partially-refunded`,
  `.wcsp-meta-li-status-partially-captured`, `.wcsp-meta-li-status-fully-captured`,
  `.wcsp-meta-li-refunded`. **Fix:** delete.
- **`meta.scss:85-95`** — Second (double-dash) status family, also dead:
  `.wcsp-meta-li-status--captured`, `--refunded`, `--partial_refund`.
  `buildTable` adds no status modifier to the value cell. **Fix:** delete.
- **`meta.scss:97-156`** — The entire card-brand block (~60 lines):
  `.wcsp-meta-li-card`, `.wcsp-meta-li-card--dots`, and all 12 brand sizers
  (`--dankort`/`--visadankort`/`--visa`/`--mastercard`/`--maestro`/`--amex`/
  `--diners`/`--jcb`/`--unionpay`/`--discover`/`--mobilepay`/`--applepay`).
  Neither meta box renders a card element (`subs.ts` shows the method name as
  plain text via `fmtMethod`). **Fix:** delete lines 97-156.

Deleting all Major + Minor dead rules drops `meta.scss` from ~220 to ~110 lines
with zero rendered-markup impact.

## Minor

- **`meta.scss:175-180`** — `.wcsp-meta-acts-link` is dead **and** has a broken
  asset path. (a) No markup renders `wcsp-meta-acts-link` (the order foot uses a
  text link `.wcsp-meta-acts-refund` "Refund…", not an SVG button). (b) The rule
  is `background: url(../images/admin/link.svg)`, which from the compiled
  `build/admin/assets/css/meta.css` resolves to `admin/assets/images/admin/link.svg`
  — but the file is at `admin/assets/images/link.svg` (there is no `images/admin/`
  subdir). **Fix:** delete the rule; if ever revived, the path is `../images/link.svg`.
- **`meta.scss:187-194`** — `.wcsp-meta-loading` (+ nested `> .spinner`) — dead,
  no `wcsp-meta-loading` in markup. **Fix:** delete.
- **`meta.scss:197-219`** — `.wcsp-loader` + its `@keyframes l3` — dead;
  `wcsp-loader` appears nowhere and nothing else uses `l3`. **Fix:** delete both.
- **`meta.scss:43-45`** — `.wcsp-meta-alert-pending { display:flex }` — dead. The
  only alert `type` values passed anywhere are `error`, `warning`, `info`.
  **Fix:** delete.
- **`meta.scss:47-50`** — `.wcsp-meta-alert--spin` — dead (was the pending-alert
  spinner). **Fix:** delete.
- **`settings.scss:13-37`** — Fragile positional selectors. Five rules key off row
  position in the card settings table (`.wcsp-set-scanpay tr:nth-child(2)`,
  `tr:nth-child(6)`, `tr:nth-last-child(2|3|4)`). Against the current 11-field
  `fields/scanpay.php` these resolve to: `nth-child(2)`=API key,
  `nth-child(6)`=stylesheet (intent undocumented — verify), `nth-last-child(4|3|2)`
  =the three auto-complete checkboxes. Any add/remove/reorder silently
  mis-targets. **Fix:** re-anchor to something stable (e.g. the `#woocommerce_
  scanpay_apikey` row via `:has()`, or wrap the auto-complete group) or, at
  minimum, comment each index with the field key it targets.

## Nit

- **`meta.scss:25-28`** — Dead nested `.wcsp-meta-alert > strong` — no alert
  message contains `<strong>` (the only inline markup in an alert is
  `<b class="scanpay-outdated">` in `types/meta.ts:34`). **Fix:** delete, or change
  to `> b` if bolding was intended. (Separately, `.scanpay-outdated` is emitted
  but has no rule.)
- **`meta.scss:31-41`** — `.wcsp-meta-alert-warning` and `-error` have byte-
  identical declarations; both are live. **Fix:** collapse to a shared selector list.
- **`meta.scss` (missing rule)** — `.wcsp-meta-alert-info` is emitted ~6× (order
  capture/waiting messages, subs info messages, `pluginVersionCheck`) but has **no**
  rule, so every info alert falls back to the base `.wcsp-meta-alert` warning-yellow.
  **Fix:** add a neutral/blue `.wcsp-meta-alert-info` rule (introduced by tasks 1–3;
  intentional-looking but visually a warning tone).
- **`meta.scss:56`** — `list-style: none !important` — likely needed to beat WP
  `ul` defaults; verify `!important` is necessary.
- **`settings.scss:6`** — `.wc-admin-header` is unprefixed, against the file's own
  "prefix with `wcsp-set-`" convention and WooCommerce-Admin's namespace. Leak risk
  is low (settings.css is gated to Scanpay screens), but rename to `.wcsp-set-header`.
- **`checkout.scss:20-29`** — `!important` on `img` heights for
  `.wcsp-icons-scanpay > img` and `.wcsp-icon-applepay` (both live) is defensible to
  override theme rules. Asymmetry: the MobilePay Blocks icon
  (`.wcsp-icon-mobilepay`) has no sizing rule, so it renders at intrinsic size while
  card + Apple Pay are pinned to 22px — verify intended.

## Clean / no action

- **`checkout.scss`** — no dead rules. `.wcsp-label`, `.wcsp-title`, `.wcsp-icons`,
  `.wcsp-icons-scanpay`, `.wcsp-icon-applepay`, `.wcsp-cards` all reached (the last
  via the classic-checkout `get_icon()` in the card gateway; icon classes via
  `checkout.ts`).
- **`settings.scss`** nav family and the task-2 additions (`.wcsp-set-nav-mtime`,
  `.wcsp-set-alert`) match rendered markup.
- **`meta.scss`** live rules (`#wcsp-meta`, `#wcsp-meta-box>.inside`,
  `.wcsp-meta-alert` base, `.wcsp-meta-ul`, `.wcsp-meta-li(-title)`,
  `.wcsp-meta-acts(-left/-refund)`) match rendered markup.
- Scoping is sound: `meta.css` enqueues only on Scanpay order/subscription edit
  screens, `settings.css` only on Scanpay settings screens, `checkout.css` only via
  the card gateway's front-end enqueue.
