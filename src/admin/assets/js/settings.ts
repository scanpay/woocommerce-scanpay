/**
 * settings.ts: the Scanpay gateway settings screens (WooCommerce > Settings > Payments).
 *
 * Renders the "last synchronized" indicator and the sync / out-of-date warnings into
 * #wcsp-set-alert, and drives the "delete data and change API key" button.
 */

/**
 * Internal dependencies
 */
import { getLastSync, checkVersion, isVersionGreater } from './util/compat';

import { __, _n, sprintf } from './util/i18n';

/**
 * Render an alert into #wcsp-set-alert.
 *
 * `msg` is parsed as HTML and must stay plugin-authored markup. Anything dynamic --
 * notably the ping endpoint's response body, which arrives here as err.message --
 * goes in `detail`, which is appended as a text node and never parsed.
 */
function showWarning(title: string, msg: string, id: string | false = false, detail: string = ''): HTMLElement {
	let div = id ? document.getElementById('wcsp-set-alert-' + id) : null;
	if (!div) {
		div = document.createElement('div');
		// Only when there is one: an id-less caller used to stringify false into
		// id="wcsp-set-alert-false".
		if (id) {
			div.id = 'wcsp-set-alert-' + id;
		}
		div.className = 'wcsp-set-alert';
		alertBox.appendChild(div);
	}
	// Rebuild in place: <h4>title</h4> followed by msg's nodes, so the alert keeps
	// the flat shape '.wcsp-set-alert > h4' is styled against.
	div.textContent = '';
	const h4 = document.createElement('h4');
	h4.textContent = title;
	div.appendChild(h4);
	div.insertAdjacentHTML('beforeend', msg);
	if (detail) {
		div.appendChild(document.createTextNode(detail));
	}
	return div;
}

