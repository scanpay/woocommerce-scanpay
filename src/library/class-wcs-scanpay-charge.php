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
	 * Build the stateless Scanpay idempotency key: orderid_rev_day.
	 * orderid = the renewal, rev = payment-method revision (bumps on card refresh),
	 * day = whole days since the renewal order was created. Scanpay binds keys for
	 * 24h on both success and error, so repeats within a day dedupe: by design at
	 * most one real charge attempt per (order, rev) per 24h day-bucket — a card
	 * update (rev bump) is the only way to charge again sooner.
	 *
	 * The day is anchored to the order's creation time rather than the UTC calendar
	 * so the bucket boundary — where two concurrent attempts could get different
	 * keys and both charge — sits ~24h away from where attempts actually happen:
	 * the first attempt fires seconds after WCS creates the renewal order, and
	 * >=24h retries provably land in a strictly later bucket, just past its start.
	 * intdiv truncation (not floor) merges small negative DB-vs-PHP clock skew
	 * into day 0 instead of creating a day boundary at the creation time itself.
	 *
	 * The rev is read from the local (sync-written) scanpay_subs row on purpose: it
	 * only advances when a sync ran, which also writes the scanpay_meta already-paid
	 * guard, so the key can never outrun that guard and double-charge.
	 *
	 * @throws Exception If the subscriber row is missing (rev unknown).
	 */
	private function idempotency_key( int $oid, int $subid, int $created ): string {
		global $wpdb;
		$rev = $wpdb->get_var( "SELECT rev FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid" );
		if ( null === $rev ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Exception( "subscriber (subid=$subid) does not exist" );
		}
		return $oid . '_' . (int) $rev . '_' . intdiv( time() - $created, DAY_IN_SECONDS );
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
	 * @return void
	 */
	public function charge( object $wco, int $subid ): void {
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
				$line_str        = wc_format_decimal( $line_total, wc_get_price_decimals() );
				$sum             = wc_scanpay_addmoney( $sum, $line_str );
				$data['items'][] = [
					'name'     => $item->get_name( 'edit' ),
					'quantity' => $item->get_quantity(),
					'total'    => $line_str . ' ' . $currency,
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

		// Authoritative double-charge guard:
		global $wpdb;
		$wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		if ( 0 !== $wpdb->num_rows || $wco->get_transaction_id( 'edit' ) ) {
			scanpay_log( 'warning', "charge skipped on #$oid: order is already paid (subid=$subid)" );
			return;
		}
		try {
			$idem = $this->idempotency_key( $oid, $subid, $wco->get_date_created( 'edit' )->getTimestamp() );
			$this->client->charge( $subid, $data, $idem );
		} catch ( \Exception $e ) {
			// WCS owns retry scheduling; we keep no local retry/lock state.
			$str = trim( $e->getMessage() );
			scanpay_log( 'error', "charge failed on #$oid: $str" );
			$wco->update_status( 'failed', "Charge failed: $str" );
		}
	}
}
