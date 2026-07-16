/*
	Show a warning message in the meta box.
	Prevent duplicate messages.
*/

import { checkVersion, isVersionGreater } from '../util/compat';

export function showError(msg: string) {
	showWarning(msg, 'error');
}

export function showWarning(msg: string, type: string = 'error') {
	const div = document.createElement('div');
	div.className = 'wcsp-meta-alert wcsp-meta-alert-' + type;
	div.innerHTML = msg;
	(document.getElementById('wcsp-meta-head') as HTMLElement).appendChild(div);
}

export function buildTable(arr: [string, any][]) {
	let html = '';
	for (const x of arr) {
		html += `<li class="wcsp-meta-li">
			<div class="wcsp-meta-li-title">${x[0]}:</div>
			<div class="wcsp-meta-li-value">${x[1]}</div>
		</li>`;
	}
	document.getElementById('wcsp-meta-ul')!.innerHTML = html;
}

export function pluginVersionCheck() {
	checkVersion().then((version) => {
		if (isVersionGreater(version, '{{ VERSION }}')) {
			showWarning(
				`Your scanpay plugin is <b class="scanpay-outdated">outdated</b>. Please update to ${version}
				(<a href="//github.com/scanpay/woocommerce-scanpay/releases" target="_blank">changelog</a>)`,
				'info'
			);
		}
	}).catch(() => {
		// update check is best-effort (GitHub rate-limit / CSP); no banner, no console noise
	});
}
