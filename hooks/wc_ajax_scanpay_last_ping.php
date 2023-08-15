<?php

/*
*   Admin AJAX to check order rev.
*/

defined('ABSPATH') || exit();

if (!isset($_GET["order"]) || !isset($_GET["rev"]) || !isset($_GET["fib"])) {
    exit;
}

if (!current_user_can('edit_shop_orders')) {
    wp_send_json(['error' => 'forbidden'], 403);
    exit;
}

$order_id = (int) $_GET["order"];
$old_rev = (int) $_GET["rev"];
$fib = min(8, (int) $_GET["fib"]);

$order = wc_get_order($order_id);
if (!$order) {
    wp_send_json(['error' => 'order not found'], 404);
    exit;
}

// Fibonacci backoff strategy (1, 1, 2, 3, 5, 8 ...)
$a = 0;
$b = 1;
$c = 0;
while(++$c < $fib) {
    $order_rev = $order->get_meta(WC_SCANPAY_URI_REV);
    if ($order_rev > $old_rev) {
        return wp_send_json_success(array(
            "rev" => $order_rev,
        ));
    }
    sleep($a + $b);
    $b = $b + $a;
    $a = $b - $a;
}

wp_send_json_success(array(
    "rev" => $old_rev
));
