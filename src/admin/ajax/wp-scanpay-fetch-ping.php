<?php

/**
 * Admin-AJAX endpoint ?x=ping: when Scanpay last pinged this shop, for the settings
 * screen. Dispatched from woocommerce-scanpay.php ahead of the rest of the bootstrap.
 *
 * Contract: GET, authenticated by the shared secret in the X-Scanpay header. Answers
 * text/plain with the cursor's mtime as a Unix timestamp, or 403 on a bad secret or an
 * unconfigured API key.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
nocache_headers();

/*
 * Not a WP form: no nonce, and the secret rides in a request header rather than the query
 * string, compared with hash_equals. The nonce and input-sanitization sniffs do not apply.
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
$shopid = (int) strstr( (string) ( $settings['apikey'] ?? '' ), ':', true );
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
