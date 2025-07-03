<?php

defined( 'ABSPATH' ) || exit();

/**
 * Handles AJAX requests to manually change an order’s status to completed.
 * Captures the payment before completing the order, ensuring emails
 * are not sent until the payment has been secured. If capture fails,
 * the order status is set to failed.
 *
 * This is running before WooCommerce's own handler.
 *
 * @hook wp_ajax_woocommerce_mark_order_status
 * This hook does not pass arguments; use the $_GET array.
 */

// Verify the user has permission to edit orders and that the nonce is valid.
if ( ! current_user_can( 'edit_shop_orders' ) || ! check_admin_referer( 'woocommerce-mark-order-status' ) ) {
	return;
}

// Ensure the request includes both 'status' and 'order_id'.
if ( ! isset( $_GET['status'], $_GET['order_id'] ) ) {
	return;
}

// Only handle requests setting status to 'completed'.
if ( 'completed' !== $_GET['status'] ) {
	return;
}

// Retrieve order and check if it belongs to Scanpay
$oid = abs( (int) $_GET['order_id'] );
$wco = wc_get_order( $oid );
if ( ! $wco ) {
	return;
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

$res    = WC_Scanpay_Capture::capture( $wco );
$msg    = $res['msg'] ?? 'unknown error';
$status = 'failed';

switch ( $res['status'] ) {
	case 'ok':
		$status = 'completed';
		$msg    = "Scanpay captured $msg.";
		break;
	case 'failed':
		scanpay_log( 'warning', "Capture failed on order #$oid: $str" );
		$msg = "Scanpay capture failed: $msg.";
		break;
	case 'aborted':
		scanpay_log( 'warning', "Capture aborted on order #$oid: $str" );
		$msg = "Scanpay capture aborted: $msg.";
		break;
	case 'skipped':
		scanpay_log( 'debug', "Capture skipped on order #$oid: $str" );
		return; // fallback to WooCommerce's own handler
}

// Optimization: Avoid Capture after Complete hook (request is about to end)
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

$wco->update_status( $status, $msg, true );
do_action( 'woocommerce_order_edit_status', $oid, $status );
wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
exit;
