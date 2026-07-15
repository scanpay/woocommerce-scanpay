<?php

defined( 'ABSPATH' ) || exit();

/*
 * Payment-return page: the customer is redirected here from the external payment
 * window, so requests carry no nonce — they are authenticated by the WooCommerce
 * order key via hash_equals below, not by a nonce.
 */
// phpcs:disable WordPress.Security.NonceVerification

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

$order_type = sanitize_key( wp_unslash( $_GET['scanpay_type'] ?? '' ) );

/**
 * Waits for payment data to become available in WC and WCS orders.
 * Hook: woocommerce_init
 */
function wc_scanpay_init_thankyou(): void {
	global $wpdb;
	$oid = absint( wp_unslash( $_GET['scanpay_thankyou'] ?? '' ) );
	$wco = $oid ? wc_get_order( $oid ) : false;
	// Ownership gate: only busy-poll for a genuine thank-you request. The success URL
	// carries WooCommerce's order key (get_checkout_order_received_url()); require it to
	// match before spending any workers on an order that may not exist.
	if (
		! $wco instanceof WC_Order
		|| ! hash_equals( $wco->get_order_key(), (string) wp_unslash( $_GET['key'] ?? '' ) )
		|| ! str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' )
	) {
		return;
	}
	if ( '' !== (string) $wco->get_transaction_id( 'edit' ) ) {
		return; // Already synced: payment data is present, nothing to wait for.
	}
	$i = 0;
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
	$oid = absint( wp_unslash( $_GET['scanpay_thankyou'] ?? '' ) );
	$wco = $oid ? wc_get_order( $oid ) : false;
	// Ownership gate on the parent order, whose key is in the success URL. (No
	// transaction-id bail here: a free-trial parent has a zero total and may never
	// carry one — this branch polls subscription activation instead.)
	if (
		! $wco instanceof WC_Order
		|| ! hash_equals( $wco->get_order_key(), (string) wp_unslash( $_GET['key'] ?? '' ) )
		|| ! str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' )
	) {
		return;
	}
	$ref = sanitize_text_field( wp_unslash( $_GET['scanpay_ref'] ?? '' ) );
	if ( ! str_starts_with( $ref, 'wcs[]' ) ) {
		return;
	}
	$subs  = explode( ',', substr( $ref, 5 ) );
	$wcsid = (int) end( $subs );
	// The subscription must belong to the (already key-verified) parent order, so a
	// key-holder cannot point scanpay_ref at an arbitrary subscription id.
	$wcsub = $wcsid ? wcs_get_subscription( $wcsid ) : false;
	if ( ! $wcsub || (int) $wcsub->get_parent_id() !== $oid ) {
		return;
	}
	$hpos = defined( 'WC_VERSION' ) && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
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
