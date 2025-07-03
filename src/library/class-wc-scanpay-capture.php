<?php

require_once WC_SCANPAY_DIR . '/library/math.php';
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';

class WC_Scanpay_Capture {
	// Prevent recursive processing of the same order
	protected static $processed = [];

	/**
	 * Attempts to capture payment for the given WooCommerce order.
	 *
	 * @param WC_Order $wco WooCommerce order object.
	 * @return array {
	 *   @type string $status  One of 'ok', 'failed', 'aborted', 'skipped'.
	 *   @type string $msg Human-readable message describing the result.
	 * }
	 */
	public static function capture( object $wco ): array {
		$oid = (int) $wco->get_id();
		if ( isset( self::$processed[ $oid ] ) ) {
			return [
				'status' => 'skipped',
				'msg'    => 'capture already processed for this order',
			];
		}
		self::$processed[ $oid ] = true;

		// Only process scanpay orders
		if ( ! str_starts_with( $wco->get_payment_method( 'edit' ), 'scanpay' ) ) {
			return [
				'status' => 'skipped',
				'msg'    => 'not a scanpay order',
			];
		}
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		$apikey   = $settings['apikey'] ?? '';

		// Validate the shopID in the API key
		$shopid = (int) strstr( $apikey, ':', true );
		if ( $shopid <= 0 ) {
			return [
				'status' => 'aborted',
				'msg'    => 'invalid or missing API key',
			];
		}

		// Check for shopID mismatch (order vs API key)
		$order_shopid = (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
		if ( $order_shopid !== $shopid ) {
			return [
				'status' => 'aborted',
				'msg'    => "shop ID mismatch (order: $order_shopid, API key: $shopid)",
			];
		}

		// Retrieve payment metadata for this order
		global $wpdb;
		$meta = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid", ARRAY_A );
		if ( 1 !== $wpdb->num_rows ) {
			return [
				'status' => 'aborted',
				'msg'    => 'no payment details found on order',
			];
		}

		// Check if the payment has been voided
		if ( wc_scanpay_cmpmoney( $meta['voided'], '0' ) > 0 ) {
			return [
				'status' => 'aborted',
				'msg'    => 'transaction has been voided',
			];
		}

		// Calculate the raw amount left to capture, subtracting any refunds
		$amount = (string) $wco->get_total( 'edit' );
		foreach ( $wco->get_refunds() as $refund ) {
			$amount = wc_scanpay_submoney( $amount, $refund->get_amount() );
		}
		$net_captured = wc_scanpay_submoney( $meta['captured'], $meta['refunded'] );
		$to_capture   = wc_scanpay_submoney( $amount, $net_captured );

		// Never exceed the remaining authorized amount
		$remaining_on_auth = wc_scanpay_submoney( $meta['authorized'], $meta['captured'] );
		if ( wc_scanpay_cmpmoney( $to_capture, $remaining_on_auth ) > 0 ) {
			$to_capture = $remaining_on_auth;
		}

		if ( wc_scanpay_cmpmoney( $to_capture, '0' ) <= 0 ) {
			return [
				'status' => 'skipped',
				'msg'    => 'nothing left to capture',
			];
		}
		try {
			$total  = $to_capture . ' ' . $wco->get_currency( 'edit' );
			$client = new WC_Scanpay_Client( $apikey );
			$client->capture(
				(int) $meta['id'],
				[
					'total' => $total,
					'index' => (int) $meta['nacts'],
				]
			);
			return [
				'status' => 'ok',
				'msg'    => $total,
			];
		} catch ( \Exception $e ) {
			return [
				'status' => 'failed',
				'msg'    => trim( $e->getMessage() ),
			];
		}
	}
}
