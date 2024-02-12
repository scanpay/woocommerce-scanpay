<?php

/*
*   Public API endpoint for ping events from Scanpay.
*/

class WC_Scanpay_Sync {
	private $client;
	private $shopid;
	private $apikey;
	private $settings;
	private $locked;
	private $lockfile;
	private $captured;
	private $wcs_exists;

	public function __construct() {
		require WC_SCANPAY_DIR . '/library/client.php';
		$this->settings   = get_option( WC_SCANPAY_URI_SETTINGS );
		$this->apikey     = $this->settings['apikey'] ?? '';
		$this->client     = new WC_Scanpay_Client( $this->apikey );
		$this->shopid     = (int) explode( ':', $this->apikey )[0];
		$this->lockfile   = sys_get_temp_dir() . '/scanpay_' . $this->shopid . '_lock/';
		$this->wcs_exists = class_exists( 'WC_Subscriptions', false );

		// [hook] Capture on Complete
		add_action( 'woocommerce_order_status_completed', [ $this, 'hook_order_status_completed' ], 3, 2 );

		// [hook] WCS Renewals
		add_action( 'woocommerce_scheduled_subscription_payment_scanpay', [ $this, 'hook_wcs_renewal' ], 3, 2 );

		// [hook] Change default order status after payment_complete()
		add_filter(
			'woocommerce_payment_complete_order_status',
			function ( string $status, int $id, object $order ) {
				if ( 'processing' === $status && 'scanpay' === $order->get_payment_method() && $order->get_meta( WC_SCANPAY_URI_AUTOCPT, true, 'edit' ) ) {
					// Order was auto-captured
					return 'completed';
				}
				return $status;
			},
			10,
			3
		);
	}

	// [hook] Capture on Complete
	public function hook_order_status_completed( int $oid, object $order ) {
		if ( 'yes' !== $this->settings['capture_on_complete'] || 'scanpay' !== $order->get_payment_method() ) {
			return;
		}
		// Check if order needs payment or if autocapture is set
		if ( $order->get_total() === 0 || $order->get_meta( WC_SCANPAY_URI_AUTOCPT, true, 'edit' ) ) {
			return;
		}

		global $wpdb;
		$sql = "INSERT INTO {$wpdb->prefix}scanpay_queue SET act = 'capture', orderid = $oid";
		if ( ! $wpdb->query( $sql ) ) {
			return; // Capture is probably already in the queue (TODO: check)
		}
		if ( $this->locked ) {
			return; // hook called inside a sync process
		}
		if ( ! $this->acquire_lock() ) {
			// Another sync process is running; Wait for the queue to be processed.
			return $this->poll_db_queue( $oid, 'capture' );
		}

		$seqdb = $wpdb->get_row( "SELECT seq, mtime, ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $this->shopid", ARRAY_A );
		if ( ! $seqdb || ( time() - (int) $seqdb['mtime'] ) > 360 || $seqdb['ping'] > $seqdb['seq'] ) {
			scanpay_log( 'error', 'Capture skipped (#' . $oid . '): database is not synchronized with Scanpay' );
			$order->add_order_note( 'Capture skipped: database is not synchronized with Scanpay' );
			return;
		}

		try {
			if ( $this->process_queue() ) {
				$seq            = (int) $seqdb['seq'];
				$this->captured = false;
				$this->sync( $this->poll_db_ping( $seq ), $seq );
			}
			$this->release_lock();
		} catch ( Exception $e ) {
			$this->release_lock();
			scanpay_log( 'error', "Capture on Complete failed (order #$oid): " . $e->getMessage() );
		}
	}

