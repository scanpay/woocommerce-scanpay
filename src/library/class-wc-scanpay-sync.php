<?php

/**
 * Turns Scanpay's change feed into WooCommerce state: upserts scanpay_meta and
 * scanpay_subs, then marks orders paid and links subscriptions. Driven only by the ping
 * drain, under Scanpay_Flock, and replay-safe -- a second drain rebuilds the same rows.
 *
 * The standing rule throughout: a malformed Scanpay payload throws, while a local anomaly
 * -- a deleted order, a corrupt total, a third-party hook that fails -- is logged and
 * skipped. Throwing on the latter pins the sync cursor, and Scanpay would serve the same
 * seq page forever, turning one stuck order into an outage for the whole shop.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/math.php';
require_once WC_SCANPAY_DIR . '/library/functions.php';

final class WC_Scanpay_Sync {
	private int $shopid;
	private bool $wcs_enabled;

	/** WooCommerce core's default statuses for which payment_complete() persists changes. */
	private const PAYMENT_COMPLETE_STATUSES = [ 'on-hold', 'pending', 'failed', 'cancelled' ];

	/** Scanpay card brand codes to display labels. */
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

	/** Scanpay wallet codes (the method type) to display labels. */
	private const CARD_WALLETS = [
		'applepay'   => 'Apple Pay',
		'googlepay'  => 'Google Pay',
		'mobilepay'  => 'MobilePay',
		'samsungpay' => 'Samsung Pay',
	];


	/** $settings is the primary woocommerce_scanpay_settings option. */
	public function __construct( array $settings, int $shopid ) {
		$this->shopid      = $shopid;
		$this->wcs_enabled = class_exists( 'WC_Subscriptions', false );

		// Not kept as a field: this filter is the only thing the class takes from the live
		// settings, and every later decision reads the flag persisted on the order instead.
		// Holding the array would invite the mid-payment-window reinterpretation that avoids.
		if ( 'yes' === ( $settings['wc_complete_virtual'] ?? 'no' ) ) {
			add_filter( 'woocommerce_order_item_needs_processing', 'wc_scanpay_item_needs_processing', 10, 3 );
		}
	}

	private function is_payment_complete_eligible( \WC_Order $order ): bool {
		// WC's own hook, re-applied so this cannot drift from what payment_complete() accepts.
		$valid = apply_filters(
			'woocommerce_valid_order_statuses_for_payment_complete',
			self::PAYMENT_COMPLETE_STATUSES,
			$order
		);
		return $order->has_status( $valid );
	}

	/**
	 * Scanpay method data to an order's payment_method_title, e.g. "Apple Pay (Visa 4321)".
	 * Display-only, so malformed data degrades to 'Scanpay' rather than throwing.
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
	 * Subscription ids out of a Scanpay subscriber reference.
	 *
	 * @return string[] Empty when $ref is not a wcs[] reference.
	 */
	private function find_subs_from_ref( string $ref ): array {
		return str_starts_with( $ref, 'wcs[]' )
			? explode( ',', substr( $ref, 5 ) )
			: [];
	}

	/** Scanpay's orderid to a WC order ID: digit strings only, 0 when it is anything else. */
	private function ordernumber( mixed $s ): int {
		if ( ! is_string( $s ) || '' === $s || ! ctype_digit( $s ) ) {
			return 0;
		}
		return (int) $s;
	}

	/**
	 * The numeric part of a currency string like "199.99 DKK". A missing total throws a
	 * TypeError through the string parameter, on purpose: a malformed payload is a backend
	 * error, never something to skip or default.
	 *
	 * @throws \RuntimeException On a malformed amount or currency.
	 */
	private function extract_amount( string $s ): string {
		$n = strlen( $s );
		if ( $n < 5 || ' ' !== $s[ $n - 4 ] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "missing space before currency: $s" );
		}
		if ( ! ctype_upper( substr( $s, -3 ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "invalid currency code: $s" );
		}
		$amount = substr( $s, 0, $n - 4 );
		if ( ! wc_scanpay_is_money( $amount ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "invalid currency amount: $s" );
		}
		return $amount;
	}

	/**
	 * Guarded upsert of the scanpay_meta row for an order.
	 *
	 * The table is keyed by orderid alone, so a second transaction on the same order -- two
	 * payment links, or a numeric merchant-reference collision -- would otherwise splice its
	 * rev/nacts/totals onto the first transaction's id and authorized amount.
	 *
	 * @param string $label Log and exception prefix, e.g. "transaction #123".
	 * @param string $sql   Prebuilt INSERT ... ON DUPLICATE KEY UPDATE statement.
	 * @return bool False when another transaction owns the order; the caller must stop.
	 * @throws \RuntimeException On a database read or write error.
	 */
	private function upsert_meta( string $label, int $oid, int $trnid, string $sql ): bool {
		global $wpdb;
		$owner = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid" );
		if ( $wpdb->last_error ) {
			// A query error also returns null; keep it distinct from a missing row.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "$label: could not read payment data for order #$oid: {$wpdb->last_error}" );
		}
		if ( null !== $owner && (int) $owner !== $trnid ) {
			// Return, not throw: another transaction owning the order is a permanent state.
			scanpay_log( 'error', "$label: order #$oid already paid by transaction #" . (int) $owner . '; ignoring' );
			return false;
		}
		if ( false === $wpdb->query( $sql ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "$label: could not save payment data to order #$oid: {$wpdb->last_error}" );
		}
		return true;
	}

	/** Syncs a Scanpay transaction with its WC order. */
	public function transaction( array $c ): void {
		$this->sync( $c, 'transaction' );
	}

	/** Syncs a Scanpay charge with its WC order. */
	public function charge( array $c ): void {
		$this->sync( $c, 'charge' );
	}

	/**
	 * Shared worker for transaction() and charge(): validate the payload, guard-upsert the
	 * scanpay_meta row, then mark the order paid once ownership, shop, currency and the
	 * authorized amount all check out.
	 *
	 * @param string $type 'transaction' or 'charge'. Sets the log labels and, for charges,
	 *                     requires and stores the subscriber id.
	 * @throws \RuntimeException On validation, database, or payment errors.
	 */
	private function sync( array $c, string $type ): void {
		$oid = $this->ordernumber( $c['orderid'] ?? '' );
		if ( $oid <= 0 ) {
			return; // Not a WooCommerce order id; skip without failing the sync.
		}
		$trnid = $c['id'] ?? null;
		if ( ! is_int( $trnid ) || $trnid <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "$type: invalid transaction ID for order #$oid (id=$trnid)" );
		}
		$label = "$type #$trnid";
		$rev   = $c['rev'] ?? null;
		if ( ! is_int( $rev ) || $rev <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "$label: invalid revision number (rev=$rev)" );
		}
		$subid = null;
		if ( 'charge' === $type ) {
			$subid = $c['subscriber']['id'] ?? null;
			if ( ! is_int( $subid ) || $subid <= 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
				throw new \RuntimeException( "$label: invalid subscriber id (id=$subid)" );
			}
		}
		$nacts  = count( $c['acts'] );
		$auth   = $this->extract_amount( $c['totals']['authorized'] );
		$capt   = $this->extract_amount( $c['totals']['captured'] );
		$refund = $this->extract_amount( $c['totals']['refunded'] );
		$void   = $this->extract_amount( $c['totals']['voided'] );
		$cur    = substr( $c['totals']['authorized'], -3 ); // SQL-safe: validated by extract_amount()

		// Charges also record the subscriber id; both values are validated ints, so SQL-safe.
		$subid_col = ( null !== $subid ) ? 'subid, ' : '';
		$subid_val = ( null !== $subid ) ? "$subid, " : '';

		global $wpdb;
		$sql = "INSERT INTO {$wpdb->prefix}scanpay_meta (orderid, {$subid_col}shopid, id, rev, nacts, currency, authorized, captured, refunded, voided)
			VALUES ($oid, {$subid_val}{$this->shopid}, $trnid, $rev, $nacts, '$cur', '$auth', '$capt', '$refund', '$void')
			ON DUPLICATE KEY UPDATE
			rev      = VALUES(rev),
			nacts    = VALUES(nacts),
			captured = VALUES(captured),
			refunded = VALUES(refunded),
			voided   = VALUES(voided)";

		if ( ! $this->upsert_meta( $label, $oid, $trnid, $sql ) ) {
			return; // A different transaction already owns this order.
		}

		// The upsert above and payment_complete() below are not atomic, so the order
		// itself is what says whether it is already paid (transaction_id, set below).
		$wco = wc_get_order( $oid );
		if ( ! $wco instanceof WC_Order ) {
			// A deleted order or a reset store, not a protocol violation. instanceof, not
			// truthiness: refunds share the order id space, and a WC_Order_Refund inherits
			// neither payment_complete() nor the transaction-id and payment-method accessors
			// used below, with no __call in the chain. Same guard, same reason, as
			// wc_scanpay_item_needs_processing().
			scanpay_log( 'warning', "$label: order not found (order=$oid)" );
			return;
		}
		if ( empty( $wco->get_transaction_id( 'edit' ) ) ) {
			if ( ! str_starts_with( (string) $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
				scanpay_log( 'error', "$label: order is not a scanpay order (order=$oid)" );
				return;
			}
			if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) !== $this->shopid ) {
				scanpay_log( 'error', "$label: shopid mismatch (order=$oid)" );
				return;
			}
			if ( $wco->get_currency( 'edit' ) !== $cur ) {
				scanpay_log( 'error', "$label: currency mismatch (order=$oid)" );
				return;
			}
			// The money helpers below throw on non-money input; a corrupt local total must
			// not become a wedged sync loop.
			$total = (string) $wco->get_total( 'edit' );
			if ( ! wc_scanpay_is_money( $total ) ) {
				scanpay_log( 'error', "$label: invalid order total '$total' (order=$oid)" );
				return;
			}
			// Underpayment: unexpected on a charge, but never mark the order paid on one.
			if ( wc_scanpay_cmpmoney( $auth, $total ) < 0 ) {
				scanpay_log( 'error', "$label: authorized $auth does not cover order total $total (order=$oid)" );
				try {
					$wco->add_order_note(
						sprintf(
							/* translators: 1: authorized amount, 2: order total, 3: currency code. */
							__( 'Scanpay: the authorized amount (%1$s %3$s) does not cover the order total (%2$s %3$s); the order was not marked as paid.', 'scanpay-for-woocommerce' ),
							$auth,
							$total,
							$cur
						)
					);
				} catch ( \Throwable $note_error ) {
					// add_order_note() runs a filter, wp_insert_comment() and an action, all
					// third-party surface. Reporting a failure must not become the failure.
					scanpay_log( 'error', "$label: could not add the underpayment note to order #$oid: " . $note_error->getMessage() );
				}
				return;
			}
			$txn = (string) $trnid;
			$wco->set_transaction_id( $txn );
			// Upper bound rejects a millisecond timestamp, which would date the order
			// millennia out instead of failing visibly.
			$ts = $c['time']['authorized'] ?? null;
			if ( is_int( $ts ) && $ts < 10_000_000_000 ) {
				$wco->set_date_paid( $ts );
			}
			// Wallets are card payments underneath, so they consolidate into the card
			// gateway; the wallet name survives in the title.
			$wco->set_payment_method( 'scanpay' );
			$wco->set_payment_method_title( $this->parse_payment_method( $c['method'] ?? null ) );
			// Always call payment_complete() so the hooks fire; it only persists changes
			// for eligible statuses, so save first when the status is not one of them.
			if ( ! $this->is_payment_complete_eligible( $wco ) ) {
				scanpay_log( 'info', "$label: Order is not eligible for payment_complete (order=$oid)" );
				// The result is deliberately not acted on: payment_complete() then takes its
				// else branch, which fires the hook and returns true without saving, so a
				// failure here reaches neither $ok nor report_incomplete(). Nothing is lost
				// for good -- the write that did not land is transaction_id, so the next
				// change on this order finds it still empty and re-enters this branch.
				$this->save_or_report( $wco, "$label: order #$oid" );
			}
			/*
			 * Force 'completed' when the accepted payment attempt asked for it -- the persisted
			 * flag, never the live settings, so a merchant toggling them mid-payment-window
			 * cannot reinterpret an attempt the store already accepted. Order meta is stored as
			 * strings, so a stored true reads back as '1'; anything else is not a request.
			 *
			 * Restricted to pending/on-hold/failed. 'cancelled' is eligible and still
			 * transitions, but to 'processing', which leaves the merchant something to review:
			 * forcing it would auto-fulfil an order hold-stock or the merchant ended.
			 * 'refunded' never reaches the filter -- WooCommerce applies it only inside the
			 * has_status() branch, which is also why this forces through payment_complete()
			 * rather than set_status().
			 */
			$want = $wco->get_meta( WC_SCANPAY_URI_COMPLETE, true, 'edit' );
			$hook = null;
			if ( ( true === $want || '1' === $want ) && $wco->has_status( [ 'pending', 'on-hold', 'failed' ] ) ) {
				/*
				 * Scoped to this one call, not standing: maybe_set_date_paid() applies the
				 * same filter read-only on every order saved before it has a paid date, so a
				 * standing callback returning 'completed' would flip that comparison for every
				 * unpaid Scanpay order in the request. Matching the order id is the whole
				 * guard, and it is what stops a nested third-party hook completing some other
				 * order from picking up the forced status. No fire-once guard needed:
				 * payment_complete() sets date_paid first, so the filter fires exactly once.
				 */
				$hook = static function ( $status, $order_id ) use ( $oid ) {
					return (int) $order_id === $oid ? 'completed' : $status;
				};
				add_filter( 'woocommerce_payment_complete_order_status', $hook, 10, 2 );
			}
			$ok  = false;
			$err = null;
			try {
				$ok = $wco->payment_complete( $txn );
			} catch ( \Throwable $e ) {
				// payment_complete() catches Exception, not Throwable, so an Error from a
				// third-party callback escapes it with neither its log entry nor its note.
				// Held, not reported here: the forced-status filter is installed until the
				// finally runs, and the reporting below writes to the order.
				$err = $e;
			} finally {
				if ( null !== $hook ) {
					remove_filter( 'woocommerce_payment_complete_order_status', $hook, 10 );
				}
			}
			if ( ! $ok ) {
				$this->report_incomplete( $wco, $label, $oid, $err );
			}
		}
	}

	/**
	 * Record an order Scanpay has paid but WooCommerce would not complete. Best effort, and
	 * silent to the caller.
	 *
	 * On the false path WooCommerce has already logged and noted the order, so this adds
	 * what only we know: the money is at Scanpay and nothing will retry. On the
	 * escaping-Error path this is the only record there is.
	 *
	 * @param string          $label Scanpay's label for the change, e.g. "charge #4321".
	 * @param \Throwable|null $err   Set only when an Error escaped payment_complete().
	 */
	private function report_incomplete( \WC_Order $wco, string $label, int $oid, ?\Throwable $err ): void {
		$why = null === $err ? 'see the order note WooCommerce added' : trim( $err->getMessage() );
		scanpay_log( 'error', "$label: WooCommerce could not complete order #$oid: $why" );
		try {
			$wco->add_order_note(
				sprintf(
					/* translators: 1: Scanpay transaction or charge label, e.g. "charge #4321". 2: WooCommerce order ID. */
					__( 'Scanpay holds a successful payment for this order (%1$s), but WooCommerce could not complete order #%2$d. Reconcile the order manually: the payment is settled at Scanpay and nothing will retry the completion.', 'scanpay-for-woocommerce' ),
					$label,
					$oid
				)
			);
		} catch ( \Throwable $note_error ) {
			// Reporting a failure must not become the failure.
			scanpay_log( 'error', "$label: could not add the reconciliation note to order #$oid: " . $note_error->getMessage() );
		}
	}

	/**
	 * Persist an order this class has just edited, containing whatever the write throws.
	 *
	 * WC_Abstract_Order::save() and WC_Order::status_transition() both catch Exception and
	 * not Throwable -- the same asymmetry the payment_complete() call in sync() is wrapped
	 * for -- so an Error out of a third-party callback escapes both, reaches the ping
	 * handler's catch and answers 500, leaving the cursor unadvanced.
	 *
	 * @param string $what Log context naming the object, e.g. "charge #4321: order #17".
	 * @return bool False when the write threw, leaving the caller to decide what to skip.
	 */
	private function save_or_report( \WC_Order $wco, string $what ): bool {
		try {
			$wco->save();
			return true;
		} catch ( \Throwable $e ) {
			scanpay_log( 'error', "$what could not be saved: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Upserts the scanpay_subs row, then links the subscriptions named by the subscriber's
	 * ref and, for a zero-total parent order, completes it.
	 *
	 * @throws \RuntimeException On validation or database errors.
	 */
	public function subscriber( array $c ): void {
		$subid = $c['id'] ?? null;
		if ( ! is_int( $subid ) || $subid <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "subscription: invalid scanpay subscription ID (id=$subid)" );
		}
		$rev = $c['rev'] ?? null;
		if ( ! is_int( $rev ) || $rev <= 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "subscription #$subid: invalid revision number (rev=$rev)" );
		}
		$ref = $c['ref'] ?? null;
		if ( ! is_string( $ref ) || '' === $ref ) {
			return; // No reference: nothing to link this subscriber to.
		}

		// Mirrors parse_payment_method()'s tolerance: a scalar method or card is malformed
		// display data, and degrades to empty rather than indexing a non-array.
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "subscriber #$subid: could not save subscriber data: $err" );
		}

		// Only the tail below needs Subscriptions; the row above is written regardless,
		// because it holds the rev idempotency_key() builds from. Seq only moves forward, so
		// a revision skipped while WCS was deactivated is gone for good, and the shop would
		// charge under a stale key once it comes back.
		if ( ! $this->wcs_enabled ) {
			return;
		}

		$pm_title = $this->parse_payment_method( $c['method'] ?? null );
		$subs     = $this->find_subs_from_ref( $ref );
		foreach ( $subs as $i ) {
			$wcs_sub = wcs_get_subscription( (int) $i );
			if ( ! $wcs_sub ) {
				continue;
			}
			scanpay_log( 'debug', 'sub order: #' . $wcs_sub->get_id() );
			$wcs_sub->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
			$wcs_sub->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
			$wcs_sub->set_payment_method_title( $pm_title );
			if ( ! $this->save_or_report( $wcs_sub, "subscriber #$subid: subscription #" . $wcs_sub->get_id() ) ) {
				// An unwritten subid is the precondition for everything below. Completing the
				// parent anyway would activate a subscription whose every renewal then dies
				// on WCS_Scanpay_Charge::scheduled_charge()'s subscriber-id guard; leaving it
				// pending keeps the half-finished state where the merchant can see it.
				continue;
			}

			// Free trial or a 100% coupon: the parent carries no payment, so nothing else
			// will ever complete it.
			//
			// 'edit' because the branch writes 'completed'. In 'view',
			// WC_Abstract_Order::get_status() substitutes the woocommerce_default_order_status
			// filter's 'pending' for an empty status, so a statusless order would take the
			// branch; under 'edit' it reads '' and is skipped, which is right.
			$parent = $wcs_sub->get_parent();
			if ( ! $parent || 'pending' !== $parent->get_status( 'edit' ) ) {
				continue;
			}
			// As in sync(): a corrupt local total must not throw.
			$ptotal = (string) $parent->get_total( 'edit' );
			if ( ! wc_scanpay_is_money( $ptotal ) ) {
				scanpay_log( 'error', "subscriber #$subid: invalid total '$ptotal' on parent order #" . $parent->get_id() );
				continue;
			}
			if ( wc_scanpay_is_zero( $ptotal ) ) {
				scanpay_log( 'debug', 'sub parent: #' . $parent->get_id() );
				$parent->add_meta_data( WC_SCANPAY_URI_SUBID, $subid, true );
				$parent->add_meta_data( WC_SCANPAY_URI_SHOPID, $this->shopid, true );
				$parent->set_payment_method_title( $pm_title );
				$parent->set_status( 'completed', __( 'Subscription initiated without payment.', 'scanpay-for-woocommerce' ), true );
				// The widest third-party surface in the drain: set_status() only queues the
				// transition, so it is this save() that runs it, and with it
				// woocommerce_order_status_completed, the emails, stock and downloads.
				$this->save_or_report( $parent, "subscriber #$subid: parent order #" . $parent->get_id() );
			}
		}
	}
}
