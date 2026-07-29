<?php

/**
 * Admin-AJAX endpoint ?x=sub: the scanpay_subs row for one subscriber, long-polled so the
 * subscription screen refreshes as soon as a ping lands.
 *
 * Contract: GET ?subid=<subscriber id>&rev=<last seen revision>, authenticated by the
 * shared secret in the X-Scanpay header. Answers JSON with the row, or { error }. When rev
 * is already current the response is held for up to 15.5s.
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
$subid  = (int) wp_unslash( $_GET['subid'] ?? 0 );

if ( 0 === $shopid ) {
	wp_send_json( [ 'error' => 'invalid shopid' ] );
}

if ( 0 === $subid ) {
	wp_send_json( [ 'error' => 'not found' ] );
}

global $wpdb;
$sub = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );

if ( isset( $sub['rev'] ) && $rev >= $sub['rev'] ) {
	// The loop below sleeps for up to 15.5s, uncomfortably close to a default
	// max_execution_time of 30 once DB round-trips are added.
	set_time_limit( 60 );
	// Sent here, not left to wp_send_json(), which sets the type only inside its own
	// ! headers_sent() guard -- and the keep-alive flush() below has already sent them, so
	// the held response would answer PHP's default text/html. Outside the loop, because a
	// second header() call after that first flush() warns once per round, mid-body.
	header( 'Content-Type: application/json; charset=UTF-8' );
	// Backoff: 0.5s, 1s, 2s, 4s, 8s -- 15.5s in total.
	$sec = 1;
	usleep( 500000 ); // usleep() for the sub-second wait only; sleep() for the rest.
	while ( 1 ) {
		$sub = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		// rev is nullable in the DDL, and NULL > $rev is false, so such a row would poll the
		// full 15.5s. It is unreachable: WC_Scanpay_Sync::subscriber() is the only INSERT
		// into scanpay_subs and rejects a non-positive rev before building the statement.
		// Keep it that way rather than adding a break for a row that cannot exist.
		if ( null === $sub || $sub['rev'] > $rev || $sec > 8 ) {
			break; // Row vanished, was updated, or the backoff is spent; respond below.
		}
		sleep( $sec );
		$sec *= 2;
		echo "\n"; // Write something each round, so a disconnected client is detected.
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}
}
wp_send_json( $sub ?? [ 'error' => 'not found' ] );
