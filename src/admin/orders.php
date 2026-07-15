<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Handle our custom bulk actions in the WC order list.
 *
 * @param string $redirect_to Redirect URL after processing.
 * @param string $action      Bulk action key.
 * @param array  $ids         Selected order IDs.
 * @return string Modified redirect URL.
 */
function wc_scanpay_handle_bulk_actions( string $redirect_to, string $action, array $ids ): string {
	$capture = true;
	if ( 'scanpay_mark_completed' === $action ) {
		WC()->payment_gateways(); // Initialize other gateways

		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
			$capture = false;
		}
	} elseif ( 'scanpay_capture_complete' !== $action ) {
		return $redirect_to; // Not our action; Let WC handle it
	}
	require_once WC_SCANPAY_DIR . '/admin/hooks/wp-bulk-actions.php';
	return wc_scanpay_handle_bulk_capture( $redirect_to, $ids, $capture );
}
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_handle_bulk_actions', 0, 3 ); // HPOS
add_filter( 'handle_bulk_actions-edit-shop_order', 'wc_scanpay_handle_bulk_actions', 0, 3 ); // Legacy


/**
 * Add our bulk actions to the WC order list. Hijack "Mark as completed"
 * so payments can be captured before order completion, not after.
 *
 * @param array $actions Existing bulk actions.
 * @return array Modified bulk actions.
 */
function wc_scanpay_add_bulk_actions( array $actions ): array {
	$arr = [];
	foreach ( $actions as $k => $v ) {
		$arr[ 'mark_completed' === $k ? 'scanpay_mark_completed' : $k ] = $v;
	}
	// Prepend our custom action "Capture and complete"
	return [ 'scanpay_capture_complete' => __( 'Capture and complete', 'scanpay-for-woocommerce' ) ] + $arr;
}
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_add_bulk_actions', 10, 1 ); // HPOS
add_filter( 'bulk_actions-edit-shop_order', 'wc_scanpay_add_bulk_actions', 10, 1 ); // Legacy


/**
 * Intercept the AJAX request to mark an order as completed.
 * This is to capture the payment before the order is set to completed.
 */
function wc_scanpay_mark_order_status(): void {
	require WC_SCANPAY_DIR . '/admin/hooks/wp-ajax-wc-mark-order-status.php';
}
add_action( 'wp_ajax_woocommerce_mark_order_status', 'wc_scanpay_mark_order_status', 0, 0 );


/**
 * Render the Scanpay order meta box content.
 *
 * @param WP_Post|WC_Order $post Current object (legacy: WP_Post, HPOS: WC_Order).
 */
function wc_scanpay_admin_render_meta_box( $post ): void {
	global $wpdb;
	$wco = wc_get_order( $post );
	if ( ! $wco ) {
		return;
	}
	$pm = (string) $wco->get_payment_method( 'edit' );
	if ( 'scanpay' !== $pm && ! str_starts_with( $pm, 'scanpay' ) ) {
		return;
	}
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/css/meta.css', [], WC_SCANPAY_VERSION );
	wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/js/order.js', [], WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );

	$oid   = $wco->get_id();
	$meta  = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid LIMIT 1", ARRAY_A );
	$props = [
		'oid'         => $oid,
		'tid'         => (int) $wco->get_transaction_id( 'edit' ),
		'subid'       => (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ),
		'shopid'      => (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ),
		'payid'       => $wco->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' ),
		'wc_total'    => (int) wc_add_number_precision( $wco->get_total() - $wco->get_total_refunded() ),
		'wc_decimals' => wc_get_price_decimals(),
		'meta'        => $meta ?? null,
		'currency'    => $wco->get_currency( 'edit' ),
		'nonce'       => wp_create_nonce( 'scanpay-order-' . $oid ),
	];
	wp_add_inline_script(
		'wcsp-meta',
		'window.ScanpayOrderData = ' . wp_json_encode( $props, JSON_UNESCAPED_SLASHES ) . ';',
		'before'
	);
	echo '<div id="wcsp-meta"></div>';
}

/**
 * Add the Scanpay meta box to the order edit screen, but only for Scanpay orders.
 *
 * Registering the box unconditionally shows an empty "Scanpay" side box on
 * PayPal/etc. orders, so gate on the payment method here (as the subscription
 * meta box in admin/subscriptions.php already does).
 *
 * @param WP_Post|WC_Order $wc_order Current object (legacy: WP_Post, HPOS: WC_Order).
 */
function wc_scanpay_add_meta_box( $wc_order ): void {
	if ( ! $wc_order instanceof WC_Order ) {
		$wc_order = wc_get_order( $wc_order->ID ); // Legacy support
		if ( ! $wc_order ) {
			return;
		}
	}
	$pm = (string) $wc_order->get_payment_method( 'edit' );
	if ( 'scanpay' !== $pm && ! str_starts_with( $pm, 'scanpay' ) ) {
		return;
	}
	add_meta_box(
		'wcsp-meta-box',
		__( 'Scanpay', 'scanpay-for-woocommerce' ),
		'wc_scanpay_admin_render_meta_box',
		null,
		'side',
		'high',
	);
}
add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'wc_scanpay_add_meta_box', 9, 1 ); // HPOS
add_action( 'add_meta_boxes_shop_order', 'wc_scanpay_add_meta_box', 9, 1 ); // legacy
