<?php

/*
*   Hook:
*   Handle pings
*/

defined('ABSPATH') || exit();

$settings = get_option(WC_SCANPAY_URI_SETTINGS);
if (!isset($_SERVER['HTTP_X_SIGNATURE']) || $settings['apikey'] === null) {
    return wp_send_json(['error' => 'missing signature'], 403);
}

$body = file_get_contents('php://input', false, null, 0, 512);
$invalidSignature = !hash_equals(
    base64_encode(hash_hmac('sha256', $body, $settings['apikey'], true)),
    $_SERVER['HTTP_X_SIGNATURE']
);

if ($invalidSignature) {
    return wp_send_json(['error' => 'invalid signature'], 403);
}

$ping = @json_decode($body, true);
if ($ping === null || !isset($ping['seq']) || !is_int($ping['seq'])) {
    return wp_send_json(['error' => 'invalid JSON'], 400);
}

require WC_SCANPAY_DIR . '/includes/OrderUpdater.php';
$orderUpdater = new WC_Scanpay_OrderUpdater($settings);

try {
    $res = $orderUpdater->handlePing($ping);
    wp_send_json_success();
} catch (\Exception $e) {
    scanpay_log('error', 'Sync failed: ' . $e->getMessage());
    wp_send_json(['error' => 'sync failed'], 500);
}
