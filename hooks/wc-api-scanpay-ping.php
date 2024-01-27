<?php

/*
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
	global $wpdb;
	$subid    = (int) $c['id'];
	$orderid  = (int) $c['ref'];
	$wc_order = wc_get_order( $orderid );
	if ( ! $wc_order ) {
		return scanpay_log( 'info', "Charge failed (subID: $subid); order #$orderid not found" );
	}

	$sub = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
	$rev = (int) $c['rev'];

	if ( is_null( $sub ) ) {
		// New subscriber. Add subid to order and subscriptions
		$wc_order->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
		$subs_for_order = wcs_get_subscriptions_for_order( $c['ref'], [ 'order_type' => [ 'parent' ] ] );
		foreach ( $subs_for_order as $wc_subid => $wc_sub ) {
			$wc_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
			$wc_sub->save();
		}

		$sql = $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}scanpay_subs
			SET subid = %d, nxt = %d, retries = %d, idem = %s, rev = %d, method = %s, method_id = %s, method_exp = %d",
			[ $subid, 0, 5, '', $rev, $c['method']['type'], $c['method']['id'], $c['method']['card']['exp'] ]
		);
		if ( ! $wpdb->query( $sql ) ) {
			throw new Exception( "could not insert subscriber data (id=$subid)" );
		}

		if ( $wc_order->needs_payment() ) {
			$sql = "INSERT INTO {$wpdb->prefix}scanpay_queue SET orderid = $orderid, subid = $subid";
			if ( ! $wpdb->query( $sql ) ) {
				throw new Exception( "could not insert order #$orderid into queue" );
			}
		}
	} else {
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->prefix}scanpay_subs
			SET nxt = %d, retries = %d, idem = %s, rev = %d, method = %s, method_id = %s, method_exp = %d
			WHERE subid = %d",
			[ 0, 5, '', $rev, $c['method']['type'], $c['method']['id'], $c['method']['card']['exp'], $subid ]
		);
		if ( false === $wpdb->query( $sql ) ) {
			throw new Exception( "could not update subscriber data (id=$subid)" );
		}
	}
}


function wc_scanpay_charge( object $client ): void {
	global $wpdb;
	$queue = $wpdb->get_results( "SELECT * from {$wpdb->prefix}scanpay_queue", ARRAY_A );
	if ( empty( $queue ) ) {
		return;
	}

	foreach ( $queue as $k => $arr ) {
		$orderid = (int) $arr['orderid'];
		$subid   = (int) $arr['subid'];
		$sub     = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );

		if ( ! $sub ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $orderid" );
			scanpay_log( 'info', "could not find subscriber data (id=$subid)" );
			continue;
		}

		if ( 0 === $sub['retries'] ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $orderid" );
			scanpay_log( 'info', "Charge skipped on #$orderid: no retries left" );
			continue;
		}

		if ( (int) $sub['nxt'] > time() ) {
			scanpay_log( 'info', "Charge skipped on #$orderid: not allowed until " . gmdate( $sub['nxt'] ) );
			continue; // Skip: not yet time for retry.
		}

		if ( empty( $sub['idem'] ) ) {
			$sub['idem'] = $orderid . ':' . rtrim( base64_encode( random_bytes( 32 ) ) );
		} else {
			// Previous charge was not be resolved. We want to reuse idem key.
			$idem_order_id = explode( ':', $sub['idem'] )[0];
			if ( $idem_order_id !== $orderid ) {
				$old_order = wc_get_order( $idem_order_id );
				if ( $old_order && $old_order->needs_payment() ) {
					// Enforce chronological order of charges
					scanpay_log( 'info', "Charge skipped on #$orderid: subscriber has not paid order #$idem_order_id" );
					continue;
				}
				// Previous charge was successful or cancelled. Reset idempotency key.
				scanpay_log( 'info', "Idempotency key was not deleted on order #$idem_order_id" );
				$sub['idem'] = $orderid . ':' . rtrim( base64_encode( random_bytes( 32 ) ) );
			}
		}

		$order = wc_get_order( $orderid );
		if ( ! $order ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $orderid" );
			scanpay_log( 'info', "could not find order with id #$orderid" );
			continue;
		}

		$meta = $wpdb->get_var( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $orderid" );
		$nxt  = time() + 900; // lock sub for 900s (15m) to limit races
		$sql  = $wpdb->query(
			"UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '" . $sub['idem'] . "'
			WHERE subid = $subid AND nxt = " . $sub['nxt'] . ''
		);
		if ( false === $sql || isset( $meta ) || ! $order->needs_payment() ) {
			scanpay_log( 'warning', "Charge skipped on #$orderid: race condition" );
			continue;
		}

		$data = [
			'orderid'     => $orderid,
			'items'       => [
				[ 'total' => $order->get_total() . ' ' . $order->get_currency() ],
			],
			'autocapture' => false,
			'billing'     => array_filter(
				[
					'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
					'email'   => $order->get_billing_email(),
					'phone'   => $order->get_billing_phone(),
					'address' => array_filter( [ $order->get_billing_address_1(), $order->get_billing_address_2() ] ),
					'city'    => $order->get_billing_city(),
					'zip'     => $order->get_billing_postcode(),
					'country' => $order->get_billing_country(),
					'state'   => $order->get_billing_state(),
					'company' => $order->get_billing_company(),
				]
			),
		];

		try {
			$charge = $client->charge( $subid, $data, [ 'headers' => [ 'Idempotency-Key' => $sub['idem'] ] ] );
			$order->payment_complete( $charge['id'] );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $orderid" );
		} catch ( \Exception $e ) {
			/*
				TODO:
				- Add backoff based of retries left
				- Technical errors should not cost retries and not change idem
				- Add error_types: transient/permanent (wait backend)
				- Permanent errors ("card expired") should set retries = 0
			*/
			$nxt = time() + 86400; // next retry in 24 hours
			$rt  = $sub['retries'] - 1;
			$wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_subs
				SET nxt = $nxt, idem = '', retries = $rt
				WHERE subid = $subid"
			);
			scanpay_log( 'error', 'scanpay client exception: ' . $e->getMessage() );
		}
	}
	usleep( 500000 ); // wait .5s for pings
}


