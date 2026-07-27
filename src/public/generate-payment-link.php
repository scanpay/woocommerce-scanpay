<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// require_once on both, because they declare a class and functions and five other
// sites require_once the same two. A bare require here includes them a second time
// regardless of that registration, and redeclares: capture.php is the reachable case,
// pulled in when a third party completes the order on
// woocommerce_checkout_order_processed, which fires before process_order_payment().
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
require_once WC_SCANPAY_DIR . '/library/math.php';

function wc_scanpay_phone_prefixer( string $phone, string $country ): string {
	if ( ! empty( $phone ) ) {
		$first_number = substr( $phone, 0, 1 );
		if ( '+' !== $first_number && '0' !== $first_number ) {
			// get_country_calling_code() returns '' -- never null -- for an absent or
			// unknown country, so an isset() check would pass and prefix " 12345678".
			// is_string() stays for the docblock, not for a filter: the method applies
			// none and unwraps an array itself (includes/class-wc-countries.php:165-182),
			// but it still declares @return string|array, and the WC 3.6 floor is a long
			// way below the version that was read to establish that.
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
		 * Switching an existing subscription to us. No new order is created here, only
		 * the subscription's payment method changes -- so $oid is the WCS subscription
		 * id, not an order id.
		 *
		 * Reachable only from wc_scanpay_process_payment()'s method-change branch, which
		 * returns above the item build. The other call site sits below that return and
		 * can never see a method change, so this branch looks dead from there: it is not,
		 * and the call stays rather than being inlined, because this is the one place the
		 * wcs[] ref format is written.
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
			// A protocol value, not a page of results: every id here becomes part of
			// subscriber.ref, and one missing from it is a subscription sync never links,
			// so its renewals fail forever. Without this WC_Object_Query supplies
			// get_option( 'posts_per_page' ) -- ten on a default install.
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
			 * An existing subscriber, reached three ways: a customer retrying a failed
			 * renewal, a resubscribe, and a payment-method change. All three get the same
			 * link, because renew() gives the same thing to all three.
			 *
			 * /v1/subscribers/{subid}/renew charges nothing. It returns a page where the
			 * customer updates their stored payment details -- a flat fact, settled with
			 * Scanpay, that no stub states and that the endpoint's name argues against. So
			 * this branch creates no transaction, has nothing for the return page to wait
			 * on, and records no completion intent: there is no payment for one to describe.
			 * The $data['autocapture'] computed above rides along inert rather than being
			 * zeroed here, so the payload shape stays the same on every path.
			 *
			 * $paid_renewal separates the two things that still differ: only a real order
			 * gets the note and the stamp below. On a method change $wco is the WCS
			 * subscription, and any key written on it is copied onto every future renewal
			 * order by WC_Subscriptions_Data_Copier.
			 *
			 * The money is collected later, by WCS's own retry, which the card update does
			 * unblock: the subscriber rev bumps, so WCS_Scanpay_Charge::idempotency_key()
			 * builds a new key and the next scheduled charge is not deduped against the
			 * declined one.
			 */
			$paid_renewal = ! wcs_scanpay_is_payment_method_change();
			try {
				$link = $client->renew( $subid, $data );
			} catch ( Exception $e ) {
				scanpay_log( 'error', 'Renewal link creation failed: ' . trim( $e->getMessage() ) );
				throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
			}
			if ( $paid_renewal ) {
				// Which shop the subscriber belongs to. With no money moving here, that is the
				// stamp's whole job: a merchant who switches API keys between the card update
				// and the retry then gets scheduled_charge()'s deliberate refusal rather than a
				// charge against a numerically colliding subid in the new shop. Only when
				// absent; a different shop id is a real mismatch and not ours to overwrite.
				if ( (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ) <= 0 ) {
					$shopid = (int) strstr( (string) ( $settings['apikey'] ?? '' ), ':', true );
					$wco->add_meta_data( WC_SCANPAY_URI_SHOPID, $shopid, true );
				}
				$wco->save_meta_data();
				// The customer followed a link labelled "Pay now" and will be returned to an
				// order that is still unpaid; this is the only thing that tells them what
				// actually happened. Customer-visible (the 1), so WooCommerce lists it under
				// "Order updates" and mails it through woocommerce_new_customer_note.
				//
				// Contained the way WC_Scanpay_Sync::report_incomplete() contains its own:
				// add_order_note() runs woocommerce_new_order_note_data, wp_insert_comment()
				// and woocommerce_order_note_added, all third-party surface, and this runs
				// inside process_payment(), where an escaping throw is put in front of the
				// shopper by WC_Checkout instead of the link they came for.
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
			 * A method change on a subscription we do not know yet -- no _scanpay_subid,
			 * so it is being moved to us from another gateway, which is what "change
			 * payment method" is normally used for. It must register a card and charge
			 * nothing, and it cannot fall through to the item build below:
			 *
			 * - $wco is the WCS subscription, not an order. WCS zeroes the amount with the
			 *   woocommerce_subscription_get_total filter, but WC_Data::get_prop() applies
			 *   {hook_prefix}{prop} in the 'view' context only, so get_total( 'edit' ) and
			 *   the get_line_total() loop below both read the real recurring total and
			 *   would bill it now.
			 * - Every _scanpay_* key written here would land on the subscription, and
			 *   WC_Subscriptions_Data_Copier excludes only WC/WCS internals -- so it would
			 *   be copied onto every renewal order WCS creates afterwards, a stored
			 *   completion intent included.
			 * - The successurl is the WCS-filtered My Account URL and carries no order
			 *   key, so thank-you args appended to it are litter the wait never reads.
			 *
			 * /v1/new with a subscriber.ref and no items creates a subscriber and nothing
			 * else: no transaction, and the orderid riding along is discarded by the
			 * backend rather than stored, so nothing comes back through the seq. That is
			 * why orderid and autocapture can stay as computed above -- there is nothing
			 * to capture and nothing for sync to resolve.
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
	 * The whole item build is contained, not just the two money calls. Every value read
	 * here passes through a filter a third party owns -- get_line_total() runs
	 * woocommerce_order_amount_line_total, and a callback returning null survives the
	 * ">= 0" guard (PHP 8 compares null >= 0 as booleans), becomes '' in
	 * wc_format_decimal(), and makes wc_scanpay_addmoney() throw. WC_Checkout catches
	 * Exception and puts the message straight in front of the shopper
	 * (class-wc-checkout.php:1419-1422), so uncontained that reads
	 * "invalid money amount: '0' or ''" at checkout.
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
		// \Throwable for the reason WCS_Scanpay_Charge::charge() gives: an Error out of a
		// filter callback is as fatal to the checkout as an exception, and here it would be
		// an uncaught fatal rather than a notice. Rethrown, never degraded -- $subref, $sum
		// and $data['items'] must not be read half-built by the code below.
		scanpay_log( 'error', "Order #$oid: could not build the item list: " . trim( $e->getMessage() ) );
		throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
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
