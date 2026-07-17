<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Hook: handle_bulk_actions-woocommerce_page_wc-orders
 * Woo has already validated nonce and capabilities.
 * This should replicate do_bulk_action_mark_orders()
 */

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

// Avoid duplicate capture via the completed hook in this request.
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

/**
 * Handle bulk capture of orders.
 *
 * @param string $redirect_to Redirect URL after processing.
 * @param array  $ids         Selected order IDs.
 * @param bool   $capture     Whether to capture payments.
 * @return string Modified redirect URL.
 */
function wc_scanpay_handle_bulk_capture( string $redirect_to, array $ids, bool $capture ): string {
	// Initialize payment gateways in case an order has hooked status transition
	// actions, as WC core does in do_bulk_action_mark_orders(). Our bulk actions
	// replace "Mark as completed" for every order in the list, not just Scanpay
	// ones, so other gateways must get the chance to register their hooks too.
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
		// 'trash' is the last line of defence: the menu no longer offers our
		// actions in the trash view, but the handler must not rely on that --
		// capturing a trashed order would charge the customer and untrash it.
		if ( ! $wco || in_array( $wco->get_status(), [ 'completed', 'trash' ], true ) ) {
			continue;
		}
		if ( $capture && ! WC_Scanpay_Capture::capture_or_hold( $wco ) ) {
			continue; // Parked 'on-hold' with a note; do not complete.
		}
		$wco->set_status( 'completed', __( 'Order status changed by bulk edit.', 'scanpay-for-woocommerce' ), true );
		$wco->save();
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
