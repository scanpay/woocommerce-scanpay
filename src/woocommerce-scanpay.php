<?php

/**
 * Plugin Name: Scanpay for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/scanpay-for-woocommerce/
 * Description: Accept payments in WooCommerce with a secure payment gateway.
 * Author: Scanpay
 * Author URI: https://scanpay.dk
 * Version: {{ VERSION }}
 * Requires Plugins: woocommerce
 * Requires at least: {{ WP_MIN }}
 * Requires PHP: {{ PHP_MIN }}
 * WC requires at least: {{ WC_MIN }}
 * WC tested up to: {{ WC_TESTED }}
 * Text Domain: scanpay-for-woocommerce
 * Domain Path: /languages/
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires the PHP cURL extension on top of the fields above. WC_Scanpay_Client is built
 * on ext-curl with no fallback transport, so without it the plugin cannot reach Scanpay
 * at all. WordPress has no plugin-header field for extensions, which is why this is prose.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

const WC_SCANPAY_VERSION      = '{{ VERSION }}';
const WC_SCANPAY_DASHBOARD    = 'https://dashboard.scanpay.dk/';
const WC_SCANPAY_URI_SETTINGS = 'woocommerce_scanpay_settings';
const WC_SCANPAY_URI_SHOPID   = '_scanpay_shopid';
const WC_SCANPAY_URI_PAYID    = '_scanpay_payid';
const WC_SCANPAY_URI_PTIME    = '_scanpay_payid_time';
const WC_SCANPAY_URI_SUBID    = '_scanpay_subid';
const WC_SCANPAY_URI_COMPLETE = '_scanpay_complete';

define( 'WC_SCANPAY_DIR', __DIR__ );
define( 'WC_SCANPAY_URL', untrailingslashit( plugins_url( '', __FILE__ ) ) );
// "<dir>/woocommerce-scanpay.php", the key get_plugins() and the plugin_action_links_
// hook use. Derived, not spelled out, so a renamed directory or a checkout symlinked
// into plugins/ still resolves -- plugin_basename() maps the realpath back through
// $wp_plugin_paths, which wp-settings.php filled when it included this file.
define( 'WC_SCANPAY_BASENAME', plugin_basename( __FILE__ ) );

/** Write to the WooCommerce log; a silent no-op until wc_get_logger() exists. */
function scanpay_log( string $level, string $msg ): void {
	static $logger = null;
	if ( null === $logger ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
	}
	$logger->log( $level, $msg, [ 'source' => 'wc-scanpay' ] );
}

/*
 * Ping (callback) endpoint: /wc-api/wc_scanpay/ or ?wc-api=wc_scanpay. The rest of
 * the bootstrap is skipped only when the URI really is that endpoint -- an
 * X-Signature header on any other request must still get a normal plugin load.
 */
if ( isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
	function wc_scanpay_handle_ping(): void {
		// Not left to wc_scanpay_init() on 'init': the return below skips the whole
		// bootstrap, so that hook is never registered on a ping. Sync persists three
		// translated order notes from this very request, which no later one repairs.
		load_plugin_textdomain( 'scanpay-for-woocommerce', false, dirname( WC_SCANPAY_BASENAME ) . '/languages' );
		require WC_SCANPAY_DIR . '/callback/wc-scanpay-ping.php';
	}
	// The action outlived the class it was named after: since WC 9.0 it is fired on
	// parse_request by Internal/Utilities/LegacyRestApiStub, not by the removed WC_API.
	add_action( 'woocommerce_api_wc_scanpay', 'wc_scanpay_handle_ping' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only compared with str_ends_with(); never echoed, stored or put in a query.
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	if ( str_ends_with( $uri, 'wc_scanpay/' ) || str_ends_with( $uri, 'wc_scanpay' ) ) {
		return;
	}
}

/*
 * Payment-return ("thank you") page. A genuine return carries all three params and a
 * known type; anything else falls through to a normal plugin load. The order-key
 * ownership check happens inside the handler, before any polling.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing decision only; the handler checks the order key before it reads or writes anything.
if ( isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'], $_GET['key'] ) && in_array( $_GET['scanpay_type'], [ 'wc', 'wcs', 'wcs_free' ], true ) ) {
	require WC_SCANPAY_DIR . '/public/wp-scanpay-thankyou.php';
	return;
}

/*
 * Lightweight admin AJAX endpoints (bypass WP/WC bootstrap).
 *
 * The shared secret authenticating these endpoints rides in the X-Scanpay
 * request header (not the query string), so it never reaches access/proxy logs,
 * browser history, or Referer headers. Each endpoint re-verifies it with
 * hash_equals; this is only the dispatch gate.
 *
 * A secret rather than a capability, and unscoped, both deliberately. This runs
 * during plugin load -- wp-settings.php includes pluggable.php only afterwards, so
 * current_user_can() does not exist yet -- and deferring the work to a later hook
 * would buy the whole WordPress and WooCommerce bootstrap these endpoints exist to
 * skip. The secret is therefore the only credential available here, and no
 * per-order scope is layered on top of it: the screens that print it
 * (admin/orders.php, admin/subscriptions.php) already require order-editing
 * access, which on a WooCommerce shop is all-or-nothing, so the holder can read
 * every order anyway. What the endpoints expose is a subset of what those screens
 * show -- amounts, a transaction id, a card type and expiry -- read-only, with no
 * card numbers or customer details. Reviewed and settled on those grounds; do not
 * re-raise it as an authorization gap without new information (a role that grants
 * partial order access would be exactly that).
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing decision only; the endpoints authenticate with the shared secret in hash_equals(), for the reason above.
if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The match arms are the whitelist; any other value yields null and falls through to a normal load.
	$file = match ( $_GET['x'] ) {
		'meta' => '/admin/ajax/wp-scanpay-fetch-meta.php',
		'ping' => '/admin/ajax/wp-scanpay-fetch-ping.php',
		'sub'  => '/admin/ajax/wp-scanpay-fetch-sub.php',
		default => null,
	};
	if ( $file ) {
		require WC_SCANPAY_DIR . $file;
		return;
	}
}

function wc_scanpay_register_gateways( array $methods ): array {
	$methods[] = WC_Gateway_Scanpay_Card::class;
	$methods[] = WC_Gateway_Scanpay_Mobilepay::class;
	$methods[] = WC_Gateway_Scanpay_ApplePay::class;
	return $methods;
}

function wc_scanpay_register_blocks( $registry ): void {
	if ( ! class_exists( 'WC_Scanpay_Blocks_Support', false ) ) {
		require WC_SCANPAY_DIR . '/gateways/blocks/class-wc-scanpay-blocks-support.php';
	}
	$registry->register( new WC_Scanpay_Blocks_Support() );
}

/** Let wp_safe_redirect() send the customer on to the payment window. */
function wc_scanpay_allowed_redirect_hosts( array $hosts ): array {
	$hosts[] = 'betal.scanpay.dk';
	return $hosts;
}

/** Action: woocommerce_order_status_completed */
function wc_scanpay_order_status_completed( int $oid, WC_Order $wco ): void {
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
		// 'off' = manual capture; 'on' = already captured at Scanpay.
		return;
	}
	scanpay_log( 'debug', "Order #$oid marked as completed, attempting capture." );
	require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';
	// On failure this parks the order 'on-hold' (never 'failed'); the order is already
	// 'completed', so a successful capture leaves it as-is.
	WC_Scanpay_Capture::capture_or_hold( $wco );
}

/**
 * The subscription-terms page URL, or '' when the checkbox must not be shown.
 *
 * The single predicate behind both renderers (classic and Blocks) and both validators, so
 * the checkbox can never be enforced without having been rendered, or the reverse.
 *
 * Deliberately independent of every gateway: the consent belongs to the subscription in
 * the cart, not to a payment method, so it applies whichever gateway the customer picks
 * -- including third-party ones -- and stays active while our own gateways are disabled.
 *
 * '' unless wcs_terms holds a positive page id whose page is still exactly 'publish'. That
 * folds the disabled states ('0' and a stored '') in with every stale-page one: the picker
 * only offers published pages, but the stored id goes stale once the page is drafted, made
 * private, trashed, or deleted. Returning the URL rather than the id keeps get_page_link()'s
 * unguarded post dereference inside the guard (it warns on a deleted page, and a trashed
 * one would 404 the customer).
 */
function wcs_scanpay_terms_url(): string {
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) ) {
		return '';
	}
	$page_id = (int) ( $settings['wcs_terms'] ?? 0 );
	if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
		return '';
	}
	return (string) get_page_link( $page_id );
}

