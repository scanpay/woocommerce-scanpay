<?php

defined( 'ABSPATH' ) || exit();

function wc_scanpay_phone_prefixer( string $phone, string $country ): string {
	if ( ! empty( $phone ) ) {
		$first_number = substr( $phone, 0, 1 );
		if ( '+' !== $first_number && '0' !== $first_number ) {
			$code = WC()->countries->get_country_calling_code( $country );
			if ( isset( $code ) ) {
				return $code . ' ' . $phone;
			}
		}
	}
	return $phone;
}

/*
	Create a regular (one-off) payment link.
*/
function wc_scanpay_payment_link( object $order, array $settings ): string {
	require WC_SCANPAY_DIR . '/library/client.php';
	require WC_SCANPAY_DIR . '/library/math.php';

	$data = [
		'autocapture' => $auto_capture,
		'orderid'     => (string) $order->get_id(),
		'successurl'  => apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order ),
		'lifetime'    => '30m',
		'billing'     => [
			'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'email'   => $order->get_billing_email(),
			'phone'   => wc_scanpay_phone_prefixer( $order->get_billing_phone(), $order->get_billing_country() ),
			'address' => [ $order->get_billing_address_1(), $order->get_billing_address_2() ],
			'city'    => $order->get_billing_city(),
			'zip'     => $order->get_billing_postcode(),
			'country' => $order->get_billing_country(),
			'state'   => $order->get_billing_state(),
			'company' => $order->get_billing_company(),
		],
		'shipping'    => [
			'name'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
			'address' => [ $order->get_shipping_address_1(), $order->get_shipping_address_2() ],
			'city'    => $order->get_shipping_city(),
			'zip'     => $order->get_shipping_postcode(),
			'country' => $order->get_shipping_country(),
			'state'   => $order->get_shipping_state(),
			'company' => $order->get_shipping_company(),
		],
	];
	if ( ( 'yes' === $settings['capture_on_complete'] ) && ! $order->needs_processing() ) {
		$data['autocapture'] = true;
		$order->add_meta_data( WC_SCANPAY_URI_AUTOCPT, 1, true );
	}

	$sum = '0';
	foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
		$line_total = $order->get_line_total( $item, true, true ); // w. taxes and rounded (how Woo does)
		if ( $line_total >= 0 ) {
			$sum             = wc_scanpay_addmoney( $sum, strval( $line_total ) );
			$data['items'][] = [
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'total'    => $line_total . ' ' . $order->get_currency(),
			];
		}
	}

	if ( wc_scanpay_cmpmoney( $sum, strval( $order->get_total() ) ) !== 0 ) {
		$data['items'][] = [
			'name'  => 'Total',
			'total' => $order->get_total() . ' ' . $order->get_currency(),
		];
		$errmsg          = sprintf(
			'The sum of all items (%s) does not match the order total (%s).' .
			'The item list will not be available in the scanpay dashboard.',
			$sum,
			$order->get_total()
		);
		$order->add_order_note( $errmsg );
		scanpay_log( 'warning', "Order #$orderid: $errmsg" );
	}

	$shopid = (int) explode( ':', $settings['apikey'] )[0];
	$client = new WC_Scanpay_Client( $settings['apikey'] );
	$link   = $client->newURL( $data );
	$order->set_status( 'wc-pending' );
	$order->add_meta_data( WC_SCANPAY_URI_PTIME, time(), true );
	$order->add_meta_data( WC_SCANPAY_URI_PAYID, basename( parse_url( $link, PHP_URL_PATH ) ), true );
	$order->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
	$order->save();
	return $link;
}

/*
	Subscriptions: Create a subscribe link to the payment window.
*/
function wcs_scanpay_subscription_link( object $order, array $settings ): string {
	require WC_SCANPAY_DIR . '/library/client.php';
	$data = [
		'subscriber' => [ 'ref' => (string) $order->get_id() ],
		'successurl' => apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order ),
		'lifetime'   => '30m',
		'billing'    => [
			'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'email'   => $order->get_billing_email(),
			'phone'   => wc_scanpay_phone_prefixer( $order->get_billing_phone(), $order->get_billing_country() ),
			'address' => [ $order->get_billing_address_1(), $order->get_billing_address_2() ],
			'city'    => $order->get_billing_city(),
			'zip'     => $order->get_billing_postcode(),
			'country' => $order->get_billing_country(),
			'state'   => $order->get_billing_state(),
			'company' => $order->get_billing_company(),
		],
		'shipping'   => [
			'name'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
			'address' => [ $order->get_shipping_address_1(), $order->get_shipping_address_2() ],
			'city'    => $order->get_shipping_city(),
			'zip'     => $order->get_shipping_postcode(),
			'country' => $order->get_shipping_country(),
			'state'   => $order->get_shipping_state(),
			'company' => $order->get_shipping_company(),
		],
	];

	$shopid = (int) explode( ':', $settings['apikey'] )[0];
	$client = new WC_Scanpay_Client( $settings['apikey'] );
	$link   = $client->newURL( $data );
	$order->set_status( 'wc-pending' );
	$order->add_meta_data( WC_SCANPAY_URI_PAYID, basename( parse_url( $link, PHP_URL_PATH ) ), true );
	$order->add_meta_data( WC_SCANPAY_URI_PTIME, time(), true );
	$order->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
	$order->save();
	return $link;
}

/*
	Subscriptions: Let cardholder renew payment method on account page
*/
function wcs_scanpay_renew_method( object $order, array $settings ): void {
	require WC_SCANPAY_DIR . '/library/client.php';
	$client = new WC_Scanpay_Client( $settings['apikey'] );
	$data   = [
		'successurl' => get_permalink( wc_get_page_id( 'myaccount' ) ), // TODO: add query param
		'lifetime'   => '30m',
		'billing'    => [
			'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'email'   => $order->get_billing_email(),
			'phone'   => wc_scanpay_phone_prefixer( $order->get_billing_phone(), $order->get_billing_country() ),
			'address' => [ $order->get_billing_address_1(), $order->get_billing_address_2() ],
			'city'    => $order->get_billing_city(),
			'zip'     => $order->get_billing_postcode(),
			'country' => $order->get_billing_country(),
			'state'   => $order->get_billing_state(),
			'company' => $order->get_billing_company(),
		],
	];
	$subid  = (int) $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
	if ( 0 === $subid ) {
		throw new \Exception( 'Error: The order does not contain a subscription ID' );
	}
	// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	wp_redirect( $client->renew( $subid, $data ) );
	exit;
}


function wc_scanpay_process_payment( int $orderid ): array {
	$order    = wc_get_order( $orderid );
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! $order ) {
		throw new \Exception( 'Error: The order does not exist.' );
	}
	if ( empty( $settings['apikey'] ) ) {
		throw new \Exception( 'Error: The Scanpay API key is not set.' );
	}

	try {
		if ( class_exists( 'WC_Subscriptions', false ) ) {
			if (
				class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) &&
				WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment
			) {
				return wcs_scanpay_renew_method( $order, $settings );
			}
			if ( wcs_order_contains_subscription( $order ) ) {
				return [
					'result'   => 'success',
					'redirect' => wcs_scanpay_subscription_link( $order, $settings ),
				];
			}
		}
		return [
			'result'   => 'success',
			'redirect' => wc_scanpay_payment_link( $order, $settings ),
		];

	} catch ( \Exception $e ) {
		scanpay_log( 'error', 'scanpay paylink exception: ' . $e->getMessage() );
		throw new \Exception( 'Error: We could not create a link to the payment window. Please wait a moment and try again.' );
	}
}
