<?php

defined( 'ABSPATH' ) || exit();

/*
*   Initial sleep before we start checking the database.
*   On our demo shop WCS is ~50 ms slower than regular WC.
*/
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
$initial_sleep = ( 'wc' === $_GET['scanpay_type'] ) ? 360000 : 450000;
usleep( $initial_sleep );

global $wpdb;
$count = 0;
$oid   = (int) $_GET['scanpay_thankyou'];

// Regular one-off payment or WooCommerce Subscriptions
if ( 'wc' === $_GET['scanpay_type'] || 'wcs' === $_GET['scanpay_type'] ) {
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

if ( 'wcs_free' === $_GET['scanpay_type'] && isset( $_GET['scanpay_ref'] ) && str_starts_with( $_GET['scanpay_ref'], 'wcs[]' ) ) {
	add_action(
		'woocommerce_thankyou_order_id',
		function ( $oid ) {
			$count = 0;
			$subs  = explode( ',', substr( $_GET['scanpay_ref'], 5 ) );
			$wcsid = (int) end( $subs );
			while ( $count++ < 8 ) {
				$wco = wc_get_order( $wcsid );
				if ( $wco && 'active' === $wco->get_status( 'edit' ) ) {
					return $oid;
				}
				// Clear the WooCommerce orders cache (from WC_Cache_Helper::invalidate_cache_group)
				wp_cache_set( 'wc_orders_cache_prefix', microtime(), 'orders' );

				// Sleep: 100ms, 200ms ... (max 800ms, total 4.5s)
				usleep( min( ( 50000 * pow( 2, $count ) ), 800000 ) );
			}
			return $oid;
		},
		10,
		1
	);
}
