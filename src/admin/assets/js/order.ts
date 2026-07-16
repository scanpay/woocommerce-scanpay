/**
 * order.ts: Scanpay meta box on the WooCommerce order edit screen (admin).
 *
 * Renders the authorized/captured/refunded/voided figures for the order, surfaces
 * warnings inline, checks the plugin version, and offers a nonce-guarded capture
 * action plus a link to the Scanpay dashboard (where refunds are performed).
 */

import { showError, showWarning, buildTable, pluginVersionCheck } from './types/meta';

const dom = document.getElementById('wcsp-meta');
const data = window.ScanpayOrderData;

type MetaRow = NonNullable<OrderData['meta']>;
const MONEY_KEYS = ['authorized', 'captured', 'refunded', 'voided'] as const;

/**
 * Format a decimal-string amount for display. Money is a decimal string on the PHP
 * side (library/math.php); there is no JS equivalent, so this is display-only and
 * does no float arithmetic — it just normalises the fractional part to `decimals`.
 */
function fmtMoney(raw: string, decimals: number): string {
	const s = raw.trim();
	const neg = s.startsWith('-');
	const body = neg ? s.slice(1) : s;
	const dot = body.indexOf('.');
	const intPart = (dot === -1 ? body : body.slice(0, dot)) || '0';
	if (decimals <= 0) return (neg ? '-' : '') + intPart;
	const frac = (dot === -1 ? '' : body.slice(dot + 1)).slice(0, decimals).padEnd(decimals, '0');
	return (neg ? '-' : '') + intPart + '.' + frac;
}

/** True when the amount is zero — pure string test, no float parsing. */
function isZeroMoney(raw: string): boolean {
	return !/[1-9]/.test(raw);
}

/** A well-formed money field is a non-empty decimal string. */
function isMoney(raw: unknown): raw is string {
	return typeof raw === 'string' && /^-?\d+(\.\d+)?$/.test(raw.trim());
}

function renderShell(): void {
	(dom as HTMLElement).innerHTML =
		'<div id="wcsp-meta-head"></div>' +
		'<ul id="wcsp-meta-ul" class="wcsp-meta-ul"></ul>' +
		'<div id="wcsp-meta-foot"></div>';
}

function renderFigures(meta: MetaRow, currency: string, decimals: number): void {
	const rows: [string, string][] = [];
	for (const key of MONEY_KEYS) {
		const raw = meta[key];
		if (!isMoney(raw)) {
			showError(`Invalid ${key} amount: ${String(raw)}`);
			continue;
		}
		// Always show authorized + captured; show refunded/voided only when non-zero.
		if ((key === 'refunded' || key === 'voided') && isZeroMoney(raw)) continue;
		rows.push([key[0].toUpperCase() + key.slice(1), `${fmtMoney(raw, decimals)} ${currency}`]);
	}
	buildTable(rows);
}

function renderFoot(meta: MetaRow, decimals: number): void {
	const foot = document.getElementById('wcsp-meta-foot');
	if (!foot) return;

	// Capturable while the auth is not voided and not already fully captured.
	// The equality test compares two strings normalised to the same precision, so
	// it needs no money arithmetic. The server (WC_Scanpay_Capture) is the
	// authority and safely no-ops if nothing is left to capture.
	const capturable =
		isMoney(meta.authorized) &&
		isMoney(meta.captured) &&
		isZeroMoney(meta.voided) &&
		fmtMoney(meta.captured, decimals) !== fmtMoney(meta.authorized, decimals);

	let html = '<div class="wcsp-meta-acts"><div class="wcsp-meta-acts-left">';
	if (capturable) {
		html += '<button type="button" class="button" id="wcsp-capture">Capture</button>';
	}
	html += '</div>';
	if (data.dashboard) {
		html +=
			`<a class="wcsp-meta-acts-refund" href="${data.dashboard}" target="_blank" rel="noopener">Refund…</a>`;
	}
	html += '</div>';
	foot.innerHTML = html;

	if (capturable) {
		document.getElementById('wcsp-capture')?.addEventListener('click', onCapture);
	}
}

/** POST the capture action, guarded by the injected per-order nonce. */
async function onCapture(ev: Event): Promise<void> {
	const btn = ev.currentTarget as HTMLButtonElement;
	btn.disabled = true;
	const label = btn.textContent;
	btn.textContent = 'Capturing…';
	try {
		const res = await fetch(window.ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				action: 'wc_scanpay_capture',
				oid: String(data.oid),
				nonce: data.nonce,
			}),
		});
		const json = await res.json();
		if (!res.ok || !json?.success) {
			throw new Error(typeof json?.data === 'string' ? json.data : 'capture_failed');
		}
		showWarning('Capture requested. Updating figures…', 'info');
		await refresh();
	} catch (err) {
		showError('Capture failed: ' + (err instanceof Error ? err.message : String(err)));
		btn.disabled = false;
		btn.textContent = label;
	}
}

/**
 * Poll the (WC-free) meta endpoint for the revision bump the capture produces once
 * Scanpay pings back and the sync updates the row, then re-render. Bounded; falls
 * back to an advisory note if the sync has not landed yet.
 */
async function refresh(): Promise<void> {
	const startRev = data.meta ? parseInt(data.meta.rev, 10) : 0;
	for (let i = 0; i < 3; i++) {
		try {
			const res = await fetch(`../wp-scanpay/fetch?x=meta&oid=${data.oid}&rev=${startRev}`, {
				headers: { 'X-Scanpay': data.secret },
			});
			const row = await res.json();
			if (res.ok && row && typeof row.rev === 'string' && parseInt(row.rev, 10) > startRev) {
				data.meta = row as MetaRow;
				renderFigures(data.meta, data.currency, data.wc_decimals);
				renderFoot(data.meta, data.wc_decimals);
				showWarning('Capture complete.', 'info');
				return;
			}
		} catch {
			/* transient; keep polling */
		}
	}
	showWarning('Capture requested — figures will refresh on the next sync.', 'info');
}

if (dom && data) {
	renderShell();
	pluginVersionCheck();
	if (data.meta) {
		renderFigures(data.meta, data.currency, data.wc_decimals);
		renderFoot(data.meta, data.wc_decimals);
	} else {
		// No synced payment row yet (e.g. the order was opened before the first ping).
		showWarning('Waiting for payment confirmation from Scanpay…', 'info');
	}
}
