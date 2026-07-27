<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/math.php';
require_once WC_SCANPAY_DIR . '/library/functions.php';
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';

final class WC_Scanpay_Capture {
	/**
	 * What this request already decided about an order, keyed by order id: true for a
	 * capture that succeeded or correctly found nothing left to capture, false for an
	 * attempt that failed and was handled through the on-hold path. Owned by
	 * capture_or_hold(); the map only ever holds booleans, so isset() is enough.
	 *
	 * @var array<int, bool>
	 */
	private static array $processed           = [];
	private static ?WC_Scanpay_Client $client = null;
	private static int $shopid                = 0;

	private static function init(): void {
		if ( null !== self::$client ) {
			return;
		}
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		$apikey   = (string) ( $settings['apikey'] ?? '' );
		$shopid   = (int) strstr( $apikey, ':', true );
		if ( $shopid <= 0 ) {
			throw new \RuntimeException( 'Invalid Scanpay API key configured' );
		}
		self::$shopid = $shopid;
		self::$client = new WC_Scanpay_Client( $apikey );
	}

	/**
	 * Attempts to capture payment for the given WooCommerce order.
	 *
	 * Throws on any failure (fail-loud primitive). Only capture_or_hold() may call it:
	 * that wrapper owns the per-request memo and the ownership check, and translates a
	 * failure into an 'on-hold' status rather than letting it surface as an unhandled
	 * Throwable or a 'failed' order. Returning is the only success signal there is --
	 * the method stays void, so it cannot report "nothing to do" any other way.
	 *
	 * @throws \RuntimeException On an unsynced payment row, misconfiguration, a lookup
	 *                           error, or a voided auth.
	 */
	private static function capture( WC_Order $wco ): void {
		$oid = (int) $wco->get_id();
		self::init();

		$order_shopid = (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
		if ( $order_shopid !== self::$shopid ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "ShopID mismatch for order #$oid: order has $order_shopid, APIkey has " . self::$shopid );
		}
		global $wpdb;
		$meta = $wpdb->get_row(
			"SELECT id, nacts, authorized, captured, refunded, voided
			FROM {$wpdb->prefix}scanpay_meta
			WHERE orderid = $oid
			LIMIT 1",
			ARRAY_A
		);
		if ( $wpdb->last_error ) {
			// A query error also returns null; keep it distinct from a missing row.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( "Payment lookup failed for order #$oid: {$wpdb->last_error}" );
		}
		if ( null === $meta ) {
			throw new \RuntimeException( 'No payment details found on order' );
		}
		if ( ! wc_scanpay_is_zero( $meta['voided'] ) ) {
			throw new \RuntimeException( 'Transaction has been voided' );
		}
		// What the order still owes: its total less the WooCommerce-side refunds, less
		// what Scanpay has already captured net of what it refunded back.
		$amount = (string) $wco->get_total( 'edit' );
		foreach ( $wco->get_refunds() as $refund ) {
			$amount = wc_scanpay_submoney( $amount, (string) $refund->get_amount() );
		}

		$net_captured = wc_scanpay_submoney( $meta['captured'], $meta['refunded'] );
		$to_capture   = wc_scanpay_submoney( $amount, $net_captured );

		// Never exceed what is left of the authorization. A refund does not restore it,
		// so this subtracts the gross captured amount, not the net one above.
		$remaining_on_auth = wc_scanpay_submoney( $meta['authorized'], $meta['captured'] );
		if ( wc_scanpay_cmpmoney( $to_capture, $remaining_on_auth ) > 0 ) {
			$to_capture = $remaining_on_auth;
		}
		if ( wc_scanpay_cmpmoney( $to_capture, '0' ) <= 0 ) {
			scanpay_log( 'debug', "Skipping capture: nothing left to capture on order #$oid" );
			return;
		}
		$amount = $to_capture . ' ' . $wco->get_currency( 'edit' );
		self::$client->capture(
			(int) $meta['id'],
			[
				'total' => $amount,
				'index' => (int) $meta['nacts'],
			]
		);
		// The money moved when client->capture() returned, so the note must not be able to
		// read as a capture failure: capture_or_hold()'s catch would park the paid order
		// on-hold and tell its caller not to complete it. add_order_note() runs
		// woocommerce_new_order_note_data, wp_insert_comment() and
		// woocommerce_order_note_added, all third-party surface; contained as in
		// WC_Scanpay_Sync::sync(), WC_Scanpay_Sync::report_incomplete() and
		// wc_scanpay_process_payment().
		try {
			$wco->add_order_note(
				sprintf(
					/* translators: %s is the captured amount with its currency, e.g. "99.00 DKK". */
					__( 'Scanpay capture of %s completed.', 'scanpay-for-woocommerce' ),
					$amount
				),
				0,
				true
			);
		} catch ( \Throwable $note_error ) {
			scanpay_log( 'error', "Could not add the capture note to order #$oid: " . $note_error->getMessage() );
		}
	}

