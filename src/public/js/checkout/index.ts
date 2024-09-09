/**
 * blocks.js: responsible for showing Scanpay in the Block-Based Checkout.
 */

/**
 * Internal dependencies
 */
import { WooPaymentMethodData } from './index.d';

/**
 * We use the `window` object to interact with the WooCommerce Blocks API.
 */
const { createElement, Fragment } = window.wp.element;
const canMakePayment = (): boolean => true;
const data = window.wc.wcSettings.getSetting(
	'scanpay_data'
) as WooPaymentMethodData;

for ( const name in data.methods ) {
	const method = data.methods[ name ];
	const content = createElement( Fragment, null, method.description );

	const label = createElement(
		'span',
		{ className: 'wc-block-components-payment-method-label wcsp-label' },
		createElement( 'span', { className: 'wcsp-title' }, method.title ),
		createElement(
			'span',
			{ className: 'wcsp-icons wcsp-icons-' + name },
			method.icons.map( ( icon: string ) =>
				createElement( 'img', {
					src: data.url + icon + '.svg',
					className: 'wcsp-icon wcsp-icon-' + icon,
				} )
			)
		)
	);

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name,
		ariaLabel: name,
		label,
		content,
		edit: content,
		canMakePayment,
		supports: {
			features: method.supports,
		},
	} );
}
