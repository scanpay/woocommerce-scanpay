<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Handle the AJAX "Capture" action from the order meta box (order.ts).
 * Action: wp_ajax_wc_scanpay_capture (no arguments; order id and nonce arrive in $_POST)
 *
 * Captures the remaining authorized amount on a Scanpay order via the same
 * primitive the order-status / bulk / mark-status flows use
 * (WC_Scanpay_Capture::capture_or_hold, which parks the order 'on-hold' on
 * failure -- never 'failed'). Guarded by the per-order nonce injected into
 * window.ScanpayOrderData and an order-editing capability.
 */

// Capability first, before anything is parsed: an unauthenticated caller should not
// learn from the response whether an order id is well-formed.
if ( ! current_user_can( 'edit_shop_orders' ) ) {
	wp_send_json_error( 'forbidden', 403 );
}

if ( ! isset( $_POST['oid'] ) ) {
	wp_send_json_error( 'invalid_order_id', 400 );
}
// No (string) cast: wp_unslash() of an array returns an array, and casting one is what
// emits "Array to string conversion" -- ctype_digit( [] ) is a plain false with no
// diagnostic. is_string() states that rule rather than relying on it.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ctype_digit is the validation; value is cast to int below.
$raw = wp_unslash( $_POST['oid'] );
if ( ! is_string( $raw ) || ! ctype_digit( $raw ) ) {
	wp_send_json_error( 'invalid_order_id', 400 );
}
$oid = (int) $raw;

// The nonce is per-order, so it cannot be verified until $oid is known.
if ( ! check_ajax_referer( 'scanpay-order-' . $oid, 'nonce', false ) ) {
	wp_send_json_error( 'forbidden', 403 );
}

$wco = wc_get_order( $oid );
if ( ! $wco || ! str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
	wp_send_json_error( 'not_a_scanpay_order', 400 );
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

// capture_or_hold() swallows the failure into an 'on-hold' status + order note and
// returns false; the concrete reason is in the WooCommerce log (source wc-scanpay).
if ( WC_Scanpay_Capture::capture_or_hold( $wco ) ) {
	wp_send_json_success();
}
wp_send_json_error( 'capture_failed', 502 );