/**
 * Render the subscription terms checkbox (classic checkout).
 * Action: woocommerce_checkout_after_terms_and_conditions
 *
 * Fires outside any gateway, next to WooCommerce's own terms checkbox
 * (templates/checkout/terms.php), so it renders once per checkout no matter which
 * payment method the customer selects -- and an update_order_review refresh clears
 * the tick along with the rest of the fragment.
 */
function wcs_scanpay_checkout_terms() {
	require WC_SCANPAY_DIR . '/public/wcs-scanpay-checkout-terms.php';
}

/**
 * Declare the 'scanpay' extension namespace on the Store API checkout endpoint.
 * Called directly from wc_scanpay_plugins_loaded().
 *
 * The Blocks checkbox posts its state as extensions.scanpay.terms. The endpoint schema
 * drops data under an unregistered namespace, so this registration is what makes the
 * value readable at all in wcs_scanpay_blocks_validate_terms(). Write-only: no
 * data_callback, so nothing is added to Store API responses.
 */
function wcs_scanpay_register_store_api_terms(): void {
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

/**
 * Server-side enforcement of the subscription terms checkbox (classic checkout).
 * Action: woocommerce_after_checkout_validation
 *
 * The woocommerce_form_field( 'required' => true ) only renders a CSS asterisk; WooCommerce
 * validates only fields registered in woocommerce_checkout_fields, so the checkbox is
 * otherwise skippable via a direct POST. Not conditioned on the payment method:
 * wcs_scanpay_checkout_terms() renders the checkbox once for the whole checkout, so
 * enforcing it per gateway would leave it shown but skippable for every gateway but one.
 *
 * $data is unused; it is part of the hook signature.
 */
function wcs_scanpay_validate_terms( array $data, WP_Error $errors ): void {
	if ( ! class_exists( 'WC_Subscriptions_Cart', false ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	if ( '' === wcs_scanpay_terms_url() ) {
		return; // Terms checkbox is disabled or its configured page is not published.
	}
	/*
	 * Enforce only what was rendered. wcs_scanpay_checkout_terms() emits a
	 * wcssp-terms-field marker beside the checkbox, so its absence means the render hook
	 * never ran -- the terms area is filtered away or the theme overrides the template --
	 * and demanding the box here would fail every classic checkout with a subscription in
	 * the cart, with nothing on the page to tick.
	 *
	 * The cost is the one WooCommerce accepts for its own terms-field
	 * (class-wc-checkout.php:794, :981): a crafted POST that omits the marker skips the
	 * check. Blocks is unaffected -- it posts extensions.scanpay.terms and never this
	 * marker, and wcs_scanpay_blocks_validate_terms() stays strict.
	 */
	// The checkout nonce is verified by WC_Checkout::process_checkout() before this action.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Nonce as above; neither value is read, only tested with empty().
	if ( empty( $_POST['wcssp-terms'] ) && ! empty( $_POST['wcssp-terms-field'] ) ) {
		$errors->add( 'wcssp-terms', __( 'You must accept the subscription terms to complete your purchase.', 'scanpay-for-woocommerce' ) );
	}
}

/**
 * Server-side enforcement of the subscription terms checkbox (Blocks / Store API checkout).
 * Action: woocommerce_store_api_checkout_update_order_from_request
 *
 * The woocommerce_after_checkout_validation action does not fire for the Store API checkout
 * used by the Cart/Checkout blocks. The checkbox is rendered by checkout.ts as a forced
 * checkout block and its state posted as extensions.scanpay.terms (registered by
 * wcs_scanpay_register_store_api_terms()); re-check it here so a crafted request cannot
 * bypass acceptance. Throwing RouteException aborts the checkout with a 400 and surfaces
 * the message to the customer.
 *
 * Not conditioned on the payment method: the block renders once below the payment method
 * list, so the consent covers every gateway the customer can pick.
 *
 * $order (the draft order built from the request) is unused; it is part of the signature.
 */
function wcs_scanpay_blocks_validate_terms( WC_Order $order, WP_REST_Request $request ): void {
	if ( ! class_exists( 'WC_Subscriptions_Cart', false ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	if ( '' === wcs_scanpay_terms_url() ) {
		return; // Terms checkbox is disabled or its configured page is not published.
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

/**
 * Whether this request is WooCommerce Subscriptions changing a subscription's payment
 * method, rather than paying for an order.
 *
 * On such a request the "order" our payment code is handed is the subscription itself,
 * not an order -- see wc_scanpay_subref(). Guarded on the class, because WCS need not
 * be active. One definition, because more than one caller has to agree on it.
 */
function wcs_scanpay_is_payment_method_change(): bool {
	return class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false )
		&& WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
}

/**
 * Whether a payment attempt asks WooCommerce to force the 'completed' status once the
 * payment syncs, per wcs_complete_initial / wcs_complete_renewal.
 *
 * Request-time policy, evaluated by the three paths that create a payment attempt and
 * persisted in WC_SCANPAY_URI_COMPLETE; sync obeys the persisted value and never reads
 * these settings, so a merchant toggling them while a payment window is open cannot
 * reinterpret an attempt the store already accepted.
 *
 * Not the payload's autocapture expression, which folds in wc_complete_virtual: settling
 * at Scanpay and completing in WooCommerce are related decisions, not the same one, and
 * mirroring that disjunct would force 'completed' on every virtual renewal with the
 * setting off. Callers AND this with the attempt's final autocapture flag, since a
 * deliberately uncaptured order must never be completed.
 *
 * @param string $flow 'renewal' for either renewal path, anything else for an initial
 *                     subscription payment. Callers own the "is this a subscription
 *                     order at all" question; this only reads the policy.
 */
function wcs_scanpay_wants_completion( array $settings, string $flow ): bool {
	$key = 'renewal' === $flow ? 'wcs_complete_renewal' : 'wcs_complete_initial';
	return 'yes' === ( $settings[ $key ] ?? 'no' );
}

/**
 * Mark a renewal failed, and log why, without ever throwing.
 *
 * The one place in the renewal flow that writes a 'failed' status. Action Scheduler
 * reads an escaping Throwable as a failed action, which would leave the renewal neither
 * charged nor marked failed -- including when the status write is itself what fails, so
 * that attempt is contained here and appended to the same log entry rather than
 * reported again by an outer catch. One transition attempt, one log entry.
 *
 * $diagnostic is raw and internal; $reason is shown to the merchant on the order, so a
 * backend or database exception message must never be passed as one.
 *
 * An ordinary failure to write the status does not throw: WC_Order::update_status()
 * catches Exception itself (class-wc-order.php:407-425) and returns false, and it also
 * returns false without doing anything at :403 for an unsaved order. Only the first of
 * those two leaves WooCommerce's own trail -- a wc_get_logger() line and an "Update
 * status event failed." note on the order -- so both are reported here, in wording that
 * tells the returned-false half from the escaping-Throwable half. Noticing matters:
 * WCS calls payment_failed() only from a 'failed' transition, and only for the
 * subscription's last renewal order, in
 * WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment() on
 * woocommerce_order_status_changed. Symbols, not a line number: subscriptions-core has
 * moved between a vendored path and includes/core/ across WCS releases, so both the
 * path and the numbering drift. A missed write therefore leaves the renewal pending,
 * the subscription unsuspended and no retry scheduled.
 */
function wcs_scanpay_fail_renewal( WC_Order $wco, string $diagnostic, string $reason ): void {
	try {
		if ( ! $wco->update_status( 'failed', $reason ) ) {
			// Mutually exclusive with the catch below -- a return of false never throws --
			// so the two must not read alike: this is WooCommerce declining the write.
			$diagnostic .= ' -- and WooCommerce refused the failed status write';
		}
	} catch ( \Throwable $e ) {
		$diagnostic .= ' -- and the failed status could not be saved: ' . $e->getMessage();
	}
	try {
		scanpay_log( 'error', $diagnostic );
	} catch ( \Throwable $e ) {
		// A broken WooCommerce logger has nowhere safer to report itself, but it must
		// not escape to Action Scheduler either.
		return;
	}
}

/**
 * Handle a scheduled subscription payment; $wco is the renewal order, not the subscription.
 * Action: woocommerce_scheduled_subscription_payment_scanpay
 *
 * The outermost handler of the renewal flow: the require, the construction and
 * everything in scheduled_charge() run inside it, so no Throwable reaches Action
 * Scheduler. The one case it cannot contain is a missing class file, which is a compile
 * error rather than a Throwable.
 */
function wcs_scanpay_scheduled_charge( float $amount, WC_Order $wco ): void {
	static $handler = null;
	try {
		if ( null === $handler ) {
			// require_once, because the catch below turns a constructor throw into a
			// normal return: the next action in the same Action Scheduler batch re-enters
			// this function with $handler still null, and a second require of the same
			// file would be a fatal class redeclaration.
			require_once WC_SCANPAY_DIR . '/library/class-wcs-scanpay-charge.php';
			$handler = new WCS_Scanpay_Charge();
		}
		$handler->scheduled_charge( $amount, $wco );
	} catch ( \Throwable $e ) {
		// Reported here only when nothing below did: scheduled_charge() and charge()
		// route their own failures through the reporter and never rethrow.
		wcs_scanpay_fail_renewal(
			$wco,
			'scheduled charge: unhandled error on #' . $wco->get_id() . ': ' . $e->getMessage(),
			__( 'The Scanpay renewal could not be processed. See the WooCommerce logs for details.', 'scanpay-for-woocommerce' )
		);
	}
}

/**
 * Enforce a >=25h floor on Scanpay renewal retries.
 * Filter: wcs_get_retry_rule_raw
 *
 * Only the interval is raised; emails/statuses/attempt count stay the merchant's.
 * The idempotency key's day (whole days since the renewal order was created) only
 * advances after >=24h, so an earlier retry would replay the cached decline. The
 * 25th hour is clock-skew margin, not correctness.
 *
 * $rule is mixed, not array: WCS core passes an array, but a plugin hooked earlier may
 * return false, a rule object, or anything else.
 */
function wcs_scanpay_retry_rule( $rule, int $retry_number, int $order_id ) {
	if ( ! is_array( $rule ) ) {
		return $rule; // Not a rule array (third-party value); pass through untouched.
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

/**
 * Main plugin loader.
 * Action: plugins_loaded (runs before init)
 */
function wc_scanpay_plugins_loaded() {
	if ( defined( 'WC_SCANPAY_LOADED' ) ) {
		return; // Already initialized.
	}
	define( 'WC_SCANPAY_LOADED', true );
	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		return; // WooCommerce not active.
	}

	// Run version-gated install/migrations. The option is autoloaded, so the check is
	// free. Passing the guard above means WC core (order functions, data stores) is
	// loaded, so upgrade.php may safely use wc_get_orders(). The transient serializes
	// two requests racing the upgrade; upgrade.php's steps are individually idempotent.
	if (
		get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION && ! get_transient( 'wc_scanpay_updating' )
	) {
		set_transient( 'wc_scanpay_updating', true, 5 * MINUTE_IN_SECONDS );
		try {
			require WC_SCANPAY_DIR . '/upgrade.php';
			delete_transient( 'wc_scanpay_updating' );
		} catch ( Throwable $e ) {
			// Deliberately keep the transient on failure: it throttles a failing upgrade to
			// one attempt per 5 minutes. Releasing it here would instead fatal plugins_loaded
			// on every request, taking out wp-admin and leaving the merchant no way to react.
			// upgrade.php stamps the version last, so a retry re-runs the whole migration.
			scanpay_log( 'error', 'Upgrade failed: ' . $e->getMessage() );
		}
	}

	require WC_SCANPAY_DIR . '/gateways/abstract-wc-gateway-scanpay-base.php';
	require WC_SCANPAY_DIR . '/gateways/class-wc-gateway-scanpay-card.php';
	require WC_SCANPAY_DIR . '/gateways/class-wc-gateway-scanpay-mobilepay.php';
	require WC_SCANPAY_DIR . '/gateways/class-wc-gateway-scanpay-applepay.php';

	add_filter( 'allowed_redirect_hosts', 'wc_scanpay_allowed_redirect_hosts' );
	add_filter( 'woocommerce_payment_gateways', 'wc_scanpay_register_gateways' );
	// Priority 5 is load-bearing. Every WooCommerce listener on this hook runs at 10 or
	// later -- queue_transactional_email / send_transactional_email
	// (class-wc-emails.php:141, :145), wc_downloadable_product_permissions
	// (wc-order-functions.php:494), wc_maybe_reduce_stock_levels
	// (wc-stock-functions.php:125), wc_update_total_sales_counts (:992),
	// wc_update_coupon_usage_counts (:1069), and wc_release_stock_for_order at 11
	// (wc-stock-functions.php:495). Capturing first means a failure parks the order
	// 'on-hold' before the customer is mailed "completed" and granted download
	// permissions for goods that were never paid for. WooCommerce's own PayPal gateway
	// captures at the default 10, after both (class-wc-gateway-paypal.php:196); ours is
	// deliberately stricter.
	add_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5, 2 );
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'wc_scanpay_register_blocks' );

	// WooCommerce Subscriptions hooks
	if ( class_exists( 'WC_Subscriptions', false ) ) {
		add_action( 'woocommerce_scheduled_subscription_payment_scanpay', 'wcs_scanpay_scheduled_charge', 10, 2 );
		add_action( 'woocommerce_checkout_after_terms_and_conditions', 'wcs_scanpay_checkout_terms', 10 );
		add_action( 'woocommerce_after_checkout_validation', 'wcs_scanpay_validate_terms', 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'wcs_scanpay_blocks_validate_terms', 10, 2 );
		add_filter( 'wcs_get_retry_rule_raw', 'wcs_scanpay_retry_rule', 10, 3 );
		// Called directly, not on woocommerce_blocks_loaded: WooCommerce fires that from
		// plugins_loaded priority -1, long before this loader runs at 10. Registering here
		// is still well before rest_api_init, where the endpoint schema is assembled.
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			wcs_scanpay_register_store_api_terms();
		}
	}
}
add_action( 'plugins_loaded', 'wc_scanpay_plugins_loaded', 10 );

/**
 * Create the custom tables on activation. Only install.php runs here (it needs no
 * WooCommerce runtime); version-gated migrations run later via wc_scanpay_plugins_loaded().
 * Activation does not fire on auto-updates, so the loader gate remains the primary path.
 */
function wc_scanpay_activate(): void {
	require WC_SCANPAY_DIR . '/install.php';
}
register_activation_hook( __FILE__, 'wc_scanpay_activate' );


/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * Action: before_woocommerce_init
 *
 * HPOS is the only feature worth declaring. Features default to compatible and WC's
 * incompatibility notice lists explicit *negative* declarations only, so the absent
 * cart_checkout_blocks declaration is a no-op rather than an oversight.
 */
function wc_scanpay_before_woocommerce_init() {
	// Autoload ($autoload = true): this may run before WooCommerce's classes are loaded.
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, true ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'wc_scanpay_before_woocommerce_init' );


/**
 * Load the translations.
 * Action: init (load_plugin_textdomain must not run any earlier)
 */
function wc_scanpay_init() {
	load_plugin_textdomain( 'scanpay-for-woocommerce', false, dirname( WC_SCANPAY_BASENAME ) . '/languages' );
}
add_action( 'init', 'wc_scanpay_init', 0 );


/**
 * Initialize the admin interface.
 * Action: admin_init (runs after init)
 */
function wc_scanpay_admin_init() {
	require WC_SCANPAY_DIR . '/admin/orders.php';
	require WC_SCANPAY_DIR . '/admin/settings.php';

	if ( class_exists( 'WC_Subscriptions', false ) ) {
		require WC_SCANPAY_DIR . '/admin/subscriptions.php';
	}
}
add_action( 'admin_init', 'wc_scanpay_admin_init', 0 );

/**
 * Drop the duplicate top-level "Payments" admin menu entry.
 *
 * Its slug is the very WooCommerce > Settings > Payments screen that already holds the
 * gateway list (and our own settings), so keeping both only makes the setup path
 * ambiguous for the merchant. Deliberate and confirmed; the rest of this block is
 * disclosure, not a reopening of the choice.
 *
 * It is a site-wide change to another plugin's menu, applied to every admin on every
 * admin page load. That makes it the one place this plugin does what
 * wc_scanpay_admin_footer_text() explicitly refuses to do, and it is expected to trip the
 * same WordPress.org plugin review that comment cites.
 *
 * The slug is matched as a literal string, and its from= parameter is telemetry rather
 * than a route. It still matches at WC 11.1.0-dev: PaymentsController::add_menu()
 * registers the entry under the same screen with the FROM_PAYMENTS_MENU_ITEM constant
 * appended (Internal/Admin/Settings/PaymentsController.php:72, Payments.php:27). Should
 * WooCommerce change either half, this silently becomes a no-op -- the entry comes back
 * with no error and no log line to say so.
 *
 * Priority 999 is required, not decorative: remove_menu_page() can only remove an entry
 * that is already registered, and WooCommerce spreads its own menu registrations across
 * priorities 9 to 70 (class-wc-admin-menus.php:39-60), with this entry itself added at
 * the default 10 (PaymentsController.php:36). 999 means "after every registration"; do
 * not lower it.
 */
function scanpay_remove_wc_payments_menu() {
	remove_menu_page( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' );
}
add_action( 'admin_menu', 'scanpay_remove_wc_payments_menu', 999 );
