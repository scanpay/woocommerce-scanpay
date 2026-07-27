<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Bulk "Capture and complete" / hijacked "Mark as completed".
 * Filter: handle_bulk_actions-woocommerce_page_wc-orders (nonce and caps already checked by WC)
 *
 * Stands in for WooCommerce's own do_bulk_action_mark_orders(), which never runs for
 * our action keys, so anything it does around the status change is replicated below
 * rather than inherited.
 */

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

// Avoid duplicate capture via the completed hook in this request.
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

/** Complete the selected orders, capturing first when asked to. */
function wc_scanpay_handle_bulk_capture( string $redirect_to, array $ids, bool $capture ): string {
	// Instantiate the gateways so their status-transition hooks are registered, as WC
	// core does. Our bulk actions replace "Mark as completed" for every order in the
	// list, not just Scanpay ones, so other gateways must get that chance too.
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

	$changed = 0;
	foreach ( $oids as $oid ) {
		$wco = wc_get_order( $oid );
		// 'trash' is the last line of defense: the menu does not offer our actions in
		// the trash view, but the handler must not rely on that -- capturing a trashed
		// order would charge the customer and untrash it.
		if ( ! $wco || in_array( $wco->get_status(), [ 'completed', 'trash' ], true ) ) {
			continue;
		}
		if ( $capture && ! WC_Scanpay_Capture::capture_or_hold( $wco ) ) {
			continue; // Parked 'on-hold' with a note; do not complete.
		}
		$wco->set_status( 'completed', __( 'Order status changed by bulk edit.', 'scanpay-for-woocommerce' ), true );
		$wco->save();
		// The second fire, as upstream's own bulk loops do it. set_status( ..., true ) above
		// already made the first, but that one runs before the write, so a listener reading
		// the order back rather than trusting the arguments would see the pre-change status.
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
