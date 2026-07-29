<?php

/**
 * WooCommerce Subscriptions, front-end half: renewal charges, the cart-level terms
 * checkbox (classic and Blocks) and the retry-interval floor. Hooks are registered at
 * require time -- the file is the hook list.
 *
 * Required from wc_scanpay_plugins_loaded() only when WC_Subscriptions is loaded. The
 * plugin bootstraps on every request to the whole site, so gating it here keeps a shop
 * without WCS from parsing these functions or carrying their hooks at all, and keeps any
 * breakage in the subscription surface off shops that cannot use it in the first place.
 *
 * The rule that follows: nothing reachable outside that guard may live here.
 * wcs_scanpay_terms_url() sits in woocommerce-scanpay.php for exactly that reason -- the
 * Blocks payload builder gates on WC_Subscriptions_Cart, which subscriptions-core ships
 * without WC_Subscriptions.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Handle a scheduled subscription payment; $wco is the renewal order, not the subscription.
 * Action: woocommerce_scheduled_subscription_payment_scanpay
 *
 * Outermost handler of the renewal flow: the require, the construction and everything in
 * scheduled_charge() run inside the try, so no Throwable reaches Action Scheduler. A
 * missing class file is the one case it cannot contain -- that is a compile error, not a
 * Throwable.
 */
function wcs_scanpay_scheduled_charge( float $amount, WC_Order $wco ): void {
	static $handler = null;
	try {
		if ( null === $handler ) {
			// require_once, because the catch below turns a constructor throw into a normal
			// return: the next action in the same Action Scheduler batch re-enters with
			// $handler still null, and a bare require would redeclare the class and fatal.
			require_once WC_SCANPAY_DIR . '/library/class-wcs-scanpay-charge.php';
			$handler = new WCS_Scanpay_Charge();
		}
		$handler->scheduled_charge( $amount, $wco );
	} catch ( \Throwable $e ) {
		// Only reached when nothing below reported: scheduled_charge() and charge() route
		// their own failures through the reporter and never rethrow.
		wcs_scanpay_fail_renewal(
			$wco,
			'scheduled charge: unhandled error on #' . $wco->get_id() . ': ' . $e->getMessage(),
			__( 'The Scanpay renewal could not be processed. See the WooCommerce logs for details.', 'scanpay-for-woocommerce' )
		);
	}
}
add_action( 'woocommerce_scheduled_subscription_payment_scanpay', 'wcs_scanpay_scheduled_charge', 10, 2 );

/**
 * Render the subscription terms checkbox (classic checkout).
 * Action: woocommerce_checkout_after_terms_and_conditions
 *
 * Fires outside any gateway, beside WooCommerce's own terms checkbox
 * (templates/checkout/terms.php), so it renders once per checkout no matter which payment
 * method the customer selects -- and an update_order_review refresh clears the tick along
 * with the rest of the fragment.
 */
function wcs_scanpay_checkout_terms() {
	require WC_SCANPAY_DIR . '/public/wcs-scanpay-checkout-terms.php';
}
add_action( 'woocommerce_checkout_after_terms_and_conditions', 'wcs_scanpay_checkout_terms', 10 );

/**
 * Server-side enforcement of the subscription terms checkbox (classic checkout).
 * Action: woocommerce_after_checkout_validation
 *
 * The 'required' flag on woocommerce_form_field() only draws a CSS asterisk, and
 * WooCommerce validates nothing outside woocommerce_checkout_fields, so the checkbox is
 * otherwise skippable by POSTing without it. Not conditioned on the payment method: the
 * renders once for the whole checkout, so a per-gateway check would leave it shown but
 * unenforced for every gateway but ours.
 */
