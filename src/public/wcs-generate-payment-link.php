<?php

/**
 * Checkout, WooCommerce Subscriptions half. An ordinary payment and a subscription payment
 * share one payload and one payment-link call, both owned by generate-payment-link.php;
 * everything a subscription needs on top of that lives here, and this is the only file at
 * checkout that names a WCS class.
 *
 * Three flows differ from an ordinary payment, and this file is all three:
 *
 *  - A subscriber we already know -- a retry of a failed renewal, a resubscribe, a
 *    payment-method change -- goes to /v1/subscribers/{subid}/renew and never reaches the
 *    item build.
 *  - A method change on a subscription we do not know goes to /v1/new with a subscriber ref
 *    and no items, and never reaches it either.
 *  - The initial order of a subscription *is* the ordinary payment; it only carries a
 *    subscriber ref, a return-page type and a completion intent of its own.
 *
 * Required unconditionally by generate-payment-link.php: nothing here runs at require time,
 * and wcs_scanpay_active() is the gate every caller asks first. The API client is the
 * caller's -- a WC_Scanpay_Client argument cannot arrive without its class already loaded.
 *
 * Like its caller, this runs inside WC_Checkout::process_checkout(), which catches Exception
 * and puts the message straight in front of the shopper, so the messages thrown are written
 * for them and the diagnostics go to the log.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Whether the subscription paths may run at all: the full WC_Subscriptions plugin, plus the
 * static wcs_scanpay_item_is_subscription() calls.
 *
 * The subscriptions-core package ships WC_Subscription and
 * WC_Subscriptions_Change_Payment_Gateway without WC_Subscriptions, so a shop running core
 * alone -- WooPayments' old bundled subscriptions -- answers false here. The card and
 * Apple Pay gateways advertise no subscription features in that state, and
 * generate-payment-link.php refuses a method change if third-party code bypasses
 * WooCommerce's gateway list. It is also the test woocommerce-scanpay.php loads
 * public/subscriptions.php on, so the capability declaration and card renewal handler share
 * the same full-plugin boundary.
 */
function wcs_scanpay_active(): bool {
	return class_exists( 'WC_Subscriptions', false ) && method_exists( 'WC_Subscriptions_Product', 'is_subscription' );
}

/**
 * The subscriber ref: which WCS subscriptions the subscriber Scanpay creates belongs to.
 *
 * @internal Reached only from this file, and only once wcs_scanpay_active() holds.
 */
