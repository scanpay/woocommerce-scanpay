<?php

/*
*   metabox.php
*   Add transaction info to WooCommerce order view
*/

defined('ABSPATH') || exit();

function wc_scanpay_meta_alert($type, $msg) {
    // TODO: this will be improved and styled (quick hack).
    echo "<div class='scanpay-info-alert scanpay-info-alert-$type'>" .
        '<img class="scanpay-info-svg" width="18" height="18" src="' . WC_SCANPAY_URL .
        '/public/images/admin/spinner.svg">' . $msg . '</div>';
}

function wc_scanpay_status($order)
{
    $authorized = $order->get_meta(WC_SCANPAY_URI_AUTHORIZED);
    $captured = $order->get_meta(WC_SCANPAY_URI_CAPTURED);
    $refunded = $order->get_meta(WC_SCANPAY_URI_REFUNDED);

    if (!empty($order->get_meta(WC_SCANPAY_URI_VOIDED))) {
        return 'voided';
    }
    if (empty($authorized)) {
        return 'unpaid';
    }
    if (empty($captured)) {
        return 'authorized';
    }
    if (empty($refunded)) {
        if ($captured === $authorized) {
            return 'fully captured';
        }
        return 'partially captured';
    }
    if ($captured === $refunded) {
        return 'fully refunded';
    }
    return 'partially refunded';
}


function wc_scanpay_meta_box($order)
{
    require WC_SCANPAY_DIR . '/includes/math.php';

    $order_shopid = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID);
    $trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID);
    $payid = $order->get_meta(WC_SCANPAY_URI_PAYID);
    $captured = $order->get_meta(WC_SCANPAY_URI_CAPTURED);
    $refunded = $order->get_meta(WC_SCANPAY_URI_REFUNDED);
    $acts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS);
    $rev = (int) $order->get_meta(WC_SCANPAY_URI_REV);
    $pending_update = (int) $order->get_meta(WC_SCANPAY_URI_PENDING_UPDATE);
    $currency = $order->get_currency();
    $status = wc_scanpay_status($order);

    if (!$order_shopid) {
        return wc_scanpay_meta_alert('notice', __('No transaction details found', 'scanpay-for-woocommerce'));
    }

    if ($pending_update > $rev) {
        wc_scanpay_meta_alert('notice', __('Synchronizing transaction.', 'scanpay-for-woocommerce'));

        wp_enqueue_script(
            'wc-scanpay-admin',
            WC_SCANPAY_URL . '/public/js/test.js',
            false,
            WC_SCANPAY_VERSION,
            true
        );
        echo '<span id="scanpay--widget" data-order="' . $order->get_id() . '" data-rev="' . $rev . '"></span>';
    } elseif ($acts === 0 && $order->get_status() === 'completed') {
        wc_scanpay_meta_alert(
            'error',
            __('The order is marked as completed, but it has not been captured.', 'scanpay-for-woocommerce')
        );
    }

    echo '
    <ul class="scanpay--widget--ul">
        <li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Status', 'scanpay-for-woocommerce') . ':</div>
            <b class="scanpay--widget--status--' . preg_replace('/\s+/', '-', $status) . '">' . $status . '</b>
        </li>';

    if ($status === 'unpaid') {
        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">PayID:</div>
            <a href="' . WC_SCANPAY_DASHBOARD . 'logs?payid= ' . $payid . '">' . $payid . '</a>
        </li>';
    } else {
        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Captured', 'scanpay-for-woocommerce') .':</div>
            <b>' . wc_price($captured, ['currency' => $currency]) . '</b>
        </li>';
    }

    if (!empty($refunded) && $refunded > 0) {
        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Refunded', 'scanpay-for-woocommerce') . ':</div>
            <span class="scanpay--widget--li--refunded">&minus;' . wc_price($refunded, ['currency' => $currency]) . '</span>
        </li>
        <li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Net payment', 'scanpay-for-woocommerce') . ':</div>
            <b>' . wc_price(wc_scanpay_submoney($captured, $refunded)) . '</b>
        </li>';
    }

    echo '</ul>
    <div class="scanpay--actions">
        <div class="scanpay--actions--expand">
            <img width="22" height="22" src="' . WC_SCANPAY_URL . '/public/images/admin/expand.svg">
        </div>';
        if ($captured > 0 && $captured !== $refunded) {
            echo '<a target="_blank" href="' . WC_SCANPAY_DASHBOARD . $order_shopid . '/' . $trnid .
                '">' . __('Refund', 'scanpay-for-woocommerce') . '</a>';
        } elseif ($status !== 'voided' && $status !== 'unpaid') {
            echo '<a target="_blank" href="' . WC_SCANPAY_DASHBOARD . $order_shopid . '/' . $trnid .
                '">' . __('Void transaction', 'scanpay-for-woocommerce') . '</a>';
        }
    echo '</div>';
}

add_meta_box(
    'scanpay-info',
    'Scanpay',
    'wc_scanpay_meta_box',
    wc_get_page_screen_id('shop-order'),
    'side',
    'high'
);
