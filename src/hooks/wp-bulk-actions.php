<?php

defined( 'ABSPATH' ) || exit();

/*
 *  Hook: handle_bulk_actions-woocommerce_page_wc-orders
 *  The hook is called by ListTable::handle_bulk_actions()
 *  Woo has validated the nonce and user permissions for us.
 */

if ( 'scanpay_capture_complete' !== $action && 'scanpay_mark_completed' !== $action ) {
	return $redirect_to;
}

if ( 'scanpay_mark_completed' === $action ) {
	// Initialize other gateways so they can react to the order status change.
	WC()->payment_gateways();
}

require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-sync.php';
$sync    = new WC_Scanpay_Sync();
$capture = 'scanpay_capture_complete' === $action || 'completed' === $sync->settings['wc_autocapture'];

if ( $capture ) {
	// Disable capture_after_complete hook
	remove_filter( 'woocommerce_order_status_completed', [ $sync, 'capture_after_complete' ], 3, 2 );
}

$sync->acquire_lock(); // Block pings until we are done.
$changed = 0;
foreach ( $ids as $oid ) {
	$wco = wc_get_order( $oid );
	if ( ! $wco || ! is_int( $oid ) || 'completed' === $wco->get_status( 'edit' ) ) {
		continue;
	}
	if ( str_starts_with( $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
		if ( ! $wco->get_transaction_id() ) {
			// Ignore orders w/o trnid: don't change 'pending payment' to 'failed'.
			continue;
		}
		if ( $capture ) {
			$sync->capture_and_complete( $oid, $wco );
			++$changed;
			continue;
		}
	} elseif ( 'scanpay_mark_completed' !== $action ) {
		// Only complete non-scanpay orders if action is 'scanpay_mark_completed'
		continue;
	}
	$wco->update_status( 'completed', '', true );
	do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
	++$changed;
}
$sync->release_lock();

// Add Capture after Complete hook (for CRON and other plugins).
if ( $capture ) {
	add_filter( 'woocommerce_order_status_completed', [ $sync, 'capture_after_complete' ], 3, 2 );
}

$redirect_to = add_query_arg(
	[
		'bulk_action' => 'marked_completed',
		'changed'     => $changed,
		'ids'         => implode( ',', $ids ),
	],
	$redirect_to
);

return $redirect_to;
