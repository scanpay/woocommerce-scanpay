<?php

/*
*   Public API endpoint for ping events from Scanpay.
*/

class WC_Scanpay_Sync {
	private $lockfile;
	public $settings;
	private $shopid;
	private $client;
	private $subscriptions;
	private $in_sync;

	public function __construct() {
		if ( ! class_exists( 'WC_Scanpay_Client', false ) ) {
			require WC_SCANPAY_DIR . '/library/math.php';
			require WC_SCANPAY_DIR . '/library/client.php';
		}
		$this->settings      = get_option( WC_SCANPAY_URI_SETTINGS );
		$this->client        = new WC_Scanpay_Client( $this->settings['apikey'] ?? '' );
		$this->shopid        = $this->client->shopid;
		$this->subscriptions = class_exists( 'WC_Subscriptions', false );

		if ( 'yes' === $this->settings['capture_on_complete'] ) {
			// [hook] Order status changed to completed
			add_filter( 'woocommerce_order_status_completed', [ $this, 'capture_after_complete' ], 3, 2 );
		}

		if ( $this->subscriptions ) {
			// [hook] Manual and Recurring renewals (cron|admin|user)
			add_filter( 'woocommerce_scheduled_subscription_payment_scanpay', function ( float $x, object $wco ) {
				$oid   = (int) $wco->get_id();
				$str   = $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
				$subid = abs( (int) $str );
				if ( 0 === $subid || (string) $subid !== $str ) {
					scanpay_log( 'error', 'charge stopped: missing or invalid scanpay subscriber ID' );
					return $wco->update_status( 'failed', 'missing or invalid scanpay subscriber ID' );
				}
				if ( ! $this->in_sync ) {
					global $wpdb;
					$mtime = (int) $wpdb->get_var( "SELECT mtime FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $this->shopid );
					// Only charge if the system is in sync (+10m)
					if ( ( time() - $mtime ) > 600 ) {
						scanpay_log( 'error', "Subscription charge interrupted (#$oid): the plugin is not synchronized" );
						return $wco->update_status( 'failed', "Subscription charge interrupted (#$oid): the plugin is not synchronized." );
					}
					$this->in_sync = true;
				}
				$this->charge( $oid, $wco, $subid );
			}, 3, 2 );
			remove_filter( 'woocommerce_scheduled_subscription_payment_scanpay', 'wc_scanpay_load_sync', 1, 0 );
		}

		if ( 'yes' === $this->settings['wc_complete_virtual'] ) {
			/*
				WC auto-completes downloadable orders, but not virtual orders. This filter
				will set virtual products to not need processing, so they are auto-completed.
			*/
			add_filter( 'woocommerce_order_item_needs_processing', function ( $needs_processing, $product ) {
				if ( $needs_processing && true === $product->get_virtual( 'edit' ) ) {
					return false; // Product is virtual, but not downloadable.
				}
				return $needs_processing;
			}, 10, 2 );
		}
		remove_filter( 'woocommerce_order_status_completed', 'wc_scanpay_load_sync', 1, 0 );
	}


	private function capture_order( int $oid, object $wco ): array {
		global $wpdb;
		try {
			$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );
			if ( 1 !== $wpdb->num_rows ) {
				throw new Exception( "payment details not found for order #$oid" );
			}
			if ( $meta['voided'] > 0 ) {
				throw new Exception( 'the payment is voided' );
			}
			$amount = (string) $wco->get_total( 'edit' );
			if ( $wco->get_total_refunded() > 0 ) {
				$amount = wc_scanpay_submoney( $amount, $wco->get_total_refunded() );
			}
			if ( $amount <= 0 ) {
				return [ true, 'ignored capture of ' . wc_price( (float) $amount ) ];
			}
			// Adjust for previous captures and refunds
			if ( $meta['captured'] > 0 ) {
				if ( $meta['refunded'] > 0 ) {
					$net_captured = wc_scanpay_submoney( $meta['captured'], $meta['refunded'] );
					if ( $net_captured > 0 ) {
						$amount = wc_scanpay_submoney( $amount, $net_captured );
					}
					$amount = min( $amount, wc_scanpay_submoney( $meta['authorized'], $meta['captured'] ) );
				} else {
					$amount = wc_scanpay_submoney( $amount, $meta['captured'] );
				}
				if ( $amount <= 0 ) {
					return [ true, 'capture skipped; nothing to capture.' ];
				}
			}
			$this->client->capture( (int) $meta['id'], [
				'total' => $amount . ' ' . $wco->get_currency( 'edit' ),
				'index' => (int) $meta['nacts'],
			] );
			return [ true, 'Captured ' . wc_price( (float) $amount ) ];
		} catch ( \Exception $e ) {
			scanpay_log( 'info', "Capture failed on order #$oid: " . $e->getMessage() );
			return [ false, 'Capture failed: ' . $e->getMessage() . '.' ];
		}
	}

