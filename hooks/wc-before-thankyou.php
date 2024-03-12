<?php

defined( 'ABSPATH' ) || exit();

$wc_order = wc_get_order( $order_id );
$settings = get_option( WC_SCANPAY_URI_SETTINGS );

if ( ! $wc_order || ! $settings || ! str_starts_with( $wc_order->get_payment_method( 'edit' ), 'scanpay' ) ) {
	return;
}

if ( 'pending' === $wc_order->get_status( 'edit' ) ) {
	global $wpdb;
	$subs_init = class_exists( 'WC_Subscriptions', false ) && wcs_order_contains_subscription( $wc_order, 'parent' );
	$counter   = 0;

	if ( $subs_init && $wc_order->get_total( 'edit' ) > 0 ) {
		sleep( 2 ); // WCS is slow, and we need to wait for the initial charge too
	}
	usleep( 50000 );

	do {
		$us = 100000 + 20000 * pow( 2, $counter ); // .12s, .14s, .18s, .26s, .42s, .74s, 1.38s ...
		usleep( min( $us, 1000000 ) ); // Sleep, but not more than 1s
		$meta_exists = $wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $order_id" );
	} while ( ! $meta_exists && $counter++ < 14 );

	// We need to wait for wc_order->save() (2x save + WCS with initial charge)
	usleep( ( $subs_init ) ? 300000 : 10000 );
	wc_delete_shop_order_transients( $order_id );
}
