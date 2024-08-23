<?php

defined( 'ABSPATH' ) || exit();
nocache_headers();

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
if ( ! $settings || rtrim( $_GET['s'] ) !== $settings['secret'] ) {
	wp_send_json( [ 'error' => 'forbidden' ], 403 );
	die();
}

global $wpdb;
$shopid = (int) explode( ':', $settings['apikey'] ?? '' )[0];
if ( 0 === $shopid ) {
	wp_send_json( [ 'error' => 'invalid shopid' ] );
}

$mtime = $wpdb->get_var( "SELECT mtime FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" ); // Int or Null
wp_send_json( [ 'mtime' => $mtime ?? 0 ] );
