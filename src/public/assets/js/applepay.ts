/**
 * applepay.ts: remove the Apple Pay gateway from classic checkouts that cannot pay with it.
 *
 * Blocks makes this decision in checkout.ts, through the payment method's own
 * canMakePayment(). Classic checkout has no equivalent hook, so the same probe runs
 * here against the rendered gateway list; without it the row is offered in every
 * browser and dead-ends at the hosted payment window.
 *
 * A browser-capability UI gate, not an authorization boundary: nothing client-side can
 * prove capability to the server or stop a crafted request. The hosted window stays the
 * final authority.
 */

const { __ } = window.wp.i18n;

const ROW = 'li.payment_method_scanpay_applepay';
const RADIO_ID = 'payment_method_scanpay_applepay';
const NOTICE_ID = 'wcsp-applepay-unavailable';

// Set only while this script is the reason the button is disabled, so a re-enable can
// never undo one WooCommerce did for its own reasons mid-update.
let blocked = false;

/** Exactly the probe Blocks uses -- not canMakePaymentsWithActiveCard(), which is async and needs a provisioned card. */
function supported(): boolean {
	return window.ApplePaySession?.canMakePayments() === true;
}

/** Every other gateway still offered in the same list. */
function alternatives(row: HTMLElement): HTMLInputElement[] {
	const list = row.parentElement;
	if (!list) return [];
	return Array.from(list.querySelectorAll<HTMLInputElement>('input[name="payment_method"]')).filter(
		(input) => input.id !== RADIO_ID && !input.disabled
	);
}

function setSubmitDisabled(state: boolean): void {
	if (state === blocked) return;
	const submit = document.getElementById('place_order') as HTMLInputElement | HTMLButtonElement | null;
	if (submit) submit.disabled = state;
	blocked = state;
}

function setNotice(list: HTMLElement | null, show: boolean): void {
	const existing = document.getElementById(NOTICE_ID);
	if (!show) {
		existing?.remove();
		return;
	}
	if (existing || !list) return;
	const notice = document.createElement('p');
	notice.id = NOTICE_ID;
	notice.className = 'woocommerce-info';
	// role=alert so a screen reader announces it when a fragment refresh inserts it.
	notice.setAttribute('role', 'alert');
	notice.textContent = __(
		'Apple Pay is not available in this browser or on this device. Please choose another payment method.',
		'scanpay-for-woocommerce'
	);
	list.insertAdjacentElement('afterend', notice);
}

/** Take the row out of the list. Returns whether it had been the selected method. */
function dropRow(row: HTMLElement): boolean {
	const was_selected = document.querySelector<HTMLInputElement>('#' + RADIO_ID)?.checked === true;
	// Removed, not hidden. A display:none <li> still counts in :nth-child(), so a theme's
	// striping and last-child border land on the wrong row, and its radio keeps posting
	// unless separately disabled. Dropping the node settles both, and owes nothing to
	// checkout.css -- which is optional (the 'stylesheet' setting) and may not be loaded.
	row.remove();
	return was_selected;
}

/** Move the selection onto a remaining gateway. */
function selectMethod(next: HTMLInputElement): void {
	next.checked = true;
	// WooCommerce delegates 'change' on input[name="payment_method"] from
	// document.body, so a bubbling native event is what makes it redraw the box.
	next.dispatchEvent(new Event('change', { bubbles: true }));
}

function apply(): void {
	const row = document.querySelector<HTMLElement>(ROW);
	if (!row || supported()) {
		return; // Not rendered, or a device that can actually pay: leave the list alone.
	}
	// Both read before the drop: alternatives() walks row.parentElement, and the notice
	// anchors to that same list, so neither is reachable once the node is gone.
	const list = row.parentElement;
	const others = alternatives(row);
	const was_selected = dropRow(row);
	if (!others.length) {
		// Sole gateway. Nothing to fall back to, so say so and keep the order from being
		// placed until a fragment refresh brings another method.
		setNotice(list, true);
		setSubmitDisabled(true);
		return;
	}
	setNotice(null, false);
	setSubmitDisabled(false);
	if (was_selected || !others.some((input) => input.checked)) {
		selectMethod(others[0]);
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', apply);
} else {
	apply();
}

// The payment list is replaced wholesale on address, shipping and coupon changes.
// A jQuery custom event, so it needs jQuery to observe. Inert on /order-pay/, which
// fires no fragment refreshes.
window.jQuery?.(document.body).on('updated_checkout', apply);
