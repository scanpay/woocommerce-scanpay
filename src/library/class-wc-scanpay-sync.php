<?php

declare(strict_types=1);

require_once WC_SCANPAY_DIR . '/library/math.php';

/**
 * Synchronizes Scanpay payments with WooCommerce orders and subscriptions.
 */
final class WC_Scanpay_Sync {
	public array $settings;
	private int $shopid;
	private bool $wcs_enabled;

	/**
	 * Order statuses considered incomplete by WooCommerce.
	 * Used to decide when payment_complete() should update and finalize an order.
	 */
	private const PAYMENT_COMPLETE_STATUSES = [ 'on-hold', 'pending', 'failed', 'cancelled' ];

	/**
	 * Map Scanpay brand and wallet codes to labels.
	 */
	private const CARD_BRANDS = [
		'amex'             => 'American Express',
		'dankort'          => 'Dankort',
		'diners'           => 'Diners Club',
		'forbrugsforening' => 'Forbrugsforeningen',
		'jcb'              => 'JCB',
		'maestro'          => 'Maestro',
		'mastercard'       => 'Mastercard',
		'unionpay'         => 'UnionPay',
		'visa'             => 'Visa',
		'visadankort'      => 'Visa/Dankort',
	];

	/**
	 * Map Scanpay card wallet codes to human-readable names.
	 */
	private const CARD_WALLETS = [
		'applepay'   => 'Apple Pay',
		'googlepay'  => 'Google Pay',
		'mobilepay'  => 'MobilePay',
		'samsungpay' => 'Samsung Pay',
	];


	/**
	 * Sets up the sync service with the gateway settings and shop context.
	 *
	 * @param array<string, mixed> $settings Gateway settings (the woocommerce_scanpay_settings option).
	 * @param int                  $shopid   Scanpay shop ID for this store.
	 */
	public function __construct( array $settings, int $shopid ) {
		$this->settings    = $settings;
		$this->shopid      = $shopid;
		$this->wcs_enabled = class_exists( 'WC_Subscriptions', false );

		if ( 'yes' === ( $this->settings['wc_complete_virtual'] ?? 'no' ) ) {
			add_filter( 'woocommerce_order_item_needs_processing', [ $this, 'item_needs_processing' ], 10, 2 );
		}
	}

	/**
	 * Returns true if the order status is eligible for WooCommerce payment_complete().
	 */
	private function is_payment_complete_eligible( \WC_Order $order ): bool {
		/**
		 * Filters the order statuses eligible for WooCommerce payment_complete().
		 *
		 * Re-applies WooCommerce core's own filter so our eligibility check stays
		 * in sync with WC_Order::payment_complete().
		 *
		 * @since 3.0.0
		 *
		 * @param string[]  $statuses Order statuses eligible for payment completion.
		 * @param \WC_Order $order    The order being evaluated.
		 */
		$valid = apply_filters(
			'woocommerce_valid_order_statuses_for_payment_complete',
			self::PAYMENT_COMPLETE_STATUSES,
			$order
		);
		return $order->has_status( $valid );
	}

	/*
	 *  WC auto-completes downloadable orders, but not virtual orders. This filter
	 *  will set virtual products to not need processing, so they are auto-completed.
	 */
	public function item_needs_processing( bool $needs_processing, \WC_Product $product ): bool {
		if ( $needs_processing && true === $product->get_virtual( 'edit' ) && ! $product->get_downloadable( 'edit' ) ) {
			return false; // Product is virtual, but not downloadable.
		}
		return $needs_processing;
	}

	/**
	 * Parse Scanpay payment method data into a human-readable string.
	 * Accepts mixed and falls back to 'Scanpay' on missing/malformed data.
	 */
	private function parse_payment_method( mixed $m ): string {
		if ( ! is_array( $m ) ) {
			return 'Scanpay';
		}
		$type = $m['type'] ?? '';
		if ( ! is_string( $type ) || '' === $type ) {
			return 'Scanpay';
		}
		if ( isset( $m['card'], $m['card']['brand'] ) && is_string( $m['card']['brand'] ) ) {
			$brand  = $m['card']['brand'];
			$brand  = self::CARD_BRANDS[ $brand ] ?? ucfirst( $brand );
			$last4  = $m['card']['last4'] ?? '';
			$card   = is_string( $last4 ) && '' !== $last4 ? "$brand $last4" : $brand;
			$wallet = self::CARD_WALLETS[ $type ] ?? null;
			return $wallet ? "$wallet ($card)" : $card;
		}
		return ucfirst( $type );
	}

