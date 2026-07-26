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
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
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

define( 'WC_SCANPAY_DIR', __DIR__ );
define( 'WC_SCANPAY_URL', untrailingslashit( plugins_url( '', __FILE__ ) ) );

/**
 * Write messages to the WooCommerce log.
 */
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

/**
 * Handle ping (callback) requests sent to /wc-api/wc_scanpay/ or ?wc_scanpay/.
 */
if ( isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
	function wc_scanpay_handle_ping(): void {
		require WC_SCANPAY_DIR . '/callback/wc-scanpay-ping.php';
	}
	add_action( 'woocommerce_api_wc_scanpay', 'wc_scanpay_handle_ping' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	if ( str_ends_with( $uri, 'wc_scanpay/' ) || str_ends_with( $uri, 'wc_scanpay' ) ) {
		return; // short-circuit
	}
}

/**
 * Handle the "thank you" page for completed payments.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'], $_GET['key'] ) && in_array( $_GET['scanpay_type'], [ 'wc', 'wcs', 'wcs_free' ], true ) ) {
	// A genuine thank-you request carries all three params and a known type; anything
	// else falls through to a normal plugin load rather than short-circuiting it. The
	// order-key ownership check happens inside the handler before any polling.
	require WC_SCANPAY_DIR . '/public/wp-scanpay-thankyou.php';
	return; // short-circuit
}

/**
 * Lightweight admin AJAX endpoints (bypass WP/WC bootstrap).
 *
 * The shared secret authenticating these endpoints rides in the X-Scanpay
 * request header (not the query string), so it never reaches access/proxy logs,
 * browser history, or Referer headers. Each endpoint re-verifies it with
 * hash_equals; this is only the dispatch gate.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$file = match ( $_GET['x'] ) {
		'meta' => '/admin/ajax/wp-scanpay-fetch-meta.php',
		'ping' => '/admin/ajax/wp-scanpay-fetch-ping.php',
		'sub'  => '/admin/ajax/wp-scanpay-fetch-sub.php',
		default => null,
	};
	if ( $file ) {
		require WC_SCANPAY_DIR . $file;
		return; // short-circuit
	}
}

/**
 * Register payment gateways with WooCommerce.
 */
function wc_scanpay_register_gateways( array $methods ): array {
	$methods[] = WC_Gateway_Scanpay_Card::class;
	$methods[] = WC_Gateway_Scanpay_Mobilepay::class;
	$methods[] = WC_Gateway_Scanpay_ApplePay::class;
	return $methods;
}

/**
 * Register WooCommerce Blocks payment method support.
 */
function wc_scanpay_register_blocks( $registry ): void {
	if ( ! class_exists( 'WC_Scanpay_Blocks_Support', false ) ) {
		require WC_SCANPAY_DIR . '/gateways/blocks/class-wc-scanpay-blocks-support.php';
	}
	$registry->register( new WC_Scanpay_Blocks_Support() );
}

/**
 * Allow redirects to betal.scanpay.dk.
 */
function wp_scanpay_allowed_redirect_hosts( array $hosts ): array {
	$hosts[] = 'betal.scanpay.dk';
	return $hosts;
}

/**
 * Capture payments when orders are marked as completed.
 */
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
 * The single predicate behind both renderers (classic and Blocks) and both validators
 * (classic and Store API), so the checkbox can never be enforced without having been
 * rendered, or rendered without being enforced.
 *
 * Deliberately independent of every gateway. The consent belongs to the subscription in
 * the cart, not to a payment method, so it applies whichever gateway the customer picks
 * -- including third-party ones -- and stays active while our own gateways are disabled.
 *
 * Returns '' unless wcs_terms holds a positive page id whose page is still exactly
 * 'publish'. That folds the disabled states ('0' and a stored '') together with every
 * stale-page state: the picker only offers published pages, but the stored id goes stale
 * once the page is drafted, made private, trashed, or deleted. Returning the resolved URL
 * rather than the id is what keeps get_page_link()'s unguarded post dereference inside
 * the guard -- on a deleted page it warns, and a trashed page would 404 the customer.
 *
 * @return string Terms page URL, or '' when the checkbox is disabled.
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
 * Add subscription terms checkbox on the checkout page (for WCS).
 * Action: woocommerce_checkout_after_terms_and_conditions
 *
 * This hook fires outside any gateway, next to WooCommerce's own terms checkbox
 * (templates/checkout/terms.php), so the checkbox renders once per checkout no matter
 * which payment method the customer selects. Same template fragment as before, so an
 * update_order_review refresh clears the tick exactly as it always did.
 */
function wcs_scanpay_checkout_terms() {
	require WC_SCANPAY_DIR . '/public/wcs-scanpay-checkout-terms.php';
}

/**
 * Declare the 'scanpay' extension namespace on the Store API checkout endpoint.
 * Action: wc_scanpay_plugins_loaded() (direct call)
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
 * otherwise skippable via a direct POST. Reject the order when the terms checkbox is shown
 * (WCS active, cart has a subscription, a published terms page is configured) but was not
 * accepted. Not conditioned on the payment method: wcs_scanpay_checkout_terms() renders the
 * checkbox once for the whole checkout, so enforcing it per gateway would leave it shown
 * but skippable for every gateway but one.
 *
 * @param array    $data   Posted checkout data. Unused; part of the hook signature.
 * @param WP_Error $errors Accumulated validation errors.
 */
function wcs_scanpay_validate_terms( array $data, WP_Error $errors ): void {
	if ( ! class_exists( 'WC_Subscriptions_Cart', false ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	if ( '' === wcs_scanpay_terms_url() ) {
		return; // Terms checkbox is disabled or its configured page is not published.
	}
	// The checkout nonce is verified by WC_Checkout::process_checkout() before this action.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	if ( empty( $_POST['wcssp-terms'] ) ) {
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
 * @param WC_Order        $order   Draft order built from the request (unused; hook signature).
 * @param WP_REST_Request $request Store API checkout request.
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
 * Handle scheduled subscription payments (charges).
 * Action: woocommerce_scheduled_subscription_payment_scanpay
 *
 * @param float    $amount Amount to charge.
 * @param WC_Order $wco    Renewal order.
 */
function wcs_scanpay_scheduled_charge( float $amount, WC_Order $wco ): void {
	static $handler = null;
	if ( null === $handler ) {
		require WC_SCANPAY_DIR . '/library/class-wcs-scanpay-charge.php';
		$handler = new WCS_Scanpay_Charge();
	}
	$handler->scheduled_charge( $amount, $wco );
}

/**
 * Enforce a >=25h floor on Scanpay renewal retries (filter: wcs_get_retry_rule_raw).
 *
 * Only the interval is raised; emails/statuses/attempt count stay the merchant's.
 * The idempotency key's day (whole days since the renewal order was created) only
 * advances after >=24h, so an earlier retry would replay the cached decline. The
 * 25th hour is clock-skew margin, not correctness.
 *
 * @param mixed $rule         Retry rule for this attempt. WCS core passes an array, but an
 *                            earlier-hooked plugin may return false, a rule object, or anything else.
 * @param int   $retry_number Position in the retry queue.
 * @param int   $order_id     Renewal order ID.
 * @return mixed
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
		return; // Already initialized
	}
	define( 'WC_SCANPAY_LOADED', true );
	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		return; // WooCommerce not active
	}

	// Run version-gated install/migrations. The option is autoloaded, so the check is
	// free. Passing the guard above means WC core (order functions, data stores) is
	// loaded, so upgrade.php may safely use wc_get_orders(). The transient serialises
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

	add_filter( 'allowed_redirect_hosts', 'wp_scanpay_allowed_redirect_hosts' );
	add_filter( 'woocommerce_payment_gateways', 'wc_scanpay_register_gateways' );
	add_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5, 2 );
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'wc_scanpay_register_blocks' );

	// WooCommerce Subscriptions hooks
	if ( class_exists( 'WC_Subscriptions', false ) ) {
		add_action( 'woocommerce_scheduled_subscription_payment_scanpay', 'wcs_scanpay_scheduled_charge', 3, 2 );
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
 */
function wc_scanpay_before_woocommerce_init() {
	// Note: Our plugin may load before WC, so class_exists is set to autoload to ensure the class is available.
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, true ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'wc_scanpay_before_woocommerce_init' );


/**
 * Initialize plugin (i18n needs to be initialized here).
 * Action: init (runs after plugins_loaded)
 */
function wc_scanpay_init() {
	load_plugin_textdomain( 'scanpay-for-woocommerce', false, 'scanpay-for-woocommerce/languages' );
}
add_action( 'init', 'wc_scanpay_init', 0 );


/**
 *  Initialize the admin interface.
 *  action: admin_init (runs after init)
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
 * Remove the WooCommerce Payments "Payments" admin menu entry.
 *
 * This menu item is injected by the WooCommerce Payments plugin and
 * serves as a promotional shortcut to its settings. It is not part of
 * WooCommerce core navigation. We remove it to make the admin UI
 * cleaner and to avoid confusion.
 */
function scanpay_remove_wc_payments_menu() {
	// Remove top-level "Payments" (localized as "Betalinger") menu entry.
	remove_menu_page( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' );
}
add_action( 'admin_menu', 'scanpay_remove_wc_payments_menu', 999 );