	public function capture_after_complete( int $oid, object $wco ): void {
		if ( '1' !== $wco->get_meta( WC_SCANPAY_URI_AUTOCPT, true, 'edit' ) ) {
			$res = $this->capture_order( $oid, $wco );
			$wco->add_order_note( $res[1], false, true );
		}
	}

	public function capture_and_complete( int $oid, object $wco ) {
		$res    = $this->capture_order( $oid, $wco );
		$status = $res[0] ? 'completed' : 'failed';
		$wco->update_status( $status, $res[1], true );
		do_action( 'woocommerce_order_edit_status', $oid, $status );
	}


	/*
		Simple "filelock" with mkdir (because it's atomic, fast and dirty!)
	*/
	public function acquire_lock(): bool {
		if ( $this->lockfile ) {
			return true;
		}
		// We use get_temp_dir over sys_get_temp_dir because it's more reliable (on Windows)
		$this->lockfile = get_temp_dir() . 'scanpay_' . $this->shopid . '_lock/';
		if ( ! @mkdir( $this->lockfile ) ) {
			if ( file_exists( $this->lockfile ) ) {
				// lockfile already exists; check if it's stale
				$dtime = time() - filemtime( $this->lockfile );
				if ( $dtime > 120 ) {
					return true;
				}
			} else {
				scanpay_log( 'error', 'could not create a lockfile in: ' . $this->lockfile );
			}
			$this->lockfile = null;
			return false;
		}
		return true;
	}

	public function release_lock(): void {
		if ( $this->lockfile ) {
			@rmdir( $this->lockfile );
			$this->lockfile = null;
		}
	}

	public function renew_lock(): void {
		if ( ! $this->lockfile || ! @touch( $this->lockfile ) ) {
			throw new Exception( 'could not renew lock' );
		}
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


	private function order_is_valid( $wco ): bool {
		$psp = $wco->get_payment_method( 'edit' );
		if ( 'scanpay' !== $psp && ! str_starts_with( $psp, 'scanpay' ) ) {
			scanpay_log( 'warning', 'Skipped order #' . $wco->get_id() . ': payment method mismatch' );
			return false;
		}
		if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
			scanpay_log( 'warning', 'Skipped order #' . $wco->get_id() . ': shopid mismatch' );
			return false;
		}
		return true;
	}