	// [hook] WCS Renewals
	public function hook_wcs_renewal( float $x, object $order ) {
		$oid    = (int) $order->get_id();
		$subid  = (int) $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		$amount = (string) $x;

		if ( (int) $order->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
			return;
		}
		if ( ! $subid ) {
			$order->update_status( 'failed', 'Charge failed: order does not have a Scanpay Subscriber ID' );
			return; // Only possible if merchant delted the subid by accident.
		}
		global $wpdb;
		$sql = "INSERT INTO {$wpdb->prefix}scanpay_queue SET act = 'charge', orderid = $oid, subid = $subid, amount = $amount";
		if ( ! $wpdb->query( $sql ) ) {
			return; // Charge is probably already in the queue (TODO: check)
		}
		if ( $this->locked ) {
			return; // hook called inside a sync process (e.g. initial charge)
		}
		if ( ! $this->acquire_lock() ) {
			// A sync process is running in another thread
			return $this->poll_db_queue( $oid, 'charge' );
		}

		$seqdb = $wpdb->get_row( "SELECT seq, mtime, ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $this->shopid", ARRAY_A );
		if ( ! $seqdb || ( time() - (int) $seqdb['mtime'] ) > 360 || $seqdb['ping'] > $seqdb['seq'] ) {
			scanpay_log( 'error', 'Charge skipped (#' . $oid . '): database is not synchronized with Scanpay' );
			$order->add_order_note( 'Charge skipped: database is not synchronized with Scanpay' );
			return;
		}

		try {
			if ( $this->process_queue() ) {
				$seq            = (int) $seqdb['seq'];
				$this->captured = false;
				$this->sync( $this->poll_db_ping( $seq ), $seq );
			}
			$this->release_lock();
		} catch ( Exception $e ) {
			$this->release_lock();
			scanpay_log( 'error', "Renewal failed (order #$oid): " . $e->getMessage() );
		}
	}

	// Simple "filelock" with mkdir (because it's atomic, fast and dirty!)
	// TODO: maybe add a fallback
	private function acquire_lock(): bool {
		if ( $this->locked ) {
			return true;
		}
		if ( ! @mkdir( $this->lockfile ) && file_exists( $this->lockfile ) ) {
			$dtime = time() - filemtime( $this->lockfile );
			if ( $dtime >= 0 && $dtime < 120 ) {
				return false;
			}
		}
		$this->locked = true;
		return true;
	}

	private function release_lock() {
		$this->locked = false;
		rmdir( $this->lockfile );
	}

	private function renew_lock() {
		if ( ! touch( $this->lockfile ) ) {
			throw new Exception( 'could not renew lock' );
		}
	}

	/*
		Poll for an incoming ping (e.g. after a capture or charge)
		We only do this when we have a lock, so we can afford to be aggressive.
	*/
	private function poll_db_ping( int $seq ) {
		global $wpdb;
		$counter = 0;
		usleep( 100000 );
		do {
			usleep( 50000 );
			$ping = (int) $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $this->shopid" );
		} while ( $ping <= $seq && $counter++ < 30 );
		return ( $ping > $seq ) ? $ping : $seq;
	}

	private function poll_db_queue( int $oid, string $type ) {
		global $wpdb;
		$counter = 0;
		do {
			usleep( 500000 ); // TODO: exponential backoff?
			$sql   = "SELECT orderid FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $oid AND act = '$type'";
			$query = $wpdb->query( $sql );
		} while ( $query && $counter++ < 20 );
		return true;
	}

