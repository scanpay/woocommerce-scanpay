<?php

/**
 * Checkout: turning a WooCommerce order into a Scanpay payment link. All three gateways'
 * process_payment() end here, and so do the subscription paths -- a first subscription
 * order, a renewal retry, a resubscribe and a payment-method change each need a different
 * Scanpay endpoint and a different payload.
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

function wc_scanpay_subref( int $oid, WC_Abstract_Order $wco ): ?string {
	if ( wcs_scanpay_is_payment_method_change() ) {
		/*
		 * A payment-method change creates no order, so $oid is the WCS subscription id.
		 *
		 * Reachable only from the method-change branch below, which returns above the item
		 * build; from the other call site this looks dead. Not inlined there, because this
		 * is the one place the wcs[] ref format is written.
		 */
		return 'wcs[]' . $oid;
	}
	/*
	 * wc_get_orders() rather than wcs_order_contains_subscription(): the same search,
	 * narrowed by status. The fallback must be 'all', never null -- null never reaches
	 * post_status, so WP_Query falls back to public statuses, and every order status is
	 * non-public.
	 */
	$wcs_subs_arr = wc_get_orders(
		[
			'type'   => 'shop_subscription',
			'status' => ( $wco->get_status() === 'pending' ) ? 'wc-pending' : 'all',
			'parent' => $oid,
			'return' => 'ids', // array of ids (an order can have multiple subs)
			// A protocol value, not a page of results: every id becomes part of
			// subscriber.ref, and one missing from it is a subscription sync never links,
			// whose renewals then fail forever. Without this WC_Object_Query supplies
			// get_option( 'posts_per_page' ), ten on a default install.
			'limit'  => -1,
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
			 * An existing subscriber, reached three ways -- a retry of a failed renewal, a
			 * resubscribe, and a payment-method change -- and renew() gives all three the
			 * same link.
			 *
			 * /v1/subscribers/{subid}/renew charges nothing, despite its name: it returns a
			 * page where the customer updates their stored payment details. So this branch
			 * creates no transaction, has nothing for the return page to wait on, and
			 * records no completion intent. The $data['autocapture'] computed above rides
			 * along inert, so the payload shape stays the same on every path.
			 *
			 * $paid_renewal separates what still differs: only a real order gets the note
			 * and the stamp below. On a method change $wco is the WCS subscription, and any
			 * key written there is copied onto every future renewal order.
			 *
			 * The money is collected later by WCS's own retry, which the card update does
			 * unblock: the subscriber rev bumps, so idempotency_key() builds a new key and
			 * the next scheduled charge is not deduped against the declined one.
			 */
			$paid_renewal = ! wcs_scanpay_is_payment_method_change();
			try {
				$link = $client->renew( $subid, $data );
			} catch ( Exception $e ) {
				scanpay_log( 'error', 'Renewal link creation failed: ' . trim( $e->getMessage() ) );
				throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
			}
			if ( $paid_renewal ) {
				// Which shop the subscriber belongs to. A merchant who switches API keys
				// between the card update and the retry then gets scheduled_charge()'s refusal
				// rather than a charge against a numerically colliding subid in the new shop.
				// Only when absent; a different shop id is a real mismatch.
				if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) <= 0 ) {
					$shopid = (int) strstr( (string) ( $settings['apikey'] ?? '' ), ':', true );
					$wco->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
				}
				$wco->save_meta_data();
				// The customer followed a link labelled "Pay now" and returns to an order that
				// is still unpaid; this note is the only thing that tells them what happened.
				// Customer-visible (the 1), so WooCommerce lists it under "Order updates" and
				// mails it through woocommerce_new_customer_note.
				//
				// Contained, as everywhere else add_order_note() is called: it runs a filter,
				// wp_insert_comment() and an action, all third-party surface, and an escaping
				// throw would reach the shopper instead of the link they came for.
				try {
					$wco->add_order_note(
						__( 'Your payment details were updated. This renewal has not been charged yet; it will be collected automatically with the new details.', 'scanpay-for-woocommerce' ),
						1
					);
				} catch ( \Throwable $e ) {
					scanpay_log( 'error', "Order #$oid: could not add the payment-details note: " . trim( $e->getMessage() ) );
				}
			}
			return [
				'result'   => 'success',
				'redirect' => $link,
			];
		}
		if ( wcs_scanpay_is_payment_method_change() ) {
			/*
			 * A method change on a subscription we do not know yet: no _scanpay_subid, so it
			 * is being moved to us from another gateway. It must register a card and charge
			 * nothing, and it cannot fall through to the item build below:
			 *
			 * - $wco is the WCS subscription. WCS zeroes the amount through
			 *   woocommerce_subscription_get_total, but WC_Data::get_prop() applies its hook
			 *   in 'view' only, so get_total( 'edit' ) and the get_line_total() loop below
			 *   both read the real recurring total and would bill it now.
			 * - Every _scanpay_* key written here lands on the subscription, and
			 *   WC_Subscriptions_Data_Copier excludes only WC/WCS internals -- so it would be
			 *   copied onto every renewal order afterwards, a stored completion intent
			 *   included.
			 * - The successurl is the WCS-filtered My Account URL and carries no order key,
			 *   so thank-you args appended to it are litter the wait never reads.
			 *
			 * /v1/new with a subscriber.ref and no items creates a subscriber and nothing
			 * else: no transaction, and the backend discards the orderid riding along, so
			 * nothing comes back through the seq. Hence orderid and autocapture can stay as
			 * computed above.
			 */
			$data['subscriber'] = [ 'ref' => wc_scanpay_subref( $oid, $wco ) ];
			try {
				$link = $client->new_url( $data );
			} catch ( Exception $e ) {
				scanpay_log( 'error', 'Payment link creation failed: ' . trim( $e->getMessage() ) );
				throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
			}
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
		// rather than a notice. Rethrown, never degraded: $subref, $sum and $data['items']
		// must not be read half-built below.
		scanpay_log( 'error', "Order #$oid: could not build the item list: " . trim( $e->getMessage() ) );
		throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
	}
	$complete = false;
	if ( $subref ) {
		$subref = wc_scanpay_subref( $oid, $wco );
		if ( $subref ) {
			$data['subscriber'] = [ 'ref' => $subref ];
			$otype              = ( $wc_totalf > 0 ) ? 'wcs' : 'wcs_free';
			// The initial order of a subscription, so wcs_complete_initial applies. Settling
			// at Scanpay and completing in WooCommerce are separate outcomes of the one
			// setting, and both need a capture -- hence the 'completed' condition: 'on'
			// already captures, and 'off' must keep doing neither.
			$complete = wcs_scanpay_wants_completion( $settings, 'initial' );
			if ( $complete && 'completed' === $autocapture ) {
				$data['autocapture'] = true;
			}
		}
	}
	// The router dispatches on scanpay_thankyou and scanpay_type, plus WooCommerce's own
	// ?key already in the URL; scanpay_ref is what the free-trial branch polls.
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
