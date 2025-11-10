<?php

defined( 'ABSPATH' ) || exit();

/**
 * Ensures payment details are available before rendering the WooCommerce ThankYou page.
 * This introduces a short wait to make sure payment data is fully saved, reducing
 * race conditions and unnecessary resource usage.
 *
 * Unfortunately, due to aggressive caching in WP and WooCommerce (especially with HPOS),
 * we have to use the 'woocommerce_init' hook instead of later hooks like
 * 'woocommerce_thankyou_order_id', which are prone to race conditions.
 *
 * Note: The delay happens while the user is still on the Scanpay payment page,
 * so it has minimal impact on the user experience.
 */

$order_type = $_GET['scanpay_type'] ?? '';

/**
 * Waits for payment data to become available in WC and WCS orders.
 * Hook: woocommerce_init
 */
function wc_scanpay_init_thankyou(): void {
	global $wpdb;
	$oid = (int) ( $_GET['scanpay_thankyou'] ?? 0 );
	$i   = 0;
	while ( $i++ < 17 ) {
		$wpdb->query( "SELECT 1 FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid LIMIT 1" );
		if ( $wpdb->num_rows ) {
			break;
		}
		if ( 1 === $i ) {
			usleep( 400_000 );
		} else {
			usleep( (int) ( 20_000 + 10_000 * pow( 1.3, $i ) ) );
		}
	}
}

if ( 'wcs' === $order_type || 'wc' === $order_type ) {
	add_filter( 'woocommerce_init', 'wc_scanpay_init_thankyou', 99, 0 );
	return;
}

/**
 * Waits for payment data to become available in free WCS orders (e.g. free trial).
 * Hook: woocommerce_init
 */
function wcs_scanpay_init_thankyou_free(): void {
	global $wpdb;
	$ref = (string) ( $_GET['scanpay_ref'] ?? '' );
	if ( ! str_starts_with( $ref, 'wcs[]' ) ) {
		return;
	}
	$subs  = explode( ',', substr( $ref, 5 ) );
	$wcsid = (int) end( $subs );
	$hpos  = defined( 'WC_VERSION' ) && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

	$i = 0;
	while ( $i++ < 8 ) {
		if ( $hpos ) {
			$wpdb->query( "SELECT 1 FROM {$wpdb->prefix}wc_orders WHERE id = $wcsid AND status = 'wc-active' LIMIT 1" );
		} else {
			$wpdb->query( "SELECT 1 FROM {$wpdb->posts} WHERE ID = $wcsid AND post_status = 'wc-active' LIMIT 1" );
		}
		if ( $wpdb->num_rows ) {
			break;
		}
		if ( 1 === $i ) {
			usleep( 450_000 );
		} else {
			usleep( (int) ( 20_000 + 10_000 * pow( 1.3, $i ) ) );
		}
	}
}

if ( 'wcs_free' === $order_type ) {
	add_filter( 'woocommerce_init', 'wcs_scanpay_init_thankyou_free', 99, 0 );
}
