/**
 * checkout.ts: Manages the integration of Scanpay within the Block-Based Checkout.
 * Utilizes the `window` object to interface with the WooCommerce Blocks API.
 */

/**
 * Internal dependencies
 */
import { WooPaymentMethodData } from './types/checkout';

const { createElement, createInterpolateElement, Fragment, useState, useEffect } = window.wp.element;
const { dispatch, useSelect } = window.wp.data;
const data = window.wc.wcSettings.getSetting('scanpay_data') as WooPaymentMethodData;

/**
 * Blocks calls this per method to decide whether to offer it. Apple Pay only works
 * on Apple devices/browsers, so a bare `return true` advertised it everywhere and
 * dead-ended the checkout for anyone who picked it. canMakePayments() is the
 * availability probe (not canMakePaymentsWithActiveCard, which is async and would
 * additionally require a provisioned card).
 */
function canMakePayment(name: string): () => boolean {
	if (name !== 'scanpay_applepay') {
		return () => true;
	}
	return () => window.ApplePaySession?.canMakePayments() === true;
}

for (const name in data.methods) {
	const method = data.methods[name];

	const label = createElement(
		'span',
		{
			className: 'wc-block-components-payment-method-label wcsp-label',
		},
		createElement('span', { className: 'wcsp-title' }, method.title),
		createElement(
			'span',
			{
				className: 'wcsp-icons wcsp-icons-' + name,
			},
			method.icons.map((icon: string) =>
				createElement('img', {
					key: icon,
					src: data.url + icon + '.svg',
					className: 'wcsp-icon wcsp-icon-' + icon,
					// Decorative: the label above already names the method.
					alt: '',
				})
			)
		)
	);

	// Shown when the method is the selected gateway. Just the description: the subscription
	// terms checkbox used to live here, but it is a checkout-wide consent (see below).
	const content = createElement(Fragment, null, method.description);

	window.wc.wcBlocksRegistry.registerPaymentMethod({
		name,
		ariaLabel: name,
		label,
		content,
		edit: content,
		canMakePayment: canMakePayment(name),
		supports: {
			features: method.supports,
		},
	});
}

/*
 *  Subscription terms checkbox (WooCommerce Subscriptions). This is a cart-level consent, so
 *  it has to apply to whichever gateway the customer picks -- including third-party ones --
 *  which is why it is not rendered inside our payment method's content.
 *
 *  registerCheckoutBlock({ force: true }) renders it even though no merchant inserted the
 *  block, so the feature needs no setup. The only other auto-rendering extension points are
 *  the ExperimentalOrder* slots, and those sit inside the order summary, which is collapsed
 *  by default on mobile -- no place for a required checkbox.
 *
 *  Parent is the payment block, not the fields block that holds it: forced blocks are
 *  appended after their parent's existing children, and the fields block's last child is
 *  the actions block, so anchoring there would render the checkbox *below* "Place order".
 *  The payment block is locked against both remove and move (its block.json), so it is
 *  always present and always ahead of the terms and actions blocks. The checkbox lands
 *  under the payment method list -- after it, not inside any single method.
 *
 *  Enforced the way WooCommerce enforces its own terms block: a validation error registered
 *  as hidden blocks "Place order" without showing a message until a submit is attempted,
 *  which un-hides it. The state is also pushed to the checkout store so the Store API
 *  request carries it for wcs_scanpay_blocks_validate_terms() to re-check server-side.
 */
const terms = data.terms;
if (terms) {
	const errorId = 'wcssp-terms';
	// Unpacked here rather than read through `terms` inside Terms(): a hoisted function
	// declaration loses the narrowing that the `if` above establishes.
	const { url, label, error: errorText } = terms;

	function Terms() {
		const [accepted, setAccepted] = useState(false);
		const error = useSelect(
			(select) => select('wc/store/validation').getValidationError(errorId),
			[]
		);

		useEffect(() => {
			const validation = dispatch('wc/store/validation');
			if (accepted) {
				validation.clearValidationError(errorId);
			} else {
				validation.setValidationErrors({
					[errorId]: { message: errorText, hidden: true },
				});
			}
			dispatch('wc/store/checkout').setExtensionData('scanpay', { terms: accepted });
			return () => validation.clearValidationError(errorId);
		}, [accepted]);

		return createElement(
			'div',
			{ className: 'wcsp-blocks-terms' },
			createElement(
				'label',
				null,
				createElement('input', {
					type: 'checkbox',
					checked: accepted,
					onChange: (e: { target: { checked: boolean } }) => setAccepted(e.target.checked),
				}),
				' ',
				// One translated sentence shared with the classic renderer, which rewrites the
				// same <a> tag in PHP. A translation that drops or mangles the tag renders as
				// plain text with no link -- every malformed-string path in
				// createInterpolateElement() falls through to its text branch. It throws only on
				// a bad conversion map, and ours is the constant below.
				createInterpolateElement(label, {
					a: createElement('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }),
				})
			),
			// Same markup as WooCommerce's ValidationInputError so the message picks up core
			// styling, without binding to a component that may move between versions.
			error?.message &&
				!error.hidden &&
				createElement(
					'div',
					{ className: 'wc-block-components-validation-error', role: 'alert' },
					createElement('p', null, error.message)
				)
		);
	}

	window.wc.blocksCheckout.registerCheckoutBlock({
		metadata: {
			name: 'scanpay/wcs-terms',
			parent: ['woocommerce/checkout-payment-block'],
		},
		component: Terms,
		force: true,
	});
}
