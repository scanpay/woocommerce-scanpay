<?php

/*
*   Hook: 'woocommerce_order_status_completed'
*   Called when order status is changed to completed.
*   GLOBALs: $order_id
*/

defined('ABSPATH') || exit();

scanpay_log('info', time() . ': woocommerce_order_status_completed');

$order = wc_get_order($order_id);
if (!$order || substr($order->get_payment_method(), 0, 7) !== 'scanpay') {
    return;
}
$settings = get_option(WC_SCANPAY_URI_SETTINGS);
$shopid = (int) explode(':', $settings['apikey'])[0];
$trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID);
$nacts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS, true, 'edit');

if ($settings['capture_on_complete'] !== 'yes') {
    return;
}

if ($shopid !== (int) $order->get_meta(WC_SCANPAY_URI_SHOPID)) {
    scanpay_log('notice', "Capture failed: Order #$order_id has a wrong shopid");
    return;
}

if (!$trnid) {
    $err = "Capture failed: Order #$order_id has not been paid or synchronized.";
    scanpay_log('notice', $err);
    $order->add_order_note($err);
    return;
}

if ($nacts > 0) {
    scanpay_log('notice', "Capture failed: Order #$order_id has already been captured.");
    return;
}

require_once WC_SCANPAY_DIR . '/includes/math.php';
require_once WC_SCANPAY_DIR . '/includes/ScanpayClient.php';

try {
    $amount = wc_scanpay_submoney((string) $order->get_total(), (string) $order->get_total_refunded());
    $client = new WC_Scanpay_API_Client($settings['apikey']);
    $client->capture($trnid, [
        'total' => $amount . ' ' . $order->get_currency(),
        'index' => (int) $order->get_meta(WC_SCANPAY_URI_NACTS)
    ]);
    $order->delete_meta_data(WC_SCANPAY_URI_REV); // indicate out of sync.
    $order->save();
} catch (\Exception $e) {
    $err = "Capture failed on order #$order_id: " . $e->getMessage();
    scanpay_log('error', $err);
    $order->add_order_note($err);
    return;
}
