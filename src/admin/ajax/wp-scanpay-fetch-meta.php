<?php

defined( 'ABSPATH' ) || exit();
nocache_headers();

/*
 * Shared-secret-authenticated polling endpoint (not a WP form): requests carry no
 * nonce, numeric IDs are cast to int, and the secret is compared with hash_equals.
 * WordPress's nonce and input-sanitization sniffs therefore do not apply here.
 */
// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$secret   = (string) ( $settings['secret'] ?? '' );
if ( '' === $secret || ! hash_equals( $secret, rtrim( (string) wp_unslash( $_GET['s'] ?? '' ) ) ) ) {
	wp_send_json( [ 'error' => 'forbidden' ], 403 );
	die();
}

$shopid = (int) strstr( $settings['apikey'] ?? '', ':', true );
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
	$counter = 0;
	do {
		// Exponential backoff: 0.5s, 1.5s, 3.5s (total 5.5s)
		usleep( 500000 * pow( 2, ++$counter ) - 500000 );
		$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );
		if ( null === $meta ) {
			break; // row vanished; respond "not found" below
		}
		echo "\n"; // echo + flush to detect if the client has disc.
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	} while ( $meta['rev'] <= $rev && $counter < 3 );
}
wp_send_json( $meta ?? [ 'error' => 'not found' ] );
