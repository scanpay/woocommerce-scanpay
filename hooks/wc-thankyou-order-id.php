<?php

defined( 'ABSPATH' ) || exit();

if ( ! isset( $_GET['gw'], $_GET['type'] ) || 'scanpay' !== $_GET['gw'] ) {
	return;
}

// 300 ms sleep to wait for ping/seq + WC processing
usleep( 300000 );

global $wpdb;
$count = 0;

// Regular one-off payment or WooCommerce Subscriptions
if ( 'wc' === $_GET['type'] || 'wcs' === $_GET['type'] ) {
	while ( $count++ < 14 ) {
		$wpdb->query( "SELECT id FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		if ( $wpdb->num_rows ) {
			return;
		}
		// Sleep: 40ms, 80ms ... (max 500ms, total 5.1s)
		usleep( min( ( 20000 * pow( 2, $count ) ), 500000 ) );
	}
	return;
}

/*
*   WooCommerce Subscription with free trial
*   Note: scanpay_meta is not created (because amount is 0).
*/
if ( 'wcs_free' === $_GET['type'] && isset( $_GET['ref'] ) && str_starts_with( $_GET['ref'], 'wcs[]' ) ) {
	$subs  = explode( ',', substr( $_GET['ref'], 5 ) );
	$wcsid = (int) end( $subs );
	while ( $count++ < 8 ) {
		$wco = wc_get_order( $wcsid );
		if ( $wco && 'active' === $wco->get_status( 'edit' ) ) {
			return;
		}
		// Clear the WooCommerce orders cache (from WC_Cache_Helper::invalidate_cache_group)
		wp_cache_set( 'wc_orders_cache_prefix', microtime(), 'orders' );

		// Sleep: 100ms, 200ms ... (max 800ms, total 4.5s)
		usleep( min( ( 50000 * pow( 2, $count ) ), 800000 ) );
	}
	return;
}
