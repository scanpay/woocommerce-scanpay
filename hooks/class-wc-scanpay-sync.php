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
	private $await_ping;
	private $wcs_exists;

	public function __construct() {
		$this->settings = get_option( WC_SCANPAY_URI_SETTINGS );
		$this->apikey   = $this->settings['apikey'] ?? '';
		$this->shopid   = (int) strstr( $this->apikey, ':', true );

		// [hook] Order status changed to completed
		add_filter( 'woocommerce_order_status_completed', function ( int $oid, object $order ) {
			if (
				'yes' === $this->settings['capture_on_complete'] && 'scanpay' === $order->get_payment_method( 'edit' ) &&
				$order->get_total( 'edit' ) > 0 && ! $order->get_meta( WC_SCANPAY_URI_AUTOCPT, true, 'edit' )
			) {
				$this->queue( 'capture', $order );
			}
		}, 3, 2 );

		// [hook] Manual and Recurring renewals (cron|admin|user)
		add_filter( 'woocommerce_scheduled_subscription_payment_scanpay', function ( float $x, object $order ) {
			$this->queue( 'charge', $order, (int) $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) );
		}, 3, 2 );

		// [filter] Change default order status after payment_complete()
		add_filter( 'woocommerce_payment_complete_order_status', function ( string $status, int $id, object $order ) {
			if ( 'processing' === $status && $order->get_meta( WC_SCANPAY_URI_AUTOCPT, true, 'edit' ) ) {
				return 'completed'; // Order was auto-captured
			}
			return $status;
		}, 10, 3 );
	}

	/*
		Simple "filelock" with mkdir (because it's atomic, fast and dirty!)
	*/
	private function acquire_lock(): bool {
		if ( $this->locked ) {
			return true;
		}
		$this->lockfile = sys_get_temp_dir() . '/scanpay_' . $this->shopid . '_lock/';
		if ( ! @mkdir( $this->lockfile ) && file_exists( $this->lockfile ) ) {
			$dtime = time() - filemtime( $this->lockfile );
			if ( $dtime >= 0 && $dtime < 120 ) {
				return false;
			}
		}
		// Load dependencies
		if ( ! class_exists( 'WC_Scanpay_Client', false ) ) {
			require WC_SCANPAY_DIR . '/library/math.php';
			require WC_SCANPAY_DIR . '/library/client.php';
			$this->client     = new WC_Scanpay_Client( $this->apikey );
			$this->wcs_exists = class_exists( 'WC_Subscriptions', false );
		}
		$this->locked = true;
		return true;
	}

	private function release_lock(): void {
		$this->locked = false;
		if ( $this->lockfile ) {
			rmdir( $this->lockfile );
		}
	}

	private function renew_lock(): void {
		if ( ! $this->lockfile || ! touch( $this->lockfile ) ) {
			throw new Exception( 'could not renew lock' );
		}
	}

	/*
		Poll until queue is processed (captured/charged/failed)
		This is not gated by lockfile.
	*/
	private function poll_db_queue( int $oid, string $type ): bool {
		global $wpdb;
		$sql = "SELECT orderid FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $oid AND act = '$type'";
		$len = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scanpay_queue" );
		if ( $len > 20 ) {
			return false; // number of processes that can poll at the same time
		}
		$delay = 150000 + 25000 * $len; // min: 0,175s; max[20]: 0,65s (limit resource usage)
		for ( $counter = 0; $counter < 30; $counter++ ) {
			usleep( $delay );
			$query = $wpdb->query( $sql );
			if ( 0 === $query ) {
				wc_delete_shop_order_transients( $oid );
				return true;
			}
		}
		return false;
	}


	private function currency_amount( string $str ): string {
		$sfloat = substr( $str, 0, -4 );
		if ( ! is_numeric( $sfloat ) ) {
			throw new Exception( "invalid currency amount: $str" );
		}
		return $sfloat;
	}

	/*
		Parse and validate totals array. Return without currency.
		[ auhtorized, captured, refunded, voided, currency ]
	*/
	private function totals( array $arr ): array {
		$currency   = substr( $arr['authorized'], -3 );
		$authorized = $this->currency_amount( $arr['authorized'] );
		if ( $arr['captured'] === $arr['authorized'] ) {
			// Fully captured. Voided is 0. Refunded is unknown
			if ( $arr['refunded'] === $arr['voided'] ) {
				return [ $authorized, $authorized, '0', '0', $currency ];
			}
			if ( $arr['refunded'] === $arr['authorized'] ) {
				return [ $authorized, $authorized, $authorized, '0', $currency ];
			}
			return [ $authorized, $authorized, $this->currency_amount( $arr['refunded'] ), '0', $currency ];
		}
		if ( $arr['captured'] === $arr['voided'] ) {
			// Captured and Voided can only be identical when they are both 0
			return [ $authorized, '0', '0', '0', $currency ];
		}
		if ( $arr['voided'] === $arr['authorized'] ) {
			// Fully voided
			return [ $authorized, '0', '0', $authorized, $currency ];
		}
		$refunded = ( $arr['refunded'] === $arr['voided'] ) ? '0' : $this->currency_amount( $arr['refunded'] );
		return [ $authorized, $this->currency_amount( $arr['captured'] ), $refunded, '0', $currency ];
	}


	private function order_is_valid( $order ): bool {
		if ( ! $order ) {
			return false;
		}
		$psp = $order->get_payment_method( 'edit' );
		if ( 'scanpay' !== $psp && ! str_starts_with( $psp, 'scanpay' ) ) {
			scanpay_log( 'warning', 'Skipped order #' . $order->get_id() . ': payment method mismatch' );
			return false;
		}
		if ( (int) $order->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
			scanpay_log( 'warning', 'Skipped order #' . $order->get_id() . ': shopid mismatch' );
			return false;
		}
		return true;
	}


	private function wc_scanpay_subscriber( int $subid, int $oid, int $rev, array $c ) {
		global $wpdb;
		$wpdb->query( "SELECT rev FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid" );
		$sub = $wpdb->last_result;
		if ( 0 === $wpdb->num_rows ) {
			$order = wc_get_order( $oid );
			if ( ! $this->order_is_valid( $order ) ) {
				return;
			}
			$insert = $wpdb->query(
				"INSERT INTO {$wpdb->prefix}scanpay_subs
                	SET subid = $subid, nxt = 0, retries = 5, idem = '', rev = $rev, method = '" . $c['method']['type'] . "',
						method_id = '" . $c['method']['id'] . "', method_exp = '" . $c['method']['card']['exp'] . "'"
			);
			if ( ! $insert ) {
				throw new Exception( "could not insert subscriber data (id=$subid)" );
			}
			if ( ! $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) ) {
				$order->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
				$order->save_meta_data();
				$wcs_subs = wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'any' ] );

				foreach ( $wcs_subs as $wcs_sub ) {
					$wcs_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
					$wcs_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
					$wcs_sub->save_meta_data();
				}

				if ( 'pending' === $order->get_status( 'edit' ) && $order->get_total( 'edit' ) > 0 ) {
					$query = $wpdb->query( "INSERT INTO {$wpdb->prefix}scanpay_queue SET act = 'charge', orderid = $oid, subid = $subid" );
					if ( ! $query ) {
						scanpay_log( 'error', "could not insert initial charge into queue (order #$oid)" );
					}
				}
			}
		} elseif ( $rev > $sub[0]->rev ) {
			$update = $wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_subs SET nxt = 0, retries = 5, idem = '', rev = $rev,
					method = '" . $c['method']['type'] . "', method_id = '" . $c['method']['id'] . "',
					method_exp = '" . $c['method']['card']['exp'] . "' WHERE subid = $subid"
			);
			if ( false === $update ) {
				throw new Exception( "could not update subscriber data (id=$subid)" );
			}
		}
	}


	private function apply_payment( int $trnid, int $oid, int $rev, array $c ) {
		global $wpdb;
		$wpdb->query( "SELECT id,rev FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		$meta = $wpdb->last_result;
		if ( 0 === $wpdb->num_rows ) {
			$order = wc_get_order( $oid );
			if ( ! $this->order_is_valid( $order ) ) {
				return;
			}
			$subid  = ( 'charge' === $c['type'] ) ? (int) $c['subscriber']['id'] : 0;
			$method = $c['method']['type'];
			if ( 'card' === $method ) {
				$method = 'card ' . $c['method']['card']['brand'] . ' ' . $c['method']['card']['last4'];
			}

			list( $authorized, $captured, $refunded, $voided, $currency ) = $this->totals( $c['totals'] );
			$insert = $wpdb->query(
				"INSERT INTO {$wpdb->prefix}scanpay_meta
					SET orderid = $oid, subid = $subid, shopid = $this->shopid, id = $trnid,
						rev = $rev, nacts = " . count( $c['acts'] ) . ", currency = '$currency', authorized = '$authorized',
						captured = '$captured', refunded = '$refunded', voided = '$voided', method = '$method'"
			);
			if ( ! $insert ) {
				throw new Exception( "could not save payment data to order #$oid" );
			}
			if ( empty( $order->get_transaction_id( 'edit' ) ) ) {
				$order->set_payment_method( 'scanpay' );
				$order->set_date_paid( $c['time']['authorized'] );
				$order->payment_complete( $trnid ); // calls save()
			}
		} elseif ( $trnid !== (int) $meta[0]->id ) {
			scanpay_log( 'warning', "Scanpay payment ignored (id=$trnid); order #$oid is already paid (id=" . $meta[0]->id . ')' );
		} elseif ( $rev > $meta[0]->rev ) {
			list( $authorized, $captured, $refunded, $voided, $currency ) = $this->totals( $c['totals'] );
			$update = $wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_meta SET rev = $rev, nacts = " . count( $c['acts'] ) . ",
				captured = '$captured', refunded = '$refunded', voided = '$voided' WHERE orderid = $oid"
			);
			if ( false === $update ) {
				throw new Exception( "could not save payment data to order #$oid" );
			}
		}
	}

	private function seq( int $ping_seq, int $old_seq ) {
		global $wpdb;
		$seq   = $old_seq;
		$force = $ping_seq === $old_seq;
		while ( $ping_seq > $seq || $force ) {
			if ( $seq !== $old_seq ) {
				set_time_limit( 60 );
				$this->renew_lock();
				wp_cache_flush();
			}
			$res = $this->client->seq( $seq );
			if ( ! $res['changes'] ) {
				return;
			}

			foreach ( $res['changes'] as $c ) {
				if ( isset( $c['error'] ) ) {
					scanpay_log( 'error', "Synchronization error: transaction [id={$c['id']}] skipped due to error: {$c['error']}" );
					continue;
				}
				if ( ! is_array( $c['acts'] ) || ! is_array( $c['time'] ) || ! is_array( $c['method'] ) ) {
					throw new Exception( "received an invalid response from server (seq=$seq)" );
				}

				switch ( $c['type'] ) {
					case 'charge':
						if ( ! ( $c['subscriber']['id'] ?? null ) ) {
							scanpay_log( 'warning', "Skipped charge #$c[id]: missing reference" );
							break;
						}
						// fall-through
					case 'transaction':
						if ( ! isset( $c['totals'], $c['totals']['authorized'] ) ) {
							throw new Exception( "received an invalid response from server (seq=$seq)" );
						}
						$oid = isset( $c['orderid'] ) ? (int) $c['orderid'] : false;
						if ( $oid && $c['orderid'] === (string) $oid ) {
							$this->apply_payment( $c['id'], $oid, $c['rev'], $c );
						}
						break;
					case 'subscriber':
						$oid = isset( $c['ref'] ) ? (int) $c['ref'] : false;
						if ( $this->wcs_exists && $oid && $c['ref'] === (string) $oid ) {
							$this->wc_scanpay_subscriber( $c['id'], $oid, $c['rev'], $c );
						}
						break;
				}
			}
			$seq = $res['seq'];
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . ", seq = $seq WHERE shopid = " . $this->shopid );

			if ( $force ) {
				$force = false;
			} elseif ( $ping_seq <= $seq && $this->process_queue() ) {
				$force = true;
			}
		}
	}

	public function handle_ping() {
		ignore_user_abort( true );
		global $wpdb;
		$body = file_get_contents( 'php://input', false, null, 0, 512 );

		if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $this->apikey, true ) ), $_SERVER['HTTP_X_SIGNATURE'] ) ) {
			wp_send_json( [ 'error' => 'invalid signature' ], 403 );
			die();
		}
		$seq  = (int) $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $this->shopid );
		$ping = json_decode( $body, true );
		if ( ! isset( $ping, $ping['seq'], $ping['shopid'] ) || ! is_int( $ping['seq'] ) || $this->shopid !== $ping['shopid'] ) {
			wp_send_json( [ 'error' => 'invalid JSON' ], 400 );
			die();
		}

		if ( $ping['seq'] === $seq ) {
			if ( ! $this->process_queue() ) {
				$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . " WHERE shopid = $this->shopid" );
				return wp_send_json_success();
			}
		} elseif ( ! $this->acquire_lock() ) {
			// Another process is running; save ping.
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET ping = $ping[seq] WHERE shopid = $this->shopid" );
			return wp_send_json( [ 'error' => 'busy' ], 423 );
		}
		try {
			$this->seq( $ping['seq'], $seq );
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
			throw new Exception( 'order does not need payment' );
		}
		$subid = (int) $arr['subid'];
		$sub   = $wpdb->get_row( "SELECT retries, nxt, idem FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		if ( ! $sub ) {
			throw new Exception( "subscriber (subid=$subid) does not exist" );
		}
		if ( 0 === $sub['retries'] ) {
			throw new Exception( "no retries left on subscriber (subid=$subid)" );
		}
		if ( (int) $sub['nxt'] > time() ) {
			throw new Exception( 'retry not allowed until ' . gmdate( $sub['nxt'] ) );
		}
		if ( (int) $order->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
			throw new Exception( 'shopid mismatch' );
		}

		if ( empty( $sub['idem'] ) ) {
			$sub['idem'] = $oid . ':' . base64_encode( random_bytes( 18 ) );
		} else {
			// Previous charge was not be resolved. We want to reuse idem key.
			$idem_order_id = (int) explode( ':', $sub['idem'] )[0];
			if ( $idem_order_id !== $oid ) {
				$old_order = wc_get_order( $idem_order_id );
				if ( $old_order && $old_order->needs_payment() ) {
					throw new Exception( "subscriber has unpaid order (#$idem_order_id). Please cancel or charge this order first." );
				}
				// Previous charge was successful or cancelled. Reset idempotency key.
				$sub['idem'] = $oid . ':' . base64_encode( random_bytes( 18 ) );
			}
		}

		// Make sure we don't have an order with this id;
		$meta_exists = $wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		if ( $meta_exists ) {
			throw new Exception( "payment details already exist for order #$oid" );
		}

		$nxt = time() + 900; // lock sub for 900s (15m) to limit races
		$sql = "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '" . $sub['idem'] . "' WHERE subid = $subid AND nxt = " . $sub['nxt'] . '';
		if ( false === $wpdb->query( $sql ) ) {
			throw new Exception( 'race condition' );
		}

		$data = [
			'orderid'     => $oid,
			'autocapture' => false,
			'billing'     => [
				'name'    => $order->get_billing_first_name( 'edit' ) . ' ' . $order->get_billing_last_name( 'edit' ),
				'email'   => $order->get_billing_email( 'edit' ),
				'phone'   => $order->get_billing_phone( 'edit' ),
				'address' => [ $order->get_billing_address_1( 'edit' ), $order->get_billing_address_2( 'edit' ) ],
				'city'    => $order->get_billing_city( 'edit' ),
				'zip'     => $order->get_billing_postcode( 'edit' ),
				'country' => $order->get_billing_country( 'edit' ),
				'state'   => $order->get_billing_state( 'edit' ),
				'company' => $order->get_billing_company( 'edit' ),
			],
			'shipping'    => [
				'name'    => $order->get_shipping_first_name( 'edit' ) . ' ' . $order->get_shipping_last_name( 'edit' ),
				'address' => [ $order->get_shipping_address_1( 'edit' ), $order->get_shipping_address_2( 'edit' ) ],
				'city'    => $order->get_shipping_city( 'edit' ),
				'zip'     => $order->get_shipping_postcode( 'edit' ),
				'country' => $order->get_shipping_country( 'edit' ),
				'state'   => $order->get_shipping_state( 'edit' ),
				'company' => $order->get_shipping_company( 'edit' ),
			],
		];

		// Add and sum order items
		$sum      = '0';
		$currency = $order->get_currency( 'edit' );
		foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
			$line_total = $order->get_line_total( $item, true, true ); // w. taxes and rounded (how Woo does)
			if ( $line_total >= 0 ) {
				$sum             = wc_scanpay_addmoney( $sum, strval( $line_total ) );
				$data['items'][] = [
					'name'     => $item->get_name( 'edit' ),
					'quantity' => $item->get_quantity(),
					'total'    => $line_total . ' ' . $currency,
				];
			}
		}

		$wc_total = strval( $order->get_total( 'edit' ) );
		if ( wc_scanpay_cmpmoney( $sum, $wc_total ) !== 0 ) {
			$data['items'] = [
				[
					'name'  => 'Total',
					'total' => $wc_total . ' ' . $currency,
				],
			];
			scanpay_log(
				'warning',
				"Order #$oid: The sum of all items ($sum) does not match the order total ($wc_total)." .
				'The item list will not be available in the scanpay dashboard.'
			);
		}

		if (
			( 'yes' === $this->settings['wcs_complete_initial'] && wcs_order_contains_subscription( $order, 'parent' ) ) ||
			( 'yes' === $this->settings['wcs_complete_renewal'] && wcs_order_contains_renewal( $order ) )
		) {
			$data['autocapture'] = true;
			$order->add_meta_data( WC_SCANPAY_URI_AUTOCPT, 1, true );
		}

		try {
			$res = $this->client->charge( $subid, $data, [ 'headers' => [ 'Idempotency-Key' => $sub['idem'] ] ] );
			$order->set_payment_method( 'scanpay' );
			$order->set_date_paid( time() );
			$order->payment_complete( $res['id'] ); // Will save the order
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = 0, idem = '', retries = 5 WHERE subid = $subid" );
			$this->await_ping = true;
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
		$meta = $wpdb->get_row( "SELECT id, nacts FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );
		if ( ! $meta ) {
			throw new Exception( "no payment details on order #$oid" );
		}
		$nacts = (int) $meta['nacts'];
		if ( ! $amount ) {
			if ( $nacts > 0 ) {
				return; // Skip: already captured or voided
			}
			// Capture on Complete
			$refunded = $order->get_total_refunded();
			if ( $refunded > 0 ) {
				// require_once WC_SCANPAY_DIR . '/library/math.php';
				$amount = wc_scanpay_submoney( (string) $order->get_total( 'edit' ), (string) $refunded );
			} else {
				$amount = $order->get_total( 'edit' );
			}
		}
		$this->client->capture( $meta['id'], [
			'total' => $amount . ' ' . $order->get_currency( 'edit' ),
			'index' => $nacts,
		] );
		$this->await_ping = true;
	}

	private function queue( string $act, object $order, int $subid = 0 ) {
		global $wpdb;
		$oid = (int) $order->get_id();
		$sql = "INSERT INTO {$wpdb->prefix}scanpay_queue SET act = '$act', orderid = $oid, subid = $subid";
		if ( ! $wpdb->query( $sql ) || $this->locked ) {
			return;
		}
		try {
			// Check if we are synchronized with Scanpay.
			$seqdb = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $this->shopid, ARRAY_A );
			if ( ! $seqdb || ( time() - (int) $seqdb['mtime'] ) > 360 || $seqdb['ping'] > $seqdb['seq'] ) {
				throw new Exception( 'not synchronized with Scanpay' );
			}

			// Try to acquire lock. If we fail, another process is already running.
			if ( ! $this->acquire_lock() ) {
				return $this->poll_db_queue( $oid, $act );
			}

			// Process queue (we own the lock)
			if ( $this->process_queue() ) {
				usleep( 100000 ); // TODO: fix this
				$seq = (int) $seqdb['seq'];
				$this->seq( $seq, $seq );
			}
			$this->release_lock();
		} catch ( Exception $e ) {
			$this->release_lock();
			$order->add_order_note( "Scanpay $act failed: " . $e->getMessage() );
			scanpay_log( 'error', "$act failed (order #$oid): " . $e->getMessage() );
		}
	}

	private function process_queue(): bool {
		global $wpdb;
		$queue = $wpdb->get_results( "SELECT orderid, act, amount, subid from {$wpdb->prefix}scanpay_queue", ARRAY_A );
		if ( ! $queue || ! $this->acquire_lock() ) {
			return false;
		}
		$this->await_ping = false;
		foreach ( $queue as $k => $arr ) {
			$oid = $arr['orderid'];
			$act = $arr['act'];
			try {
				$order = wc_get_order( $oid );
				if ( $order ) {
					if ( 'charge' === $act && $this->wcs_exists ) {
						$this->charge( $oid, $order, $arr );
					} elseif ( 'capture' === $act ) {
						$this->capture( $oid, $order, $arr['amount'] );
					}
				}
			} catch ( \Exception $e ) {
				scanpay_log( 'warning', "$act failed on order #$oid: " . $e->getMessage() );
				$order->update_status( 'failed', "Scanpay $act failed: " . $e->getMessage() );
			}
			$wpdb->query( "DELETE FROM {$wpdb->prefix}scanpay_queue WHERE orderid = $oid AND act = '$act'" );
		}
		return $this->await_ping;
	}
}