function wcs_scanpay_validate_terms( array $data, WP_Error $errors ): void {
	if ( ! class_exists( 'WC_Subscriptions_Cart', false ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	if ( '' === wcs_scanpay_terms_url() ) {
		return;
	}
	/*
	 * Enforce only what was rendered: the renderer emits a wcssp-terms-field marker, so its
	 * absence means the render hook never ran -- terms area filtered away, or a theme
	 * template override -- and demanding the box would fail every classic checkout holding a
	 * subscription, with nothing on the page to tick. The cost is the one WooCommerce accepts
	 * for its own terms-field in WC_Checkout::validate_checkout(): a crafted POST that drops
	 * the marker skips the check. Blocks posts extensions.scanpay.terms instead, never this
	 * marker, and stays strict.
	 */
	// The checkout nonce is verified by WC_Checkout::process_checkout() before this action.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce as above; neither value is read, only tested with empty().
	if ( empty( $_POST['wcssp-terms'] ) && ! empty( $_POST['wcssp-terms-field'] ) ) {
		$errors->add( 'wcssp-terms', __( 'You must accept the subscription terms to complete your purchase.', 'scanpay-for-woocommerce' ) );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'wcs_scanpay_validate_terms', 10, 2 );

/**
 * Server-side enforcement of the subscription terms checkbox (Blocks / Store API checkout).
 * Action: woocommerce_store_api_checkout_update_order_from_request
 *
 * The woocommerce_after_checkout_validation action never fires for the Store API checkout.
 * checkout.ts renders the checkbox as a forced checkout block and posts its state as
 * extensions.scanpay.terms (namespace declared at the foot of this file); re-check it here
 * so a crafted request cannot bypass acceptance. RouteException aborts the checkout with a
 * 400 and surfaces the message to the customer.
 *
 * Not conditioned on the payment method: the block renders once below the payment method
 * list, so the consent covers every gateway the customer can pick.
 */
function wcs_scanpay_blocks_validate_terms( WC_Order $order, WP_REST_Request $request ): void {
	if ( ! class_exists( 'WC_Subscriptions_Cart', false ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	if ( '' === wcs_scanpay_terms_url() ) {
		return;
	}
	$extensions = (array) ( $request['extensions'] ?? [] );
	if ( empty( $extensions['scanpay']['terms'] ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'wcssp_terms_required',
			esc_html__( 'You must accept the subscription terms to complete your purchase.', 'scanpay-for-woocommerce' ),
			400
		);
	}
}
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'wcs_scanpay_blocks_validate_terms', 10, 2 );

/**
 * Enforce a >=25h floor on Scanpay renewal retries.
 * Filter: wcs_get_retry_rule_raw
 *
 * Only the interval is raised; emails, statuses and attempt count stay the merchant's. The
 * idempotency key's day component (whole days since the renewal order was created) advances
 * only after 24h, so an earlier retry would replay the cached decline. The 25th hour is
 * clock-skew margin, not correctness.
 *
 * $rule is untyped because WCS core passes an array, but a filter hooked earlier may return
 * false, a rule object, or anything else; those pass through untouched.
 */
function wcs_scanpay_retry_rule( $rule, int $retry_number, int $order_id ) {
	if ( ! is_array( $rule ) ) {
		return $rule;
	}
	$wco = wc_get_order( $order_id );
	if ( ! $wco instanceof WC_Order || 'scanpay' !== $wco->get_payment_method( 'edit' ) ) {
		return $rule;
	}
	if ( isset( $rule['retry_after_interval'] ) ) {
		$rule['retry_after_interval'] = max( (int) $rule['retry_after_interval'], DAY_IN_SECONDS + HOUR_IN_SECONDS );
	}
	return $rule;
}
add_filter( 'wcs_get_retry_rule_raw', 'wcs_scanpay_retry_rule', 10, 3 );

/*
 * Declare the 'scanpay' extension namespace on the Store API checkout endpoint. The schema
 * drops data posted under an unregistered namespace, so without this
 * extensions.scanpay.terms never reaches wcs_scanpay_blocks_validate_terms(). Write-only:
 * no data_callback and an empty context, so nothing is added to Store API responses.
 *
 * Registered at require time, not on woocommerce_blocks_loaded: WooCommerce fires that from
 * plugins_loaded priority -1, long before wc_scanpay_plugins_loaded() runs at 10 and gets
 * here. Still well before rest_api_init, where the endpoint schema is assembled.
 */
if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
	woocommerce_store_api_register_endpoint_data(
		[
			'endpoint'        => 'checkout', // CheckoutSchema::IDENTIFIER.
			'namespace'       => 'scanpay',
			'schema_callback' => function (): array {
				return [
					'terms' => [
						'description' => 'Whether the customer accepted the subscription terms.',
						'type'        => 'boolean',
						'context'     => [],
					],
				];
			},
		]
	);
}
