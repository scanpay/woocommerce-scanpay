<?php

// phpcs:disable WordPress.Security.NonceVerification.Recommended

defined( 'ABSPATH' ) || exit();
ignore_user_abort( false );
wc_nocache_headers();
wc_set_time_limit( 0 );

if ( ! isset( $_GET['order_id'], $_GET['rev'] ) || ! current_user_can( 'edit_shop_orders' ) ) {
	wp_send_json( [ 'error' => 'forbidden' ], 403 );
	die;
}

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$shopid   = (int) explode( ':', $settings['apikey'] ?? '' )[0];
$rev      = (int) $_GET['rev'];
$order_id = (int) $_GET['order_id'];

if ( 0 === $shopid ) {
	wp_send_json( [ 'error' => 'invalid shopid' ] );
	die;
}

if ( 0 === $order_id ) {
	wp_send_json( [ 'error' => 'not found' ] );
	die;
}

global $wpdb;
$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $order_id", ARRAY_A );

if ( isset( $meta['rev'] ) && $rev >= $meta['rev'] ) {
	// Backoff strategy: .5s, 1s, 2s, 4s, 8s: Total: 15.5s
	$sec = 1;
	usleep( 500000 ); // 0.5 secs. Note: usleep is only OS-safe below 1s
	while ( 1 ) {
		$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $order_id", ARRAY_A );
		if ( $meta['rev'] > $rev || $sec > 8 ) {
			break;
		}
		sleep( $sec );
		$sec = $sec + $sec;
		echo "\n"; // echo + flush to detect if the client has disc.
		ob_flush();
		flush();
	}
}
wp_send_json( $meta ?? [ 'error' => 'not found' ] );
