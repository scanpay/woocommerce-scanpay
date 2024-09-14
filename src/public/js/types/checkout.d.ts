/**
 *	External definitions for WooCommerce Blocks.
 */
import * as wpElement from '@wordpress/element';

declare global {
	interface Window {
		wp: {
			element: typeof wpElement;
		};
		wc: {
			wcBlocksRegistry: {
				//defined in woocommerce/plugins/woocommerce-blocks/assets/js/blocks-registry/index.js
				registerPaymentMethod: any;
			};
			wcSettings: {
				getSetting: (key: string) => any;
			};
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
}
