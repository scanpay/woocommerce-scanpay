<?php

/*
*   wc_api_scanpay.php:
*   Public API endpoint for ping events from Scanpay.
*/

defined( 'ABSPATH' ) || exit();

ignore_user_abort( true );
nocache_headers();
wc_set_time_limit( 0 );
wp_raise_memory_limit( 'cron' );

function wc_scanpay_validate_seq( array $c ): bool {
	if ( isset( $c['error'] ) ) {
		scanpay_log( 'error', "Synchronization error: transaction [id={$c['id']}] skipped due to error: {$c['error']}" );
		return false;
	}
	if (
		! isset( $c['rev'], $c['acts'], $c['id'] ) ||
		! is_int( $c['rev'] ) || ! is_int( $c['id'] ) || ! is_array( $c['acts'] )
	) {
		throw new Exception( 'received invalid seq from server' );
	}

	switch ( $c['type'] ) {
		case 'charge':
			if ( empty( $c['subscriber'] ) ) {
				scanpay_log( 'warning', "Skipped charge #{$c['id']}: missing reference" );
				return false;
			}
			// fall-through
		case 'transaction':
			if ( ! isset( $c['totals'], $c['totals']['authorized'] ) ) {
				throw new Exception( 'received invalid seq from server' );
			}
			if ( empty( $c['orderid'] ) || ! is_numeric( $c['orderid'] ) ) {
				scanpay_log( 'warning', "skipped transaction #{$c['id']}: no WooCommerce orderid" );
				return false;
			}
			break;
		case 'subscriber':
			if ( empty( $c['ref'] ) ) {
				scanpay_log( 'warning', "Skipped subscriber #{$c['id']}: missing reference" );
				return false;
			}
			break;
		default:
			throw new Exception( "received unknown seq type: {$c['type']}" );
	}
	return true;
}

function wc_scanpay_subscriber( array $c ) {
	// 	phpcs:ignore Squiz.PHP.CommentedOutCode.Found
	/*
	if ( 'subscriber' === $c['type'] ) {
		$wc_order->add_meta_data( WC_SCANPAY_URI_SUBID, $c['id'], true );
		if ( $wc_order->needs_payment() && $wc_order->get_total() > 0 ) {
			// $queue += [ $c['ref'] => $c['id'] ];
		}
	}
	*/
}


function wc_scanpay_apply_changes( int $shopid, array $arr ) {
	global $wpdb;

	foreach ( $arr as $c ) {
		if ( ! wc_scanpay_validate_seq( $c ) ) {
			continue;
		}
		if ( 'subscriber' === $c['type'] ) {
			wc_scanpay_subscriber( $c );
			continue;
		}
		$orderid  = (int) $c['orderid'];
		$db_rev   = $wpdb->get_var( "SELECT rev FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $orderid" );
		$rev      = (int) $c['rev'];
		$nacts    = count( $c['acts'] );
		$captured = substr( $c['totals']['captured'], 0, -4 );
		$refunded = substr( $c['totals']['refunded'], 0, -4 );
		$voided   = substr( $c['totals']['voided'], 0, -4 );

		if ( is_null( $db_rev ) ) {
			$currency   = substr( $c['totals']['authorized'], -3 );
			$authorized = substr( $c['totals']['authorized'], 0, -4 );
			$subid      = ( 'charge' === $c['type'] ) ? (int) $c['subscriber']['id'] : 0;
			$method     = $c['method']['type'];
			if ( 'card' === $method ) {
				$method = 'card ' . $c['method']['card']['brand'] . ' ' . $c['method']['card']['last4'];
			}

			$res = $wpdb->query(
				"INSERT INTO {$wpdb->prefix}scanpay_meta
					SET orderid = $orderid,
						subid = $subid,
						shopid = $shopid,
						id = " . (int) $c['id'] . ",
						rev = $rev,
						nacts = $nacts,
						currency = '$currency',
						authorized = '$authorized',
						captured = '$captured',
						refunded = '$refunded',
						voided = '$voided',
						method = '$method'"
			);
			if ( ! $res ) {
				throw new Exception( "could not save payment data to order #$orderid" );
			}
			$wc_order = wc_get_order( $c['orderid'] );
			if ( ! $wc_order ) {
				continue;
			}
			if ( $wc_order->needs_payment() ) {
				// Change order status to 'processing' and save transaction ID
				$wc_order->payment_complete( $c['id'] );
			}

			// 	phpcs:ignore Squiz.PHP.CommentedOutCode.Found
			// $wc_order->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
			$wc_order->set_payment_method( 'scanpay' );
			$wc_order->save();
		} elseif ( $rev > $db_rev ) {
			$res = $wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_meta
					SET rev = $rev, nacts = $nacts, captured = '$captured', refunded = '$refunded', voided = '$voided'
					WHERE orderid = $orderid"
			);
			if ( ! $res ) {
				throw new Exception( "could not save payment data to order #$orderid" );
			}
		}
	}
}


