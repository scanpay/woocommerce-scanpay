/**
 *	External definitions for WooCommerce Blocks.
 */
import * as wpElement from '@wordpress/element';

declare global {
	interface Window {
		wp: {
			// TypeScript merges `Window` across the whole program, so this one declaration
			// covers the admin bundles too: wp.i18n is what they consume, guaranteed by the
			// 'wp-i18n' script dependency declared at each enqueue site in PHP. Declaring it
			// a second time in an admin d.ts would be a duplicate-property error, not a merge.
			i18n: {
				__: (text: string, domain?: string) => string;
				sprintf: (format: string, ...args: (string | number)[]) => string;
			};
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
		// Only the delegated event binding applepay.ts needs. Optional because the
		// classic checkout script declares jquery as a dependency but the global is not
		// ours to guarantee.
		jQuery?: (target: Document | HTMLElement) => {
			on: (events: string, handler: () => void) => void;
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
