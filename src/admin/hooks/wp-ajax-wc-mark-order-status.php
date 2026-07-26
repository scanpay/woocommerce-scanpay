<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Handles the order-list row action that marks an order completed.
 * Action: wp_ajax_woocommerce_mark_order_status (no arguments; the request is in $_GET)
 *
 * Registered at priority 0, ahead of WooCommerce's own handler, so the capture
 * happens before completion -- and therefore before the completion emails go out. A
 * failed capture parks the order 'on-hold' with a note (never 'failed') and the order
 * is left uncompleted; see WC_Scanpay_Capture::capture_or_hold().
 */

if (
	! current_user_can( 'edit_shop_orders' ) ||
	! check_ajax_referer( 'woocommerce-mark-order-status', false, false )
) {
	wp_send_json_error( 'forbidden', 403 );
}

// Any other status transition is WooCommerce's business; fall through to its handler.
if (
	! isset( $_GET['status'], $_GET['order_id'] ) ||
	'completed' !== $_GET['status']
) {
	return;
}

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
	return; // Not a Scanpay order; fall through to WooCommerce's handler.
}

// This request captures explicitly, so drop the status hook: the completion below
// would otherwise re-enter capture for the same order.
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

// This handler exits, so WC_AJAX::mark_order_status() never runs. Replicate the two
// things it does around the status change, or an integration hooking either one
// silently misses every Scanpay order completed from the order-list row action.
// The bulk path does the same (admin/hooks/wp-bulk-actions.php).
WC()->payment_gateways();

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
	$wco->update_status( 'completed', '', true );
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
	do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
}

wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
exit;
