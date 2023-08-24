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
    if (!isset($d['rev']) || !is_int($d['rev'])) {
        scanpay_log('error', "Synchronization failed [$seq]: missing 'rev' in transaction [id=$d[id]]");
        die();
    }
    if (!isset($d['acts']) || !is_array($d['acts'])) {
        scanpay_log('error', "Synchronization failed [$seq]: missing 'acts' in transaction [id=$d[id]]");
        die();
    }
    if (!isset($d['totals']) || !is_array($d['totals']) || !isset($d['totals']['authorized'])) {
        scanpay_log('error', "Synchronization failed [$seq]: missing 'totals.authorized' in transaction [id=$d[id]]");
        die();
    }

    $orderid = $d['orderid'];
    $order = wc_get_order($orderid);
    if (!$order) {
        scanpay_log('warning', "Order #$orderid not found in WooCommerce");
        return; // Skip this change
    }

    $orderShopId = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID);
    $nacts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS);
    $rev = (int) $order->get_meta(WC_SCANPAY_URI_REV);

    if ($orderShopId !== $shopid) {
        scanpay_log('warning', "Order #$orderid with shopID: $orderShopId " . "does not match current shopID ($shopid)");
        return;
    }

    // Check if the order is already up to date
    if ($d['rev'] <= $rev) {
        scanpay_log('info', "Order #$orderid is already up to date");
        return;
    }

    // Revive order if it was cancelled
    if ($order->get_status() === 'cancelled') {
        $order->update_status('processing');
    }

    for ($i = $nacts; $i < count($d['acts']); $i++) {
        $act = $d['acts'][$i];
        switch ($act['act']) {
            case 'capture':
                if (isset($act['total']) && is_string($act['total'])) {
                    $order->add_order_note(sprintf('Captured %s', $act['total']));
                }
                break;
            case 'refund':
                //wc_create_refund($actArgs);
                if (isset($act['total']) && is_string($act['total'])) {
                    $order->add_order_note(sprintf('Refunded %s.', $act['total']));
                }
                break;
            case 'void':
                $order->add_meta_data(WC_SCANPAY_URI_VOIDED, 1, true);
                if (isset($act['total']) && is_string($act['total'])) {
                    $order->add_order_note(sprintf('Voided %s.', $act['total']));
                }
                break;
        }
    }

    $order->add_meta_data(WC_SCANPAY_URI_NACTS, count($d['acts']), true);

    if (isset($d['totals']['captured'])) {
        $captured = explode(' ', $d['totals']['captured'])[0];
        $order->add_meta_data(WC_SCANPAY_URI_CAPTURED, $captured, true);
    }

    if (isset($d['totals']['refunded'])) {
        $refunded = explode(' ', $d['totals']['refunded'])[0];
        $order->add_meta_data(WC_SCANPAY_URI_REFUNDED, $refunded, true);
    }

    if ($order->needs_payment()) {
        $order->payment_complete($d['id']);
    }

    if (empty($order->get_meta(WC_SCANPAY_URI_TRNID))) {
        $order->add_meta_data(WC_SCANPAY_URI_TRNID, $d['id']);
        $order->add_meta_data(WC_SCANPAY_URI_AUTHORIZED, explode(' ', $d['totals']['authorized'])[0]);
    }

    // Autocomplete virtual orders
    /*
    if ($this->settings['autocomplete_virtual'] && $order->get_status() === 'processing') {
        $this->autocompleteVirtual($order);
    }
    */

    $order->add_meta_data(WC_SCANPAY_URI_REV, $d['rev'], true);
    $order->save();
}
