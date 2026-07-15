<?php

defined( 'ABSPATH' ) || exit();
nocache_headers();

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$secret   = (string) ( $settings['secret'] ?? '' );
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
if ( '' === $secret || ! hash_equals( $secret, rtrim( (string) ( $_GET['s'] ?? '' ) ) ) ) {
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
echo $mtime;
exit;
