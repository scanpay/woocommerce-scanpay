<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
require WC_SCANPAY_DIR . '/library/math.php';

function wc_scanpay_phone_prefixer( string $phone, string $country ): string {
	if ( ! empty( $phone ) ) {
		$first_number = substr( $phone, 0, 1 );
		if ( '+' !== $first_number && '0' !== $first_number ) {
			// get_country_calling_code() returns '' -- never null -- for an absent or
			// unknown country, so an isset() check would pass and prefix " 12345678".
			// is_string() stays because the upstream docblock declares string|array,
			// and a filter can still hand us an array.
			$code = WC()->countries->get_country_calling_code( $country );
			if ( is_string( $code ) && '' !== $code ) {
				return $code . ' ' . $phone;
			}
		}
	}
	return $phone;
}

function wc_scanpay_subref( int $oid, object $wco ): ?string {
	if ( wcs_scanpay_is_payment_method_change() ) {
		/*
		 * Switching an existing subscription to us. No new order is created here, only
		 * the subscription's payment method changes -- so $oid is the WCS subscription
		 * id, not an order id.
		 */
		return 'wcs[]' . $oid;
	}
	/*
	 * wc_get_orders() rather than wcs_order_contains_subscription(): the same search,
	 * but narrowed by status.
	 *
	 * The fallback must be 'all', never null: null is not passed through to post_status
	 * at all, so WP_Query falls back to public statuses only, and every order status is
	 * non-public -- the query would match nothing.
	 */
	$wcs_subs_arr = wc_get_orders(
		[
			'type'   => 'shop_subscription',
			'status' => ( $wco->get_status() === 'pending' ) ? 'wc-pending' : 'all',
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
		throw new Exception( esc_html__( 'Error: The order does not exist. Please create a new order or contact support.', 'scanpay-for-woocommerce' ) );
	}
	if ( empty( $settings['apikey'] ) ) {
		scanpay_log( 'error', 'Cannot create payment link: Missing or invalid API key.' );
		throw new Exception( esc_html__( 'Error: The payment plugin is not configured. Please contact support.', 'scanpay-for-woocommerce' ) );
	}

	scanpay_log( 'info', "Creating payment link for Order #$oid." );

	$otype               = 'wc';
	$client              = new WC_Scanpay_Client( (string) $settings['apikey'] );
	$autocapture         = $settings['wc_autocapture'] ?? 'completed';
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

	$wcs = class_exists( 'WC_Subscriptions', false ) && method_exists( 'WC_Subscriptions_Product', 'is_subscription' );
	if ( $wcs ) {
		$subid = (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		if ( $subid ) {
			/*
			 * A customer paying for an existing subscriber: a failed renewal they retry, or
			 * a resubscribe. Both take wcs_complete_renewal -- the payment mechanically is a
			 * renew() against the existing subscriber, and wcs_complete_initial has only ever
			 * applied to the new_url() sign-up path, so no resubscribe test is needed here.
			 *
			 * The exception is a payment-method change, which is not a paid order attempt:
			 * it gets no completion intent, and writes no metadata at all -- $wco is the
			 * subscription there, and a key on the subscription would be copied onto every
			 * future renewal order by WC_Subscriptions_Data_Copier.
			 */
			$paid_renewal = ! wcs_scanpay_is_payment_method_change();
			$complete     = $paid_renewal && wcs_scanpay_wants_completion( $settings, 'renewal' );
			if ( $complete && 'completed' === $autocapture ) {
				// Completion means the order is settled, so the attempt must capture. Already
				// true under 'on', and deliberately left false under 'off'.
				$data['autocapture'] = true;
			}
			if ( $paid_renewal ) {
				/*
				 * Route the return through the same bounded wait an ordinary paid order gets.
				 * This payment does create order data: sync writes the transaction id onto the
				 * order named by $data['orderid'], which here is the renewal order, so without
				 * the wait the order-received page can render before the ping lands. Type 'wc'
				 * selects the paid-order wait; scanpay_ref is read only by the free-trial
				 * branch, so there is nothing to invent. The WooCommerce order key is already
				 * in the filtered URL, so the handler's ownership gate still applies.
				 *
				 * A pure method change keeps the WCS-filtered My Account URL untouched: it
				 * creates no order transaction, so there is nothing for it to wait on.
				 */
				$data['successurl'] = add_query_arg(
					[
						'scanpay_thankyou' => $oid,
						'scanpay_type'     => 'wc',
					],
					$data['successurl']
				);
			}
			try {
				$link = $client->renew( $subid, $data );
			} catch ( Exception $e ) {
				scanpay_log( 'error', 'Renewal link creation failed: ' . trim( $e->getMessage() ) );
				throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
			}
			if ( $paid_renewal ) {
				// Written after the link exists and before the customer can pay it, and
				// unconditionally, so a retry under changed settings replaces the old intent
				// rather than leaving a stale one.
				$wco->add_meta_data( WC_SCANPAY_URI_COMPLETE, $complete && $data['autocapture'], true );
				$wco->save_meta_data();
			}
			return [
				'result'   => 'success',
				'redirect' => $link,
			];
		}
	}

	$subref   = false;
	$currency = $wco->get_currency( 'edit' );
	$sum      = '0';
	foreach ( $wco->get_items( [ 'line_item', 'fee', 'shipping' ] ) as $id => $item ) {
		if ( $wcs && ! $subref && $item instanceof WC_Order_Item_Product ) {
			$product = $item->get_product();
			if ( $product && WC_Subscriptions_Product::is_subscription( $product ) ) {
				$subref = true;
			}
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
	$complete = false;
	if ( $subref ) {
		$subref = wc_scanpay_subref( $oid, $wco );
		if ( $subref ) {
			$data['subscriber'] = [ 'ref' => $subref ];
			$otype              = ( $wc_totalf > 0 ) ? 'wcs' : 'wcs_free';
			// This is the initial order of a subscription, so wcs_complete_initial applies.
			// Settling at Scanpay and completing in WooCommerce are separate outcomes of the
			// one setting: capture now rather than on completion, and complete the order
			// once the payment syncs. Both need a capture, hence the 'completed' condition
			// -- 'on' already captures, and 'off' must keep doing neither.
			$complete = wcs_scanpay_wants_completion( $settings, 'initial' );
			if ( $complete && 'completed' === $autocapture ) {
				$data['autocapture'] = true;
			}
		}
	}
	// The router dispatches on scanpay_thankyou + scanpay_type (plus WooCommerce's own
	// ?key, already in the URL); scanpay_ref is what the free-trial branch polls.
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
		// Rides along with the writes above: no payment can exist before new_url() returns,
		// and $unique replaces the previous attempt's value rather than keeping it, so the
		// newest link -- the one the customer can still pay -- is the one described here.
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
