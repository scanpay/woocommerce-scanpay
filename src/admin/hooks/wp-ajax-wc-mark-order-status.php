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
 *
 * Priority 0 also means this file sees *every* order-list row action on the site, for
 * every status and every gateway, which is why the gate is split: the capability first,
 * then the read-only tests that decide whether the request is ours, and the nonce last.
 * Answering a stale nonce ourselves would replace WooCommerce's retry page with raw JSON
 * on somebody else's PayPal order. The invariant that keeps that safe is positional --
 * everything above the nonce check only reads; everything below it changes the request's
 * behaviour or the order.
 */

// Capability first, before anything is parsed: an unauthenticated caller must not learn
// from the response whether an order id is well-formed. Same property, same reason as
// wp-ajax-wc-scanpay-capture.php:18-20. What an authenticated edit_shop_orders actor can
// learn from the tests below -- that an order id exists, and whether it is ours -- the
// Orders screen already shows them.
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
 * unreachable from a rendered row action -- WooCommerce builds both URLs from
 * $order->get_id() (ListTable.php:1340, :1348) -- it is not a CSRF hole, because nothing
 * above the nonce writes, and returning instead would hand a malformed order_id to
 * WC_AJAX::mark_order_status(), which absint()s it: order 0 silently completed, or
 * whatever '12abc' truncates to.
 *
 * No (string) cast on the unslashed value. wp_unslash() of an array returns an array, and
 * casting one is what emits "Array to string conversion"; ctype_digit( [] ) is a plain
 * false with no diagnostic, so the cast was the whole bug. is_string() states that rule
 * instead of relying on the reader knowing it, and upstream's absint( wp_unslash( … ) )
 * is exactly the leniency this die exists to refuse.
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

if ( ! str_starts_with( $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
	return; // Not a Scanpay order; fall through to WooCommerce's handler.
}

// The bulk handler's guard, for the reason it gives (wp-bulk-actions.php:41-45): the menu
// does not offer a row action in the trash view, but the nonce is per *action*, not per
// order -- the id rides in the query string beside it (ListTable.php:1340, :1348) -- so
// one valid link is reusable for any id, a trashed one included. The exit differs from
// the bulk path's: there a continue skips the order outright, here the return hands it to
// WC_AJAX::mark_order_status(), which still completes and untrashes it. What this buys is
// that no capture runs, so the customer is not charged; the untrash is core behaviour for
// an order we declined and is not ours to stop from here.
if ( in_array( $wco->get_status(), [ 'completed', 'trash' ], true ) ) {
	return;
}

// Last, and a return rather than a die: WooCommerce's own handler runs next and checks
// the nonce properly -- current_user_can() && check_admin_referer() at
// class-wc-ajax.php:667 -- which answers a stale one with wp_nonce_ays(), WordPress's
// "Are you sure you want to do this? / Please try again" page (pluggable.php:1394-1397).
// Row actions are ordinary browser navigations, not fetch, so that page is what a human
// has to get. The nonce is therefore verified twice on our path: by us to decide whether
// to act, by WooCommerce to decide whether to refuse, and only its answer is rendered.
if ( ! check_ajax_referer( 'woocommerce-mark-order-status', false, false ) ) {
	return;
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
