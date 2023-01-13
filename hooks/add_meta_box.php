<?php

/*
*   metabox.php
*   Add transaction info to WooCommerce order view
*/

defined('ABSPATH') || exit();


function wc_scanpay_meta_alert($type, $msg) {
    // TODO: this will be improved and styled (quick hack).
    echo "<div class='scanpay-info-alert scanpay-info-alert-$type'>" . $msg . '</div>';
}

function wc_scanpay_meta_box($order)
{
    require WC_SCANPAY_DIR . '/includes/math.php';

    $order_shopid = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID);
    $trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID);
    $auth = $order->get_meta(WC_SCANPAY_URI_AUTHORIZED);
    $captured = $order->get_meta(WC_SCANPAY_URI_CAPTURED);
    $refunded = $order->get_meta(WC_SCANPAY_URI_REFUNDED);
    $acts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS);
    $currency = $order->get_currency();

    if (!$order_shopid) {
        return wc_scanpay_meta_alert('notice', 'Not a Scanpay order');
    }

    // Warn if order is completed but not captured
    if ($acts === 0 && $order->get_status() === 'completed') {
        wc_scanpay_meta_alert(
            'error',
            'Order has not been captured, but it is marked as completed'
        );
    }

    // Order is not paid (through Scanpay, mby other gateway)
    if (!$trnid) {
        return wc_scanpay_meta_alert('notice', 'Order is unpaid.');
    }

    // Order is authorized, but not captured
    if ($acts === 0) {
        echo '
        <ul class="scanpay--widget--ul">
            <li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">Authorized:</div>
                ' . wc_price($auth, ['currency' => $currency]) . '
            </li>
            <li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">Captured:</div>
                ' . wc_price($captured, ['currency' => $currency]) . '
            </li>
        </ul>
        <div class="scanpay--actions">
            <a href="#" class="button">Capture</a> <a href="#" class="button">Void</a>
        </div>';
        return;
    }

    // Order is fully refunded
    if ($refunded === $auth) {
        echo '
        <ul class="scanpay--widget--ul">
            <li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">Authorized:</div>
                ' . wc_price($auth, ['currency' => $currency]) . '
            </li>
            <li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">Captured:</div>
                ' . wc_price($captured, ['currency' => $currency]) . '
            </li>
            <li class="scanpay--widget--li" style="color:#901212">
                <div class="scanpay--widget--li--title">Refunded:</div>
                ' . wc_price($refunded, ['currency' => $currency]) . '
            </li>
            <li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">Net payment:</div>
                <span>
                    ' . wc_price(wc_scanpay_submoney($captured, $refunded)) . '
                </span>
            </li>
        </ul>
        <div class="scanpay--actions">
            <a href="#" class="button">Capture</a> <a href="#" class="button">Void</a>
        </div>';
        return;
    }

    if ((float)$refunded > '0') {
        // TODO: Add
        $status = 'partial_refund';
        return;
    }

    // Order is captured and not refunded
    if ($captured === $auth) {
        echo '
        <ul class="scanpay--widget--ul">
            <li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">Authorized:</div>
                ' . wc_price($auth, ['currency' => $currency]) . '
            </li>
            <li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">Captured:</div>
                ' . wc_price($captured, ['currency' => $currency]) . '
            </li>
        </ul>
        <div class="scanpay--actions">
            <a href="#" class="button">Refund</a>
        </div>';
        return;
    }
}

$screen = wc_get_page_screen_id('shop-order');
add_meta_box(
    'scanpay-info',
    'Scanpay',
    'wc_scanpay_meta_box',
    $screen,
    'side',
    'high'  // priority
);
