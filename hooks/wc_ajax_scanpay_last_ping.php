<?php

/*
*   wc_ajax_scanpay_last_ping.php:
*   Admin AJAX to check local ping timestamp (mtime).
*/

defined('ABSPATH') || exit();
wc_nocache_headers();

if (!current_user_can('edit_shop_orders')) {
    wp_send_json(['error' => 'forbidden'], 403);
    exit;
}

require WC_SCANPAY_DIR . '/includes/SeqDB.php';

$settings = get_option(WC_SCANPAY_URI_SETTINGS);
if (empty($settings['apikey'])) {
    wp_send_json(['error' => 'missing apikey'], 403);
    exit;
}

$shopid = (int) explode(':', $settings['apikey'])[0];
$seqdb = new WC_Scanpay_SeqDB($shopid);

if ($seqdb) {
    $obj = $seqdb->get_seq();
    wp_send_json_success(array(
        "mtime" => ($obj) ? $obj['mtime'] : 0
    ));
}
