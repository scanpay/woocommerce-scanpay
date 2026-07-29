<?php

/**
 * Settling an authorization at Scanpay once the order is completed. capture() is the
 * fail-loud primitive; capture_or_hold() is the only entry point from outside and the one
 * place that turns a failure into an 'on-hold' order.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/math.php';
require_once WC_SCANPAY_DIR . '/library/functions.php';
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';

final class WC_Scanpay_Capture {
	/**
	 * What this request already decided per order id: true for a capture that succeeded or
	 * found nothing left to capture, false for one that failed and took the on-hold path.
	 * Owned by capture_or_hold().
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
		// An absent key is the expected state after a reset, which unsets 'apikey' but
		// leaves wc_autocapture, so completing a historical Scanpay order still lands here.
		// A malformed one is a misconfiguration; conflating the two would tell the merchant
		// to repair a key they removed on purpose.
		if ( '' === $apikey ) {
			throw new \RuntimeException( 'No Scanpay API key is configured' );
		}
		if ( $shopid <= 0 ) {
			throw new \RuntimeException( 'Invalid Scanpay API key configured' );
		}
		self::$shopid = $shopid;
		self::$client = new WC_Scanpay_Client( $apikey );
	}

	/**
	 * Captures payment for an order. Only capture_or_hold() may call it: that wrapper owns
	 * the memo and the ownership check. Void by design, so returning is the only success
	 * signal -- from outside, "captured" and "nothing to do" look the same.
	 *
	 * @throws \RuntimeException On an unsynced payment row, misconfiguration, a lookup
	 *                           error, or a voided auth.
	 */
	private static function capture( WC_Order $wco ): void {
		$oid = (int) $wco->get_id();
		self::init();

		$order_shopid = (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
		if ( $order_shopid !== self::$shopid ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
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
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( "Payment lookup failed for order #$oid: {$wpdb->last_error}" );
		}
		if ( null === $meta ) {
			throw new \RuntimeException( 'No payment details found on order' );
		}
		if ( ! wc_scanpay_is_zero( $meta['voided'] ) ) {
			throw new \RuntimeException( 'Transaction has been voided' );
		}
		// What the order still owes: total, less WooCommerce-side refunds, less what
		// Scanpay has captured net of what it refunded back.
		$amount = (string) $wco->get_total( 'edit' );
		foreach ( $wco->get_refunds() as $refund ) {
			// 'edit', or a woocommerce_order_refund_get_amount callback would decide what
			// the customer is charged.
			$amount = wc_scanpay_submoney( $amount, (string) $refund->get_amount( 'edit' ) );
		}

		$net_captured = wc_scanpay_submoney( $meta['captured'], $meta['refunded'] );
		$to_capture   = wc_scanpay_submoney( $amount, $net_captured );

		// Never exceed what is left of the authorization. A refund does not restore it,
		// so this subtracts the gross captured amount, not the net one above.
		$remaining_on_auth = wc_scanpay_submoney( $meta['authorized'], $meta['captured'] );
		if ( wc_scanpay_cmpmoney( $to_capture, $remaining_on_auth ) > 0 ) {
			// Logged before the clamp overwrites the figure, because nothing downstream says
			// it happened: capture() returns normally, capture_or_hold() answers true, and the
			// success note below reads exactly like a full capture. Sync's underpayment branch
			// is the sister case and reports it, so this is the standard the file already sets.
			scanpay_log(
				'error',
				"Order #$oid: capture reduced to the authorized remainder -- order owes " .
				"$to_capture, authorization has $remaining_on_auth left. The difference will " .
				'not be collected.'
			);
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
		// The money moved when capture() returned, so a throwing note must not reach
		// capture_or_hold()'s catch: it would park the paid order on-hold and tell its
		// caller not to complete it. add_order_note() runs a filter, wp_insert_comment()
		// and an action, all third-party surface.
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
	 * decline; 'failed' would send a misleading email and trigger WCS dunning. 'on-hold' is
	 * in OrderStatus::PAYMENT_COMPLETE_STATUSES, so the next ping reconciles the order and
	 * the merchant can retry -- the remaining-amount guard prevents a double capture.
	 *
	 * Parking can itself fail quietly: WC_Order::update_status() returns false both from
	 * its own Exception catch and, before it, for an unsaved order. Both are reported
	 * below, because the order then keeps the status it had -- 'completed', on the
	 * woocommerce_order_status_completed path -- and a merchant would take "capture failed"
	 * for parked and ship on an authorization that was never captured.
	 *
	 * @return bool True if the capture succeeded, so the caller may complete the order.
	 */
	public static function capture_or_hold( WC_Order $wco ): bool {
		// Someone else's order: nothing attempted, and nothing recorded -- neither meaning
		// of a recorded true fits it.
		if ( ! wc_scanpay_is_scanpay_order( $wco ) ) {
			return true;
		}
		$oid = (int) $wco->get_id();

		// One capture per order per request: several paths fire for the same completion,
		// and the bulk handler does not deduplicate its id list. A repeat call answers what
		// actually happened -- a failed attempt reading as a success here would let the
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
			// Includes the "payment not synced yet" race, a merchant completing an order
			// before the first ping. The next ping reconciles it via payment_complete().
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
				// Silent about WooCommerce's own log entry on purpose: the Exception path
				// inside update_status() leaves one, the unsaved-order guard before it does
				// not. Naming an entry that may not exist sends the merchant looking.
				if ( ! $parked ) {
					scanpay_log( 'error', "Order #$oid was not parked on hold: update_status() returned false" );
				}
			} catch ( \Throwable $status_error ) {
				// Nothing to retry, and it must still return the recorded false rather than
				// escape into a caller that has no handler for it.
				scanpay_log( 'error', "Could not park order #$oid on hold: " . $status_error->getMessage() );
			}
			return false;
		}
	}
}
