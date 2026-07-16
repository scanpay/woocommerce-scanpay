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
        wc_total: number;    // minor units
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
        nonce: string;
    }

    interface Window {
        ScanpayOrderData: OrderData;
        wcSettings: unknown;
    }
}
