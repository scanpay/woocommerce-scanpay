<?php

/**
 * Helpers shared by the card gateway, capture, sync and the admin order screens, so they
 * cannot drift apart. Each requires this file itself; none inherits it from another.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/*
 * WooCommerce auto-completes downloadable orders but not virtual ones; marking virtual,
 * non-downloadable products as needing no processing auto-completes those too.
 *
 * Scoped to our own orders: the card gateway is registered unconditionally, so the filter
 * is live for the whole request. Without the order check a Scanpay setting would decide a
 * BACS, COD or third-party order's status.
 *
 * $order_id has been the third argument since WC 2.7, below the 3.6 floor, in both
 * WC_Order::needs_processing() and WC_Subscriptions_Order::maybe_autocomplete_order().
 */
function wc_scanpay_item_needs_processing( bool $needs_processing, \WC_Product $product, int $order_id ): bool {
	// Cheapest first; never look up an order whose answer we cannot change.
	if ( ! $needs_processing ) {
		return $needs_processing;
	}
	if ( true !== $product->get_virtual( 'edit' ) || $product->get_downloadable( 'edit' ) ) {
		return $needs_processing;
	}
	// A missing, unsaved or refund "order" degrades to WooCommerce's own decision, never
	// to auto-completion.
	$wco = wc_get_order( $order_id );
	if ( ! $wco instanceof \WC_Order || ! wc_scanpay_is_scanpay_order( $wco ) ) {
		return $needs_processing;
	}
	return false; // WooCommerce auto-completes the order.
}

/**
 * Whether an order is paid through one of our gateways ('scanpay',
 * 'scanpay_mobilepay', 'scanpay_applepay').
 */
function wc_scanpay_is_scanpay_order( \WC_Order $wco ): bool {
	return str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' );
}
