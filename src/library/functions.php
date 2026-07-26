<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/*
 *  WC auto-completes downloadable orders, but not virtual orders. This filter
 *  sets virtual (but not downloadable) products to not need processing, so they
 *  are auto-completed. Shared by the card gateway and the sync service so the
 *  two callers cannot drift apart.
 *
 *  Scoped to our own orders. WooCommerce instantiates every registered gateway to
 *  build the registry, and the card gateway is registered unconditionally, so this
 *  filter is live for the whole request -- including one that completes a BACS,
 *  COD, or third-party order. Without the order check a Scanpay setting decides
 *  another gateway's order status.
 *
 *  $order_id is the third argument WooCommerce (class-wc-order.php) and WCS
 *  (class-wc-subscriptions-order.php) have always passed; it predates the WC 3.6
 *  minimum, so there is no fallback.
 */
function wc_scanpay_item_needs_processing( bool $needs_processing, \WC_Product $product, int $order_id ): bool {
	// Cheapest first, and never look up an order we cannot change the answer for.
	if ( ! $needs_processing ) {
		return $needs_processing;
	}
	if ( true !== $product->get_virtual( 'edit' ) || $product->get_downloadable( 'edit' ) ) {
		return $needs_processing;
	}
	// Degrade to WooCommerce's own decision -- never to auto-completion -- when the
	// order is missing, not yet persisted, or a refund rather than an order.
	$wco = wc_get_order( $order_id );
	if ( ! $wco instanceof \WC_Order || ! wc_scanpay_is_scanpay_order( $wco ) ) {
		return $needs_processing;
	}
	return false; // No processing needed, so WooCommerce auto-completes the order.
}

/**
 * Whether an order is paid through one of our gateways ('scanpay',
 * 'scanpay_mobilepay', 'scanpay_applepay').
 */
function wc_scanpay_is_scanpay_order( \WC_Order $wco ): bool {
	return str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' );
}
