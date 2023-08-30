<?php

/*
*   metabox.php
*   Add transaction info to WooCommerce order view
*/

defined('ABSPATH') || exit();

function wc_scanpay_meta_alert(string $type, string $msg): void
{
    echo "<div class='scanpay--alert scanpay--alert-$type'>";
    if ($type === 'pending') {
        echo '<img class="scanpay--alert--spin" width="18" height="18"
            src="' . WC_SCANPAY_URL . '/public/images/admin/spinner.svg">';
    } elseif ($type === 'error') {
        echo '<strong>ERROR</strong>: ';
    }
    echo $msg . '</div>';
}

function wc_scanpay_status(array $totals): string
{
    if ($totals['voided'] === $totals['authorized']) {
        return 'voided';
    }
    if ($totals['authorized'] === '0') {
        return 'unpaid';
    }
    if ($totals['captured'] === '0') {
        return 'authorized';
    }
    if ($totals['refunded'] === '0') {
        if ($totals['captured'] === $totals['authorized']) {
            return 'fully captured';
        }
        return 'partially captured';
    }
    if ($totals['captured'] === $totals['refunded']) {
        return 'fully refunded';
    }
    return 'partially refunded';
}

function wc_scanpay_meta_box(object $order): void
{
    $order_shopid = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID, true, 'edit');
    if (!$order_shopid) {
        wc_scanpay_meta_alert('notice', __('No payment details found!', 'scanpay-for-woocommerce'));
        return;
    }

    require WC_SCANPAY_DIR . '/includes/math.php';
    $currency = $order->get_currency();
    $woo_status = $order->get_status();
    $woo_net = wc_scanpay_submoney(strval($order->get_total()), strval($order->get_total_refunded()));

    $trnid = (int) $order->get_meta(WC_SCANPAY_URI_TRNID, true, 'edit');
    $rev = (int) $order->get_meta(WC_SCANPAY_URI_REV, true, 'edit');
    $nacts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS, true, 'edit');
    $totals = (array) $order->get_meta(WC_SCANPAY_URI_TOTALS, true, 'edit');
    $method = (array) $order->get_meta(WC_SCANPAY_URI_PAYMENT_METHOD, true, 'edit');

    foreach ($totals as $key => $value) {
        $totals[$key] = substr($value, 0, -4); // tmp solution
    }

    echo '<span id="scanpay--data" data-order="' . $order->get_id() . '" data-rev="' . $rev . '"></span>';

    if ($rev === 0) {
        wc_scanpay_meta_alert('pending', __('Awaiting scanpay synchronization.', 'scanpay-for-woocommerce'));
        return;
    }
    $status = wc_scanpay_status($totals);
    $link = WC_SCANPAY_DASHBOARD . $order_shopid . '/' . $trnid;
    $net = wc_scanpay_submoney($totals['captured'], $totals['refunded']);
    $mismatch = $woo_status !== 'processing' && wc_scanpay_cmpmoney($net, $woo_net) !== 0;

    // Alert Boxes
    if ($nacts === 0 && ($woo_status === 'cancelled' || $woo_status === 'refunded')) {
        // Tell merchant to void the payment.
        wc_scanpay_meta_alert(
            'notice',
            __('Void the payment to release the reserved amount in the customer\'s bank account. Reservations last for 7-28 days.',
                'scanpay-for-woocommerce')
        );
    } elseif ($mismatch) {
        // Net payment mismatch
        wc_scanpay_meta_alert(
            'warning', sprintf(
                __('There is a discrepancy between the order net payment (%s) and your actual net payment (%s).', 'scanpay-for-woocommerc'),
                wc_price($woo_net, ['currency' => $currency]), wc_price($net, ['currency' => $currency])
            )
        );

        $refund_mismatch = wc_scanpay_cmpmoney($totals['refunded'], (string) $order->get_total_refunded());
        if ($refund_mismatch !== 0 && empty($refunded)) {
            // Merchant likely forgot to refund in our dashboard
            wc_scanpay_meta_alert(
                'notice', sprintf(
                    __('For security reasons, payments can only be refunded in the scanpay %s.', 'scanpay-for-woocommerc'),
                    '<a target="_blank" href="' . $link . '/refund">' . __('dashboard', 'scanpay-for-woocommerce') . '</a>'
                )
            );
        }
    }

    /*
        Transaction details
    */
    echo '
    <ul class="scanpay--widget--ul">
        <li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Status', 'scanpay-for-woocommerce') . ':</div>
            <b class="scanpay--widget--status--' . preg_replace('/\s+/', '-', $status) . '">' . $status . '</b>
        </li>';

    if ($status === 'unpaid') {
        $payid = $order->get_meta(WC_SCANPAY_URI_PAYID, true, 'edit');
        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">PayID:</div>
            <a href="' . WC_SCANPAY_DASHBOARD . 'logs?payid= ' . $payid . '">' . $payid . '</a>
        </li>';
    } else {
        if (is_array($method) && isset($method['type'])) {
            echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Method', 'scanpay-for-woocommerce') . ':</div>
                <div class="scanpay--widget--li--card">';

            if ($method['type'] === 'applepay') {
                echo '<img class="scanpay--widget--li--card--applepay" title="Apple Pay"
                    src="' . WC_SCANPAY_URL . '/public/images/admin/methods/applepay.svg">';
            } elseif ($method['type'] === 'mobilepay') {
                echo '<img class="scanpay--widget--li--card--mobilepay" title="MobilePay"
                    src="' . WC_SCANPAY_URL . '/public/images/admin/methods/mobilepay.svg">';
            }
            if (isset($method['card']['brand']) && isset($method['card']['last4'])) {
                echo '<img class="scanpay--widget--li--card--' . $method['card']['brand'] . '" title="' . $method['card']['brand'] . '"
                        src="' . WC_SCANPAY_URL . '/public/images/admin/methods/' . $method['card']['brand'] . '.svg">
                    <span class="scanpay--widget--li--card--dots">•••</span><b>' . $method['card']['last4'] . '</b>';
            }
            echo '</div></li>';
        }

        echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Authorized', 'scanpay-for-woocommerce') .':</div>
                <b>' . wc_price($totals['authorized'], ['currency' => $currency]) . '</b>
            </li>';

        if ($totals['captured'] > 0) {
            echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Captured', 'scanpay-for-woocommerce') .':</div>
                <b>' . wc_price($totals['captured'], ['currency' => $currency]) . '</b>
            </li>';
        }

        if ($mismatch || $totals['refunded'] > 0) {
            echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Refunded', 'scanpay-for-woocommerce') . ':</div>
                <span class="scanpay--widget--li--refunded">&minus;' . wc_price($totals['refunded'], ['currency' => $currency]) . '</span>
            </li>';
        }

        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Net payment', 'scanpay-for-woocommerce') . ':</div>
            <b>' . wc_price($net, ['currency' => $currency]) . '</b>
        </li></ul>';

        if ($status !== 'fully refunded' && $status !== 'unpaid') {
            echo '<div class="scanpay--actions">
                    <div class="scanpay--actions--expand">
                        <a target="_blank" href="' . $link . '">
                            <img width="22" height="22" src="' . WC_SCANPAY_URL . '/public/images/admin/link.svg">
                        </a>
                    </div>';
                    if ($totals['captured'] > 0) {
                        echo '<a target="_blank" class="scanpay--widget--refund" href="' . $link . '/refund">' .
                            __('Refund', 'scanpay-for-woocommerce') . '</a>';
                    } elseif ($status === 'authorized') {
                        echo '<a target="_blank" class="scanpay--widget--refund" href="' . $link . '">' .
                            __('Void payment', 'scanpay-for-woocommerce') . '</a>';
                    }
            echo '</div>';
        }
    }
}

add_meta_box('scanpay-info', 'Scanpay', 'wc_scanpay_meta_box', wc_get_page_screen_id('shop-order'), 'side', 'high');
