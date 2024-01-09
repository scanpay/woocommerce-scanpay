<?php

/*
*   wcs_scheduled_payment.php
*   Hook: woocommerce_scheduled_subscription_payment_scanpay
*   A gateway specific hook for when a subscription renewal payment is due.
*/

defined( 'ABSPATH' ) || exit();

scanpay_log( 'info', 'wcs_scheduled_payment.php' );
$orderid = $order->get_id();
if ( ! $order->needs_payment() ) {
	scanpay_log( 'info', "Interupted a scheduled charge (orderid=$orderid). Order is already paid?" );
	return;
}

$idem = (array) $order->get_meta( WC_SCANPAY_URI_IDEM, true, 'edit' );
if ( empty( $idem['next'] ) ) {
	$idem = [
		'retries' => 5,
		'key'     => rtrim( base64_encode( random_bytes( 32 ) ) ),
	];
} elseif ( 'never' === $idem['next'] || ! $idem['retries'] ) {
	scanpay_log( 'debug', "Interupted a scheduled charge (orderid=$orderid). No retries left" );
	return; // No retries until action (e.g. card renew)
} elseif ( $idem['next'] > time() ) {
	scanpay_log( 'debug', "Interupted a scheduled charge (orderid=$orderid). Not yet time for a retry" );
	return; // Not yet time for a retry
} elseif ( isset( $idem['err'] ) ) {
	// Reset idempotency key after 24 hours
	$idem['err'] = null;
	$idem['key'] = rtrim( base64_encode( random_bytes( 32 ) ) );
}

// Save $idem w. new 'next' to reduce risk of race conditions
$idem['next'] = time() + 600;
$order->add_meta_data( WC_SCANPAY_URI_IDEM, $idem, true );
$order->save();

require_once WC_SCANPAY_DIR . '/library/client.php';

// Check last ping before we go
global $wpdb;
$shopid = (int) $order->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
$mtime  = (int) $wpdb->get_var( "SELECT mtime FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
$dtime  = time() - $mtime;
if ( $dtime > 302 ) {
	scanpay_log( 'info', "Interupted a scheduled charge (orderid=$orderid). Long time since last ping ($dtime secs)" );
	return;
}

// Race check
$idemkey = $idem['key'];
$order->read_meta_data( true ); // force reload meta data from DB
$idem = (array) $order->get_meta( WC_SCANPAY_URI_IDEM, true, 'edit' );
if ( $idem['key'] !== $idemkey ) {
	scanpay_log( 'info', 'Interupted a scheduled charge. Idemkey changed during call (race condition)' );
	return;
}

// Charge subscriber
$subid   = (int) $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
$shopid  = (int) $order->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
$country = $order->get_billing_country();
$phone   = $order->get_billing_phone();

if ( empty( $subid ) ) {
	scanpay_log( 'error', "Failed to retrieve Scanpay subscriber id from order #$orderid" );
	return;
}

if ( ! empty( $phone ) ) {
	$first_number = substr( $phone, 0, 1 );
	if ( '+' !== $first_number && '0' !== $first_number ) {
		$code = WC()->countries->get_country_calling_code( $country );
		if ( isset( $code ) ) {
			$phone = $code . ' ' . $phone;
		}
	}
}

$data = [
	'orderid' => strval( $orderid ),
	'items'   => [
		[ 'total' => $amount . ' ' . $order->get_currency() ],
	],
	'billing' => array_filter(
		[
			'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'email'   => $order->get_billing_email(),
			'phone'   => $phone,
			'address' => array_filter( [ $order->get_billing_address_1(), $order->get_billing_address_2() ] ),
			'city'    => $order->get_billing_city(),
			'zip'     => $order->get_billing_postcode(),
			'country' => $country,
			'state'   => $order->get_billing_state(),
			'company' => $order->get_billing_company(),
		]
	),
];

$client = new WC_Scanpay_Client( $settings['apikey'] );
$res    = $client->charge( $subid, $data, [ 'headers' => [ 'Idempotency-Key' => $idemkey ] ] );
