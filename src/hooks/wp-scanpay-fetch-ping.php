<?php

defined( 'ABSPATH' ) || exit();
nocache_headers();

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
if ( ! $settings || rtrim( $_GET['s'] ) !== $settings['secret'] ) {
	status_header( 403, 'Forbidden' );
	header( 'Content-Type: text/plain' );
	echo 'invalid secret';
	die();
}

global $wpdb;
$shopid = (int) explode( ':', $settings['apikey'] ?? '' )[0];
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
