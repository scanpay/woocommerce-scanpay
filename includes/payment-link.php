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
	$wco = wc_get_order( $oid );
	if ( ! $wco ) {
		scanpay_log( 'error', "Cannot create payment link: Order #$oid does not exist." );
		throw new Exception( 'Error: The order does not exist. Please create a new order or contact support.' );
	}
	if ( empty( $settings['apikey'] ) ) {
		scanpay_log( 'error', 'Cannot create payment link: Missing or invalid API key.' );
		throw new Exception( 'Error: The payment plugin is not configured. Please contact support.' );
	}

	$client = new WC_Scanpay_Client( $settings['apikey'] );
	$data   = [
		'autocapture' => 'yes' === ( $settings['capture_on_complete'] ?? 'no' ) && ! $wco->needs_processing(),
		'successurl'  => apply_filters( 'woocommerce_get_return_url', $wco->get_checkout_order_received_url(), $wco ),
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

	if ( class_exists( 'WC_Subscriptions', false ) ) {
		$subid = (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		if ( $subid ) {
			return [
				'result'   => 'success',
				'redirect' => $client->renew( $subid, $data ),
			];
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
		$data['orderid'] = (string) $oid;
		$currency        = $wco->get_currency( 'edit' );
		$order_total     = '0';

		foreach ( $wco->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
			$line_total = $wco->get_line_total( $item, true, true ); // w. taxes and rounded (how Woo does)
			if ( $line_total >= 0 ) {
				$order_total     = wc_scanpay_addmoney( $order_total, strval( $line_total ) );
				$data['items'][] = [
					'name'     => $item->get_name( 'edit' ),
					'quantity' => $item->get_quantity(),
					'total'    => $line_total . ' ' . $currency,
				];
			}
		}
		$wc_total = strval( $wco->get_total( 'edit' ) );
		if ( $wc_total !== $order_total && wc_scanpay_cmpmoney( $order_total, $wc_total ) !== 0 ) {
			$data['items'] = [
				[
					'name'  => 'Total',
					'total' => $wc_total . ' ' . $currency,
				],
			];
			scanpay_log(
				'warning',
				"Order #$oid: The sum of all items ($order_total) does not match the order total ($wc_total)." .
				'The item list will not be available in the scanpay dashboard.'
			);
		}
	}

	try {
		$link = $client->newURL( $data );
		$wco->add_meta_data( WC_SCANPAY_URI_PAYID, basename( $link ), true );
		$wco->add_meta_data( WC_SCANPAY_URI_PTIME, time(), true );
		$wco->add_meta_data( WC_SCANPAY_URI_SHOPID, $client->shopid, true );
		$wco->add_meta_data( WC_SCANPAY_URI_AUTOCPT, (int) $data['autocapture'], true );
		$wco->save_meta_data();

		return [
			'result'   => 'success',
			'redirect' => $link,
		];
	} catch ( Exception $e ) {
		scanpay_log( 'error', 'scanpay paylink exception: ' . $e->getMessage() );
		throw new Exception( 'Error: We could not create a link to the payment window. Please wait a moment and try again.' );
	}
}
