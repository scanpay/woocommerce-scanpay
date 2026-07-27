<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

final class WCS_Scanpay_Charge {
	private array $settings;
	private WC_Scanpay_Client $client;
	private int $shopid;

	public function __construct() {
		// math.php and the client are independent requires: gating math.php on the client's
		// class would leave the money helpers undefined on any request that already loaded
		// the client by itself (process-admin-options.php does). require_once is its own guard.
		require_once WC_SCANPAY_DIR . '/library/math.php';
		require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
		$opts           = get_option( WC_SCANPAY_URI_SETTINGS );
		$this->settings = is_array( $opts ) ? $opts : [];
		$apikey         = (string) ( $this->settings['apikey'] ?? '' );
		// Derived here, reported in scheduled_charge(): this constructor has no order to
		// mark failed, and the hook memoizes the handler for the whole request, so a throw
		// here would be per-request rather than per-renewal.
		$this->shopid = (int) strstr( $apikey, ':', true );
		$this->client = new WC_Scanpay_Client( $apikey );
	}

	/**
	 * Build the stateless Scanpay idempotency key: orderid_rev_day.
	 * orderid = the renewal, rev = payment-method revision (bumps on card refresh),
	 * day = whole days since the renewal order was created. Scanpay binds keys for
	 * 24h on both success and error, so repeats within a day dedupe: by design at
	 * most one real charge attempt per (order, rev) per 24h day-bucket -- a card
	 * update (rev bump) is the only way to charge again sooner.
	 *
	 * The day is anchored to the order's creation time rather than the UTC calendar so
	 * the bucket boundary -- where two concurrent attempts could get different keys and
	 * both charge -- sits ~24h away from where attempts actually happen: the first fires
	 * seconds after WCS creates the renewal order, and >=24h retries provably land in a
	 * strictly later bucket, just past its start. intdiv truncation (not floor) merges
	 * small negative DB-vs-PHP clock skew into day 0 instead of creating a boundary at
	 * the creation time itself.
	 *
	 * The rev is read from the local (sync-written) scanpay_subs row on purpose: it
	 * only advances when a sync ran, which also writes the scanpay_meta already-paid
	 * guard, so the key can never outrun that guard and double-charge.
	 *
	 * @throws Exception If the subscriber row is missing or unreadable (rev unknown).
	 */
	private function idempotency_key( int $oid, int $subid, int $created ): string {
		global $wpdb;
		$rev = $wpdb->get_var( "SELECT rev FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid" );
		if ( $wpdb->last_error ) {
			// A query error also returns null; keep it distinct from a missing row.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Exception( "subscriber (subid=$subid) lookup failed: {$wpdb->last_error}" );
		}
		if ( null === $rev ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Exception( "subscriber (subid=$subid) does not exist" );
		}
		return $oid . '_' . (int) $rev . '_' . intdiv( time() - $created, DAY_IN_SECONDS );
	}


