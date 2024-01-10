<?php

defined( 'ABSPATH' ) || exit();

function wc_scanpay_payment_link( int $orderid ): string {
	require WC_SCANPAY_DIR . '/library/client.php';
	require WC_SCANPAY_DIR . '/library/math.php';

	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	$apikey   = $settings['apikey'] ?? '';
	$shopid   = (int) explode( ':', $apikey )[0];
	if ( ! $shopid ) {
		scanpay_log( 'alert', 'Missing or invalid Scanpay API key' );
		throw new \Exception( 'Error: The Scanpay API key is invalid. Please contact the shop.' );
	}
	$order    = wc_get_order( $orderid );
	$currency = $order->get_currency();
	$country  = $order->get_billing_country();
	$phone    = $order->get_billing_phone();

	if ( ! empty( $phone ) ) {
		$first_number = substr( $phone, 0, 1 );
		if ( '+' !== $first_number && '0' !== $first_number ) {
			$code = WC()->countries->get_country_calling_code( $country );
			if ( isset( $code ) ) {
				$phone = $code . ' ' . $phone;
			}
		}
	}

	$data = [
		'orderid'    => strval( $orderid ),
		'successurl' => $order->get_checkout_order_received_url(),
		'billing'    => array_filter(
			[
				'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'email'   => $order->get_billing_email(),
				'phone'   => $phone,
				'address' => array_filter( [ $order->get_billing_address_1(), $order->get_billing_address_2() ] ),
				'city'    => $order->get_billing_city(),
				'zip'     => $order->get_billing_postcode(),
				'country' => $country,
				'state'   => $order->get_billing_state(),
				'company' => $order->get_billing_company(),
			]
		),
		'shipping'   => array_filter(
			[
				'name'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'address' => array_filter( [ $order->get_shipping_address_1(), $order->get_shipping_address_2() ] ),
				'city'    => $order->get_shipping_city(),
				'zip'     => $order->get_shipping_postcode(),
				'country' => $order->get_shipping_country(),
				'state'   => $order->get_shipping_state(),
				'company' => $order->get_shipping_company(),
			]
		),
	];

	$sum = '0';
	foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
		$line_total = $order->get_line_total( $item, true, true ); // w. taxes and rounded (how Woo does)
		if ( $line_total > 0 ) {
			$sum             = wc_scanpay_addmoney( $sum, strval( $line_total ) );
			$data['items'][] = [
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $line_total . ' ' . $currency,
			];
		} elseif ( $line_total < 0 ) {
			$data['items'] = null;
			break;
		}
	}

	if ( isset( $data['items'] ) && wc_scanpay_cmpmoney( $sum, strval( $order->get_total() ) ) !== 0 ) {
		$data['items'] = null;
		$errmsg        = sprintf(
			'The sum of all items (%s) does not match the order total (%s).' .
			'The item list will not be available in the scanpay dashboard.',
			$sum,
			$order->get_total()
		);
		$order->add_order_note( $errmsg );
		scanpay_log( 'warning', "Order #$orderid: $errmsg" );
	}

	if ( is_null( $data['items'] ) ) {
		$data['items'][] = [
			'name'  => 'Total',
			'total' => $order->get_total() . ' ' . $currency,
		];
	}

	$client = new WC_Scanpay_Client( $apikey );

	/*
		https://woocommerce.com/document/subscriptions/develop/payment-gateway-integration/
		Subscriptions
		Only new or renew
	*/
	if ( class_exists( 'WC_Subscriptions' ) && WC_Subscriptions_Order::order_contains_subscription( $orderid ) ) {
		$data['items']      = null;
		$data['subscriber'] = [ 'ref' => strval( $orderid ) ];

		// RENEW???
		if ( wcs_is_subscription( $order ) ) {
			scanpay_log( 'info', 'wcs_is_subscription' );
		}
	}

	try {
		$opts = [
			'headers' => [
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '',
			],
		];
		$link = $client->newURL( $data, $opts );
	} catch ( \Exception $e ) {
		scanpay_log( 'error', 'scanpay client exception: ' . $e->getMessage() );
		throw new \Exception(
			'Error: We could not create a link to the payment window. Please wait a moment and try again.'
		);
	}

	$order->set_status( 'wc-pending' );
	$order->add_meta_data( WC_SCANPAY_URI_PTIME, time(), true );
	$order->add_meta_data( WC_SCANPAY_URI_PAYID, basename( parse_url( $link, PHP_URL_PATH ) ), true );
	$order->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
	$order->save();
	return $link;
}