	/**
	 * Captures payment for an order, parking it 'on-hold' (not 'failed') on any error.
	 *
	 * Capture settles an already-authorized payment, so a failure is never a customer
	 * decline; 'failed' would send a misleading failed-order email and trigger WCS
	 * dunning. 'on-hold' is in PAYMENT_COMPLETE_STATUSES, so the next ping reconciles the
	 * order automatically and the merchant can safely retry (the remaining-amount guard
	 * prevents a double capture).
	 *
	 * Parking can itself fail, and mostly does so quietly: WC_Order::update_status()
	 * catches Exception itself (class-wc-order.php:407-425) and returns false, and returns
	 * false without doing anything at :403 for an unsaved order. Both are reported below,
	 * because the order then keeps the status it had -- 'completed', on the
	 * woocommerce_order_status_completed path -- and a merchant reading "capture failed"
	 * against the documented contract would take it for parked and ship on an
	 * authorization that was never captured.
	 *
	 * @return bool True if the capture succeeded, so the caller may complete the order.
	 */
	public static function capture_or_hold( WC_Order $wco ): bool {
		// Someone else's order: no attempt is made and nothing is recorded, since neither
		// meaning of a recorded true fits it. The wrapper has to answer this, because
		// capture() is void -- from outside, its early return and a completed capture
		// look the same.
		if ( ! wc_scanpay_is_scanpay_order( $wco ) ) {
			return true;
		}
		$oid = (int) $wco->get_id();

		// One capture per order per request: more than one path can fire for the same
		// completion (status hook, bulk action, mark-completed intercept, meta box), and
		// the bulk handler does not deduplicate its id list. A repeat call answers what
		// actually happened -- a failed attempt that read as a success here would let the
		// caller complete an order that was never captured.
		if ( isset( self::$processed[ $oid ] ) ) {
			scanpay_log( 'debug', "Skipping capture: order #$oid already processed in this request" );
			return self::$processed[ $oid ];
		}
		try {
			self::capture( $wco );
			self::$processed[ $oid ] = true;
			return true;
		} catch ( \Throwable $e ) {
			// Recorded first: the attempt has failed whether or not the log, the note and
			// the status write below get through.
			self::$processed[ $oid ] = false;
			// Any failure -- including the "payment not synced yet" race (a merchant
			// completing an order before the first ping) -- parks the order rather than
			// failing it. The next ping reconciles it via payment_complete().
			scanpay_log( 'error', "Capture on order #$oid failed: " . $e->getMessage() );
			try {
				$parked = $wco->update_status(
					'on-hold',
					sprintf(
						/* translators: %s is the raw failure reason, which is not translated. */
						__( 'Scanpay capture failed: %s', 'scanpay-for-woocommerce' ),
						$e->getMessage()
					),
					true
				);
				// Says nothing about WooCommerce's own log entry on purpose. False has two
				// sources and only one of them leaves a trail: the Exception path logs and
				// notes the order, while the unsaved-order guard at class-wc-order.php:403
				// returns before the try and records nothing at all. Naming an entry that may
				// not exist would send the merchant looking for it.
				if ( ! $parked ) {
					scanpay_log( 'error', "Order #$oid was not parked on hold: update_status() returned false" );
				}
			} catch ( \Throwable $status_error ) {
				// WooCommerce could not persist the fallback status. Nothing to retry: the
				// capture failed either way, and this must still return the recorded false
				// rather than escape into a caller that has no handler for it.
				scanpay_log( 'error', "Could not park order #$oid on hold: " . $status_error->getMessage() );
			}
			return false;
		}
	}
}