	/**
	 * Extract subscription IDs from Scanpay subscriber reference string.
	 *
	 * @return string[] Subscription IDs, or an empty array if $ref is not a wcs[] reference.
	 */
	private function find_subs_from_ref( string $ref ): array {
		return str_starts_with( $ref, 'wcs[]' )
			? explode( ',', substr( $ref, 5 ) )
			: [];
	}

	/**
	 * Validate and convert an order ID to integer.
	 * Accepts only non-empty digit strings (0–9). Returns 0 if invalid.
	 */
	private function ordernumber( mixed $s ): int {
		if ( ! is_string( $s ) || '' === $s || ! ctype_digit( $s ) ) {
			return 0;
		}
		return (int) $s;
	}

	/**
	 * Extract numeric amount from a currency string like "199.99 DKK".
	 * A missing total intentionally throws (TypeError via the string param):
	 * a malformed payload is a backend error that must halt the sync loudly,
	 * never be skipped or defaulted.
	 *
	 * @throws \RuntimeException if invalid format
	 */
	private function extract_amount( string $s ): string {
		$n = strlen( $s );
		if ( $n < 5 || ' ' !== $s[ $n - 4 ] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "missing space before currency: $s" );
		}
		if ( ! ctype_upper( substr( $s, -3 ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "invalid currency code: $s" );
		}
		$amount = substr( $s, 0, $n - 4 );
		if ( ! wc_scanpay_is_money( $amount ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "invalid currency amount: $s" );
		}
		return $amount;
	}

