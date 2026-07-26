/**
 *	External definitions for WooCommerce Blocks.
 */
import * as wpElement from '@wordpress/element';

declare global {
	interface Window {
		wp: {
			element: typeof wpElement;
			// @wordpress/data. Only the two Blocks stores we touch: 'wc/store/validation'
			// (blocks "Place order") and 'wc/store/checkout' (carries extension data to
			// the Store API request).
			data: {
				dispatch: (store: string) => any;
				useSelect: <T>(mapSelect: (select: (store: string) => any) => T, deps?: unknown[]) => T;
			};
		};
		wc: {
			wcBlocksRegistry: {
				//defined in woocommerce/plugins/woocommerce-blocks/assets/js/blocks-registry/index.js
				registerPaymentMethod: any;
			};
			// @woocommerce/blocks-checkout. registerCheckoutBlock() with force: true renders
			// a block inside its parent area even though no merchant inserted it.
			blocksCheckout: {
				registerCheckoutBlock: (options: {
					metadata: { name: string; parent: string[] };
					component: () => any;
					force?: boolean;
				}) => void;
			};
			wcSettings: {
				getSetting: (key: string) => any;
			};
		};
		// Safari / Apple devices only; undefined everywhere else.
		ApplePaySession?: {
			canMakePayments: () => boolean;
		};
	}
}

/**
 * Data structure for the payment methods. Defined in class-wc-scanpay-blocks-support.php.
 */

interface WooPaymentMethodData {
	methods: {
		[key: string]: {
			title: string;
			description: string;
			icons: string[];
			supports: string[];
		};
	};
	url: string; // URL to the plugin's assets
	// Present when the cart holds a subscription and a published terms page is configured.
	// Not scoped to a payment method: the checkbox is a required consent for the whole
	// checkout, so it is rendered once, outside any gateway.
	terms?: {
		url: string;
		label: string; // Sentence with a %s placeholder for the link.
		link: string;
		error: string;
	};
}
