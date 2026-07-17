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

import { showWarning, buildTable, pluginVersionCheck } from './types/meta';

interface SubRow {
	subid?: string;
	rev?: string;
	method?: string;
	method_exp?: string;
	error?: string;
}

const box = document.getElementById('wcsp-meta');
const secret = box?.dataset.secret ?? '';
const subid = box?.dataset.subid ?? '';
const payid = box?.dataset.payid ?? '';
const ptime = box?.dataset.ptime ?? '';

// scanpay_subs.method stores the raw method type ($pm_type, class-wc-scanpay-sync.php),
// not the pretty "Visa 1234" label (which lives on the WC subscription's method title).
const METHOD_LABELS: Record<string, string> = {
	card: 'Card',
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
	if (payid) rows.push(['Payment ID', payid]);
	if (ptime) rows.push(['Payment date', fmtDate(ptime)]);
	rows.push(['Method', fmtMethod(sub.method)]);

	const exp = sub.method_exp ?? '0';
	if (parseInt(exp, 10) > 0) {
		rows.push(['Card expiry', fmtExp(exp)]);
		if (parseInt(exp, 10) * 1000 < Date.now()) {
			showWarning('The saved card has expired.', 'warning');
		}
	}
	buildTable(rows);
}

/** Fetch the current subscriber row (rev=0 returns it without a long-poll hold). */
async function load(): Promise<void> {
	try {
		const res = await fetch(`../wp-scanpay/fetch?x=sub&subid=${encodeURIComponent(subid)}&rev=0`, {
			headers: { 'X-Scanpay': secret },
		});
		if (!res.ok) throw new Error(await res.text());
		const sub = (await res.json()) as SubRow;
		if (sub.error || typeof sub.rev !== 'string') {
			return showWarning('No Scanpay subscription data found yet.', 'info');
		}
		render(sub);
	} catch (err) {
		showWarning('Could not load subscription details: ' + (err instanceof Error ? err.message : String(err)));
	}
}

// All inside the box guard: the version banner has nowhere to render without it.
// order.ts nests it the same way.
if (box) {
	if (subid) {
		load();
	} else {
		showWarning('This subscription has no Scanpay payment data yet.', 'info');
	}
	pluginVersionCheck();
}
