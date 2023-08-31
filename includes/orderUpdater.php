<?php

defined('ABSPATH') || exit();

function scanpay_order_updater(array $d, int $seq, int $shopid, array $settings): void
{
    if (!isset($d['id']) || !is_int($d['id'])) {
        scanpay_log('error', "Synchronization failed [$seq]: missing 'id' in transaction");
        die();
    }
    if (isset($d['error'])) {
        scanpay_log('error', "Synchronization error [$seq]: transaction [id=$d[id]] skipped due to error: $d[error]");
        return; // Skip this transaction
    }
    if (!isset($d['orderid'])) {
        return; // Skip this transaction
    }
    if (
        !isset($d['rev']) || !is_int($d['rev']) || !isset($d['acts']) || !is_array($d['acts']) ||
        !isset($d['totals']['authorized'])
    ) {
        scanpay_log('error', "Synchronization failed [$seq]: received invalid seq from server");
        // TODO: mark the order as invalid/buggy (warning)
        die();
    }

    $orderid = $d['orderid'];
    $order = wc_get_order($orderid);
    $order_status = $order->get_status();

    if (!$order) {
        scanpay_log('warning', "Order #$orderid not found in WooCommerce");
        return; // Skip this change
    }
    if ($shopid !== (int) $order->get_meta(WC_SCANPAY_URI_SHOPID)) {
        scanpay_log('warning', "Order #$orderid does not match current shopID ($shopid)");
        return;
    }
    if ($d['rev'] <= intval($order->get_meta(WC_SCANPAY_URI_REV))) {
        return; // This change has already been applied
    }

    $order->add_meta_data(WC_SCANPAY_URI_TOTALS, $d['totals'], true);
    $order->add_meta_data(WC_SCANPAY_URI_NACTS, count($d['acts']), true);
    $order->add_meta_data(WC_SCANPAY_URI_REV, $d['rev'], true);

    if (empty($order->get_meta(WC_SCANPAY_URI_TRNID))) {
        $order->add_meta_data(WC_SCANPAY_URI_TRNID, $d['id']);
        $order->add_meta_data(WC_SCANPAY_URI_PAYMENT_METHOD, $d['method']);
        $order->payment_complete($d['id']); // Changes order status to 'processing'
    }
    if ($order_status === 'pending') {
        if ($settings['autocomplete_all'] === 'yes' && $order->get_status() !== 'completed') {
            scanpay_log('info', "Auto-complete order #$orderid");
            $order->set_status('completed');
        }
    }
    $order->save();
    return;
}
