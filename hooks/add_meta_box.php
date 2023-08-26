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

function wc_scanpay_status(object $order): string
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


function wc_scanpay_meta_box(object $order): void
{
    $order_shopid = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID);
    if (!$order_shopid) {
        wc_scanpay_meta_alert('notice', __('No payment details found!', 'scanpay-for-woocommerce'));
        return;
    }

    require WC_SCANPAY_DIR . '/includes/math.php';
    $status = wc_scanpay_status($order);
    $rev = (int) $order->get_meta(WC_SCANPAY_URI_REV);
    $pending_sync = ((int) $order->get_meta(WC_SCANPAY_URI_PENDING_UPDATE)) > $rev;
    $acts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS);
    $authorized = $order->get_meta(WC_SCANPAY_URI_AUTHORIZED);
    $captured = $order->get_meta(WC_SCANPAY_URI_CAPTURED);
    $refunded = $order->get_meta(WC_SCANPAY_URI_REFUNDED);
    $net = wc_scanpay_submoney($captured, $refunded);
    $link = WC_SCANPAY_DASHBOARD . $order_shopid . '/' . (int) $order->get_meta(WC_SCANPAY_URI_TRNID);

    $currency = $order->get_currency();
    $woo_status = $order->get_status();
    $woo_net = wc_scanpay_submoney((string) $order->get_total(), (string) $order->get_total_refunded());
    $net_mismatch = wc_scanpay_cmpmoney($net, $woo_net);
    $show_refund_row = !empty($refunded);
    $show_auth_row = $authorized !== $captured;

    echo '<span id="scanpay--data"
        data-order="' . $order->get_id() . '"
        data-rev="' . $rev . '"
        data-pending="' . ($pending_sync ? 'true' : 'false') . '"></span>';

    if ($pending_sync) {
        wc_scanpay_meta_alert('pending', __('Awaiting scanpay synchronization.', 'scanpay-for-woocommerce'));
        return;
    }

    // Alert Boxes
    if ($acts === 0 && ($woo_status === 'cancelled' || $woo_status === 'refunded')) {
        // Tell merchant to void the payment.
        wc_scanpay_meta_alert(
            'notice',
            __('Void the payment to release the reserved amount in the customer\'s bank account. Reservations last for 7-28 days.',
                'scanpay-for-woocommerce')
        );
    } elseif ($net_mismatch !== 0 && $woo_status !== 'processing') {
        // Net payment mismatch
        $show_refund_row = true;
        $show_auth_row = true;
        wc_scanpay_meta_alert(
            'warning', sprintf(
                __('There is a discrepancy between the order net payment (%s) and your actual net payment (%s).', 'scanpay-for-woocommerc'),
                wc_price($woo_net, ['currency' => $currency]), wc_price($net, ['currency' => $currency])
            )
        );

        $refund_mismatch = wc_scanpay_cmpmoney($refunded, (string) $order->get_total_refunded());
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
        $payid = $order->get_meta(WC_SCANPAY_URI_PAYID);
        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">PayID:</div>
            <a href="' . WC_SCANPAY_DASHBOARD . 'logs?payid= ' . $payid . '">' . $payid . '</a>
        </li>';
    } else {
        if ($show_auth_row) {
            echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Authorized', 'scanpay-for-woocommerce') .':</div>
                <b>' . wc_price($authorized, ['currency' => $currency]) . '</b>
            </li>';
        }

        echo '<li class="scanpay--widget--li">
            <div class="scanpay--widget--li--title">' . __('Captured', 'scanpay-for-woocommerce') .':</div>
            <b>' . wc_price($captured, ['currency' => $currency]) . '</b>
        </li>';

        if ($show_refund_row) {
            echo '<li class="scanpay--widget--li">
                <div class="scanpay--widget--li--title">' . __('Refunded', 'scanpay-for-woocommerce') . ':</div>
                <span class="scanpay--widget--li--refunded">&minus;' . wc_price($refunded, ['currency' => $currency]) . '</span>
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
                    if ($captured > 0) {
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
