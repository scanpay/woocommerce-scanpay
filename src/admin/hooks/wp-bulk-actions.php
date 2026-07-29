<?php

/**
 * Bulk "Capture and complete", and the hijacked "Mark as completed", on the orders list.
 * WooCommerce has already checked the nonce and capabilities by the time the filter fires.
 *
 * Stands in for WooCommerce's own bulk mark-status loop, which never runs for our action
 * keys, so whatever it does around the status change is replicated here, not inherited.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

// Avoid duplicate capture via the completed hook in this request.
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

/** Complete the selected orders, capturing first when asked to. */
function wc_scanpay_handle_bulk_capture( string $redirect_to, array $ids, bool $capture ): string {
	// Instantiate the gateways so their status-transition hooks register, as WC core does:
	// our actions replace "Mark as completed" for every order in the list, not only ours.
	WC()->payment_gateways();

	$oids = [];
	foreach ( $ids as $id ) {
		if ( ( is_int( $id ) && $id > 0 ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
			$oids[] = (int) $id;
		}
	}
	if ( ! $oids ) {
		return $redirect_to;
	}

	// Granted before the loop, so the threshold below races this 60 rather than a host
	// default of 30, where the two would be equal and the renewal unreachable. Not
	// hypothetical: the bailout lands at the opcode after a 20 s cURL wait returns, inside
	// capture() and never at the top of the loop where the check lives.
	set_time_limit( 60 );

	$changed = 0;
	$renewed = microtime( true );
	foreach ( $oids as $oid ) {
		// The list is merchant-supplied and unbounded, and each Scanpay order costs a 20 s
		// capture plus an email save() sends inline, so 30 is a renewal cadence and not a
		// bound on one pass. A kill between the charge and that save() leaves a paid order
		// uncompleted, and only capture()'s note keeps that from being silent.
		if ( microtime( true ) - $renewed >= 30 ) {
			set_time_limit( 60 );
			$renewed = microtime( true );
		}
		$wco = wc_get_order( $oid );
		// 'trash' is the last line of defense, read in 'edit' so no filter can answer it.
		// The menu hides our actions in the trash view, but capturing a trashed order would
		// charge the customer and untrash it, so the handler must not rely on that.
		if ( ! $wco || in_array( $wco->get_status( 'edit' ), [ 'completed', 'trash' ], true ) ) {
			continue;
		}
		if ( $capture && ! WC_Scanpay_Capture::capture_or_hold( $wco ) ) {
			continue; // Parked 'on-hold' with a note; do not complete.
		}
		$wco->set_status( 'completed', __( 'Order status changed by bulk edit.', 'scanpay-for-woocommerce' ), true );
		$wco->save();
		// A second fire, as upstream's own bulk loop does it: set_status( ..., true ) already
		// made the first, but that one runs before the write, so a listener reading the order
		// back rather than trusting the arguments would see the pre-change status.
		do_action( 'woocommerce_order_edit_status', $oid, 'completed' );
		++$changed;
	}
	return add_query_arg(
		[
			'bulk_action' => 'marked_completed',
			'changed'     => $changed,
			'ids'         => implode( ',', $oids ),
		],
		$redirect_to
	);
}
