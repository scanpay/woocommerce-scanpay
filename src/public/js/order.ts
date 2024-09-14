/**
 * 	order.js: Show the meta box in the WooCommerce order page (admin).
 */

/**
 * Internal dependencies
 */
import { getLastSync, checkVersion } from './util/compat';
import { showWarning } from './util/meta';
declare let wcSettings: any;

const order = (document.getElementById('wcsp-meta') as HTMLElement).dataset as {
	secret?: string;
	id?: string;
	payid?: string;
	ptime?: number;
	status?: string;
	total?: string;
};
const secret = order.secret as string;
const iso = (wcSettings as any).currency.decimalSeparator === ',' ? 'da-DK' : 'en-US';
const currency = new Intl.NumberFormat(iso, {
	style: 'currency',
	currency: 'DKK',
});
let rev = 0;

function verifySync() {
	getLastSync(secret).then((unixtime) => {
		const dmins = Math.floor((Math.floor(Date.now() / 1000) - unixtime) / 60);
		if (unixtime === 0 || dmins > 60 * 24 * 3) {
			return showWarning(
				`Your plugin is not synchronized with the Scanpay backend. Please follow the instructions
				<a href="https://wordpress.org/plugins/scanpay-for-woocommerce/#installation">here</a>.`
			);
		}
		if (dmins > 10) {
			showWarning('Your scanpay extension is out of sync: ' + dmins + 'minutes since last synchronization.');
		}
	});
}

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

function buildTable(arr: [string, any][]) {
	let html = '';
	for (const x of arr) {
		html += `<li class="wcsp-meta-li">
			<div class="wcsp-meta-li-title">${x[0]}:</div>
			<div class="wcsp-meta-li-value">${x[1]}</div>
		</li>`;
	}
	document.getElementById('wcsp-meta-ul')!.innerHTML = html;
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
		if (!order.payid) return showWarning('No payment details found for this order.');
		const dtime = 30 - Math.floor((Date.now() / 1000 - order.ptime!) / 60);
		if (dtime > 0) {
			showWarning(`The order has not been paid yet. The payment link expires in ${dtime} minutes.`);
		} else {
			showWarning('The payment link has expired. No payment received.');
		}
		buildTable([['Pay ID', order.payid]]);
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
	if (!order.id) return; // TODO: lookup data
	abortCtrl = new AbortController();
	const warningsCleared = clearWarnings();
	fetch('../wp-scanpay/fetch?x=meta&s=' + secret + '&oid=' + order.id + '&rev=' + rev, {
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

			if (order.status === 'completed' || order.status === 'refunded') {
				const total = parseFloat(meta.captured) - parseFloat(meta.refunded);
				if (parseFloat(order.total!) !== total) {
					showWarning(
						'The order total (<b><i>' +
							currency.format(parseFloat(order.total ?? '0')) +
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
	// Check synchronization
	verifySync();
}

loadOrderMeta();
document.addEventListener('visibilitychange', () => {
	if (document.visibilityState == 'visible') return loadOrderMeta();
	abortCtrl.abort();
});

checkVersion().then((version) => {
	if (version !== '{{ VERSION }}') {
		// TODO: This warning should be permanent
		showWarning(
			`Your scanpay plugin is <b class="scanpay-outdated">outdated</b>. Please update to ${version}
				(<a href="//github.com/scanpay/woocommerce-scanpay/releases" target="_blank">changelog</a>)`,
			'info'
		);
	}
});
