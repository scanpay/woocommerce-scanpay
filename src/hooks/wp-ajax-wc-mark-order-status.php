<?php

defined( 'ABSPATH' ) || exit();

// Check if user has permission to edit orders and if the nonce is valid.
if ( ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( 'woocommerce-mark-order-status' ) ) {
	return;
}

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

if ( $wco && str_starts_with( $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
	if ( 'completed' === $sync->settings['wc_autocapture'] ) {
		remove_filter( 'woocommerce_order_status_completed', [ $sync, 'capture_after_complete' ], 3, 2 );
		$sync->capture_and_complete( $oid, $wco );
	} else {
		$wco->update_status( 'completed', '', true );
		do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
	}
	wp_safe_redirect( wp_get_referer() ?? admin_url( 'edit.php?post_type=shop_order' ) );
	exit;
}
