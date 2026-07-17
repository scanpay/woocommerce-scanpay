<?php

declare(strict_types=1);

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

if (
	! current_user_can( 'edit_shop_orders' ) ||
	! check_ajax_referer( 'woocommerce-mark-order-status', false, false )
) {
	wp_send_json_error( 'forbidden', 403 );
}

// Check if the request is to mark an order as completed
if (
	! isset( $_GET['status'], $_GET['order_id'] ) ||
	'completed' !== $_GET['status']
) {
	return;
}

// Validate order ID (strictly digits)
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ctype_digit is the validation; value is cast to int below.
if ( ! ctype_digit( (string) wp_unslash( $_GET['order_id'] ) ) ) {
	wp_send_json_error( 'invalid_order_id', 400 );
}
$oid = (int) $_GET['order_id'];
$wco = wc_get_order( $oid );
if ( ! $wco ) {
	return;
}

if ( ! str_starts_with( $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
	// Not a Scanpay order. Fallback to WooCommerce's handler.
	return;
}

// Optimization: Avoid capture on completed hook
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

// This handler exits, so WC_AJAX::mark_order_status() never runs. Replicate the two
// things it does around the status change, or an integration hooking either one
// silently misses every Scanpay order completed from the order-list row action.
// The bulk path does the same (admin/hooks/wp-bulk-actions.php).
WC()->payment_gateways();

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
	$wco->update_status( 'completed', '', true );
	// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Re-fires WooCommerce core's hook from WC_AJAX::mark_order_status(); documented in WooCommerce, not owned here.
	do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
	wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
	exit;
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

// On failure this parks the order 'on-hold' with a note (never 'failed'); only
// complete the order when the capture actually succeeded.
if ( WC_Scanpay_Capture::capture_or_hold( $wco ) ) {
	$wco->set_status( 'completed', '', true );
	$wco->save();
	// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Re-fires WooCommerce core's hook from WC_AJAX::mark_order_status(); documented in WooCommerce, not owned here.
	do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
}

wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
exit;