	private function wc_scanpay_validate_seq( array $c ): bool {
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

	private function wc_scanpay_subscriber( array $c ) {
		global $wpdb;
		$subid = (int) $c['id'];
		$oid   = (int) $c['ref'];
		$order = wc_get_order( $oid );
		$rev   = (int) $c['rev'];
		if ( ! $order ) {
			return;
		}
		$old_shopid = (int) $order->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
		if ( $old_shopid && $old_shopid !== $this->shopid ) {
			return;
		}
		$sub_exists = $wpdb->query( "SELECT subid FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid" );
		if ( $sub_exists ) {
			$sql = $wpdb->prepare(
				"UPDATE {$wpdb->prefix}scanpay_subs
                SET nxt = %d, retries = %d, idem = %s, rev = %d, method = %s, method_id = %s, method_exp = %d
                WHERE subid = %d",
				[ 0, 5, '', $rev, $c['method']['type'], $c['method']['id'], $c['method']['card']['exp'], $subid ]
			);
			if ( false === $wpdb->query( $sql ) ) {
				throw new Exception( "could not update subscriber data (id=$subid)" );
			}
		} else {
			$order->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
			$order->save_meta_data();

			$subs_for_order = wcs_get_subscriptions_for_order( $c['ref'], [ 'order_type' => [ 'parent' ] ] );
			foreach ( $subs_for_order as $wc_subid => $wc_sub ) {
				$wc_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
				$wc_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
				$wc_sub->save_meta_data();
			}

			$sql = $wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}scanpay_subs
                SET subid = %d, nxt = %d, retries = %d, idem = %s, rev = %d, method = %s, method_id = %s, method_exp = %d",
				[ $subid, 0, 5, '', $rev, $c['method']['type'], $c['method']['id'], $c['method']['card']['exp'] ]
			);
			if ( ! $wpdb->query( $sql ) ) {
				throw new Exception( "could not insert subscriber data (id=$subid)" );
			}

			// Add initial charge
			if ( 'pending' === $order->get_status( 'edit' ) && $order->get_total() > 0 ) {
				$sql = "INSERT INTO {$wpdb->prefix}scanpay_queue SET act = 'charge', orderid = $oid, subid = $subid";
				if ( ! $wpdb->query( $sql ) ) {
					// No point in throwing here. It will not be retried since subscriber row is created. (TODO)
					$order->add_order_note( 'Error: could not perform initial charge' );
					scanpay_log( 'error', "could not insert initial charge into queue (order #$oid)" );
					return;
				}
			}
		}
	}

	private function sync( int $ping_seq, int $seq ) {
		global $wpdb;
		ignore_user_abort( true );

		while ( $ping_seq > $seq ) {
			set_time_limit( 60 );
			$res = $this->client->seq( $seq );
			if ( ! $res['changes'] || $res['seq'] <= $seq ) {
				throw new Exception( "Received an unexpected seq from scanpay (seq=$seq)" );
			}

			foreach ( $res['changes'] as $c ) {
				if ( ! $this->wc_scanpay_validate_seq( $c ) ) {
					continue;
				}
				if ( 'subscriber' === $c['type'] ) {
					if ( $this->wcs_exists ) {
						$this->wc_scanpay_subscriber( $c );
					}
					continue;
				}
				$oid      = (int) $c['orderid'];
				$db_rev   = $wpdb->get_var( "SELECT rev FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
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
					$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE act = 'charge' AND orderid = $oid" );
				}

				if ( is_null( $db_rev ) ) {
					$order = wc_get_order( $c['orderid'] );
					if ( ! $order || ! str_starts_with( $order->get_payment_method(), 'scanpay' ) ) {
						continue;
					}
					$old_shopid = (int) $order->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
					if ( $old_shopid && $old_shopid !== $this->shopid ) {
						scanpay_log( 'warning', "Skipped order #$oid: shopid mismatch" );
						continue;
					}
					$currency   = substr( $c['totals']['authorized'], -3 );
					$authorized = substr( $c['totals']['authorized'], 0, -4 );
					$method     = $c['method']['type'];
					if ( 'card' === $method ) {
						$method = 'card ' . $c['method']['card']['brand'] . ' ' . $c['method']['card']['last4'];
					}
					$sql = "INSERT INTO {$wpdb->prefix}scanpay_meta
							SET orderid = $oid,
								subid = $subid,
								shopid = $this->shopid,
								id = " . (int) $c['id'] . ",
								rev = $rev,
								nacts = $nacts,
								currency = '$currency',
								authorized = '$authorized',
								captured = '$captured',
								refunded = '$refunded',
								voided = '$voided',
								method = '$method'";

					if ( ! $wpdb->query( $sql ) ) {
						throw new Exception( "could not save payment data to order #$oid" );
					}

					if ( empty( $order->get_transaction_id( 'edit' ) ) ) {
						// Change order status to 'processing' and save transaction ID
						$order->set_date_paid( $c['time']['authorized'] );
						$order->payment_complete( $c['id'] );
					}

					$order->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
					$order->set_payment_method( 'scanpay' );
					$order->save();
				} elseif ( $rev > $db_rev ) {
					$sql = "UPDATE {$wpdb->prefix}scanpay_meta
							SET rev = $rev, nacts = $nacts, captured = '$captured', refunded = '$refunded', voided = '$voided'
							WHERE orderid = $oid";
					if ( false === $wpdb->query( $sql ) ) {
						throw new Exception( "could not save payment data to order #$oid" );
					}
				}
			}
			$this->renew_lock();
			wp_cache_flush();

			// Update seq and mtime
			$seq = $res['seq'];
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . ", seq = $seq WHERE shopid = $this->shopid" );

			if ( $ping_seq <= $seq && $this->process_queue() ) {
				$this->captured = false;
				$ping_seq       = $this->poll_db_ping( $seq ); // We charged; pull ping
			}
		}
	}


	public function handle_ping() {
		global $wpdb;
		$body = file_get_contents( 'php://input', false, null, 0, 512 );
		$sig  = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

		if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $this->apikey, true ) ), $sig ) ) {
			wp_send_json( [ 'error' => 'invalid signature' ], 403 );
			die();
		}

		$ping = json_decode( $body, true );
		if ( ! isset( $ping, $ping['seq'], $ping['shopid'] ) || ! is_int( $ping['seq'] ) || $this->shopid !== $ping['shopid'] ) {
			wp_send_json( [ 'error' => 'invalid JSON' ], 400 );
			die();
		}

		$seq = (int) $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $this->shopid" );

		if ( $ping['seq'] === $seq ) {
			if ( $this->process_queue() ) {
				// We acquired a lock and charged. Now wait for ping.
				$this->captured = false;
				$ping['seq']    = $this->poll_db_ping( $seq );
			} else {
				$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . " WHERE shopid = $this->shopid" );
				return wp_send_json_success();
			}
		} elseif ( ! $this->acquire_lock() ) {
			// Another process is running; block and save ping.
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET ping = " . $ping['seq'] . " WHERE shopid = $this->shopid" );
			return wp_send_json( [ 'error' => 'busy' ], 423 );
		}

		try {
			$this->sync( $ping['seq'], $seq );
			$this->release_lock();
			wp_send_json_success();
		} catch ( Exception $e ) {
			$this->release_lock();
			scanpay_log( 'error', 'Scanpay Sync Error: ' . $e->getMessage() );
			wp_send_json( [ 'error' => $e->getMessage() ], 500 );
		}
	}


	private function charge( int $oid, object $order, array $arr ) {
		global $wpdb;
		if ( ! $order->needs_payment() ) {
			scanpay_log( 'info', "Charge skipped on #$oid: order does not need payment" );
			return;
		}
		$subid = (int) $arr['subid'];
		$sub   = $wpdb->get_row( "SELECT retries, nxt, idem FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		if ( ! $sub ) {
			return;
		}
		if ( 0 === $sub['retries'] ) {
			scanpay_log( 'info', "Charge skipped on #$oid: no retries left" );
			return;
		}
		if ( (int) $sub['nxt'] > time() ) {
			scanpay_log( 'info', "Charge skipped on #$oid: not allowed until " . gmdate( $sub['nxt'] ) );
			return; // Skip: not yet time for retry.
		}

		if ( empty( $sub['idem'] ) ) {
			$sub['idem'] = $oid . ':' . base64_encode( random_bytes( 18 ) );
		} else {
			// Previous charge was not be resolved. We want to reuse idem key.
			$idem_order_id = (int) explode( ':', $sub['idem'] )[0];
			if ( $idem_order_id !== $oid ) {
				$old_order = wc_get_order( $idem_order_id );
				if ( $old_order && $old_order->needs_payment() ) {
					scanpay_log( 'info', "Charge skipped on #$oid: subscriber has unpaid order (#$idem_order_id)" );
					return;
				}
				// Previous charge was successful or cancelled. Reset idempotency key.
				$sub['idem'] = $oid . ':' . base64_encode( random_bytes( 18 ) );
			}
		}

		// Make sure we don't have an order with this id;
		$meta_exists = $wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		if ( $meta_exists ) {
			scanpay_log( 'info', "Charge skipped on #$oid: order already exists" );
			return;
		}

		$nxt = time() + 900; // lock sub for 900s (15m) to limit races
		$sql = "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '" . $sub['idem'] . "' WHERE subid = $subid AND nxt = " . $sub['nxt'] . '';
		if ( false === $wpdb->query( $sql ) ) {
			scanpay_log( 'warning', "Charge skipped on #$oid: race condition avoided" );
			return;
		}

		$data = [
			'orderid'     => $oid,
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

		if ( 'yes' === $this->settings['wcs_complete_initial'] && wcs_order_contains_subscription( $order, 'parent' ) ) {
			$data['autocapture'] = true; // Auto-Complete: Initial Charge
			$order->add_meta_data( WC_SCANPAY_URI_AUTOCPT, 1, true );
			$order->save_meta_data();
		} elseif ( 'yes' === $this->settings['wcs_complete_renewal'] && wcs_order_contains_renewal( $order ) ) {
			$data['autocapture'] = true; // Auto-Complete: Renewals
			$order->add_meta_data( WC_SCANPAY_URI_AUTOCPT, 1, true );
			$order->save_meta_data();
		}

		try {
			$this->client->charge( $subid, $data, [ 'headers' => [ 'Idempotency-Key' => $sub['idem'] ] ] );
			$this->captured = true;
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
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '', retries = $rt WHERE subid = $subid" );
			scanpay_log( 'error', 'scanpay client exception: ' . $e->getMessage() );
		}
	}

	private function capture( int $oid, object $order, string $amount = null ): void {
		global $wpdb;
		try {
			$meta = $wpdb->get_row( "SELECT nacts FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );
			if ( ! $meta ) {
				throw new Exception( "could not find metadata for order #$oid" );
			}
			$nacts = (int) $meta['nacts'];
			if ( ! $amount ) {
				if ( $nacts > 0 ) {
					return; // Skip: already captured or voided
				}
				// Capture on Complete
				$refunded = $order->get_total_refunded();
				if ( $refunded > 0 ) {
					require_once WC_SCANPAY_DIR . '/library/math.php';
					$amount = wc_scanpay_submoney( (string) $order->get_total(), (string) $refunded );
				} else {
					$amount = $order->get_total();
				}
			}

			$this->client->capture(
				$order->get_transaction_id( 'edit' ),
				[
					'total' => $amount . ' ' . $order->get_currency(),
					'index' => $nacts,
				]
			);
			$this->captured = true;
		} catch ( \Exception $e ) {
			scanpay_log( 'notice', "Capture failed on order #$oid: " . $e->getMessage() );
			$order->update_status( 'failed', 'Scanpay capture failed: ' . $e->getMessage() );
		}
	}

	private function process_queue(): bool {
		global $wpdb;
		$queue = $wpdb->get_results( "SELECT orderid, act, amount, subid from {$wpdb->prefix}scanpay_queue", ARRAY_A );
		foreach ( $queue as $k => $arr ) {
			$oid = $arr['orderid'];
			$act = $arr['act'];
			$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $oid AND act = '$act'" );
			$order = wc_get_order( $oid );
			if ( $order ) {
				if ( 'charge' === $act && $this->wcs_exists ) {
					$this->charge( $oid, $order, $arr );
				} elseif ( 'capture' === $act ) {
					$this->capture( $oid, $order, $arr['amount'] );
				}
			}
		}
		return $this->captured ?? false;
	}
}
