<?php

defined( 'ABSPATH' ) || exit();

// Check if user has permission to edit orders and if the nonce is valid.
if ( ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( 'woocommerce-mark-order-status' ) ) {
	return;
}

// Check if the request is for marking an order as completed
if ( ! isset( $_GET['status'], $_GET['order_id'] ) || 'completed' !== $_GET['status'] ) {
	return;
}

// Check if the order ID is a positive number
$oid = abs( (int) $_GET['order_id'] );
if ( (string) $oid !== $_GET['order_id'] ) {
	return;
}

require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-sync.php';

$sync = new WC_Scanpay_Sync();
$wco  = wc_get_order( $oid );

// Skip if order is missing or not using Scanpay
if ( ! $wco || ! str_starts_with( $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
	return;
}

// Skip if capture on complete is not enabled
if ( 'completed' !== $sync->settings['wc_autocapture'] ) {
	return;
}

// Remove the filter to prevent duplicate captures
remove_filter( 'woocommerce_order_status_completed', [ $sync, 'capture_after_complete' ], 3, 2 );

$res    = $sync->capture_order( $oid, $wco );
$status = $res[0] ? 'completed' : 'failed';
$wco->update_status( $status, $res[1], true );
do_action( 'woocommerce_order_edit_status', $oid, $status );

// Redirect back to admin interface
wp_safe_redirect( wp_get_referer() ?? admin_url( 'edit.php?post_type=shop_order' ) );
exit;
