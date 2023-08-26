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

$shopid = (int) explode(':', $settings['apikey'])[0];
$order_shopid = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID);
$trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID);
$nacts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS);
$rev = $order->get_meta(WC_SCANPAY_URI_REV);

if ($order_shopid !== $shopid) {
    $err = "The order shopid ($order_shopid) does not match your scanpay shopid ($shopid)";
    scanpay_log('notice', $err);
    $order->add_order_note('Scanpay: ' . $err);
    return;
}

// Stop if order has been captured
if (isset($order->get_meta(WC_SCANPAY_URI_CAPTURED))) {
    // TODO: Add partial capture support
    return;
}

if (!$trnid) {
    $err = "Capture failed: Order #$order_id has not been synchronized yet";
    scanpay_log('notice', $err);
    $order->add_order_note($err);
    return;
}

try {
    require WC_SCANPAY_DIR . '/includes/ScanpayClient.php';
    $client = new WC_Scanpay_API_Client($settings['apikey']);
    $client->capture($trnid, [
        'total' => $order->get_total() . ' ' . $order->get_currency(),
        'index' => $nacts
    ]);
    $order->add_meta_data(WC_SCANPAY_URI_PENDING_UPDATE, $rev + 1, true);
    $order->save();
} catch (\Exception $e) {
    return $order->add_order_note("Capture failed on order #$order_id: " . $e->getMessage());
}
