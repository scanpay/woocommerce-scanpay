<?php

class WCS_Scanpay_Sub {
	private static bool $in_sync = false;
	private array $settings;
	private object $client;
	private int $shopid;

	public function __construct() {
		if ( ! class_exists( 'WC_Scanpay_Client', false ) ) {
			require WC_SCANPAY_DIR . '/library/math.php';
			require WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
		}
		$this->settings = get_option( WC_SCANPAY_URI_SETTINGS );
		$this->client   = new WC_Scanpay_Client( $this->settings['apikey'] ?? '' );
		$this->shopid   = (int) strstr( $this->settings['apikey'] ?? '', ':', true );
	}

	/**
	 * Check if the plugin is synchronized with the Scanpay server.
	 * This is a defensive check to ensure that the plugin is ready to process charges.
	 */
	private function in_sync(): bool {
		if ( ! self::$in_sync ) {
			if ( 0 === $this->shopid ) {
				scanpay_log( 'error', 'Charge stopped: missing or invalid shop ID' );
				return false;
			}
			global $wpdb;
			$mtime = (int) $wpdb->get_var( "SELECT mtime FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $this->shopid );
			if ( ( time() - $mtime ) > 500 ) {
				scanpay_log( 'error', 'Plugin is not synchronized with Scanpay server.' );
				return false;
			}
			self::$in_sync = true;
		}
		return true;
	}


	/**
	 * Generate an idempotency key for the charge.
	 * This key is used to ensure that the same charge is not processed multiple times.
	 *
	 * @param int $oid    Order ID.
	 * @param int $subid  Subscriber ID.
	 * @return string     The idempotency key.
	 * @throws Exception  If the subscriber does not exist or has no retries left.
	 */
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


	/**
	 * Handle scheduled subscription payments (charges).
	 * This method is called by WooCommerce Subscriptions to process scheduled payments.
	 *
	 * @param float    $amount The amount to charge.
	 * @param WC_Order $wco    The WooCommerce order object.
	 */
	public function scheduled_charge( float $amount, object $wco ): void {
		// Defensive: Check $wco is valid and has required method(s)
		if ( ! is_object( $wco ) || ! method_exists( $wco, 'get_total' ) ) {
			scanpay_log( 'error', 'Scheduled charge failed: invalid order object' );
			return;
		}
		$oid = (int) $wco->get_id();

		// Get the subscriber ID from the order meta (accepts leading zeroes)
		$str   = trim( (string) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) );
		$subid = (int) $str;
		if ( empty( $subid ) || ! ctype_digit( $str ) ) {
			scanpay_log( 'error', 'Charge stopped: invalid subscriber ID for order #' . $oid );
			$wco->update_status( 'failed', 'invalid Scanpay subscriber ID' );
			return;
		}
		$this->charge( $oid, $wco, $subid );
	}

	/**
	 * Charge a WooCommerce order using Scanpay.
	 *
	 * @param int    $oid    Order ID.
	 * @param object $wco    WooCommerce order object.
	 * @param int    $subid  Scanpay subscriber ID.
	 */
	public function charge( int $oid, object $wco, int $subid ) {
		if ( ! $this->in_sync() ) {
			$wco->update_status( 'failed', 'Scanpay plugin is not synchronized with Scanpay server.' );
			scanpay_log( 'error', 'Charge stopped: plugin is not synchronized with Scanpay server.' );
			return;
		}
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
		 *  Calculate the sum of all items and check if the order needs processing. WC_Order->needs_payment() is not in
		 *  the cache here, and WCS does not use it, so we make our own is_virtual check.
		 */
		$sum        = '0';
		$currency   = $wco->get_currency( 'edit' );
		$is_virtual = 1;
		foreach ( $wco->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
			if ( $is_virtual && $item instanceof WC_Order_Item_Product ) {
				$prod = $item->get_product();
				if ( $prod ) {
					$is_virtual = $prod->is_virtual() && (
						'yes' === $this->settings['wc_complete_virtual'] ||
						$prod->is_downloadable()
					);
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
		set_transient( 'wc_order_' . $oid . '_needs_processing', ! $is_virtual, 1800 );

		$auto_completed      = $is_virtual || 'yes' === $this->settings['wcs_complete_renewal'];
		$data['autocapture'] = 'on' === $this->settings['wc_autocapture'] || ( 'completed' === $this->settings['wc_autocapture'] && $auto_completed );
		$wc_total            = (string) $wco->get_total( 'edit' );
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

		try {
			// Final check before charge. We don't want to charge twice.
			$wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
			if ( 0 !== $wpdb->num_rows || $wco->get_transaction_id( 'edit' ) ) {
				throw new Exception( 'order is already paid' );
			}
			$res = $this->client->charge( $subid, $data, $this->idempotency_key( $oid, $subid ) );
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
			 *  WCS default is 5 retries: +12h, +12h, +24h, +48h, +72h. We will let WCS handle the retry logic,
			 *  but as a safeguard we set a minimum requirement of 8 hours between automatic retries.
			 */
			$nxt = time() + 28800; // 8 hours
			$sub = $wpdb->get_row( "SELECT retries, nxt, idem FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
			$rt  = $sub['retries'] - 1;
			$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '', retries = $rt WHERE subid = $subid" );
			$str = trim( $e->getMessage() );
			scanpay_log( 'error', "charge failed on #$oid: $str" );
			$wco->update_status( 'failed', "Charge failed: $str" );
		}
	}
}
