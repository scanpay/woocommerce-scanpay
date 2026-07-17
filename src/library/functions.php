<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/*
 *  WC auto-completes downloadable orders, but not virtual orders. This filter
 *  sets virtual (but not downloadable) products to not need processing, so they
 *  are auto-completed. Shared by the card gateway and the sync service so the
 *  two callers cannot drift apart.
 */
function wc_scanpay_item_needs_processing( bool $needs_processing, \WC_Product $product ): bool {
	if ( $needs_processing && true === $product->get_virtual( 'edit' ) && ! $product->get_downloadable( 'edit' ) ) {
		return false; // Product is virtual, but not downloadable.
	}
	return $needs_processing;
}

/**
 * Whether an order is paid through one of our gateways ('scanpay',
 * 'scanpay_mobilepay', 'scanpay_applepay').
 *
 * @param \WC_Order $wco WooCommerce order object.
 * @return bool
 */
function wc_scanpay_is_scanpay_order( \WC_Order $wco ): bool {
	return str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' );
}
