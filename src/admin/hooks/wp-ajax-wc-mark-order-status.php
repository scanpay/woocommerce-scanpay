<?php

/**
 * The order-list row action that marks an order completed, hooked to
 * wp_ajax_woocommerce_mark_order_status at priority 0, ahead of WooCommerce's own handler,
 * so the capture happens before completion and its emails.
 *
 * Priority 0 also means this file sees *every* row action on the site, for every status
 * and gateway, which is why the gate is split: the capability first, then the read-only
 * tests that decide whether the request is ours, and the nonce last. Answering a stale
 * nonce ourselves would replace WooCommerce's retry page with raw JSON on somebody else's
 * PayPal order. The invariant is positional -- everything above the nonce check only
 * reads, everything below it changes the request or the order.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// Required here rather than inherited from admin/orders.php, which happens to have loaded it
// already: that file states the rule, and the capture class below is required the same way.
require_once WC_SCANPAY_DIR . '/library/functions.php';

// Capability first, before anything is parsed: an unauthenticated caller must not learn
// from the response whether an order id is well-formed. What an authenticated
// edit_shop_orders actor learns from the tests below, the Orders screen already shows them.
if ( ! current_user_can( 'edit_shop_orders' ) ) {
	wp_send_json_error( 'forbidden', 403 );
}

// Any other status transition is WooCommerce's business; fall through to its handler.
if (
	! isset( $_GET['status'], $_GET['order_id'] ) ||
	'completed' !== $_GET['status']
) {
	return;
}

/*
 * The one JSON answer left above the nonce check, and deliberately still a die. It is
 * unreachable from a rendered row action, since WooCommerce's order list builds both URLs
 * from $order->get_id(); it is not a CSRF hole, because nothing above the nonce writes; and
 * returning instead would hand a malformed order_id to WC_AJAX::mark_order_status(), which
 * absint()s it -- order 0 silently completed, or whatever '12abc' truncates to.
 *
 * No (string) cast: wp_unslash() of an array returns an array, and casting one emits "Array
 * to string conversion". is_string() states the rule rather than relying on ctype_digit()'s
 * silent false.
 */
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ctype_digit is the validation; value is cast to int below.
$raw = wp_unslash( $_GET['order_id'] );
if ( ! is_string( $raw ) || ! ctype_digit( $raw ) ) {
	wp_send_json_error( 'invalid_order_id', 400 );
}
$oid = (int) $raw;
$wco = wc_get_order( $oid );
if ( ! $wco ) {
	return;
}

if ( ! wc_scanpay_is_scanpay_order( $wco ) ) {
	return; // Not a Scanpay order; fall through to WooCommerce's handler.
}

// The bulk handler's guard, for the reason it gives, and needed here too: the nonce is per
// *action*, not per order -- the id rides in the query string beside it -- so one valid
// link is reusable for any id, a trashed one included. The exit differs from the bulk
// path's: this return hands the order to WC_AJAX::mark_order_status(), which still
// completes and untrashes it. What it buys is that no capture runs, so the customer is not
// charged; the untrash is core behaviour and not ours to stop from here.
if ( in_array( $wco->get_status( 'edit' ), [ 'completed', 'trash' ], true ) ) {
	return;
}

// Last, and a return rather than a die: WC_AJAX::mark_order_status() runs next and checks
// the nonce with check_admin_referer(), which answers a stale one through wp_nonce_ays()
// with WordPress's expired-link page. Row actions are ordinary browser navigations, not
// fetch, so that page is what a human has to get. The nonce is verified twice on our path
// -- by us to decide whether to act, by WooCommerce to decide whether to refuse -- and only
// its answer is rendered.
if ( ! check_ajax_referer( 'woocommerce-mark-order-status', false, false ) ) {
	return;
}

// This request captures explicitly, so drop the status hook or the completion below
// re-enters capture for the same order.
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

// This handler exits, so WC_AJAX::mark_order_status() never runs. Replicate the two things
// it does around the status change, or an integration hooking either silently misses every
// Scanpay order completed from a row action. The bulk path does the same.
WC()->payment_gateways();

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
	$wco->update_status( 'completed', '', true );
	do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
	wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
	exit;
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

// A failure parks the order 'on-hold' with a note, never 'failed'; complete it only when
// the capture actually succeeded.
if ( WC_Scanpay_Capture::capture_or_hold( $wco ) ) {
	$wco->set_status( 'completed', '', true );
	$wco->save();
	do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
}

wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
exit;
