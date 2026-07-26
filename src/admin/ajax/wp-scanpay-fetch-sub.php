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
	// The long-poll below sleeps for up to 15.5s, which comes uncomfortably close to a
	// default max_execution_time of 30 once DB round-trips are added. Raise it so the
	// endpoint answers rather than being killed mid-poll.
	set_time_limit( 60 );
	// Backoff: 0.5s, 1s, 2s, 4s, 8s -- 15.5s in total.
	$sec = 1;
	usleep( 500000 ); // usleep() for the sub-second wait only; sleep() for the rest.
	while ( 1 ) {
		$sub = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		if ( null === $sub || $sub['rev'] > $rev || $sec > 8 ) {
			break; // Row vanished, was updated, or the backoff is spent; respond below.
		}
		sleep( $sec );
		$sec = $sec + $sec;
		echo "\n"; // Write something each round, so a disconnected client is detected.
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}
}
wp_send_json( $sub ?? [ 'error' => 'not found' ] );
