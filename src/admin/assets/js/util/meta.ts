/**
 * Rendering helpers for the Scanpay meta box, shared by the order edit screen (order.ts)
 * and the subscription edit screen (subs.ts): renderShell() builds the box's inner
 * markup, then alerts and the out-of-date banner go into #wcsp-meta-head and the figures
 * list into #wcsp-meta-ul.
 *
 * Every helper no-ops when the meta box is not on the page. Each looks its own container
 * up by id and returns when it is missing, so no caller has to guard.
 */

import { checkVersion, isVersionGreater } from './compat';
import { __ } from './i18n';

/**
 * Build the box's inner markup, replacing whatever is there. The sole owner of the ids
 * the helpers below look up, which is why both server-side renderers print an empty
 * #wcsp-meta and nothing inside it (orders.php, subscriptions.php). The foot stays empty
 * on the subscription screen, which has no actions to offer.
 */
export function renderShell(): void {
	const box = document.getElementById('wcsp-meta');
	if (!box) {
		return;
	}
	box.innerHTML =
		'<div id="wcsp-meta-head"></div>' +
		'<ul id="wcsp-meta-ul" class="wcsp-meta-ul"></ul>' +
		'<div id="wcsp-meta-foot"></div>';
}

/** The alert container, or null when the meta box was not rendered. */
function alertHead(): HTMLElement | null {
	return document.getElementById('wcsp-meta-head');
}

/** Create and append an alert div, returning it for the caller to fill. */
function appendAlert(head: HTMLElement, type: string): HTMLElement {
	const div = document.createElement('div');
	div.className = 'wcsp-meta-alert wcsp-meta-alert-' + type;
	head.appendChild(div);
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
	const head = alertHead();
	if (!head) {
		return;
	}
	// Dedup by message. renderFigures() re-runs on every refresh() and onCapture()
	// can fire repeatedly, while only renderShell() ever clears this container and
	// it runs once -- so without this the same banner stacks up on each capture.
	if (Array.from(head.children).some((el) => el.textContent === msg)) {
		return;
	}
	appendAlert(head, type).textContent = msg;
}

export function buildTable(arr: [string, any][]) {
	const ul = document.getElementById('wcsp-meta-ul');
	if (!ul) {
		return;
	}
	ul.textContent = '';
	for (const x of arr) {
		const li = document.createElement('li');
		li.className = 'wcsp-meta-li';
		const title = document.createElement('div');
		title.className = 'wcsp-meta-li-title';
		title.textContent = x[0] + ':';
		const value = document.createElement('div');
		value.textContent = String(x[1]);
		li.append(title, value);
		ul.appendChild(li);
	}
}

export function pluginVersionCheck() {
	checkVersion()
		.then((version) => {
			const head = alertHead();
			if (!head || !isVersionGreater(version, '{{ VERSION }}')) {
				return;
			}
			// The one alert that needs markup, and all of it is plugin-authored.
			// The version itself comes from the GitHub API, so it goes in as text.
			const div = appendAlert(head, 'info');
			// A separate msgid from the settings-screen banner on purpose: that one names the
			// running version, and only that screen has one to render.
			div.innerHTML = __(
				'Your scanpay plugin is <b>outdated</b>. Please update to <span class="wcsp-meta-version"></span> (<a href="//github.com/scanpay/woocommerce-scanpay/releases" target="_blank">changelog</a>)',
				'scanpay-for-woocommerce'
			);
			const span = div.querySelector('.wcsp-meta-version');
			if (span) {
				span.textContent = version;
			}
		})
		.catch(() => {
			// update check is best-effort (GitHub rate-limit / CSP); no banner, no console noise
		});
}
