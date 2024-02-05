<?php

defined( 'ABSPATH' ) || exit();
nocache_headers();

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
if ( ! $settings || rtrim( $_GET['s'] ) !== $settings['secret'] ) {
	wp_send_json( [ 'error' => 'forbidden' ], 403 );
	die();
}

$shopid = (int) explode( ':', $settings['apikey'] ?? '' )[0];
$rev    = (int) ( $_GET['rev'] ?? 0 );
$oid    = (int) ( $_GET['oid'] ?? 0 );

if ( 0 === $shopid ) {
	wp_send_json( [ 'error' => 'invalid shopid' ] );
}

if ( 0 === $oid ) {
	wp_send_json( [ 'error' => 'not found' ] );
}

global $wpdb;
$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );

if ( isset( $meta['rev'] ) && $rev >= $meta['rev'] ) {
	$counter = 0;
	do {
		// Exponential backoff: 0.5s, 1.5s, 3.5s, 7.5s, 15.5s.
		usleep( 500000 * pow( 2, ++$counter ) - 500000 );
		$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );
		echo "\n"; // echo + flush to detect if the client has disc.
		ob_flush();
		flush();
	} while ( $meta['rev'] <= $rev && $counter < 5 );
}
wp_send_json( $meta ?? [ 'error' => 'not found' ] );
