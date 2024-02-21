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

function wc_scanpay_process_payment( int $oid, array $settings ): array {
	$order = wc_get_order( $oid );
	if ( ! $order ) {
		scanpay_log( 'error', "Cannot create payment link: Order #$oid does not exist." );
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
			'name'    => $order->get_billing_first_name( 'edit' ) . ' ' . $order->get_billing_last_name( 'edit' ),
			'email'   => $order->get_billing_email( 'edit' ),
			'phone'   => wc_scanpay_phone_prefixer( $order->get_billing_phone( 'edit' ), $order->get_billing_country( 'edit' ) ),
			'address' => [ $order->get_billing_address_1( 'edit' ), $order->get_billing_address_2( 'edit' ) ],
			'city'    => $order->get_billing_city( 'edit' ),
			'zip'     => $order->get_billing_postcode( 'edit' ),
			'country' => $order->get_billing_country( 'edit' ),
			'state'   => $order->get_billing_state( 'edit' ),
			'company' => $order->get_billing_company( 'edit' ),
		],
		'shipping'    => [
			'name'    => $order->get_shipping_first_name( 'edit' ) . ' ' . $order->get_shipping_last_name( 'edit' ),
			'address' => [ $order->get_shipping_address_1( 'edit' ), $order->get_shipping_address_2( 'edit' ) ],
			'city'    => $order->get_shipping_city( 'edit' ),
			'zip'     => $order->get_shipping_postcode( 'edit' ),
			'country' => $order->get_shipping_country( 'edit' ),
			'state'   => $order->get_shipping_state( 'edit' ),
			'company' => $order->get_shipping_company( 'edit' ),
		],
	];

	if ( class_exists( 'WC_Subscriptions', false ) ) {
		$subid = (int) $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		if ( $subid ) {
			// Update payment method (resubscribe)
			$data['successurl'] = get_permalink( wc_get_page_id( 'myaccount' ) );
			wp_redirect( $client->renew( $subid, $data ) );
			exit;
		}
		/*
			Check if the order has any subscriptions. We don't want to use wcs_order_contains_subscription();
			it has a CRAZY overhead and loads the subscriptions, which is a waste of resources.
		*/
		if ( wc_get_orders(
			[
				'type'   => 'shop_subscription',
				'status' => 'wc-pending',
				'parent' => $oid,
				'return' => 'ids',
			]
		) ) {
			$data['subscriber'] = [ 'ref' => (string) $oid ];
		}
	}

	if ( ! isset( $data['subscriber'] ) ) {
		$data['orderid'] = (string) $order->get_id();

		$virtual  = ( 'yes' === $settings['wc_complete_virtual'] );
		$sum      = '0';
		$currency = $order->get_currency( 'edit' );
		foreach ( $order->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
			if ( $virtual && $item instanceof WC_Order_Item_Product ) {
				$product = $item->get_product();
				if ( $product && ! $product->is_virtual() ) {
					$virtual = false;
				}
			}
			$line_total = $order->get_line_total( $item, true, true ); // w. taxes and rounded (how Woo does)
			if ( $line_total >= 0 ) {
				$sum             = wc_scanpay_addmoney( $sum, strval( $line_total ) );
				$data['items'][] = [
					'name'     => $item->get_name( 'edit' ),
					'quantity' => $item->get_quantity(),
					'total'    => $line_total . ' ' . $currency,
				];
			}
		}
		if ( $settings['wc_complete_virtual'] ) {
			// Set transient so needs_processing() does not need to check all items (again)
			set_transient( 'wc_order_' . $order->get_id() . '_needs_processing', (int) ! $virtual, DAY_IN_SECONDS );
		}

		$wc_total = strval( $order->get_total( 'edit' ) );
		if ( wc_scanpay_cmpmoney( $sum, $wc_total ) !== 0 ) {
			$data['items'] = [
				[
					'name'  => 'Total',
					'total' => $wc_total . ' ' . $currency,
				],
			];
			scanpay_log(
				'warning',
				"Order #$oid: The sum of all items ($sum) does not match the order total ($wc_total)." .
				'The item list will not be available in the scanpay dashboard.'
			);
		}

		if ( 'yes' === $settings['capture_on_complete'] && ( $virtual || ! $order->needs_processing() ) ) {
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
