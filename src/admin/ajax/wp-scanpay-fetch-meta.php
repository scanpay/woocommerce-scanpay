<?php

/**
 * Admin-AJAX endpoint ?x=meta: the scanpay_meta row for one order, long-polled so the
 * order screen refreshes as soon as a ping lands.
 *
 * Contract: GET ?oid=<order id>&rev=<last seen revision>, authenticated by the shared
 * secret in the X-Scanpay header. Answers JSON with the row, or { error }. When rev is
 * already current the response is held for up to 5.5s.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
nocache_headers();

/*
 * Not a WP form: no nonce, numeric ids are cast to int, and the secret rides in a request
 * header rather than the query string, compared with hash_equals. The nonce and
 * input-sanitization sniffs do not apply.
 */
// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$secret   = (string) ( $settings['secret'] ?? '' );
if ( '' === $secret || ! hash_equals( $secret, trim( (string) ( $_SERVER['HTTP_X_SCANPAY'] ?? '' ) ) ) ) {
	// No die() after any wp_send_json(): it terminates either way, through wp_die() when
	// wp_doing_ajax() and a bare die otherwise.
	wp_send_json( [ 'error' => 'forbidden' ], 403 );
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
	// The loop below sleeps for up to 5.5s, which fits the usual max_execution_time of 30
	// but not a host that has tightened it.
	set_time_limit( 30 );
	// Sent here, not left to wp_send_json(), which sets the type only inside its own
	// ! headers_sent() guard -- and the keep-alive flush() below has already sent them, so
	// the held response would answer PHP's default text/html. Outside the loop, because a
	// second header() call after that first flush() warns once per round, mid-body.
	header( 'Content-Type: application/json; charset=UTF-8' );
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
