<?php

/*
*   Admin check ping mtime
*/

defined('ABSPATH') || exit();

if (!current_user_can('edit_shop_orders')) {
    wp_send_json(['error' => 'forbidden'], 403);
    exit;
}

require_once WC_SCANPAY_DIR . '/includes/SeqDB.php';

$settings = get_option(WC_SCANPAY_URI_SETTINGS);
$shopid = (int) explode(':', $settings['apikey'])[0];
$seqdb = new WC_Scanpay_SeqDB($shopid);
$obj = $seqdb->get_seq();

wp_send_json_success(array(
    "mtime" => ($obj) ? $obj['mtime'] : 0
));