	/**
	 * Guarded upsert of the scanpay_meta row for an order.
	 *
	 * The scanpay_meta table is keyed by orderid alone, so a second transaction on
	 * the same order (e.g. two payment links, or a numeric merchant-reference
	 * collision) would otherwise splice its rev/nacts/totals onto the first
	 * transaction's id and authorized amount via ON DUPLICATE KEY UPDATE.
	 *
	 * @param string $label Log/exception prefix, e.g. "transaction #123".
	 * @param int    $oid   WooCommerce order ID.
	 * @param int    $trnid Scanpay transaction ID claiming the order.
	 * @param string $sql   Prebuilt INSERT ... ON DUPLICATE KEY UPDATE statement.
	 * @return bool True if the row is now owned by $trnid; false if another
	 *              transaction already owns the order (caller must not proceed).
	 * @throws \RuntimeException On a database read or write error.
	 */
	private function upsert_meta( string $label, int $oid, int $trnid, string $sql ): bool {
		global $wpdb;
		$owner = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		if ( $wpdb->last_error ) {
			// A query error also returns null; keep it distinct from a missing row.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "$label: could not read payment data for order #$oid: {$wpdb->last_error}" );
		}
		if ( null !== $owner && (int) $owner !== $trnid ) {
			// A different transaction already owns this order. Return (not throw):
			// throwing would replay this change forever and wedge the sync loop.
			scanpay_log( 'error', "$label: order #$oid already paid by transaction #" . (int) $owner . '; ignoring' );
			return false;
		}
		if ( false === $wpdb->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "$label: could not save payment data to order #$oid: {$wpdb->last_error}" );
		}
		return true;
	}

	/**
	 * Syncs a Scanpay transaction with its WC order. Inserts metadata, verifies
	 * data/ownership, then registers payment completion if applicable.
	 *
	 * @param array $c Transaction payload from Scanpay.
	 * @throws \RuntimeException On validation, database, or payment errors.
	 */
	public function transaction( array $c ): void {
		$oid = $this->ordernumber( $c['orderid'] ?? '' );
		if ( $oid <= 0 ) {
			return; // skip: invalid orderID
		}
		$trnid = $c['id'] ?? null;
		if ( ! is_int( $trnid ) || $trnid <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "transaction: invalid transaction ID for order #$oid (id=$trnid)" );
		}
		$rev = $c['rev'] ?? null;
		if ( ! is_int( $rev ) || $rev <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "transaction #$trnid: invalid revision number (rev=$rev)" );
		}
		$nacts  = count( $c['acts'] );
		$auth   = $this->extract_amount( $c['totals']['authorized'] );
		$capt   = $this->extract_amount( $c['totals']['captured'] );
		$refund = $this->extract_amount( $c['totals']['refunded'] );
		$void   = $this->extract_amount( $c['totals']['voided'] );
		$cur    = substr( $c['totals']['authorized'], -3 ); // SQL-safe: validated by extract_amount()

		global $wpdb;
		$sql = "INSERT INTO {$wpdb->prefix}scanpay_meta (orderid, shopid, id, rev, nacts, currency, authorized, captured, refunded, voided)
			VALUES ($oid, {$this->shopid}, $trnid, $rev, $nacts, '$cur', '$auth', '$capt', '$refund', '$void')
			ON DUPLICATE KEY UPDATE
			rev      = VALUES(rev),
			nacts    = VALUES(nacts),
			captured = VALUES(captured),
			refunded = VALUES(refunded),
			voided   = VALUES(voided)";

		if ( ! $this->upsert_meta( "transaction #$trnid", $oid, $trnid, $sql ) ) {
			return; // A different transaction already owns this order.
		}

		// The INSERT above and payment_complete() below are not atomic, so we
		// we need to check the $wco to verify if the order is marked as paid.
		$wco = wc_get_order( $oid );
		if ( ! $wco ) {
			// Legitimate state, not a protocol violation: the order may have been deleted
			// or the store reset while Scanpay still holds the old transaction. Log and
			// continue — throwing would retry the same seq forever and wedge the sync.
			scanpay_log( 'warning', "transaction #$trnid: order not found (order=$oid)" );
			return;
		}
		if ( empty( $wco->get_transaction_id( 'edit' ) ) ) {
			if ( ! str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
				scanpay_log( 'error', "transaction #$trnid: order is not a scanpay order (order=$oid)" );
				return;
			}
			if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
				scanpay_log( 'error', "transaction #$trnid: shopid mismatch (order=$oid)" );
				return;
			}
			if ( $wco->get_currency( 'edit' ) !== $cur ) {
				scanpay_log( 'error', "transaction #$trnid: currency mismatch (order=$oid)" );
				return;
			}
			// Legitimate underpayment, not a protocol violation: payment links live 15 minutes,
			// so an order total raised after the link was created is no longer covered by the
			// older, smaller authorization. Deferred capture caps at the authorized amount and
			// cannot repair this, so keep the synced meta row but do not mark the order paid.
			// Log + note + return (never throw) — mirror the currency-mismatch handling above.
			$total = (string) $wco->get_total( 'edit' );
			if ( wc_scanpay_cmpmoney( $auth, $total ) < 0 ) {
				scanpay_log( 'error', "transaction #$trnid: authorized $auth does not cover order total $total (order=$oid)" );
				$wco->add_order_note( "Scanpay: authorized amount ($auth $cur) does not cover the order total ($total $cur); order not marked as paid." );
				return;
			}
			$txn = (string) $trnid;
			$wco->set_transaction_id( $txn );
			$ts = $c['time']['authorized'] ?? null;
			if ( is_int( $ts ) && $ts < 10_000_000_000 ) {
				$wco->set_date_paid( $ts );
			}
			// Wallets (MobilePay, Apple Pay) are card payments behind the scenes, so we
			// consolidate them into the card gateway. The wallet is kept in the title.
			$wco->set_payment_method( 'scanpay' );
			$wco->set_payment_method_title( $this->parse_payment_method( $c['method'] ?? null ) );
			/*
			* Always invoke payment_complete() to trigger hooks. Save first if order status
			* is ineligible, since payment_complete() only persists changes for eligible statuses.
			*/
			if ( ! $this->is_payment_complete_eligible( $wco ) ) {
				scanpay_log( 'info', "transaction #$txn: Order is not eligible for payment_complete (order=$oid)" );
				$wco->save();
			}
			$wco->payment_complete( $txn );
		}
	}

	/**
	 * Syncs a Scanpay charge with its WC order. Inserts metadata, verifies
	 * data/ownership, then registers payment completion if applicable.
	 *
	 * @param array $c Charge payload from Scanpay.
	 * @throws \RuntimeException On validation, database, or payment errors.
	 */
	public function charge( array $c ): void {
		$oid = $this->ordernumber( $c['orderid'] ?? '' );
		if ( $oid <= 0 ) {
			return; // skip: invalid orderID
		}
		$trnid = $c['id'] ?? null;
		if ( ! is_int( $trnid ) || $trnid <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "charge: invalid transaction ID for order #$oid (id=$trnid)" );
		}
		$rev = $c['rev'] ?? null;
		if ( ! is_int( $rev ) || $rev <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "charge #$trnid: invalid revision number (rev=$rev)" );
		}
		$subid = $c['subscriber']['id'] ?? null;
		if ( ! is_int( $subid ) || $subid <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "charge #$trnid: invalid subscriber id (id=$subid)" );
		}
		$nacts  = count( $c['acts'] );
		$auth   = $this->extract_amount( $c['totals']['authorized'] );
		$capt   = $this->extract_amount( $c['totals']['captured'] );
		$refund = $this->extract_amount( $c['totals']['refunded'] );
		$void   = $this->extract_amount( $c['totals']['voided'] );
		$cur    = substr( $c['totals']['authorized'], -3 ); // SQL-safe: validated by extract_amount()

		global $wpdb;
		$sql = "INSERT INTO {$wpdb->prefix}scanpay_meta (orderid, subid, shopid, id, rev, nacts, currency, authorized, captured, refunded, voided)
			VALUES ($oid, $subid, {$this->shopid}, $trnid, $rev, $nacts, '$cur', '$auth', '$capt', '$refund', '$void')
			ON DUPLICATE KEY UPDATE
			rev      = VALUES(rev),
			nacts    = VALUES(nacts),
			captured = VALUES(captured),
			refunded = VALUES(refunded),
			voided   = VALUES(voided)";

		if ( ! $this->upsert_meta( "charge #$trnid", $oid, $trnid, $sql ) ) {
			return; // A different transaction already owns this order.
		}

		// The INSERT above and payment_complete() below are not atomic, so we
		// we need to check the $wco to verify if the order is marked as paid.
		$wco = wc_get_order( $oid );
		if ( ! $wco ) {
			// Legitimate state, not a protocol violation: the order may have been deleted
			// or the store reset while Scanpay still holds the old charge. Log and
			// continue — throwing would retry the same seq forever and wedge the sync.
			scanpay_log( 'warning', "charge #$trnid: order not found (order=$oid)" );
			return;
		}
		if ( empty( $wco->get_transaction_id( 'edit' ) ) ) {
			if ( ! str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
				scanpay_log( 'error', "charge #$trnid: order is not a scanpay order (order=$oid)" );
				return;
			}
			if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
				scanpay_log( 'error', "charge #$trnid: shopid mismatch (order=$oid)" );
				return;
			}
			if ( $wco->get_currency( 'edit' ) !== $cur ) {
				scanpay_log( 'error', "charge #$trnid: currency mismatch (order=$oid)" );
				return;
			}
			// Underpayment. This should not happen with charges, but if it does, we don't
			// want to mark the order as paid, so we log + note + return (never throw).
			$total = (string) $wco->get_total( 'edit' );
			if ( wc_scanpay_cmpmoney( $auth, $total ) < 0 ) {
				scanpay_log( 'error', "charge #$trnid: authorized $auth does not cover order total $total (order=$oid)" );
				$wco->add_order_note( "Scanpay: authorized amount ($auth $cur) does not cover the order total ($total $cur); order not marked as paid." );
				return;
			}
			$txn = (string) $trnid;
			$wco->set_transaction_id( $txn );
			$ts = $c['time']['authorized'] ?? null;
			if ( is_int( $ts ) && $ts < 10_000_000_000 ) {
				$wco->set_date_paid( $ts );
			}
			// Wallets (MobilePay, Apple Pay) are card payments behind the scenes, so we
			// consolidate them into the card gateway. The wallet is kept in the title.
			$wco->set_payment_method( 'scanpay' );
			$wco->set_payment_method_title( $this->parse_payment_method( $c['method'] ?? null ) );
			/*
			* Always invoke payment_complete() to trigger hooks. Save first if order status
			* is ineligible, since payment_complete() only persists changes for eligible statuses.
			*/
			if ( ! $this->is_payment_complete_eligible( $wco ) ) {
				scanpay_log( 'info', "charge #$txn: Order is not eligible for payment_complete (order=$oid)" );
				$wco->save();
			}
			$wco->payment_complete( $txn );
		}
	}

	/**
	 * Syncs a Scanpay subscriber with WooCommerce. Upserts subscriber state and
	 * updates the linked subscription and parent-order metadata.
	 *
	 * @param array $c Subscriber payload from Scanpay.
	 * @throws \RuntimeException On validation or database errors.
	 */
	public function subscriber( array $c ): void {
		if ( ! $this->wcs_enabled ) {
			return;
		}
		$subid = $c['id'] ?? null;
		if ( ! is_int( $subid ) || $subid <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "subscription: invalid scanpay subscription ID (id=$subid)" );
		}
		$rev = $c['rev'] ?? null;
		if ( ! is_int( $rev ) || $rev <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "subscription #$subid: invalid revision number (rev=$rev)" );
		}
		$ref = $c['ref'] ?? null;
		if ( ! is_string( $ref ) || '' === $ref ) {
			return; // skip: missing subscriber reference
		}

		// Mirror parse_payment_method()'s tolerance: a scalar method/card is malformed
		// display data that degrades to empty here, rather than fatally accessing an
		// offset on a non-array.
		$method  = is_array( $c['method'] ?? null ) ? $c['method'] : [];
		$card    = is_array( $method['card'] ?? null ) ? $method['card'] : [];
		$pm_type = $method['type'] ?? '';
		if ( ! is_string( $pm_type ) || ! ctype_alnum( $pm_type ) ) {
			$pm_type = '';
		}
		$pm_exp = $card['exp'] ?? 0;
		if ( is_string( $pm_exp ) && ctype_digit( $pm_exp ) ) {
			$pm_exp = (int) $pm_exp;
		}
		if ( ! is_int( $pm_exp ) || $pm_exp < 0 ) {
			$pm_exp = 0;
		}
		global $wpdb;
		$res = $wpdb->query(
			"INSERT INTO {$wpdb->prefix}scanpay_subs (subid, rev, method, method_exp)
			VALUES ($subid, $rev, '$pm_type', $pm_exp)
			ON DUPLICATE KEY UPDATE
			rev = $rev, method = '$pm_type', method_exp = $pm_exp"
		);
		if ( false === $res ) {
			$err = $wpdb->last_error;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "subscriber #$subid: could not save subscriber data: $err" );
		}

		$pm_title = $this->parse_payment_method( $c['method'] ?? null );
		$subs     = $this->find_subs_from_ref( $ref );
		foreach ( $subs as $i ) {
			$wcs_sub = wcs_get_subscription( (int) $i );
			if ( ! $wcs_sub ) {
				continue;
			}
			scanpay_log( 'debug', 'sub order: #' . $wcs_sub->get_id() );
			// Update subscription metadata
			$wcs_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
			$wcs_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
			$wcs_sub->set_payment_method_title( $pm_title );
			$wcs_sub->save();

			// Handle free trial and coupons
			$parent = $wcs_sub->get_parent();
			if ( $parent && $parent->get_status() === 'pending' && wc_scanpay_is_zero( (string) $parent->get_total( 'edit' ) ) ) {
				scanpay_log( 'debug', 'sub parent: #' . $parent->get_id() );
				$parent->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
				$parent->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
				$parent->add_meta_data( WC_SCANPAY_URI_STATUS, 'free trial', true );
				$parent->set_payment_method_title( $pm_title );
				$parent->set_status( 'completed', 'Subscription initiated without payment.', true );
				$parent->save();
			}
		}
	}
}
