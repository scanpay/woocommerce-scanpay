/**
 * checkout.js: Manages the integration of Scanpay within the Block-Based Checkout.
 * Utilizes the `window` object to interface with the WooCommerce Blocks API.
 */

/**
 * Internal dependencies
 */
import { WooPaymentMethodData } from './types/checkout';

const { createElement, Fragment, useState, useEffect } = window.wp.element;
const data = window.wc.wcSettings.getSetting('scanpay_data') as WooPaymentMethodData;

function canMakePayment(): boolean {
	return true;
}

for (const name in data.methods) {
	const method = data.methods[name];
	const terms = method.terms;

	/*
	 *  Content shown when the method is the selected gateway. Besides the description it
	 *  renders the subscription-terms checkbox (card gateway only) and enforces it:
	 *  onCheckoutValidation blocks "Place order" client-side, onPaymentSetup forwards the
	 *  acceptance to the server, which re-validates it (wcs_scanpay_blocks_validate_terms).
	 */
	function Content(props: { eventRegistration: any; emitResponse: any }) {
		const { eventRegistration, emitResponse } = props;
		const [accepted, setAccepted] = useState(false);

		useEffect(() => {
			if (!terms) {
				return;
			}
			return eventRegistration.onCheckoutValidation(() => (accepted ? true : { errorMessage: terms.error }));
		}, [accepted, eventRegistration]);

		useEffect(() => {
			if (!terms) {
				return;
			}
			return eventRegistration.onPaymentSetup(() => ({
				type: emitResponse.responseTypes.SUCCESS,
				meta: { paymentMethodData: { 'wcssp-terms': accepted ? '1' : '' } },
			}));
		}, [accepted, eventRegistration, emitResponse]);

		return createElement(
			Fragment,
			null,
			method.description,
			terms &&
				createElement(
					'label',
					{ className: 'wcsp-blocks-terms', style: { display: 'block', marginTop: '0.75em' } },
					createElement('input', {
						type: 'checkbox',
						checked: accepted,
						onChange: (e: { target: { checked: boolean } }) => setAccepted(e.target.checked),
					}),
					' ',
					terms.before,
					createElement('a', { href: terms.url, target: '_blank', rel: 'noopener noreferrer' }, terms.link),
					terms.after
				)
		);
	}

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
					src: data.url + icon + '.svg',
					className: 'wcsp-icon wcsp-icon-' + icon,
				})
			)
		)
	);

	window.wc.wcBlocksRegistry.registerPaymentMethod({
		name,
		ariaLabel: name,
		label,
		content: createElement(Content, null),
		edit: createElement(Fragment, null, method.description),
		canMakePayment,
		supports: {
			features: method.supports,
		},
	});
}
