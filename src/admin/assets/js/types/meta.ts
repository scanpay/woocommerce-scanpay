/*
	Show a warning message in the meta box.
	Identical messages are shown once (see showWarning).
*/

import { checkVersion, isVersionGreater } from '../util/compat';

/** Create and append an alert div, returning it for the caller to fill. */
function appendAlert(type: string): HTMLElement {
	const div = document.createElement('div');
	div.className = 'wcsp-meta-alert wcsp-meta-alert-' + type;
	(document.getElementById('wcsp-meta-head') as HTMLElement).appendChild(div);
	return div;
}

export function showError(msg: string) {
	showWarning(msg, 'error');
}

/**
 * Show a message in the meta box.
 *
 * `msg` is inserted as text and never parsed as HTML: every caller feeds this
 * server-, backend- or DB-sourced data (response bodies, subscriber fields, money
 * columns). None of it is customer-controlled under the plugin's threat model, so
 * this is defence in depth rather than a live hole -- but the sink is one line and
 * the callers are many.
 */
export function showWarning(msg: string, type: string = 'error') {
	// Dedup by message. renderFigures() re-runs on every refresh() and onCapture()
	// can fire repeatedly, while only renderShell() ever clears this container and
	// it runs once -- so without this the same banner stacks up on each capture.
	const head = document.getElementById('wcsp-meta-head') as HTMLElement;
	if (Array.from(head.children).some((el) => el.textContent === msg)) {
		return;
	}
	appendAlert(type).textContent = msg;
}

export function buildTable(arr: [string, any][]) {
	const ul = document.getElementById('wcsp-meta-ul')!;
	ul.textContent = '';
	for (const x of arr) {
		const li = document.createElement('li');
		li.className = 'wcsp-meta-li';
		const title = document.createElement('div');
		title.className = 'wcsp-meta-li-title';
		title.textContent = x[0] + ':';
		const value = document.createElement('div');
		value.className = 'wcsp-meta-li-value';
		value.textContent = String(x[1]);
		li.append(title, value);
		ul.appendChild(li);
	}
}

export function pluginVersionCheck() {
	checkVersion()
		.then((version) => {
			if (isVersionGreater(version, '{{ VERSION }}')) {
				// The one alert that needs markup, and all of it is plugin-authored.
				// The version itself comes from the GitHub API, so it goes in as text.
				const div = appendAlert('info');
				div.innerHTML =
					'Your scanpay plugin is <b class="scanpay-outdated">outdated</b>. Please update to ' +
					'<span class="wcsp-meta-version"></span> ' +
					'(<a href="//github.com/scanpay/woocommerce-scanpay/releases" target="_blank">changelog</a>)';
				(div.querySelector('.wcsp-meta-version') as HTMLElement).textContent = version;
			}
		})
		.catch(() => {
			// update check is best-effort (GitHub rate-limit / CSP); no banner, no console noise
		});
}
