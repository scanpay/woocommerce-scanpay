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
	// No die() after any wp_send_json() here: it terminates either way, through wp_die()
	// when wp_doing_ajax() and a bare die otherwise (wp-includes/functions.php).
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
	// The long-poll below sleeps for up to 15.5s, which comes uncomfortably close to a
	// default max_execution_time of 30 once DB round-trips are added. Raise it so the
	// endpoint answers rather than being killed mid-poll.
	set_time_limit( 60 );
	// Sent here, not left to wp_send_json(): that only sets the type and status inside
	// its own `! headers_sent()` guard (wp-includes/functions.php), and the keep-alive
	// echo + flush() in the loop below has already sent them -- so the held response would
	// answer PHP's default text/html. Outside the loop, because a second header() call
	// after the first flush() is "headers already sent", one warning per round, in the
	// middle of the body under display_errors.
	header( 'Content-Type: application/json; charset=UTF-8' );
	// Backoff: 0.5s, 1s, 2s, 4s, 8s -- 15.5s in total.
	$sec = 1;
	usleep( 500000 ); // usleep() for the sub-second wait only; sleep() for the rest.
	while ( 1 ) {
		$sub = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		// rev is nullable in the DDL, and NULL > $rev is false, so such a row would poll
		// the full 15.5s before answering. It is unreachable: WC_Scanpay_Sync::subscriber()
		// is the only INSERT into scanpay_subs and throws on ! is_int( $rev ) || $rev <= 0
		// before building the statement, so a stored rev is always >= 1. Keep it that way
		// rather than adding a break condition for a row that cannot exist.
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
