/*
	Show a warning message in the meta box.
	Prevent duplicate messages.
*/

import { checkVersion, isVersionGreater, getLastSync } from './compat';

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
	});
}

export function pluginSyncCheck(secret: string) {
	getLastSync(secret).then((unixtime) => {
		const dmins = Math.floor((Math.floor(Date.now() / 1000) - unixtime) / 60);
		if (unixtime === 0 || dmins > 60 * 24 * 3) {
			return showWarning(
				`Your plugin is not synchronized with the Scanpay backend. Please follow the instructions
				<a href="https://wordpress.org/plugins/scanpay-for-woocommerce/#installation">here</a>.`
			);
		}
		if (dmins > 10) {
			showWarning('Your scanpay extension is out of sync: ' + dmins + ' minutes since last synchronization.');
		}
	});
}
