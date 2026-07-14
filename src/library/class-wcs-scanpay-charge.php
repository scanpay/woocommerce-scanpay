<?php
declare(strict_types=1);

final class WCS_Scanpay_Charge {
	private array $settings;
	private WC_Scanpay_Client $client;

	public function __construct() {
		if ( ! class_exists( 'WC_Scanpay_Client', false ) ) {
			require_once WC_SCANPAY_DIR . '/library/math.php';
			require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
		}
		$opts           = get_option( WC_SCANPAY_URI_SETTINGS );
		$this->settings = is_array( $opts ) ? $opts : [];
		$this->client   = new WC_Scanpay_Client( $this->settings['apikey'] ?? '' );
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
		$now = time();
		$sub = $wpdb->get_row( "SELECT retries, nxt, idem FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
		if ( ! $sub ) {
			throw new Exception( "subscriber (subid=$subid) does not exist" );
		}
		$sub['retries'] = (int) $sub['retries']; // wpdb returns all columns as strings
		if ( $sub['retries'] <= 0 ) {
			throw new Exception( "no retries left on subscriber (subid=$subid)" );
		}
		// Idempotency keys last for 24 hours

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
		$nxt = $now + 1800; // lock sub for 900s (30m) to limit races
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '" . $sub['idem'] . "' WHERE subid = $subid" );
		return $sub['idem'];
	}


	/**
	 * Handle automatic subscription payments.
	 *
	 * Triggered by WooCommerce Subscriptions for:
	 *  - Scheduled automatic renewals via Action Scheduler
	 *  - Manual “Process renewal” actions in the admin
	 *
	 * @param float    $amount Amount to charge for this renewal cycle.
	 * @param WC_Order $wco    The renewal order object.
	 */
	public function scheduled_charge( float $amount, WC_Order $wco ): void {
		$oid   = $wco->get_id();
		$subid = (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		if ( $subid <= 0 ) {
			scanpay_log( 'error', "scheduled charge: invalid subscriber ID on #$oid" );
			$wco->update_status( 'failed', 'invalid Scanpay subscriber ID' );
			return;
		}
		if ( $wco->is_paid() || ! empty( $wco->get_transaction_id( 'edit' ) ) ) {
			scanpay_log( 'debug', "scheduled charge: order #$oid already paid; skipping (subid=$subid)" );
			return;
		}
		// Zero-amount renewals are normally auto-completed by WCS and won't reach this callback.
		// This guard is only here for rare edge cases (e.g., 100% discount or proration credit).
		if ( $amount <= 0.0 ) {
			scanpay_log( 'debug', "scheduled charge: zero-amount renewal on #$oid; skipping charge (subid=$subid)" );
			$wco->payment_complete();
			return;
		}
		// Sanity-check amount vs order total to catch scheduler mismatches.
		$amt_str = wc_format_decimal( $amount, wc_get_price_decimals() );
		$tot_str = (string) $wco->get_total( 'edit' );
		if ( wc_scanpay_cmpmoney( $amt_str, $tot_str ) !== 0 ) {
			scanpay_log( 'warning', "scheduled charge: amount mismatch on #$oid: WCS=$amt_str, order_total=$tot_str (subid=$subid)" );
		}
		$this->charge( $wco, $subid );
	}

	/**
	 * Charge a WooCommerce order using Scanpay.
	 *
	 * @param object $wco    WooCommerce order object.
	 * @param int    $subid  Scanpay subscriber ID.
	 */
	public function charge( object $wco, int $subid ) {
		$oid  = $wco->get_id();
		$data = [
			'orderid'  => (string) $oid,
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

		global $wpdb;
		try {
			// Final check before charge. We don't want to charge twice.
			$wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
			if ( 0 !== $wpdb->num_rows || $wco->get_transaction_id( 'edit' ) ) {
				throw new Exception( 'order is already paid' );
			}
			$idem = $this->idempotency_key( $oid, $subid );
			// $wco->set_payment_method( 'scanpay' );
			// $wco->add_meta_data( WC_SCANPAY_URI_AUTOCPT, (string) $data['autocapture'], true );
			// $wco->save();

			$this->client->charge( $subid, $data, $idem );
		} catch ( \Exception $e ) {
			/*
			 *  WCS default is 5 retries: +12h, +12h, +24h, +48h, +72h. We will let WCS handle the retry logic,
			 *  but as a safeguard we set a minimum requirement of 8 hours between automatic retries.
			 */
			$nxt = time() + 28800; // 8 hours
			$sub = $wpdb->get_row( "SELECT retries FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid", ARRAY_A );
			if ( $sub ) {
				$rt = max( (int) $sub['retries'] - 1, 0 );
				$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_subs SET nxt = $nxt, idem = '', retries = $rt WHERE subid = $subid" );
			}
			$str = trim( $e->getMessage() );
			scanpay_log( 'error', "charge failed on #$oid: $str" );
			$wco->update_status( 'failed', "Charge failed: $str" );
		}
	}
}