	private function wc_scanpay_subscriber( int $subid, int $oid, int $rev, array $c ) {
		global $wpdb;
		$wpdb->query( "SELECT rev FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid" );
		$sub = $wpdb->last_result;
		if ( 0 === $wpdb->num_rows ) {
			$wco = wc_get_order( $oid );
			if ( ! $wco || ! $this->order_is_valid( $wco ) ) {
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

			if ( ! $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) ) {
				$wco->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
				$wco->save_meta_data();

				$wcs_subs = wcs_get_subscriptions_for_order( $wco, [ 'order_type' => 'any' ] );
				foreach ( $wcs_subs as $wcs_sub ) {
					$wcs_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
					$wcs_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
					$wcs_sub->save_meta_data();
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

	private function wcs_subscriber( int $subid, array $c ) {
		global $wpdb;
		$rev = (int) $c['rev'];
		$wpdb->query( "SELECT rev FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid" );
		$sub = $wpdb->last_result;
		if ( 0 === $wpdb->num_rows ) {
			$subs  = explode( ',', substr( $c['ref'], 5 ) );
			$count = count( $subs );
			for ( $i = 0; $i < $count; $i++ ) {
				$wcs_sub = wcs_get_subscription( (int) $subs[ $i ] );
				if ( ! $wcs_sub ) {
					continue;
				}
				if ( $wcs_sub->get_trial_period( 'edit' ) ) {
					// Subscription initiated with a free trial. We probably need to complete the parent order
					$parent = $wcs_sub->get_parent();
					if ( $parent && $parent->get_status() === 'pending' && (float) $parent->get_total( 'edit' ) === 0.0 ) {
						$parent->set_payment_method( 'scanpay' );
						$parent->set_status( 'completed', 'Subscription initiated with a free trial. Payment details saved on scanpay subscriber #' . $subid . '.', true );
						$parent->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
						$parent->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
						$parent->add_meta_data( WC_SCANPAY_URI_STATUS, 'free trial', true );
						$parent->save();
					}
				}
				$wcs_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
				$wcs_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
				$wcs_sub->save_meta_data();
			}
			$insert = $wpdb->query(
				"INSERT INTO {$wpdb->prefix}scanpay_subs
                	SET subid = $subid, nxt = 0, retries = 5, idem = '', rev = $rev, method = '" . $c['method']['type'] . "',
						method_id = '" . $c['method']['id'] . "', method_exp = '" . $c['method']['card']['exp'] . "'"
			);
			if ( ! $insert ) {
				throw new Exception( "could not insert subscriber data (id=$subid)" );
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

	private function parse_payment_method( array $method ): string {
		if ( isset( $method['type'] ) ) {
			if ( isset( $method['card'], $method['card']['brand'], $method['card']['last4'] ) ) {
				return $method['type'] . ' ' . $method['card']['brand'] . ' ' . $method['card']['last4'];
			}
			return $method['type'];
		}
		return 'scanpay';
	}

	private function apply_payment( int $trnid, int $oid, int $rev, array $c ) {
		global $wpdb;
		$wpdb->query( "SELECT id,rev FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		$meta = $wpdb->last_result;
		if ( 0 === $wpdb->num_rows ) {
			$wco = wc_get_order( $oid );
			if ( ! $wco || ! $this->order_is_valid( $wco ) ) {
				return;
			}
			if ( empty( $wco->get_transaction_id( 'edit' ) ) ) {
				$wco->set_payment_method( 'scanpay' );
				$wco->set_payment_method_title( $this->parse_payment_method( $c['method'] ) );
				$wco->set_date_paid( $c['time']['authorized'] );
				$wco->set_transaction_id( $trnid );
				if ( 'yes' === $this->settings['capture_on_complete'] ) {
					$wco->set_status( ( '1' === $wco->get_meta( WC_SCANPAY_URI_AUTOCPT, true, 'edit' ) ) ? 'completed' : 'processing' );
				} else {
					$wco->set_status( apply_filters( 'woocommerce_payment_complete_order_status', $wco->needs_processing() ? 'processing' : 'completed', $oid, $wco ) );
				}
				$wco->save();
				do_action( 'woocommerce_payment_complete', $oid, $trnid );
			}
			$subid = ( 'charge' === $c['type'] ) ? (int) $c['subscriber']['id'] : 0;
			list( $authorized, $captured, $refunded, $voided, $currency ) = $this->totals( $c['totals'] );
			$insert = $wpdb->query(
				"INSERT INTO {$wpdb->prefix}scanpay_meta
					SET orderid = $oid, subid = $subid, shopid = $this->shopid, id = $trnid,
						rev = $rev, nacts = " . count( $c['acts'] ) . ", currency = '$currency', authorized = '$authorized',
						captured = '$captured', refunded = '$refunded', voided = '$voided'"
			);
			if ( ! $insert ) {
				throw new Exception( "could not save payment data to order #$oid" );
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

	private function seq( int $ping_seq, int $seq ) {
		global $wpdb;
		while ( $ping_seq > $seq ) {
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
						if ( ! $this->subscriptions ) {
							scanpay_log( 'warning', "Subscriber skipped (seq=$seq). WooCommerce Subscriptions is not active." );
							break;
						}
						if ( ! isset( $c['ref'], $c['id'] ) ) {
							throw new Exception( "received an invalid response from server (seq=$seq)" );
						}
						if ( str_starts_with( $c['ref'], 'wcs[]' ) ) {
							$this->wcs_subscriber( (int) $c['id'], $c );
						} else {
							// Old scheme where ref is the parent order id (or subscription id)
							$oid = isset( $c['ref'] ) ? (int) $c['ref'] : false;
							if ( $oid && $c['ref'] === (string) $oid ) {
								$this->wc_scanpay_subscriber( $c['id'], $oid, $c['rev'], $c );
							}
						}
						break;
				}
			}
			$seq = $res['seq'];
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . ", seq = $seq WHERE shopid = " . $this->shopid );
			if ( $ping_seq === $seq ) {
				$ping_seq = (int) $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $this->shopid );
			}
			if ( $ping_seq > $seq ) {
				$this->renew_lock();
				set_time_limit( 60 );
				wp_cache_flush(); // TODO: consider using wp_cache_flush_group( 'orders' ) instead
			}
		}
		return $seq;
	}

	public function handle_ping() {
		global $wpdb;
		ignore_user_abort( true );
		$ping = $this->client->parsePing();
		if ( ! $ping ) {
			return wp_send_json( [ 'error' => 'invalid signature' ], 403 );
		}
		$seq = (int) $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $this->shopid );

		if ( $ping['seq'] < $seq ) {
			return wp_send_json( [ 'error' => "local seq ($seq) is greater than ping seq ({$ping['seq']})" ], 400 );
		}

		if ( $ping['seq'] === $seq ) {
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . " WHERE shopid = $this->shopid" );
			return wp_send_json_success();
		}

		if ( ! $this->acquire_lock() ) {
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

	private function idempotency_key( int $oid, int $subid ): string {
		global $wpdb;
		$nxt = time() + 1800; // lock sub for 900s (30m) to limit races
		$sub = $wpdb->get_row( "SELECT retries, nxt, idem FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		if ( ! $sub ) {
			throw new Exception( "subscriber (subid=$subid) does not exist" );
		}
		if ( 0 === $sub['retries'] ) {
			throw new Exception( "no retries left on subscriber (subid=$subid)" );
		}
		if ( (int) $sub['nxt'] > time() ) {
			throw new Exception( 'charge not allowed until ' . gmdate( $sub['nxt'] ) );
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
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '" . $sub['idem'] . "' WHERE subid = $subid" );
		return $sub['idem'];
	}


	private function charge( int $oid, object $wco, int $subid ) {
		global $wpdb;
		$data = [
			'orderid'  => $oid,
			'billing'  => [
				'name'    => $wco->get_billing_first_name( 'edit' ) . ' ' . $wco->get_billing_last_name( 'edit' ),
				'email'   => $wco->get_billing_email( 'edit' ),
				'phone'   => $wco->get_billing_phone( 'edit' ),
				'address' => [ $wco->get_billing_address_1( 'edit' ), $wco->get_billing_address_2( 'edit' ) ],
				'city'    => $wco->get_billing_city( 'edit' ),
				'zip'     => $wco->get_billing_postcode( 'edit' ),
				'country' => $wco->get_billing_country( 'edit' ),
				'state'   => $wco->get_billing_state( 'edit' ),
				'company' => $wco->get_billing_company( 'edit' ),
			],
			'shipping' => [
				'name'    => $wco->get_shipping_first_name( 'edit' ) . ' ' . $wco->get_shipping_last_name( 'edit' ),
				'address' => [ $wco->get_shipping_address_1( 'edit' ), $wco->get_shipping_address_2( 'edit' ) ],
				'city'    => $wco->get_shipping_city( 'edit' ),
				'zip'     => $wco->get_shipping_postcode( 'edit' ),
				'country' => $wco->get_shipping_country( 'edit' ),
				'state'   => $wco->get_shipping_state( 'edit' ),
				'company' => $wco->get_shipping_company( 'edit' ),
			],
		];

		/*
			Calculate the sum of all items and check if the order needs processing. WC_Order->needs_payment() is not in
			the cache here, and WCS does not use it, so we make our own is_virtual check.
		*/
		$sum        = '0';
		$currency   = $wco->get_currency( 'edit' );
		$is_virtual = 1;
		foreach ( $wco->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
			if ( $is_virtual && $item instanceof WC_Order_Item_Product ) {
				$prod = $item->get_product();
				if ( $prod ) {
					$is_virtual = $prod->is_virtual() && ( 'yes' === $this->settings['wc_complete_virtual'] || $prod->is_downloadable() );
				}
			}
			$line_total = $wco->get_line_total( $item, true, true ); // w. taxes and rounded (how Woo does)
			if ( $line_total >= 0 ) {
				$sum             = wc_scanpay_addmoney( $sum, (string) $line_total );
				$data['items'][] = [
					'name'     => $item->get_name( 'edit' ),
					'quantity' => $item->get_quantity(),
					'total'    => $line_total . ' ' . $currency,
				];
			}
		}

		$wc_total = (string) $wco->get_total( 'edit' );
		if ( $sum !== $wc_total && wc_scanpay_cmpmoney( $sum, $wc_total ) !== 0 ) {
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
		$data['autocapture'] = 'yes' === $this->settings['capture_on_complete'] && ( $is_virtual || 'yes' === $this->settings['wcs_complete_renewal'] );

		try {
			// Final check before charge. We don't want to charge twice.
			$wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
			if ( 0 !== $wpdb->num_rows || $wco->get_transaction_id( 'edit' ) ) {
				throw new Exception( 'order is already paid' );
			}
			$res = $this->client->charge( $subid, $data, [ 'headers' => [ 'Idempotency-Key' => $this->idempotency_key( $oid, $subid ) ] ] );
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = 0, idem = '', retries = 5 WHERE subid = $subid" );
			$wco->set_payment_method( 'scanpay' );
			$wco->set_date_paid( time() );
			$wco->set_transaction_id( $res['id'] );
			$wco->add_meta_data( WC_SCANPAY_URI_AUTOCPT, (string) $data['autocapture'], true );
			$wco->set_status( $data['autocapture'] ? 'completed' : apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $oid, $wco ) );
			$wco->save();
			do_action( 'woocommerce_payment_complete', $oid, $res['id'] );
		} catch ( \Exception $e ) {
			/*
				WCS default is 5 retries: +12h, +12h, +24h, +48h, +72h. We will let WCS handle the retry logic,
				but as a safeguard we set a minimum requirement of 8 hours between automatic retries.
			*/
			$nxt = time() + 28800; // 8 hours
			$sub = $wpdb->get_row( "SELECT retries, nxt, idem FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
			$rt  = $sub['retries'] - 1;
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '', retries = $rt WHERE subid = $subid" );
			scanpay_log( 'error', "charge failed on #$oid: " . $e->getMessage() );
			$wco->update_status( 'failed', 'Charge failed: ' . $e->getMessage() );
		}
	}
}
