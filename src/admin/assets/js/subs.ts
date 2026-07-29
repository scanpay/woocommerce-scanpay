/**
 * subs.ts: Scanpay meta box on the WooCommerce Subscription admin screen.
 *
 * Reads the box's data-* attributes, fetches the subscriber row from the WC-free
 * endpoint (wp-scanpay-fetch-sub.php, authenticated by the X-Scanpay header —
 * see task 8), and renders the card method + expiry alongside the payment id/time.
 *
 * The scanpay_subs table only holds subid/rev/method/method_exp (install.php); there
 * are no retries/idempotency/nxt columns, so the box surfaces only those fields.
 */

import { renderShell, showWarning, buildTable, pluginVersionCheck } from './util/meta';
import { __, sprintf } from './util/i18n';

interface SubRow {
	subid?: string;
	rev?: string;
	method?: string;
	method_exp?: string;
	error?: string;
}

const box = document.getElementById('wcsp-meta');
const secret = box?.dataset.secret ?? '';
// Base for the ?x=sub poll, sent by PHP so the request reaches a real file instead of a
// path that only resolves with pretty permalinks. The fallback keeps a cached older
// bundle working against a newer plugin, and collapses '' and undefined together.
const ep = box?.dataset.endpoint || '../wp-scanpay/fetch';
const subid = box?.dataset.subid ?? '';
const payid = box?.dataset.payid ?? '';
const ptime = box?.dataset.ptime ?? '';

// scanpay_subs.method stores the raw method type ($pm_type, class-wc-scanpay-sync.php),
// not the pretty "Visa 1234" label (which lives on the WC subscription's method title).
// MobilePay and Apple Pay are brand names and stay as they are.
const METHOD_LABELS: Record<string, string> = {
	card: __('Card', 'scanpay-for-woocommerce'),
	mobilepay: 'MobilePay',
	applepay: 'Apple Pay',
};

/** method_exp / ptime are unix seconds (strings from the JSON/DB). */
function fmtDate(unixSecs: string): string {
	const n = parseInt(unixSecs, 10);
	return n > 0 ? new Date(n * 1000).toISOString().split('T')[0] : '—';
}

function fmtExp(unixSecs: string): string {
	const n = parseInt(unixSecs, 10);
	if (n <= 0) return '—';
	const d = new Date(n * 1000);
	return `${String(d.getUTCMonth() + 1).padStart(2, '0')}/${d.getUTCFullYear()}`;
}

function fmtMethod(method: string | undefined): string {
	if (!method) return 'Scanpay';
	return METHOD_LABELS[method] ?? method.charAt(0).toUpperCase() + method.slice(1);
}

function render(sub: SubRow): void {
	const rows: [string, string][] = [];
	if (payid) rows.push([__('Payment ID', 'scanpay-for-woocommerce'), payid]);
	if (ptime) rows.push([__('Payment date', 'scanpay-for-woocommerce'), fmtDate(ptime)]);
	rows.push([__('Method', 'scanpay-for-woocommerce'), fmtMethod(sub.method)]);

	const exp = sub.method_exp ?? '0';
	if (parseInt(exp, 10) > 0) {
		rows.push([__('Card expiry', 'scanpay-for-woocommerce'), fmtExp(exp)]);
		if (parseInt(exp, 10) * 1000 < Date.now()) {
			showWarning(__('The saved card has expired.', 'scanpay-for-woocommerce'), 'warning');
		}
	}
	buildTable(rows);
}

/** Fetch the current subscriber row (rev=0 returns it without a long-poll hold). */
async function load(): Promise<void> {
	try {
		const res = await fetch(`${ep}?x=sub&subid=${encodeURIComponent(subid)}&rev=0`, {
			headers: { 'X-Scanpay': secret },
		});
		if (!res.ok) throw new Error(await res.text());
		const sub = (await res.json()) as SubRow;
		if (sub.error || typeof sub.rev !== 'string') {
			return showWarning(__('No Scanpay subscription data found yet.', 'scanpay-for-woocommerce'), 'info');
		}
		render(sub);
	} catch (err) {
		showWarning(
			sprintf(
				/* translators: %s is the raw error the endpoint returned, which is not translated. */
				__('Could not load subscription details: %s', 'scanpay-for-woocommerce'),
				err instanceof Error ? err.message : String(err)
			)
		);
	}
}

// All inside the box guard: the version banner has nowhere to render without it.
// order.ts nests it the same way.
if (box) {
	// First: everything below renders into the containers this creates.
	renderShell();
	if (subid) {
		load();
	} else {
		showWarning(__('This subscription has no Scanpay payment data yet.', 'scanpay-for-woocommerce'), 'info');
	}
	pluginVersionCheck();
}
