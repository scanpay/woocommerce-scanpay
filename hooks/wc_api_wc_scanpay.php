<?php

/*
*   wc_api_wc_scanpay.php:
*   Public API endpoint for ping events from Scanpay.
*/

defined('ABSPATH') || exit();
ignore_user_abort(true);
wc_nocache_headers();
wc_set_time_limit(0);

$settings = get_option(WC_SCANPAY_URI_SETTINGS);
if (!isset($_SERVER['HTTP_X_SIGNATURE']) || empty($settings['apikey'])) {
    return wp_send_json(['error' => 'missing signature'], 403);
}

// Only load the first 512 bytes of data.
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

// Ping is valid.
require WC_SCANPAY_DIR . '/includes/SeqDB.php';
$shopid = (int) explode(':', $settings['apikey'])[0];
$SeqDB = new WC_Scanpay_SeqDB($shopid);
$seq = $db['seq'];
if (!$SeqDB) {
    scanpay_log('error', 'Could not open database');
    return wp_send_json(['error' => 'Could not open database'], 500);
}

$db = $SeqDB->get_seq();
if ($ping['shopid'] !== $db['shopid']) {
    scanpay_log('error', 'shopid mismatch');
    return wp_send_json(['error' => 'shopid mismatch'], 400);
}

if ($ping['seq'] === $seq) {
    $SeqDB->update_mtime();
    return wp_send_json(['ok' => 0], 200);
}

if ($ping['seq'] < $seq) {
    $msg = sprintf('Ping seq (%u) is lower than the local seq (%u)', $ping['seq'], $seq);
    scanpay_log('error', $msg);
    return wp_send_json(['error' => $msg], 400);
}

// Get the changes from Scanpay backend.
require WC_SCANPAY_DIR . '/includes/ScanpayClient.php';
$client = new WC_Scanpay_API_Client($settings['apikey']);


function scanpay_order_updater($change) {

}


while (1) {
    $res = $client->seq($seq);
    if (count($res['changes']) === 0) {
        break; // done
    }

    foreach ($res['changes'] as $change) {
        switch ($change['type']) {
            case 'transaction':
            case 'charge':
                scanpay_order_updater($change);
                break;
            case 'subscriber':
                // scanpay_subscriber_updater($change);
                break;
            default:
                throw new Exception('Unknown change type: ' . $change['type']);
        }
    }
    $seq = $res['seq'];
    $this->SeqDB->set_seq($seq);
}