function checkMtime() {
	if (!alertBox.dataset.secret) return;
	getLastSync(alertBox.dataset.secret, alertBox.dataset.endpoint ?? '')
		.then((unixtime) => {
			if (unixtime === 0) {
				return showWarning(
					__('Initiate synchronization', 'scanpay-for-woocommerce'),
					__(
						"Please click <i>'send ping'</i> to initiate the synchronization with the scanpay backend.",
						'scanpay-for-woocommerce'
					),
					'sync'
				);
			}
			const dsecs = Math.floor(Date.now() / 1000) - unixtime;
			if (dsecs < 400) {
				const oldWarn = document.getElementById('wcsp-set-alert-sync');
				if (oldWarn) oldWarn.remove();
				document.getElementById('wcsp-set-nav-mtime')!.innerHTML = sprintf(
					/* translators: %d is a number of seconds. */
					_n('<b>Synchronized:</b> %d second ago.', '<b>Synchronized:</b> %d seconds ago.', dsecs, 'scanpay-for-woocommerce'),
					dsecs
				);
			} else if (dsecs < 604800) {
				const dmins = Math.floor(dsecs / 60);
				showWarning(
					__('Warning: Your system may be out of sync', 'scanpay-for-woocommerce'),
					sprintf(
						/* translators: %d is a number of minutes. */
						_n(
							"More than %d minute has passed since the last received ping. Please check your <i>API key</i> and click <i>'send ping'</i>.",
							"More than %d minutes have passed since the last received ping. Please check your <i>API key</i> and click <i>'send ping'</i>.",
							dmins,
							'scanpay-for-woocommerce'
						),
						dmins
					),
					'sync'
				);
			} else {
				showWarning(
					__('Warning: Your system is out of sync', 'scanpay-for-woocommerce'),
					__(
						"A long time has passed since the last received ping. Please check your <i>API key</i> and click <i>'send ping'</i>.",
						'scanpay-for-woocommerce'
					),
					'sync'
				);
			}
		})
		.catch((err) => {
			showWarning(
				__('Error: Something went wrong', 'scanpay-for-woocommerce'),
				__('Your system responded with the following error message: ', 'scanpay-for-woocommerce'),
				'sync',
				// The raw response body, appended as text and never translated.
				err.message
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
		__(
			'Delete all local Scanpay data and clear the API key?\n\nThis deletes the local payment tables and disables the Scanpay gateways. It does not affect anything at Scanpay: adding a key for the same shop re-syncs the data automatically.',
			'scanpay-for-woocommerce'
		)
	);
	if (!ok) return;
	// Captured, not re-translated: PHP owns this label
	// (abstract-wc-gateway-scanpay-base.php), and a second copy of the msgid here would be
	// a catalog entry that has to stay in step with it for nothing. order.ts does the same.
	const label = btn.textContent;
	btn.disabled = true;
	btn.textContent = __('Deleting…', 'scanpay-for-woocommerce');
	postReset(btn.dataset.nonce ?? '')
		.then(() => {
			// The ping cache outlives the tables it describes. Left in place, a timestamp
			// under 5 minutes old lets the reloaded page report "Synchronized N seconds
			// ago" for a shop whose data was just deleted.
			localStorage.removeItem('scanpay_lastPing');
			window.location.reload();
		})
		.catch((err) => {
			btn.disabled = false;
			btn.textContent = label;
			if (msg) {
				msg.textContent = sprintf(
					/* translators: %s is the raw error code the server returned, which is not translated. */
					__(' Could not delete the data: %s', 'scanpay-for-woocommerce'),
					err.message
				);
			}
		});
}

const resetBtn = document.querySelector('.wcsp-set-reset') as HTMLButtonElement | null;
if (resetBtn) resetBtn.addEventListener('click', onReset);

const alertBox = document.getElementById('wcsp-set-alert') as HTMLElement;
if (alertBox.dataset.shopid === '0') {
	const html =
		'<span class="wcsp-set-api-info">' +
		__(
			'You can find your Scanpay API key <a target="_blank" href="https://dashboard.scanpay.dk/settings/api">here</a>.',
			'scanpay-for-woocommerce'
		) +
		'</span>';

	const field = document.getElementById('woocommerce_scanpay_apikey') as HTMLElement;
	if (field) {
		// Appended, never `innerHTML +=`: that serialises the cell and re-parses it, which
		// replaces the API key <input> itself -- dropping anything already typed into it
		// along with the listeners WooCommerce's settings JS has bound to it.
		const td = field.closest('td');
		if (td) td.insertAdjacentHTML('beforeend', html);
	}
} else {
	checkMtime();
	// Registered inside the else, under the same condition as the call above. With no key
	// stored, ?x=ping answers 403 'invalid apikey' (wp-scanpay-fetch-ping.php), and
	// checkMtime()'s own guard does not cover it -- install.php mints the secret at
	// activation, long before a key is entered. The branch above hands the merchant a
	// target="_blank" link to fetch that key, so an ungated listener would greet every
	// first-time setup with "Error: Something went wrong" on the way back to the tab.
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') checkMtime();
	});
}

checkVersion()
	.then((version) => {
		// From the #wcsp-set-alert data attribute rather than a {{ VERSION }} token in the
		// message: build.sh substitutes tokens in .js but not in the Jed .json catalog, so
		// a token inside a msgid would key the catalog on the literal and the lookup would
		// silently miss -- the string would stay English rather than look broken.
		const current = alertBox.dataset.version ?? '';
		if (!current || !isVersionGreater(version, current)) {
			return;
		}
		// A real id, so a re-check replaces this banner instead of appending another.
		const div = showWarning(
			__('There is a new version of the plugin available. ', 'scanpay-for-woocommerce'),
			sprintf(
				/* translators: %1$s is the installed plugin version. The <span> is filled in with the latest version. */
				__(
					'Your Scanpay extension (<i>%1$s</i>) needs to be updated to <span class="wcsp-set-version"></span> (<a href="//github.com/scanpay/woocommerce-scanpay/releases" target="_blank">changelog</a>).',
					'scanpay-for-woocommerce'
				),
				current
			),
			'version'
		);
		// The version comes from the GitHub API, so it goes in as text.
		const span = div.querySelector('.wcsp-set-version');
		if (span) {
			span.textContent = version;
		}
	})
	.catch(() => {
		// update check is best-effort (GitHub rate-limit / CSP)
	});
