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
$subid  = (int) wp_unslash( $_GET['subid'] ?? 0 );

if ( 0 === $shopid ) {
	wp_send_json( [ 'error' => 'invalid shopid' ] );
	die;
}

if ( 0 === $subid ) {
	wp_send_json( [ 'error' => 'not found' ] );
	die;
}

global $wpdb;
$sub = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );

if ( isset( $sub['rev'] ) && $rev >= $sub['rev'] ) {
	// Backoff strategy: .5s, 1s, 2s, 4s, 8s: Total: 15.5s
	$sec = 1;
	usleep( 500000 ); // 0.5 secs. Note: usleep is only OS-safe below 1s
	while ( 1 ) {
		$sub = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		if ( null === $sub || $sub['rev'] > $rev || $sec > 8 ) {
			break; // row vanished or updated; respond below
		}
		sleep( $sec );
		$sec = $sec + $sec;
		echo "\n"; // echo + flush to detect if the client has disc.
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}
}
wp_send_json( $sub ?? [ 'error' => 'not found' ] );
