<?php
declare(strict_types = 1);

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
    wp_send_json(['error' => 'missing signature'], 403);
    die();
}

$body = file_get_contents('php://input', false, null, 0, 512); // valid pings are <512 bytes
if (!hash_equals(base64_encode(hash_hmac('sha256', $body, $settings['apikey'], true)), $_SERVER['HTTP_X_SIGNATURE'])) {
    wp_send_json(['error' => 'invalid signature'], 403);
    die();
}

$ping = @json_decode($body, true);
if (
    $ping === null || !isset($ping['seq']) || !is_int($ping['seq']) ||
    !isset($ping['shopid']) || !is_int($ping['shopid'])
) {
    wp_send_json(['error' => 'invalid JSON'], 400);
    die();
}

require WC_SCANPAY_DIR . '/includes/SeqDB.php';
$SeqDB = new WC_Scanpay_SeqDB($ping['shopid']);
$db = $SeqDB->get_seq();
if (!$db) {
    $SeqDB->create_table();
    $db = $SeqDB->get_seq();
    if (!$db) {
        scanpay_log('critical', 'Failed creating table in database');
        return wp_send_json(['error' => 'failed creating table'], 500);
    }
}

if ($ping['seq'] === $db['seq']) {
    $SeqDB->update_mtime();
    return wp_send_json_success();
}

if ($ping['seq'] < $db['seq']) {
    $msg = sprintf('Ping seq (%u) is lower than the local seq (%u)', $ping['seq'], $db['seq']);
    scanpay_log('error', $msg);
    return wp_send_json(['error' => $msg], 400);
}

require WC_SCANPAY_DIR . '/includes/ScanpayClient.php';
require WC_SCANPAY_DIR . '/includes/orderUpdater.php';
$client = new WC_Scanpay_API_Client($settings['apikey']);

$seq = $db['seq'];
while (1) {
    $res = $client->seq($seq);
    if (count($res['changes']) === 0) {
        break; // done
    }

    foreach ($res['changes'] as $change) {
        switch ($change['type']) {
            case 'transaction':
            case 'charge':
                scanpay_order_updater($change, $seq, $ping['shopid'], $settings);
                break;
            case 'subscriber':
                // scanpay_subscriber_updater($change);
                break;
            default:
                scanpay_log('error', 'Unknown change type: ' . $change['type']);
                die();
        }
    }
    $seq = $res['seq'];
    $SeqDB->set_seq($seq);
}
return wp_send_json_success();
