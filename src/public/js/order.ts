/**
 * 	order.js: Show the meta box in the WooCommerce order page (admin).
 */

/**
 * Internal dependencies
 */
import { showWarning, buildTable, pluginVersionCheck, pluginSyncCheck } from './util/meta';
declare let wcSettings: any;

const wco = (document.getElementById('wcsp-meta') as HTMLElement).dataset as {
	secret?: string;
	id?: string;
	payid?: string;
	ptime?: number;
	status?: string;
	total?: string;
};
const secret = wco.secret as string;
const iso = (wcSettings as any).currency.decimalSeparator === ',' ? 'da-DK' : 'en-US';
const currency = new Intl.NumberFormat(iso, {
	style: 'currency',
	currency: 'DKK',
});
let rev = 0;

function buildDataArray(o: any) {
	const data = [
		['Authorized', currency.format(o.authorized)],
		['Captured', currency.format(o.captured)],
	];
	if (o.voided > 0) {
		data.push(['Voided', currency.format(o.voided)], ['Net payment', currency.format(o.captured)]);
	} else if (o.refunded > 0) {
		data.push(['Refunded', currency.format(o.refunded)], ['Net payment', currency.format(o.captured - o.refunded)]);
	} else {
		data.push(['Net payment', currency.format(o.captured)]);
	}
	return data;
}

function buildFooter(meta: any) {
	let btns = '';
	const link = 'https://dashboard.scanpay.dk/' + meta.shopid + '/' + meta.id;
	if (meta.captured === '0') {
		btns = `<a target="_blank" href="${link}" class="wcsp-meta-acts-refund">Void payment</a>`;
	} else if (parseFloat(meta.refunded) < parseFloat(meta.authorized)) {
		btns = `<a target="_blank" href="${link}/refund" class="wcsp-meta-acts-refund">Refund</a>`;
	}
	document.getElementById('wcsp-meta-foot')!.innerHTML =
		`<div class="wcsp-meta-acts"><div class="wcsp-meta-acts-left"><a target="_blank" href="${link}" class="wcsp-meta-acts-link"></a></div>${btns}</div>`;
}

function handleMetaError(error: string) {
	if (error === 'not found') {
		if (!wco.payid) return showWarning('No payment details found for this order.');
		const dtime = 30 - Math.floor((Date.now() / 1000 - wco.ptime!) / 60);
		if (dtime > 0) {
			showWarning(`The order has not been paid yet. The payment link expires in ${dtime} minutes.`);
		} else {
			showWarning('The payment link has expired. No payment received.');
		}
		buildTable([['Pay ID', wco.payid]]);
	} else if (error === 'invalid shopid') {
		showWarning('Invalid or missing API key. Please check your plugin settings or contact support.');
	}
	// Remove meta table and footer
	document.getElementById('wcsp-meta-ul')!.innerHTML = '';
	document.getElementById('wcsp-meta-foot')!.innerHTML = '';
}

/*
 *	Clear all warnings except the meta info.
 *	We need a smarter way to do this...
 */
function clearWarnings() {
	const head = document.getElementById('wcsp-meta-head') as HTMLElement;
	const childrenArray = Array.from(head.children);
	let cleared = false;
	for (const child of childrenArray) {
		if (!child.classList.contains('wcsp-meta-info')) {
			child.remove();
			cleared = true;
		}
	}
	return cleared;
}

let abortCtrl: AbortController;
function loadOrderMeta() {
	if (!wco.id) return; // TODO: lookup order ID with AJAX request

	abortCtrl = new AbortController();
	const warningsCleared = clearWarnings();
	fetch('../wp-scanpay/fetch?x=meta&s=' + secret + '&oid=' + wco.id + '&rev=' + rev, {
		signal: abortCtrl.signal,
		headers: { 'X-Scanpay': 'fetch' },
	})
		.then((res) => res.json())
		.then((meta) => {
			if (meta.error) {
				return handleMetaError(meta.error);
			}
			// Check if meta has changed since last load
			if (meta.rev === rev && !warningsCleared) return;

			buildTable(buildDataArray(meta) as [string, any][]);
			buildFooter(meta);

			if (wco.status === 'completed' || wco.status === 'refunded') {
				const total = parseFloat(meta.captured) - parseFloat(meta.refunded);
				if (parseFloat(wco.total!) !== total) {
					showWarning(
						'The order total (<b><i>' +
							currency.format(parseFloat(wco.total ?? '0')) +
							'</i></b>) does not match the net payment.'
					);
				}
			}
			rev = meta.rev;
		})
		.catch(({ name }) => {
			if (name === 'AbortError') return;
			showWarning('Error: could not load payment details.');
		});
	pluginSyncCheck(secret);
}

loadOrderMeta();
pluginVersionCheck();

document.addEventListener('visibilitychange', () => {
	if (document.visibilityState == 'visible') return loadOrderMeta();
	abortCtrl.abort();
});
