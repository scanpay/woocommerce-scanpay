/**
 * checkout.js: Manages the integration of Scanpay within the Block-Based Checkout.
 * Utilizes the `window` object to interface with the WooCommerce Blocks API.
 */

/**
 * Internal dependencies
 */
import { WooPaymentMethodData } from './types/checkout';

const { createElement, Fragment } = window.wp.element;
const data = window.wc.wcSettings.getSetting('scanpay_data') as WooPaymentMethodData;

function canMakePayment(): boolean {
	return true;
}

for (const name in data.methods) {
	const method = data.methods[name];
	const content = createElement(Fragment, null, method.description);

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
		content,
		edit: content,
		canMakePayment,
		supports: {
			features: method.supports,
		},
	});
}
