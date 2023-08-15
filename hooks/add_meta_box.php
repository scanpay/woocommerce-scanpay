<?php

/*
*   metabox.php
*   Add transaction info to WooCommerce order view
*/

defined('ABSPATH') || exit();

function wc_scanpay_meta_alert($type, $msg) {
    echo "<div class='scanpay--alert scanpay--alert-$type'>";
    if ($type === 'pending') {
        echo '<img class="scanpay--alert--spin" width="18" height="18"
            src="' . WC_SCANPAY_URL . '/public/images/admin/spinner.svg">';
    } elseif ($type === 'error') {
        echo '<b>ERROR</b>: ';
    }
    echo $msg . '</div>';
}

function wc_scanpay_status($order)
{
    if (!empty($order->get_meta(WC_SCANPAY_URI_VOIDED))) {
        return 'voided';
    }
    $authorized = $order->get_meta(WC_SCANPAY_URI_AUTHORIZED);
    if (empty($authorized)) {
        return 'unpaid';
    }
    $captured = $order->get_meta(WC_SCANPAY_URI_CAPTURED);
    if (empty($captured)) {
        return 'authorized';
    }
    $refunded = $order->get_meta(WC_SCANPAY_URI_REFUNDED);
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
    if (!$order_shopid) {
        return wc_scanpay_meta_alert('notice', __('No transaction details found!', 'scanpay-for-woocommerce'));
    }
    wp_enqueue_script('wc-scanpay-admin', WC_SCANPAY_URL . '/public/js/pending.js', false, WC_SCANPAY_VERSION, true);

    $trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID);
    $payid = $order->get_meta(WC_SCANPAY_URI_PAYID);
    $authorized = $order->get_meta(WC_SCANPAY_URI_AUTHORIZED);
    $captured = $order->get_meta(WC_SCANPAY_URI_CAPTURED);
    $refunded = $order->get_meta(WC_SCANPAY_URI_REFUNDED);
    $acts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS);
    $rev = (int) $order->get_meta(WC_SCANPAY_URI_REV);
    $pending_sync = ((int) $order->get_meta(WC_SCANPAY_URI_PENDING_UPDATE)) > $rev;
    $currency = $order->get_currency();
    $status = wc_scanpay_status($order);
    $refund_mismatch = wc_scanpay_cmpmoney($refunded, ((string) $order->get_total_refunded()));
    $show_refund_row = !empty($refunded);

    echo '<span id="scanpay--data"
        data-order="' . $order->get_id() . '"
        data-rev="' . $rev . '"
        data-pending="' . ($pending_sync ? 'true' : 'false') . '"></span>';

    if ($pending_sync) {
        wc_scanpay_meta_alert('pending', __('Synchronizing transaction.', 'scanpay-for-woocommerce'));
    }

    /*
        Add alerts to meta-box if...
            * Error: Pending sync after Capture-on-Complete (CoC)
            * Error: No acts, but order status is 'completed' or 'refunded'
            * Error: Refunded but no refund in acts
            * Error: Woo (captured|refunded) does not match acts
            * Warn.: Ping > 7 mins
    */

    if ($acts === 0) {
        $order_status = $order->get_status();
        if ($order_status === 'completed' || $order_status === 'refunded') {
            wc_scanpay_meta_alert(
                'error', sprintf(
                    __('The order status is %s, but the transaction has not been captured.', 'scanpay-for-woocommerc'),
                    '<u>' . $order_status . '</u>'
                )
            );
        }
    } elseif ($refund_mismatch !== 0) {
        $show_refund_row = true;
        if (empty($refunded)) {
            wc_scanpay_meta_alert(
                'warning', sprintf(
                    __('For security reasons, you can only refund payments in our %s.', 'scanpay-for-woocommerc'),
                    '<a target="_blank" href="' . WC_SCANPAY_DASHBOARD . $order_shopid . '/' . $trnid .
                        '/refund">' . __('dashboard', 'scanpay-for-woocommerce') . '</a>'
                )
            );
        } else {
            wc_scanpay_meta_alert(
                'error', sprintf(
                    __('Discrepancy between what WooCommerce says has been refunded (%s) and what we have actually refunded (%s).', 'scanpay-for-woocommerc'),
                    wc_price($order->get_total_refunded(), ['currency' => $currency]), wc_price($refunded)
                )
            );
        }
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
        $captured_amount = wc_price($captured, ['currency' => $currency]);
        $net_amount = $captured_amount;

        if ($captured !== $authorized) {
            echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Authorized', 'scanpay-for-woocommerce') .':</div>
                <b>' . wc_price($authorized, ['currency' => $currency]) . '</b>
            </li>';
        }

        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Captured', 'scanpay-for-woocommerce') .':</div>
            <b>' . $captured_amount . '</b>
        </li>';

        if ($show_refund_row) {
            echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Refunded', 'scanpay-for-woocommerce') . ':</div>
                <span class="scanpay--widget--li--refunded">&minus;' . wc_price($refunded, ['currency' => $currency]) . '</span>
            </li>';
            $net_amount = wc_price(wc_scanpay_submoney($captured, $refunded), ['currency' => $currency]);
        }

        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Net payment', 'scanpay-for-woocommerce') . ':</div>
            <b>' . $net_amount . '</b>
        </li></ul>';

        if ($status !== 'fully refunded' && $status !== 'unpaid') {
            echo '<div class="scanpay--actions">
                    <div class="scanpay--actions--expand">
                        <a target="_blank" href="' . WC_SCANPAY_DASHBOARD . $order_shopid . '/' . $trnid . '">
                            <img width="22" height="22" src="' . WC_SCANPAY_URL . '/public/images/admin/link.svg">
                        </a>
                    </div>';
                    if ($captured > 0) {
                        echo '<a target="_blank" class="scanpay--widget--refund" href="' . WC_SCANPAY_DASHBOARD . $order_shopid . '/' . $trnid .
                            '/refund">' . __('Refund', 'scanpay-for-woocommerce') . '</a>';
                    } elseif ($status === 'authorized') {
                        echo '<a target="_blank" class="scanpay--widget--refund" href="' . WC_SCANPAY_DASHBOARD . $order_shopid . '/' . $trnid .
                            '">' . __('Void transaction', 'scanpay-for-woocommerce') . '</a>';
                    }
            echo '</div>';
        }
    }
}

add_meta_box('scanpay-info', 'Scanpay', 'wc_scanpay_meta_box', wc_get_page_screen_id('shop-order'), 'side', 'high');
