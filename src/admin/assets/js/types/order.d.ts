// types/global.d.ts
export { };

/**
 * Ambient types for the plugin.
 */
declare global {

    /**
     * Data structure for the order information injected into the page by PHP.
     */
    interface OrderData {
        oid: number;
        tid: number;
        subid: number;
        shopid: number;
        payid: string;
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
        currency: string;    // e.g. "DKK"
        secret: string;      // admin-AJAX polling secret (sent in the X-Scanpay header)
        dashboard: string;   // Scanpay dashboard transaction URL ('' if unsynced)
        nonce: string;       // guards the wc_scanpay_capture AJAX action
    }

    interface Window {
        ScanpayOrderData: OrderData;
        wcSettings: unknown;
        ajaxurl: string;
    }
}
