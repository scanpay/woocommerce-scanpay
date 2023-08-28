<?php

/*
*   Hook: 'woocommerce_order_status_completed'
*   Called when order status is changed to completed.
*   GLOBALs: $order_id
*/

defined('ABSPATH') || exit();

$order = wc_get_order($order_id);
if (!$order || substr($order->get_payment_method(), 0, 7) !== 'scanpay') {
    return;
}
$settings = get_option(WC_SCANPAY_URI_SETTINGS);
$shopid = (int) explode(':', $settings['apikey'])[0];
$trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID);

if ($settings['capture_on_complete'] !== 'yes') {
    return;
}
if ($shopid !== (int) $order->get_meta(WC_SCANPAY_URI_SHOPID)) {
    scanpay_log('notice', "Capture failed: Order #$order_id has a wrong shopid");
    return;
}
if (!$trnid) {
    $err = "Capture failed: Order #$order_id has not been paid yet.";
    scanpay_log('notice', $err);
    $order->add_order_note($err);
    return;
}
if (!empty($order->get_meta(WC_SCANPAY_URI_CAPTURED))) {
    scanpay_log('notice', "Capture failed: Order #$order_id has already been captured");
    return;
}

try {
    require WC_SCANPAY_DIR . '/includes/math.php';
    require WC_SCANPAY_DIR . '/includes/ScanpayClient.php';

    $amount = wc_scanpay_submoney((string) $order->get_total(), (string) $order->get_total_refunded());
    $client = new WC_Scanpay_API_Client($settings['apikey']);
    $client->capture($trnid, [
        'total' => $amount . ' ' . $order->get_currency(),
        'index' => (int) $order->get_meta(WC_SCANPAY_URI_NACTS)
    ]);
    $rev = (int) $order->get_meta(WC_SCANPAY_URI_REV);
    $order->add_meta_data(WC_SCANPAY_URI_PENDING_UPDATE, $rev + 1, true);
    $order->save();
} catch (\Exception $e) {
    $err = "Capture failed on order #$order_id: " . $e->getMessage();
    scanpay_log('error', $err);
    $order->add_order_note($err);
    return;
}
