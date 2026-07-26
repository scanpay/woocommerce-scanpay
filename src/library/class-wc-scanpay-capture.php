<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/math.php';
require_once WC_SCANPAY_DIR . '/library/functions.php';
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';

final class WC_Scanpay_Capture {
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
	 * Throws on any failure (fail-loud primitive). Callers should go through
	 * capture_or_hold(), which translates failures into an 'on-hold' status rather
	 * than letting them surface as an unhandled Throwable or a 'failed' order.
	 *
	 * @throws \RuntimeException On an unsynced payment row, misconfiguration, a lookup
	 *                           error, or a voided auth.
	 */
	private static function capture( WC_Order $wco ): void {
		if ( ! wc_scanpay_is_scanpay_order( $wco ) ) {
			return;
		}
		$oid = (int) $wco->get_id();
		// One capture per order per request: more than one path can fire for the same
		// completion (status hook, bulk action, mark-completed intercept, meta box).
		if ( isset( self::$processed[ $oid ] ) ) {
			scanpay_log( 'debug', "Skipping capture: already processed order #$oid" );
			return;
		}
		self::init();
		self::$processed[ $oid ] = true;

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
		$wco->add_order_note( "Scanpay capture of $amount completed.", 0, true );
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
	 * @return bool True if the capture succeeded, so the caller may complete the order.
	 */
	public static function capture_or_hold( WC_Order $wco ): bool {
		try {
			self::capture( $wco );
			return true;
		} catch ( \Throwable $e ) {
			// Any failure -- including the "payment not synced yet" race (a merchant
			// completing an order before the first ping) -- parks the order rather than
			// failing it. The next ping reconciles it via payment_complete().
			scanpay_log( 'error', 'Capture on order #' . $wco->get_id() . ' failed: ' . $e->getMessage() );
			$wco->update_status( 'on-hold', 'Scanpay capture failed: ' . $e->getMessage(), true );
			return false;
		}
	}
}
