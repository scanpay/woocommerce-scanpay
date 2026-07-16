/**
 * 	settings.js: Used in the Scanpay settings page.
 */

/**
 * Internal dependencies
 */
import { getLastSync, checkVersion, isVersionGreater } from './util/compat';

function showWarning(title: string, msg: string, id: string | false = false) {
	const html = '<h4>' + title + '</h4>' + msg;
	if (id) {
		const oldWarn = document.getElementById('wcsp-set-alert-' + id);
		if (oldWarn) return (oldWarn.innerHTML = html);
	}
	const div = document.createElement('div');
	div.id = 'wcsp-set-alert-' + id;
	div.className = 'wcsp-set-alert';
	div.innerHTML = html;
	alertBox.appendChild(div);
}

function checkMtime() {
	if (!alertBox.dataset.secret) return;
	getLastSync(alertBox.dataset.secret)
		.then((unixtime) => {
			console.log(unixtime);
			if (unixtime === 0) {
				return showWarning(
					'Initiate synchronization',
					`Please click <i>'send ping'</i> to initiate the synchronization with the scanpay backend.`,
					'sync'
				);
			}
			const dsecs = Math.floor(Date.now() / 1000) - unixtime;
			if (dsecs < 400) {
				const oldWarn = document.getElementById('wcsp-set-alert-sync');
				if (oldWarn) oldWarn.remove();
				document.getElementById('wcsp-set-nav-mtime')!.innerHTML = `<b>Synchronized:</b> ${dsecs} seconds ago.`;
			} else if (dsecs < 604800) {
				const dmins = Math.floor(dsecs / 60);
				showWarning(
					'Warning: Your system may be out of sync',
					`More than ${dmins} minutes have passed since the last received ping. Please check your <i>API key</i> and click <i>'send ping'</i>.`,
					'sync'
				);
			} else {
				showWarning(
					'Warning: Your system is out of sync',
					`A long time has passed since the last received ping. Please check your <i>API key</i> and click <i>'send ping'</i>.`,
					'sync'
				);
			}
		})
		.catch((err) => {
			showWarning(
				'Error: Something went wrong',
				'Your system responded with the following error message: ' + err.message,
				'sync'
			);
		});
}

/** POST the reset action (nonce-guarded); throws on a non-success response. */
async function postReset(nonce: string): Promise<void> {
	const res = await fetch(window.ajaxurl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({ action: 'wc_scanpay_reset', nonce }),
	});
	const json = await res.json();
	if (!res.ok || !json?.success) {
		throw new Error(typeof json?.data === 'string' ? json.data : 'reset_failed');
	}
}

/**
 * Drive the "Delete data and change API key" button. On success the page is
 * reloaded: the API key field re-renders as an empty input once the key is gone.
 */
function onReset(ev: Event): void {
	const btn = ev.currentTarget as HTMLButtonElement;
	const msg = btn.parentElement?.querySelector('.wcsp-set-reset-msg') as HTMLElement | null;
	const ok = confirm(
		'Delete all local Scanpay data and clear the API key?\n\n' +
			'This deletes the local payment tables and disables the Scanpay gateways. ' +
			'It does not affect anything at Scanpay: adding a key for the same shop ' +
			're-syncs the data automatically.'
	);
	if (!ok) return;
	btn.disabled = true;
	btn.textContent = 'Deleting…';
	postReset(btn.dataset.nonce ?? '')
		.then(() => window.location.reload())
		.catch((err) => {
			btn.disabled = false;
			btn.textContent = 'Delete data and change API key';
			if (msg) msg.textContent = ' Could not delete the data: ' + err.message;
		});
}

const resetBtn = document.querySelector('.wcsp-set-reset') as HTMLButtonElement | null;
if (resetBtn) resetBtn.addEventListener('click', onReset);

const alertBox = document.getElementById('wcsp-set-alert') as HTMLElement;
if (alertBox.dataset.shopid === '0') {
	const html = `<span class="wcsp-set-api-info">
            You can find your Scanpay API key <a target="_blank" href="https://dashboard.scanpay.dk/settings/api">here</a>.
        </span>`;

	const field = document.getElementById('woocommerce_scanpay_apikey') as HTMLElement;
	if (field) {
		// Get the <td> element that contains the input field
		const td = field.closest('td');
		if (td) td.innerHTML += html;
	}
} else {
	checkMtime();
}

// checkPing when the tab is visible again
document.addEventListener('visibilitychange', () => {
	if (document.visibilityState === 'visible') checkMtime();
});

checkVersion().then((version) => {
	if (isVersionGreater(version, '{{ VERSION }}')) {
		showWarning(
			'There is a new version of the plugin available. ',
			`Your Scanpay extension (<i>{{ VERSION }}</i>) needs to be updated to ${version}
			(<a href="//github.com/scanpay/woocommerce-scanpay/releases" target="_blank">changelog</a>).`
		);
	}
}).catch(() => {
	// update check is best-effort (GitHub rate-limit / CSP)
});