function wc_scanpay_apply_changes( int $shopid, array $arr ) {
	global $wpdb;

	foreach ( $arr as $c ) {
		if ( ! wc_scanpay_validate_seq( $c ) ) {
			continue;
		}
		if ( 'subscriber' === $c['type'] && class_exists( 'WC_Subscriptions', false ) ) {
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
		$subid    = 0;

		if ( 'charge' === $c['type'] ) {
			$subid = (int) $c['subscriber']['id'];
			// Reset the subscriber's retry counter etc.
			$wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_subs
				SET nxt = 0, idem = '', retries = 5
				WHERE subid = $subid"
			);
			$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $orderid" );
		}

		if ( is_null( $db_rev ) ) {
			$currency   = substr( $c['totals']['authorized'], -3 );
			$authorized = substr( $c['totals']['authorized'], 0, -4 );
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
			$order = wc_get_order( $c['orderid'] );
			if ( ! $order ) {
				continue;
			}
			if ( $order->needs_payment() ) {
				// Change order status to 'processing' and save transaction ID
				$order->payment_complete( $c['id'] );
			}

			// 	phpcs:ignore Squiz.PHP.CommentedOutCode.Found
			// $order->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
			$order->set_payment_method( 'scanpay' );
			$order->save();
		} elseif ( $rev > $db_rev ) {
			$res = $wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_meta
					SET rev = $rev, nacts = $nacts, captured = '$captured', refunded = '$refunded', voided = '$voided'
					WHERE orderid = $orderid"
			);
			if ( false === $res ) {
				throw new Exception( "could not save payment data to order #$orderid" );
			}
		}
	}
}

global $wpdb;
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

// Simple "filelock" with mkdir (because it's atomic, fast and dirty!)
$flock = sys_get_temp_dir() . '/scanpay_' . $shopid . '_lock/';
if ( ! @mkdir( $flock ) && file_exists( $flock ) ) {
	$dtime = time() - filemtime( $flock );
	if ( $dtime >= 0 && $dtime < 60 ) {
		// Ignore ping; save it to DB
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET ping = " . $ping['seq'] . " WHERE shopid = $shopid" );
		return wp_send_json( [ 'error' => 'busy' ], 423 );
	}
}

try {
	require WC_SCANPAY_DIR . '/library/client.php';
	$client = new WC_Scanpay_Client( $apikey );
	$seq    = (int) $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );

	if ( $ping['seq'] === $seq ) {
		$mtime = time();
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = $mtime WHERE shopid = $shopid" );
		if ( class_exists( 'WC_Subscriptions', false ) ) {
			wc_scanpay_charge( $client );
			$ping['seq'] = $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
		}
		if ( $ping['seq'] === $seq ) {
			rmdir( $flock );
			return wp_send_json_success();
		}
	} elseif ( $ping['seq'] < $seq ) {
		throw new Exception( 'The received ping seq (' . $ping['seq'] . ") was smaller than the local seq ($seq)" );
	}

	while ( $seq < $ping['seq'] ) {
		$res   = $client->seq( $seq );
		$seq   = (int) $res['seq'];
		$mtime = time();
		wc_scanpay_apply_changes( $shopid, $res['changes'] );
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = $mtime, seq = $seq WHERE shopid = $shopid" );
		touch( $flock );

		if ( $seq >= $ping['seq'] ) {
			usleep( 500000 ); // wait .5s; check if we missed/ignored a ping
			$ping['seq'] = $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );

			// If we are done then process the queue
			if ( $seq >= $ping['seq'] && class_exists( 'WC_Subscriptions', false ) ) {
				wc_scanpay_charge( $client );
				$ping['seq'] = $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
			}
		}
	}
	rmdir( $flock );
	wp_send_json_success();
} catch ( Exception $e ) {
	rmdir( $flock );
	scanpay_log( 'error', 'synchronization error: ' . $e->getMessage() );
	wp_send_json( [ 'error' => $e->getMessage() ], 500 );
}