	/**
	 * Handle an automatic subscription payment: a renewal scheduled through Action
	 * Scheduler, or a "Process renewal" action in the admin. $wco is the renewal order.
	 */
	public function scheduled_charge( float $amount, WC_Order $wco ): void {
		$oid          = $wco->get_id();
		$subid        = (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		$order_shopid = (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
		if ( $subid <= 0 ) {
			scanpay_log( 'error', "scheduled charge: invalid subscriber ID on #$oid" );
			$wco->update_status( 'failed', 'invalid Scanpay subscriber ID' );
			return;
		}
		if ( $wco->is_paid() || ! empty( $wco->get_transaction_id( 'edit' ) ) ) {
			scanpay_log( 'debug', "scheduled charge: order #$oid already paid; skipping (subid=$subid)" );
			return;
		}
		if ( $this->shopid <= 0 ) {
			// Caught locally so the merchant reads a cause, not an opaque 401 from the API.
			scanpay_log( 'error', "scheduled charge: invalid API key configured; cannot charge #$oid (subid=$subid)" );
			$wco->update_status( 'failed', 'invalid Scanpay API key configured' );
			return;
		}
		/*
		 * Shop ownership. Scanpay subscriber IDs are namespaced per shop, so after a
		 * merchant switches keys a subid that merely collides numerically resolves to a
		 * different customer's stored card -- the API path carries no shop id, the
		 * authenticated key does. Do not simplify this away on the assumption that
		 * subscriber IDs are globally unique.
		 *
		 * A shopid column on scanpay_subs would look cheaper (idempotency_key() already
		 * reads that row) and defends against nothing: the new shop's sync upserts the
		 * same subid row with its own shop id. Only the subscription's own meta records
		 * what it was created under.
		 *
		 * Absent or zero proceeds, deliberately, unlike WC_Scanpay_Capture, which throws
		 * on it: a 1.x-migrated store can legitimately have no stamp. sync's subscriber()
		 * writes WC_SCANPAY_URI_SHOPID only for subscriptions resolved from a 'wcs[]' ref
		 * (class-wc-scanpay-sync.php:359-367), which 1.x never wrote -- it used a bare
		 * order id -- while the scanpay_subs upsert above it (:346-351) is independent of
		 * that parsing, so the idempotency key resolves with the stamp missing. The 2.1.3
		 * migration (upgrade.php:57-88) backfills only the subid. Failing those renewals
		 * would stop them with no merchant-visible cause.
		 */
		if ( $order_shopid <= 0 ) {
			scanpay_log( 'warning', "scheduled charge: no shop id on #$oid; charging under shop {$this->shopid} (subid=$subid)" );
		} elseif ( $order_shopid !== $this->shopid ) {
			scanpay_log( 'error', "scheduled charge: shop mismatch on #$oid: order has $order_shopid, API key has {$this->shopid} (subid=$subid)" );
			$wco->update_status( 'failed', "subscription belongs to Scanpay shop $order_shopid, not {$this->shopid}" );
			return;
		}
		/*
		 * Both amounts are normalized and compared before anything here decides the
		 * renewal is free. WCS imposes the float signature; every decision below is made
		 * on the money string. A scheduler amount of zero against a positive order total
		 * is a disagreement, not a free renewal, and must not complete the order without
		 * charging it -- the hook is reachable off-schedule, from third-party code or a
		 * "Process renewal" admin action.
		 */
		$amt_str = wc_format_decimal( $amount, wc_get_price_decimals() );
		$tot_str = (string) $wco->get_total( 'edit' );
		// Pre-guard before cmpmoney(), which throws InvalidArgumentException on
		// non-money input. This runs outside charge()'s try, so a corrupt local total
		// would otherwise escape to Action Scheduler and leave the renewal neither
		// charged nor marked failed. WC_Scanpay_Sync pre-guards for the same reason.
		if ( ! wc_scanpay_is_money( $amt_str ) || ! wc_scanpay_is_money( $tot_str ) ) {
			scanpay_log( 'error', "scheduled charge: invalid amount on #$oid: WCS=$amt_str, order_total=$tot_str (subid=$subid)" );
			$wco->update_status( 'failed', "invalid amount ($amt_str) or order total ($tot_str)" );
			return;
		}
		// charge() builds the payload from the order total, so a scheduler amount that
		// disagrees with it means we'd charge something other than what WCS asked for.
		// Fail loud instead of silently charging the order total; WCS owns retry scheduling.
		if ( wc_scanpay_cmpmoney( $amt_str, $tot_str ) !== 0 ) {
			scanpay_log( 'error', "scheduled charge: amount mismatch on #$oid: WCS=$amt_str, order_total=$tot_str (subid=$subid)" );
			$wco->update_status( 'failed', "WCS amount ($amt_str) does not match order total ($tot_str)" );
			return;
		}
		/*
		 * WCS normally auto-completes zero-amount renewals without reaching this callback;
		 * this covers the edge cases (a 100% discount, a proration credit). The test is
		 * non-positive, not strictly zero: a credit whose total matches has nothing to
		 * charge either, and wc_scanpay_is_zero() -- true for '-0.00', false for '-50.00'
		 * -- would send that one to the API instead.
		 */
		if ( wc_scanpay_cmpmoney( $amt_str, '0' ) <= 0 ) {
			scanpay_log( 'debug', "scheduled charge: zero-amount renewal on #$oid; skipping charge (subid=$subid)" );
			// Surface a completion WooCommerce could not persist: WCS must see a failed
			// renewal rather than a silently unpaid order it believes it settled.
			if ( ! $wco->payment_complete() ) {
				scanpay_log( 'error', "scheduled charge: could not complete zero-amount renewal #$oid (subid=$subid)" );
				$wco->update_status( 'failed', "could not complete the zero-amount renewal ($amt_str)" );
			}
			return;
		}
		$this->charge( $wco, $subid );
	}

	/** Charge an order against the Scanpay subscriber's stored payment method. */
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

		// $sum is checked against the order total below; $is_virtual feeds the
		// auto-complete/autocapture decision. WC has no "all items are virtual" query.
		$sum        = '0';
		$currency   = $wco->get_currency( 'edit' );
		$is_virtual = 1;
		foreach ( $wco->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $id => $item ) {
			if ( $is_virtual && $item instanceof WC_Order_Item_Product ) {
				$prod = $item->get_product();
				if ( $prod ) {
					$is_virtual = $prod->is_virtual() && (
						'yes' === ( $this->settings['wc_complete_virtual'] ?? 'no' ) ||
						$prod->is_downloadable()
					);
				}
			}
			$line_total = $wco->get_line_total( $item, true, true ); // Incl. tax and rounded, as WC totals it.
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
		$auto_completed      = $is_virtual || 'yes' === ( $this->settings['wcs_complete_renewal'] ?? 'no' );
		$autocapture         = $this->settings['wc_autocapture'] ?? 'completed';
		$data['autocapture'] = 'on' === $autocapture || ( 'completed' === $autocapture && $auto_completed );
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
		try {
			$found = $wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
			if ( false === $found ) {
				// A SELECT returns its row count, or false on error -- never read that as "no
				// payment row". The catch below marks the renewal failed so WCS reschedules,
				// and the idempotency key dedupes the retry.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \RuntimeException( "scanpay_meta lookup failed: {$wpdb->last_error}" );
			}
			if ( $found > 0 || $wco->get_transaction_id( 'edit' ) ) {
				scanpay_log( 'warning', "charge skipped on #$oid: order is already paid (subid=$subid)" );
				return;
			}
			// get_date_created() is nullable; the idempotency key is anchored to it, so
			// there is no safe fallback -- an arbitrary anchor would shift the day
			// bucket and could let a retry through as a second real charge.
			$created = $wco->get_date_created( 'edit' );
			if ( ! $created instanceof WC_DateTime ) {
				throw new \RuntimeException( "order #$oid has no creation date" );
			}
			$idem = $this->idempotency_key( $oid, $subid, $created->getTimestamp() );
			$this->client->charge( $subid, $data, $idem );
		} catch ( \Throwable $e ) {
			// \Throwable, not \Exception: an Error/TypeError here would otherwise escape
			// to Action Scheduler and leave the renewal neither charged nor marked
			// failed. WC_Scanpay_Capture::capture_or_hold() catches the same way.
			// WCS owns retry scheduling; we keep no local retry/lock state.
			$str = trim( $e->getMessage() );
			scanpay_log( 'error', "charge failed on #$oid: $str" );
			$wco->update_status( 'failed', "Charge failed: $str" );
		}
	}
}
