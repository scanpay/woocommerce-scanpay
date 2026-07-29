<?php

/**
 * Checkout: turning a WooCommerce order into a Scanpay payment link. All three gateways'
 * process_payment() end here, and so do the subscription paths -- but a subscription is the
 * only thing this file does not decide for itself: wcs-generate-payment-link.php owns which
 * Scanpay endpoint those need and what they add to the payload, and is asked at three points
 * below. Read them together; nothing else here knows WooCommerce Subscriptions exists.
 *
 * Everything here runs inside WC_Checkout::process_checkout(), which catches Exception and
 * puts the message straight in front of the shopper, so the messages thrown are written
 * for them and the diagnostics go to the log.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// require_once, not require: a bare require would redeclare. The reachable case is a third
// party completing the order on woocommerce_checkout_order_processed, which fires before
// process_order_payment() and pulls in the capture class.
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
require_once WC_SCANPAY_DIR . '/library/math.php';
// Unconditional, unlike public/subscriptions.php: this one registers no hooks and runs nothing
// at require time. Its wcs_scanpay_active() is the gate, and it must be callable to be asked.
require_once WC_SCANPAY_DIR . '/public/wcs-generate-payment-link.php';

function wc_scanpay_phone_prefixer( string $phone, string $country ): string {
	if ( ! empty( $phone ) ) {
		$first_number = substr( $phone, 0, 1 );
		if ( '+' !== $first_number && '0' !== $first_number ) {
			// get_country_calling_code() returns '' -- never null -- for an unknown country,
			// so an isset() check would pass and prefix " 12345678". is_string() is for the
			// declared @return string|array, not for a filter: the method applies none and
			// unwraps the array itself, but the WC 3.6 floor is far below the version read
			// to establish that.
			$code = WC()->countries->get_country_calling_code( $country );
			if ( is_string( $code ) && '' !== $code ) {
				return $code . ' ' . $phone;
			}
		}
	}
	return $phone;
}

function wc_scanpay_process_payment( int $oid, array $settings ): array {
	$wco = wc_get_order( $oid );
	if ( ! $wco ) {
		scanpay_log( 'error', "Cannot create payment link: Order #$oid does not exist." );
		throw new Exception( esc_html__( 'Error: The order does not exist. Please create a new order or contact support.', 'scanpay-for-woocommerce' ) );
	}
	if ( empty( $settings['apikey'] ) ) {
		scanpay_log( 'error', 'Cannot create payment link: Missing or invalid API key.' );
		throw new Exception( esc_html__( 'Error: The payment plugin is not configured. Please contact support.', 'scanpay-for-woocommerce' ) );
	}

	scanpay_log( 'info', "Creating payment link for Order #$oid." );

	$otype  = 'wc';
	$subref = null;
	$client = new WC_Scanpay_Client( (string) $settings['apikey'] );
	// Cast because the value is passed on as a string: a stored non-string is a corrupt
	// option, and 'Array' matching neither 'on' nor 'completed' is the safe reading of one.
	$autocapture         = (string) ( $settings['wc_autocapture'] ?? 'completed' );
	$capture_on_complete = 'completed' === $autocapture && ! $wco->needs_processing();

	$data = [
		'orderid'     => (string) $oid,
		'autocapture' => $capture_on_complete || 'on' === $autocapture,
		'successurl'  => apply_filters( 'woocommerce_get_return_url', $wco->get_checkout_order_received_url(), $wco ),
		'lifetime'    => '15m',
		'billing'     => [
			'name'    => $wco->get_billing_first_name( 'edit' ) . ' ' . $wco->get_billing_last_name( 'edit' ),
			'email'   => $wco->get_billing_email( 'edit' ),
			'phone'   => wc_scanpay_phone_prefixer( $wco->get_billing_phone( 'edit' ), $wco->get_billing_country( 'edit' ) ),
			'address' => [ $wco->get_billing_address_1( 'edit' ), $wco->get_billing_address_2( 'edit' ) ],
			'city'    => $wco->get_billing_city( 'edit' ),
			'zip'     => $wco->get_billing_postcode( 'edit' ),
			'country' => $wco->get_billing_country( 'edit' ),
			'state'   => $wco->get_billing_state( 'edit' ),
			'company' => $wco->get_billing_company( 'edit' ),
		],
		'shipping'    => [
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
	 * A renewal, a resubscribe or a payment-method change is not a payment for goods: it needs
	 * a Scanpay endpoint of its own and must never reach the item build below. Those are the
	 * only paths that end here without one, so a link back means the work is done -- the WCS
	 * half has already written whatever meta and order note its path calls for.
	 */
	$wcs = wcs_scanpay_active();
	if ( $wcs ) {
		$link = wcs_scanpay_payment_link( $oid, $wco, $settings, $data, $client );
		if ( null !== $link ) {
			return [
				'result'   => 'success',
				'redirect' => $link,
			];
		}
	}

	/*
	 * The whole item build is contained, not just the two money calls: every value read here
	 * passes through a filter a third party owns. get_line_total() runs
	 * woocommerce_order_amount_line_total, and a callback returning null survives the ">= 0"
	 * guard -- PHP compares null >= 0 as booleans -- becomes '' in wc_format_decimal(), and
	 * makes wc_scanpay_addmoney() throw. Uncontained, the shopper reads "invalid money
	 * amount: '0' or ''" at checkout.
	 */
	try {
		$has_sub  = false;
		$currency = $wco->get_currency( 'edit' );
		$sum      = '0';
		foreach ( $wco->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $id => $item ) {
			if ( $wcs && ! $has_sub && wcs_scanpay_item_is_subscription( $item ) ) {
				$has_sub = true;
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

		$wc_total = (string) $wco->get_total( 'edit' );
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
	} catch ( \Throwable $e ) {
		// \Throwable, because an Error out of a filter callback would be an uncaught fatal
		// rather than a notice. Rethrown, never degraded: $has_sub, $sum and $data['items']
		// must not be read half-built below.
		scanpay_log( 'error', "Order #$oid: could not build the item list: " . trim( $e->getMessage() ) );
		throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
	}
	// The initial order of a subscription: an ordinary payment that also registers a
	// subscriber. Null means no subscription id resolved, and it stays an ordinary payment.
	$complete = false;
	if ( $has_sub ) {
		$wcs_order = wcs_scanpay_initial_payment( $oid, $wco, $settings, $autocapture );
		if ( $wcs_order ) {
			$subref             = $wcs_order['ref'];
			$otype              = $wcs_order['otype'];
			$complete           = $wcs_order['complete'];
			$data['subscriber'] = [ 'ref' => $subref ];
			if ( $wcs_order['capture'] ) {
				$data['autocapture'] = true;
			}
		}
	}
	// The router dispatches on scanpay_thankyou and scanpay_type, plus WooCommerce's own
	// ?key already in the URL; scanpay_ref is what the free-trial branch polls. A null
	// scanpay_ref is dropped by add_query_arg(), so an ordinary order carries neither.
	$data['successurl'] = add_query_arg(
		[
			'scanpay_thankyou' => $oid,
			'scanpay_type'     => $otype,
			'scanpay_ref'      => $subref,
		],
		$data['successurl']
	);

	try {
		$link   = $client->new_url( $data );
		$shopid = (int) strstr( (string) ( $settings['apikey'] ?? '' ), ':', true );
		$wco->add_meta_data( WC_SCANPAY_URI_PAYID, basename( $link ), true );
		$wco->add_meta_data( WC_SCANPAY_URI_PTIME, time(), true );
		$wco->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
		// Rides along with the writes above: no payment exists before new_url() returns, and
		// $unique replaces the previous attempt's value, so the newest link -- the one the
		// customer can still pay -- is the one described here.
		$wco->add_meta_data( WC_SCANPAY_URI_COMPLETE, $complete && $data['autocapture'], true );
		$wco->save_meta_data();
		return [
			'result'   => 'success',
			'redirect' => $link,
		];
	} catch ( Exception $e ) {
		scanpay_log( 'error', 'Payment link creation failed: ' . trim( $e->getMessage() ) );
		throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
	}
}
