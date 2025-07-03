<?php

defined( 'ABSPATH' ) || exit();

/**
 *  Hook: handle_bulk_actions-woocommerce_page_wc-orders
 *  The hook is called by ListTable::handle_bulk_actions()
 *  Woo has validated the nonce and user permissions for us.
 */

$settings = get_option( WC_SCANPAY_URI_SETTINGS );

$capture = false;
switch ( $action ) {
	case 'scanpay_capture_complete':
		$capture = true;
		break;
	case 'scanpay_mark_completed':
		if ( 'completed' === $settings['wc_autocapture'] ) {
			$capture = true;
		}
		break;
	default:
		return $redirect_to; // Not a Scanpay action, exit early.
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';

// Optimization: Avoid Capture after Complete hook
remove_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5 );

$changed = 0;
foreach ( $ids as $oid ) {
	$wco     = wc_get_order( $oid );
	$ostatus = $wco->get_status( 'edit' );
	$msg     = '';
	if ( ! $wco || 'completed' === $ostatus ) {
		continue;
	}

	$nstatus = 'completed';
	if ( $capture ) {
		$res = WC_Scanpay_Capture::capture( $wco );
		$msg = $res['msg'];
		if ( 'skipped' === $res['status'] ) {
			scanpay_log( 'debug', "Capture skipped on order #$oid: $msg" );
			continue;
		}
		switch ( $res['status'] ) {
			case 'ok':
				$msg = "Scanpay captured $msg.";
				break;
			case 'failed':
				$nstatus = 'failed';
				$msg     = "Scanpay capture failed: $msg.";
				scanpay_log( 'warning', "Capture failed on order #$oid: $msg" );
				break;
			case 'aborted':
				$nstatus = 'failed';
				$msg     = "Scanpay capture aborted: $msg.";
				scanpay_log( 'warning', "Capture aborted on order #$oid: $msg" );
				break;
		}
	}

	if ( $nstatus === $ostatus ) {
		// No change in status, just add a note
		$wco->add_order_note( "$msg.", false, true );
	} else {
		$wco->update_status( $nstatus, $msg, true );
		do_action( 'woocommerce_order_edit_status', $oid, $nstatus );
		++$changed;
	}
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