$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$apikey   = $settings['apikey'] ?? '';
$shopid   = (int) explode( ':', $apikey )[0];
$body     = file_get_contents( 'php://input', false, null, 0, 512 );

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $apikey, true ) ), $sig ) ) {
	wp_send_json( [ 'error' => 'invalid signature' ], 403 );
	die();
}

$ping = json_decode( $body, true );
if ( ! isset( $ping, $ping['seq'], $ping['shopid'] ) || ! is_int( $ping['seq'] ) || $shopid !== $ping['shopid'] ) {
	wp_send_json( [ 'error' => 'invalid JSON' ], 400 );
	die();
}

global $wpdb;
$seq = (int) $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );

if ( $ping['seq'] === $seq ) {
	$mtime = time();
	$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = $mtime WHERE shopid = $shopid" );
	return wp_send_json( [ 'success' => true ], 304 );
} elseif ( $ping['seq'] < $seq ) {
	$err_msg = 'The received ping seq (' . $ping['seq'] . ") was smaller than the local seq ($seq)";
	scanpay_log( 'error', 'scanpay synchronization error: ' . $err_msg );
	return wp_send_json( [ 'error' => $err_msg ], 400 );
}

// Simple "filelock" with mkdir (because it's atomic, fast and dirty!)
$flock = sys_get_temp_dir() . '/scanpay_' . $shopid . '_lock/';
if ( ! @mkdir( $flock ) && file_exists( $flock ) ) {
	$dtime = time() - filemtime( $flock );
	if ( $dtime >= 0 && $dtime < 60 ) {
		// Save pings we ignore to the DB
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET ping = " . $ping['seq'] . " WHERE shopid = $shopid" );
		return wp_send_json( [ 'error' => 'busy' ], 423 );
	}
}

require WC_SCANPAY_DIR . '/library/client.php';
$client = new WC_Scanpay_Client( $apikey );
$queue  = [];

try {
	while ( $seq < $ping['seq'] ) {
		$res = $client->seq( $seq );
		if ( empty( $res['changes'] ) ) {
			if ( ! empty( $queue ) ) {
				require_once WC_SCANPAY_DIR . '/includes/initial-charge.php';
				wc_scanpay_initial_charge( $client, $queue, $settings );
				$queue = [];
				continue;
			}
			break; // done
		}
		wc_scanpay_apply_changes( $shopid, $res['changes'] );

		// Update seq in the DB
		$seq   = (int) $res['seq'];
		$mtime = time();
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = $mtime, seq = $seq WHERE shopid = $shopid" );

		touch( $flock );
		usleep( 500000 ); // sleep for 500 ms (wait for changes)
		if ( $seq >= $ping['seq'] ) {
			$ping['seq'] = $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
		}
	}
	rmdir( $flock );
	wp_send_json_success();
} catch ( Exception $e ) {
	rmdir( $flock );
	scanpay_log( 'error', 'synchronization error: ' . $e->getMessage() );
	wp_send_json( [ 'error' => $e->getMessage() ], 500 );
}
