<?php

/*
*   Hook: 'woocommerce_order_status_completed'
*   Called when order status is changed to completed.
*   GLOBALs: $order_id
*/

defined('ABSPATH') || exit();

$order = wc_get_order($order_id);
$settings = get_option(WC_SCANPAY_URI_SETTINGS);

if (!$order || $settings['capture_on_complete'] !== 'yes') {
    return;
}

// Stop if order is a Subscription (TODO: use order type)
if (class_exists('WC_Subscriptions') && wcs_is_subscription($order)) {
    return;
}

require_once WC_SCANPAY_DIR . '/includes/ScanpayClient.php';
$order_shopid = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID);
$shopid = (int) explode(':', $settings['apikey'])[0];

if ($order_shopid !== $shopid) {
    $err = "The shopid ($order_shopid) in order #$order_id does not match the API key ($shopid)";
    scanpay_log('notice', $err);
    $order->add_order_note('Scanpay: ' . $err);
    return;
}

// Stop if order has been captured
if ((int) $order->get_meta(WC_SCANPAY_URI_CAPTURED)) {
    $err = "Capture failed: A capture has already been performed on order (#$order_id)";
    scanpay_log('notice', $err);
    $order->add_order_note($err);
    return;
}

$trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID);
if (!$trnid) {
    $err = "Capture failed: Order #$order_id has not been synchronized yet";
    scanpay_log('notice', $err);
    $order->add_order_note($err);
    return;
}

try {
    $client = new WC_Scanpay_API_Client($settings['apikey']);
    $client->capture($trnid, [
        'total' => $order->get_total() . ' ' . $order->get_currency(),
        'index' => (int) $order->get_meta(WC_SCANPAY_URI_NACTS)
    ]);
} catch (\Exception $e) {
    $order->add_order_note("Capture failed on order #$order_id: " . $e->getMessage());
}