function wcs_scanpay_subref( int $oid, WC_Abstract_Order $wco ): ?string {
	if ( wcs_scanpay_is_payment_method_change( $wco ) ) {
		/*
		 * A payment-method change creates no order, so $oid is the WCS subscription id.
		 *
		 * Reachable only from wcs_scanpay_payment_link()'s method-change branch, which returns
		 * before any item is read; from wcs_scanpay_initial_payment() this looks dead. Not
		 * inlined there, because this is the one place the wcs[] ref format is written.
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

/**
 * Whether an order line is a subscription product, and the payment therefore needs a
 * subscriber. Variations included: is_subscription() matches subscription_variation and
 * variable-subscription as well.
 *
 * The typed parameter is the guard on what woocommerce_order_get_items handed back, and
 * get_product() plus the woocommerce_is_subscription filter are the same third-party surface
 * the rest of the caller's item build is -- which is why this is called from inside its try
 * and not before it.
 *
 * @internal Caller has established wcs_scanpay_active().
 */
function wcs_scanpay_item_is_subscription( WC_Order_Item $item ): bool {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return false;
	}
	$product = $item->get_product();
	return $product && WC_Subscriptions_Product::is_subscription( $product );
}

/**
 * The payment window for a subscription flow that is not a payment for goods, or null when
 * this attempt is an ordinary payment -- including the initial order of a subscription, which
 * the caller pays normally and decorates with wcs_scanpay_initial_payment().
 *
 * Neither branch here creates a transaction, so neither stamps a payid, a payment time or a
 * completion intent, and neither appends the thank-you arguments: there is nothing for the
 * return page to wait on.
 *
 * @throws Exception Written for the shopper; WC_Checkout::process_checkout() shows it to them.
 */
function wcs_scanpay_payment_link( int $oid, WC_Abstract_Order $wco, array $settings, array $data, WC_Scanpay_Client $client ): ?string {
	$subid = (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
	if ( $subid ) {
		/*
		 * An existing subscriber, reached three ways -- a retry of a failed renewal, a
		 * resubscribe, and a payment-method change -- and renew() gives all three the
		 * same link.
		 *
		 * /v1/subscribers/{subid}/renew charges nothing, despite its name: it returns a
		 * page where the customer updates their stored payment details. So this branch
		 * creates no transaction and records no completion intent. The $data['autocapture']
		 * the caller computed rides along inert, so the payload shape stays the same on every
		 * path.
		 *
		 * $paid_renewal separates what still differs: only a real order gets the note
		 * and the stamp below. On a method change $wco is the WCS subscription, and any
		 * key written there is copied onto every future renewal order.
		 *
		 * The money is collected later by WCS's own retry, which the card update does
		 * unblock: the subscriber rev bumps, so idempotency_key() builds a new key and
		 * the next scheduled charge is not deduped against the declined one.
		 */
		$paid_renewal = ! wcs_scanpay_is_payment_method_change( $wco );
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
		return $link;
	}
	if ( wcs_scanpay_is_payment_method_change( $wco ) ) {
		/*
		 * A method change on a subscription we do not know yet: no _scanpay_subid, so it
		 * is being moved to us from another gateway. It must register a card and charge
		 * nothing, and it cannot fall through to the caller's item build:
		 *
		 * - $wco is the WCS subscription. WCS zeroes the amount through
		 *   woocommerce_subscription_get_total, but WC_Data::get_prop() applies its hook
		 *   in 'view' only, so get_total( 'edit' ) and the get_line_total() loop there
		 *   both read the real recurring total and would bill it now.
		 * - Every _scanpay_* key written there lands on the subscription, and
		 *   WC_Subscriptions_Data_Copier excludes only WC/WCS internals -- so it would be
		 *   copied onto every renewal order afterwards, a stored completion intent
		 *   included.
		 * - The successurl is the WCS-filtered My Account URL and carries no order key,
		 *   so thank-you args appended to it are litter the wait never reads.
		 *
		 * /v1/new with a subscriber.ref and no items creates a subscriber and nothing
		 * else: no transaction, and the backend discards the orderid riding along, so
		 * nothing comes back through the seq. Hence orderid and autocapture can stay as
		 * the caller computed them.
		 */
		$data['subscriber'] = [ 'ref' => wcs_scanpay_subref( $oid, $wco ) ];
		try {
			return $client->new_url( $data );
		} catch ( Exception $e ) {
			scanpay_log( 'error', 'Payment link creation failed: ' . trim( $e->getMessage() ) );
			throw new Exception( esc_html__( 'Error: We could not create a link to the payment window. Please wait a moment and try again.', 'scanpay-for-woocommerce' ) );
		}
	}
	return null;
}

/**
 * What the initial order of a subscription adds to an otherwise ordinary payment, or null
 * when no subscription id resolves and it must be paid as one.
 *
 * 'capture' is a request to force the payload's autocapture on, never off: settling at
 * Scanpay and completing in WooCommerce are separate outcomes of the one wcs_complete_initial
 * setting, and both need a capture -- hence the 'completed' condition, since 'on' already
 * captures and 'off' must keep doing neither.
 *
 * @param string $autocapture The wc_autocapture setting as the caller resolved it.
 * @return array{ref: string, otype: string, complete: bool, capture: bool}|null
 * @internal Caller has established wcs_scanpay_active().
 */
function wcs_scanpay_initial_payment( int $oid, WC_Abstract_Order $wco, array $settings, string $autocapture ): ?array {
	$subref = wcs_scanpay_subref( $oid, $wco );
	if ( null === $subref ) {
		return null;
	}
	$complete = wcs_scanpay_wants_completion( $settings, 'initial' );
	// A zero total is a free trial, which the return page polls for an activated subscription
	// instead of a transaction id. Read in 'edit' for the reason the method-change branch
	// above gives: 'view' is where WCS's own zeroing filter lives.
	return [
		'ref'      => $subref,
		'otype'    => ( $wco->get_total( 'edit' ) > 0 ) ? 'wcs' : 'wcs_free',
		'complete' => $complete,
		'capture'  => $complete && 'completed' === $autocapture,
	];
}
