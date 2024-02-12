<?php

defined( 'ABSPATH' ) || exit();

require WC_SCANPAY_DIR . '/library/client.php';
require WC_SCANPAY_DIR . '/library/math.php';

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

function wc_scanpay_process_payment( int $orderid, array $settings ): array {
	$order = wc_get_order( $orderid );
	if ( ! $order ) {
		scanpay_log( 'error', "Cannot create payment link: Order #$orderid does not exist." );
		throw new Exception( 'Error: The order does not exist. Please create a new order or contact support.' );
	}
	if ( empty( $settings['apikey'] ) ) {
		scanpay_log( 'error', 'Cannot create payment link: Missing or invalid API key.' );
		throw new Exception( 'Error: The payment plugin is not configured. Please contact support.' );
	}

	$client = new WC_Scanpay_Client( $settings['apikey'] );
	$data   = [
		'autocapture' => false,
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

	if ( class_exists( 'WC_Subscriptions', false ) ) {
		$subid = (int) $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		if ( $subid ) {
			// Update payment method
			$data['successurl'] = get_permalink( wc_get_page_id( 'myaccount' ) );
			wp_redirect( $client->renew( $subid, $data ) );
			exit;
		}
		if ( wcs_order_contains_subscription( $order ) ) {
			// New subscriber
			$data['subscriber'] = [ 'ref' => (string) $order->get_id() ];
		}
	}

	if ( ! isset( $data['subscriber'] ) ) {
		$data['orderid'] = (string) $order->get_id();

		// Add and sum order items
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

		$wc_total = strval( $order->get_total() );
		if ( wc_scanpay_cmpmoney( $sum, $wc_total ) !== 0 ) {
			$data['items'] = [
				[
					'name'  => 'Total',
					'total' => $wc_total . ' ' . $order->get_currency(),
				],
			];
			scanpay_log(
				'warning',
				"Order #$orderid: The sum of all items ($sum) does not match the order total ($wc_total)." .
				'The item list will not be available in the scanpay dashboard.'
			);
		}

		if ( 'yes' === $settings['capture_on_complete'] && ! $order->needs_processing() ) {
			$data['autocapture'] = true;
			$order->add_meta_data( WC_SCANPAY_URI_AUTOCPT, 1, true );
		}
	}

	try {
		$link = $client->newURL( $data );
		$order->add_meta_data( WC_SCANPAY_URI_PAYID, basename( parse_url( $link, PHP_URL_PATH ) ), true );
		$order->add_meta_data( WC_SCANPAY_URI_PTIME, time(), true );
		$order->add_meta_data( WC_SCANPAY_URI_SHOPID, (int) explode( ':', $settings['apikey'] )[0], true );
		$order->save_meta_data();
		return [
			'result'   => 'success',
			'redirect' => $link,
		];
	} catch ( Exception $e ) {
		scanpay_log( 'error', 'scanpay paylink exception: ' . $e->getMessage() );
		throw new Exception( 'Error: We could not create a link to the payment window. Please wait a moment and try again.' );
	}
}
