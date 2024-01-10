<?php

/*
*   wc_ajax_scanpay_last_ping.php:
*   Admin AJAX to check local ping timestamp (mtime).
*/

defined( 'ABSPATH' ) || exit();
wc_nocache_headers();

if ( ! current_user_can( 'edit_shop_orders' ) ) {
	wp_send_json( [ 'error' => 'forbidden' ], 403 );
	exit;
}

global $wpdb;
$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$shopid   = (int) explode( ':', $settings['apikey'] ?? '' )[0];
$mtime    = $wpdb->get_var( "SELECT mtime FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" ); // Int or Null
wp_send_json_success( [ 'mtime' => (int) ( $mtime ?? 0 ) ] );
