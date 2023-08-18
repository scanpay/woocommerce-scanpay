<?php

/*
*   Admin AJAX to check order rev.
*/

defined('ABSPATH') || exit();
ignore_user_abort(false);
wc_nocache_headers();
wc_set_time_limit(0);

if (!isset($_GET["order"]) || !isset($_GET["rev"]) || !current_user_can('edit_shop_orders')) {
    wp_send_json(['error' => 'forbidden'], 403);
    exit;
}

$old_rev = (int) $_GET["rev"];
$order = wc_get_order((int) $_GET["order"]);

if (!$order) {
    wp_send_json(['error' => 'order not found'], 404);
    exit;
}

// Backoff strategy: 500ms, 1s, 2s, 4s, 4s, 4s. Total: 15.5s
$b = 500000; // 500 ms
$i = 0;
while(++$i < 7) {
    $order_rev = $order->get_meta(WC_SCANPAY_URI_REV);
    if ($order_rev > $old_rev) {
        return wp_send_json_success(array(
            "rev" => $order_rev,
        ));
    }
    usleep($b);
    if ($b < 4000000) {
        $b = $b * 2;
    }

    // Echo and flush to detect if the client has disconnected.
    echo "\n";
    ob_flush();
    flush();

    // ignore_user_abort(false) will terminate the script for us before
    // but we will leave this here for good measure.
    if (connection_status() !== 0) {
        scanpay_log('notice', $c . ': Connection closed by client...');
        die;
    }
}

wp_send_json_success(array(
    "rev" => $old_rev
));
