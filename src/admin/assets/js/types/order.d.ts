export {};

/**
 * Ambient types for the order meta box (order.ts). The payload is printed inline by
 * admin/orders.php, so the two must agree field for field; nothing checks that.
 */
declare global {
	/**
	 * Data structure for the order information injected into the page by PHP.
	 */
	interface OrderData {
		oid: number;
		wc_decimals: number; // e.g. 2
		meta: {
			orderid: string;
			shopid: string;
			subid: string;
			id: string;
			rev: string;
			nacts: string;
			currency: string;
			authorized: string;
			captured: string;
			refunded: string;
			voided: string;
		} | null;
		currency: string; // e.g. "DKK"
		secret: string; // admin-AJAX polling secret (sent in the X-Scanpay header)
		endpoint: string; // base URL for the ?x= polls (admin_url('admin-ajax.php'))
		dashboard: string; // Scanpay dashboard transaction URL ('' if unsynced)
		nonce: string; // guards the wc_scanpay_capture AJAX action
	}

	interface Window {
		ScanpayOrderData: OrderData;
		ajaxurl: string;
	}
}
