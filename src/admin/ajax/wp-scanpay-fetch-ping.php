<?php

defined( 'ABSPATH' ) || exit();
nocache_headers();

/*
 * Shared-secret-authenticated polling endpoint (not a WP form): the request carries
 * no nonce and the secret (passed in the X-Scanpay request header, never the query
 * string) is compared with hash_equals. WordPress's nonce and input-sanitization
 * sniffs therefore do not apply here.
 */
// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$secret   = (string) ( $settings['secret'] ?? '' );
if ( '' === $secret || ! hash_equals( $secret, trim( (string) ( $_SERVER['HTTP_X_SCANPAY'] ?? '' ) ) ) ) {
	status_header( 403, 'Forbidden' );
	header( 'Content-Type: text/plain' );
	echo 'invalid secret';
	die();
}

global $wpdb;
$shopid = (int) strstr( $settings['apikey'] ?? '', ':', true );
if ( 0 === $shopid ) {
	status_header( 403, 'Forbidden' );
	header( 'Content-Type: text/plain' );
	echo 'invalid apikey';
	die();
}

$mtime = (int) $wpdb->get_var( "SELECT mtime FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
header( 'Content-Type: text/plain' );
echo (int) $mtime;
exit;
