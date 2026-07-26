<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
nocache_headers();

/*
 * Shared-secret-authenticated polling endpoint (not a WP form): requests carry no
 * nonce, numeric IDs are cast to int, and the secret (passed in the X-Scanpay
 * request header, never the query string) is compared with hash_equals.
 * WordPress's nonce and input-sanitization sniffs therefore do not apply here.
 */
// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$secret   = (string) ( $settings['secret'] ?? '' );
if ( '' === $secret || ! hash_equals( $secret, trim( (string) ( $_SERVER['HTTP_X_SCANPAY'] ?? '' ) ) ) ) {
	wp_send_json( [ 'error' => 'forbidden' ], 403 );
	die();
}

$shopid = (int) strstr( (string) ( $settings['apikey'] ?? '' ), ':', true );
$rev    = (int) wp_unslash( $_GET['rev'] ?? 0 );
$oid    = (int) wp_unslash( $_GET['oid'] ?? 0 );

if ( 0 === $shopid ) {
	wp_send_json( [ 'error' => 'invalid shopid' ] );
}

if ( 0 === $oid ) {
	wp_send_json( [ 'error' => 'not found' ] );
}

global $wpdb;
$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );

if ( isset( $meta['rev'] ) && $rev >= $meta['rev'] ) {
	// The long-poll below sleeps for up to 5.5s. That fits the usual
	// max_execution_time of 30, but not a host that has tightened it, so ask for the
	// headroom explicitly instead of relying on the default.
	set_time_limit( 30 );
	$counter = 0;
	do {
		// Exponential backoff: 0.5s, 1.5s, 3.5s (5.5s in total).
		usleep( 500000 * pow( 2, ++$counter ) - 500000 );
		$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );
		if ( null === $meta ) {
			break; // Row vanished; respond "not found" below.
		}
		echo "\n"; // Write something each round, so a disconnected client is detected.
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	} while ( $meta['rev'] <= $rev && $counter < 3 );
}
wp_send_json( $meta ?? [ 'error' => 'not found' ] );
