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

    if (empty($order->get_meta(WC_SCANPAY_URI_TRNID))) {
        $order->payment_complete($d['id']);
        $order->add_meta_data(WC_SCANPAY_URI_TRNID, $d['id']);
        $order->add_meta_data(WC_SCANPAY_URI_AUTHORIZED, explode(' ', $d['totals']['authorized'])[0]);

        if (isset($d['method']['type'])) {
            switch ($d['method']['type']) {
                case 'card':
                    break;
                case 'mobilepay':
                    break;
                case 'applepay':
                    break;
            }
        }

        if (isset($d['method']['card']['brand']) && isset($d['method']['card']['last4'])) {
            $order->add_meta_data(WC_SCANPAY_URI_CARD, $d['method']);
        }
    }

    if ($d['totals']['voided'] === $d['totals']['authorized']) {
        $order->add_meta_data(WC_SCANPAY_URI_VOIDED, 1, true);
        $order->update_status('cancelled');
    } elseif ($order->get_status() === 'cancelled') {
        // Revive order, but not if action is 'void'
        $order->update_status('processing');
    }

    $order->add_meta_data(WC_SCANPAY_URI_NACTS, count($d['acts']), true);
    $order->add_meta_data(WC_SCANPAY_URI_CAPTURED, explode(' ', $d['totals']['captured'])[0], true);
    $order->add_meta_data(WC_SCANPAY_URI_REFUNDED, explode(' ', $d['totals']['refunded'])[0], true);
    $order->add_meta_data(WC_SCANPAY_URI_REV, $d['rev'], true);
    $order->save();

    /*
    if ($this->settings['autocomplete_virtual'] && $order->get_status() === 'processing') {
        $this->autocompleteVirtual($order);
    }
    */
}
