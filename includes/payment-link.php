<?php

defined( 'ABSPATH' ) || exit();

require WC_SCANPAY_DIR . '/library/client.php';
require WC_SCANPAY_DIR . '/library/math.php';

// phpcs:ignore WordPress.Security.NonceVerification.Missing
if ( isset( $_POST['wcssp-terms-field'] ) && ! isset( $_POST['wcssp-terms'] ) ) {
	wc_add_notice(
		'For at fortsætte, skal du acceptere abonnementsbetingelserne.',
		'error'
	);
	throw new Exception();
}

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

function wc_scanpay_subref( int $oid, object $wco ): ?string {
	if (
		class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false )
		&& WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment
	) {
		/*
		*   This only happens when the user changes PSP to us. This process DOES NOT
		*   create a new order; it only updates the payment method of the WCS Subscription.
		*/
		return 'wcs[]' . $oid; // $oid is the WCS subscription ID
	}
	/*
	*   Check if the order contains subs. Most PSPs use wcs_order_contains_subscription(), but it is
	*   incredibly inefficient. We can use wc_get_orders directly and optimize the search with status.
	*/
	$wcs_subs_arr = wc_get_orders(
		[
			'type'   => 'shop_subscription',
			'status' => ( $wco->get_status() === 'pending' ) ? 'wc-pending' : null,
			'parent' => $oid,
			'return' => 'ids', // array of ids (an order can have multiple subs)
		]
	);
	if ( $wcs_subs_arr ) {
		return 'wcs[]' . implode( ',', $wcs_subs_arr );
	}
	return null;
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

	$otype  = 'wc';
	$client = new WC_Scanpay_Client( $settings['apikey'] );
	$wcs    = class_exists( 'WC_Subscriptions', false );
	$coc    = 'yes' === ( $settings['capture_on_complete'] ?? '' );
	$subref = false;

	$data = [
		'orderid'     => (string) $oid,
		'autocapture' => $coc && ! $wco->needs_processing(),
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

	if ( $wcs ) {
		$subid = (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		if ( $subid ) {
			return [
				'result'   => 'success',
				'redirect' => $client->renew( $subid, $data ),
			];
		}
	}

	$currency = $wco->get_currency( 'edit' );
	$sum      = '0';
	foreach ( $wco->get_items( [ 'line_item', 'fee', 'shipping', 'coupon' ] ) as $id => $item ) {
		if ( $wcs && ! $subref && $item instanceof WC_Order_Item_Product ) {
			$product = $item->get_product();
			if ( $product && $product->is_type( 'subscription' ) ) {
				$subref = true;
			}
		}
		$line_total = $wco->get_line_total( $item, true, true ); // w. taxes and rounded (how Woo does)
		if ( $line_total >= 0 ) {
			$sum             = wc_scanpay_addmoney( $sum, (string) $line_total );
			$data['items'][] = [
				'name'     => $item->get_name( 'edit' ),
				'quantity' => $item->get_quantity(),
				'total'    => $line_total . ' ' . $currency,
			];
		}
	}

	$wc_totalf = $wco->get_total( 'edit' );
	$wc_total  = (string) $wc_totalf;
	if ( $sum !== $wc_total && wc_scanpay_cmpmoney( $sum, $wc_total ) !== 0 ) {
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
	if ( $subref ) {
		$subref = wc_scanpay_subref( $oid, $wco );
		if ( $subref ) {
			$data['subscriber'] = [ 'ref' => $subref ];
			$otype              = ( $wc_totalf > 0 ) ? 'wcs' : 'wcs_free';
			if ( $coc && ! $data['autocapture'] ) {
				$data['autocapture'] = ( 'yes' === $settings['wcs_complete_initial'] ?? '' );
			}
		}
	}
	// Update the success URL (args are used on the thank you page)
	$data['successurl'] = add_query_arg(
		[
			'scanpay_thankyou' => $oid,
			'scanpay_type'     => $otype,
			'scanpay_ref'      => $subref,
		],
		$data['successurl']
	);

	try {
		$link = $client->newURL( $data );
		$wco->add_meta_data( WC_SCANPAY_URI_PAYID, basename( $link ), true );
		$wco->add_meta_data( WC_SCANPAY_URI_PTIME, time(), true );
		$wco->add_meta_data( WC_SCANPAY_URI_SHOPID, $client->shopid, true );
		$wco->add_meta_data( WC_SCANPAY_URI_AUTOCPT, (string) $data['autocapture'], true );
		$wco->save_meta_data();
		return [
			'result'   => 'success',
			'redirect' => $link,
		];
	} catch ( Exception $e ) {
		scanpay_log( 'error', 'Payment link creation failed: ' . $e->getMessage() );
		throw new Exception( 'Error: We could not create a link to the payment window. Please wait a moment and try again.' );
	}
}
