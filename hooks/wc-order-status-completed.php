<?php

/*
*   Hook: 'woocommerce_order_status_completed' (priority 0)
*   Called when order status is changed to completed.
*   GLOBALs: $order_id
*/

defined( 'ABSPATH' ) || exit();

require WC_SCANPAY_DIR . '/library/math.php';
require WC_SCANPAY_DIR . '/library/client.php';

function scanpay_capture_failed( object $wc_order, string $str ): void {
	$wc_order->update_status( 'failed', 'Scanpay capture failed: ' . $str );
	throw new Exception(); // throw to stop other plugins/hooks
}

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$wc_order = wc_get_order( $order_id );

if (
	! $wc_order || substr( $wc_order->get_payment_method(), 0, 7 ) !== 'scanpay' ||
	'yes' !== $settings['capture_on_complete']
) {
	return;
}

$shopid = (int) explode( ':', (string) $settings['apikey'] )[0];
if ( 0 === $shopid ) {
	scanpay_capture_failed( $wc_order, 'invalid or missing API key' );
}

global $wpdb;
$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $order_id", ARRAY_A );

if ( empty( $meta ) ) {
	scanpay_capture_failed( $wc_order, 'no payment details found for this order' );
}

if ( $meta['voided'] === $meta['authorized'] ) {
	scanpay_capture_failed( $wc_order, 'payment is voided' );
}

// Only proceed if nothing has been captured yet
if ( $meta['captured'] !== $meta['voided'] ) {
	return;
}

try {
	$amount = wc_scanpay_submoney( (string) $wc_order->get_total(), (string) $wc_order->get_total_refunded() );
	$client = new WC_Scanpay_Client( $settings['apikey'] );
	$client->capture(
		$meta['id'],
		[
			'total' => $amount . ' ' . $wc_order->get_currency(),
			'index' => $meta['nacts'],
		]
	);
} catch ( \Exception $e ) {
	scanpay_log( 'notice', "Capture failed on order #$order_id: " . $e->getMessage() );
	scanpay_capture_failed( $wc_order, $e->getMessage() );
}
