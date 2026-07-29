<?php

/**
 * Renewal charges against a Scanpay subscriber's stored payment method. Constructed once
 * per Action Scheduler batch by wcs_scanpay_scheduled_charge(), which is the outer
 * boundary: nothing here rethrows, every failure is reported through
 * wcs_scanpay_fail_renewal(), and WCS owns retry scheduling.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

final class WCS_Scanpay_Charge {
	private array $settings;
	private WC_Scanpay_Client $client;
	private int $shopid;

	public function __construct() {
		// Independent requires: gating math.php on the client's class would leave the money
		// helpers undefined on a request that already loaded the client by itself, as
		// process_admin_options() does. require_once is its own guard.
		require_once WC_SCANPAY_DIR . '/library/math.php';
		require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
		$opts           = get_option( WC_SCANPAY_URI_SETTINGS );
		$this->settings = is_array( $opts ) ? $opts : [];
		$apikey         = (string) ( $this->settings['apikey'] ?? '' );
		// Derived here, reported in scheduled_charge(): this constructor has no order to
		// mark failed. A throw is per-renewal, not per-request -- wcs_scanpay_scheduled_charge()
		// assigns its memo after the constructor returns, so the next action constructs again.
		$this->shopid = (int) strstr( $apikey, ':', true );
		$this->client = new WC_Scanpay_Client( $apikey );
	}

	/**
	 * The stateless idempotency key, orderid_rev_day: the renewal, the payment-method
	 * revision (which bumps on a card refresh), and whole days since the renewal order was
	 * created. Scanpay binds a key for 24h on success and error alike, so at most one real
	 * attempt happens per (order, rev) per day-bucket, and a card update is the only way to
	 * charge again sooner.
	 *
	 * The day is anchored to the order's creation time, not the UTC calendar, so the bucket
	 * boundary -- where two concurrent attempts could get different keys and both charge --
	 * sits ~24h from where attempts actually happen: the first fires seconds after WCS
	 * creates the order, and retries at >=24h land in a strictly later bucket. intdiv
	 * truncates rather than floors, merging small negative clock skew into day 0.
	 *
	 * The rev comes from the local scanpay_subs row on purpose: it only advances when a sync
	 * ran, and that same sync writes the scanpay_meta already-paid guard, so the key can
	 * never outrun the guard and double-charge.
	 *
	 * @throws Exception If the subscriber row is missing or unreadable.
	 */
	private function idempotency_key( int $oid, int $subid, int $created ): string {
		global $wpdb;
		$rev = $wpdb->get_var( "SELECT rev FROM {$wpdb->prefix}scanpay_subs WHERE subid = $subid" );
		if ( $wpdb->last_error ) {
			// A query error also returns null; keep it distinct from a missing row.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new Exception( "subscriber (subid=$subid) lookup failed: {$wpdb->last_error}" );
		}
		if ( null === $rev ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
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
			wcs_scanpay_fail_renewal(
				$wco,
				"scheduled charge: invalid subscriber ID on #$oid",
				__( 'Invalid Scanpay subscriber ID.', 'scanpay-for-woocommerce' )
			);
			return;
		}
		if ( $wco->is_paid() || ! empty( $wco->get_transaction_id( 'edit' ) ) ) {
			scanpay_log( 'debug', "scheduled charge: order #$oid already paid; skipping (subid=$subid)" );
			return;
		}
		// The same absent-versus-malformed split as WC_Scanpay_Capture::init(), whose
		// messages are exceptions rather than order notes and so stay untranslated. Both
		// branches fail the renewal: one that cannot be charged must not read as paid.
		if ( '' === (string) ( $this->settings['apikey'] ?? '' ) ) {
			wcs_scanpay_fail_renewal(
				$wco,
				"scheduled charge: no API key configured; cannot charge #$oid (subid=$subid)",
				__( 'No Scanpay API key is configured.', 'scanpay-for-woocommerce' )
			);
			return;
		}
		if ( $this->shopid <= 0 ) {
			// Caught locally so the merchant reads a cause, not an opaque 401 from the API.
			wcs_scanpay_fail_renewal(
				$wco,
				"scheduled charge: invalid API key configured; cannot charge #$oid (subid=$subid)",
				__( 'Invalid Scanpay API key configured.', 'scanpay-for-woocommerce' )
			);
			return;
		}
		/*
		 * Shop ownership. Subscriber ids are namespaced per shop, so after a merchant
		 * switches keys a subid that merely collides numerically resolves to a different
		 * customer's stored card -- the API path carries no shop id, the authenticated key
		 * does. Do not simplify this away on the assumption that subscriber ids are globally
		 * unique. A shopid column on scanpay_subs would defend against nothing: the new
		 * shop's sync upserts that same row with its own shop id, and only the
		 * subscription's own meta records what it was created under.
		 *
		 * Absent or zero proceeds, unlike WC_Scanpay_Capture, which throws on it: a
		 * 1.x-migrated store can legitimately have no stamp. WC_Scanpay_Sync::subscriber()
		 * writes it only for subscriptions resolved from a 'wcs[]' ref, which 1.x never
		 * wrote. Failing those renewals would stop them with no merchant-visible cause.
		 */
		if ( $order_shopid <= 0 ) {
			scanpay_log( 'warning', "scheduled charge: no shop id on #$oid; charging under shop {$this->shopid} (subid=$subid)" );
		} elseif ( $order_shopid !== $this->shopid ) {
			wcs_scanpay_fail_renewal(
				$wco,
				"scheduled charge: shop mismatch on #$oid: order has $order_shopid, API key has {$this->shopid} (subid=$subid)",
				sprintf(
					/* translators: 1: Scanpay shop ID the subscription was created under, 2: the shop ID of the configured API key. */
					__( 'This subscription belongs to Scanpay shop %1$d, not %2$d.', 'scanpay-for-woocommerce' ),
					$order_shopid,
					$this->shopid
				)
			);
			return;
		}
		/*
		 * WCS imposes the float signature; every decision below is made on the money string.
		 * A scheduler amount of zero against a positive order total is a disagreement, not a
		 * free renewal, and the hook is reachable off-schedule -- from third-party code or a
		 * "Process renewal" admin action -- so both are normalized and compared before
		 * anything decides the renewal is free.
		 */
		$amt_str = wc_format_decimal( $amount, wc_get_price_decimals() );
		$tot_str = (string) $wco->get_total( 'edit' );
		// Pre-guard for money_equals(), which throws on non-money input: the hook contains
		// that throw, but it would arrive as an opaque "unhandled error".
		if ( ! wc_scanpay_is_money( $amt_str ) || ! wc_scanpay_is_money( $tot_str ) ) {
			wcs_scanpay_fail_renewal(
				$wco,
				"scheduled charge: invalid amount on #$oid: WCS=$amt_str, order_total=$tot_str (subid=$subid)",
				sprintf(
					/* translators: 1: amount supplied by WooCommerce Subscriptions, 2: the order total. */
					__( 'Invalid renewal amount (%1$s) or order total (%2$s).', 'scanpay-for-woocommerce' ),
					$amt_str,
					$tot_str
				)
			);
			return;
		}
		// charge() builds the payload from the order total, so a disagreeing scheduler amount
		// would charge something other than what WCS asked for.
		if ( ! wc_scanpay_money_equals( $amt_str, $tot_str ) ) {
			wcs_scanpay_fail_renewal(
				$wco,
				"scheduled charge: amount mismatch on #$oid: WCS=$amt_str, order_total=$tot_str (subid=$subid)",
				sprintf(
					/* translators: 1: amount supplied by WooCommerce Subscriptions, 2: the order total. */
					__( 'The renewal amount (%1$s) does not match the order total (%2$s).', 'scanpay-for-woocommerce' ),
					$amt_str,
					$tot_str
				)
			);
			return;
		}
		/*
		 * WCS normally auto-completes zero-amount renewals without reaching this callback;
		 * this covers a 100% discount or a proration credit. Non-positive rather than
		 * strictly zero: a credit has nothing to charge either, and wc_scanpay_is_zero()
		 * would send '-50.00' to the API.
		 */
		if ( wc_scanpay_cmpmoney( $amt_str, '0' ) <= 0 ) {
			scanpay_log( 'debug', "scheduled charge: zero-amount renewal on #$oid; skipping charge (subid=$subid)" );
			// WCS must see a failed renewal rather than a silently unpaid order it settled.
			if ( ! $wco->payment_complete() ) {
				wcs_scanpay_fail_renewal(
					$wco,
					"scheduled charge: could not complete zero-amount renewal #$oid (subid=$subid)",
					__( 'The zero-amount renewal could not be completed.', 'scanpay-for-woocommerce' )
				);
			}
			return;
		}
		$this->charge( $wco, $subid );
	}

	/**
	 * Charge an order against the Scanpay subscriber's stored payment method.
	 *
	 * The whole body sits inside the catch, not just the request: the payload build and the
	 * per-line summation call money helpers that throw, and the per-line values are the one
	 * input scheduled_charge() cannot pre-validate -- get_line_total() passes through the
	 * woocommerce_order_amount_line_total filter, where a third party can return null.
	 */
	private function charge( WC_Order $wco, int $subid ): void {
		try {
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

			// $sum is checked against the order total below; $is_virtual feeds the autocapture
			// decision. WooCommerce has no "all items are virtual" query.
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
			// Settling at Scanpay, not completing in WooCommerce. $is_virtual folds in
			// wc_complete_virtual, which is why the completion decision below is
			// wcs_scanpay_wants_completion() rather than a copy of this expression.
			$auto_completed      = $is_virtual || 'yes' === ( $this->settings['wcs_complete_renewal'] ?? 'no' );
			$autocapture         = $this->settings['wc_autocapture'] ?? 'completed';
			$data['autocapture'] = 'on' === $autocapture || ( 'completed' === $autocapture && $auto_completed );
			$wc_total            = (string) $wco->get_total( 'edit' );
			if ( $sum !== $wc_total && ! wc_scanpay_money_equals( $sum, $wc_total ) ) {
				$data['items'] = [
					[
						'name'  => 'Total',
						'total' => $wc_total . ' ' . $currency,
					],
				];
				scanpay_log(
					'warning',
					"Order #$oid: The sum of all items ($sum) does not match the order total ($wc_total). " .
					'The item list will not be available in the scanpay dashboard.'
				);
			}

			// Authoritative double-charge guard:
			global $wpdb;
			$found = $wpdb->query( "SELECT orderid FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
			if ( false === $found ) {
				// A SELECT returns its row count, or false on error -- never read that as "no
				// payment row". The catch below fails the renewal so WCS reschedules, and the
				// idempotency key dedupes the retry.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
				throw new \RuntimeException( "scanpay_meta lookup failed: {$wpdb->last_error}" );
			}
			if ( $found > 0 || $wco->get_transaction_id( 'edit' ) ) {
				scanpay_log( 'warning', "charge skipped on #$oid: order is already paid (subid=$subid)" );
				return;
			}
			// get_date_created() is nullable, and the key is anchored to it, so there is no
			// safe fallback: another anchor shifts the day bucket and could let a retry
			// through as a second real charge.
			$created = $wco->get_date_created( 'edit' );
			if ( ! $created instanceof WC_DateTime ) {
				throw new \RuntimeException( "order #$oid has no creation date" );
			}
			$idem = $this->idempotency_key( $oid, $subid, $created->getTimestamp() );
			// Persisted before the charge, and after the already-paid guard so it is never
			// written for an order this call declines to charge. If the process dies here,
			// sync still knows what the attempt asked for. A write that throws fails the
			// renewal, which is right: an intent we could not record is one sync would not
			// honour.
			$wco->add_meta_data(
				WC_SCANPAY_URI_COMPLETE,
				$data['autocapture'] && wcs_scanpay_wants_completion( $this->settings, 'renewal' ),
				true
			);
			/*
			 * Which shop the attempt ran under -- the other half of that record. Absent is
			 * legitimate for a 1.x-migrated order, which scheduled_charge() lets through, but
			 * both readers treat absent as *another* shop's order: WC_Scanpay_Sync::sync()
			 * drops the drained charge as a shopid mismatch and WC_Scanpay_Capture::capture()
			 * throws, with the money already moved, while every WCS retry short-circuits on
			 * the already-paid guard above without writing a status.
			 *
			 * Only when absent. A stamp naming a different shop is the mismatch
			 * scheduled_charge() refuses, and it must stay refused.
			 */
			if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) <= 0 ) {
				$wco->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
			}
			$wco->save_meta_data();
			$res = $this->client->charge( $subid, $data, $idem );
			// On a shop whose pings are blocked this is the only store-side record that the
			// customer was charged. No isset() around $res['id']: WC_Scanpay_Client::charge()
			// throws unless the response carries type 'charge' and an int id.
			scanpay_log( 'info', "charged order #$oid: charge {$res['id']} (subid=$subid)" );
		} catch ( \Throwable $e ) {
			// \Throwable, not \Exception: an Error here is as fatal to the renewal. Reported,
			// never rethrown, so the hook's outer catch cannot report it a second time.
			wcs_scanpay_fail_renewal(
				$wco,
				'charge failed on #' . $wco->get_id() . ': ' . trim( $e->getMessage() ),
				// The raw message can be a database or transport error, so the merchant gets a
				// fixed sentence and the detail goes to the log.
				__( 'The Scanpay charge failed. See the WooCommerce logs for details.', 'scanpay-for-woocommerce' )
			);
		}
	}
}
