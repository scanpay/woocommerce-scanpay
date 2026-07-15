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
const WC_SCANPAY_URI_AUTOCPT  = '_scanpay_autocpt';
const WC_SCANPAY_URI_STATUS   = '_scanpay_status';

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
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'], $_GET['s'] ) ) {
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
 * Add subscription terms checkbox on the checkout page (for WCS).
 * Action: woocommerce_review_order_before_submit
 */
function wcs_scanpay_checkout_terms() {
	require WC_SCANPAY_DIR . '/public/wcs-scanpay-checkout-terms.php';
}

/**
 * Server-side enforcement of the subscription terms checkbox (classic checkout).
 * Action: woocommerce_after_checkout_validation
 *
 * The woocommerce_form_field( 'required' => true ) only renders a CSS asterisk; WooCommerce
 * validates only fields registered in woocommerce_checkout_fields, so the checkbox is
 * otherwise skippable via a direct POST. Reject the order when the terms checkbox is shown
 * (WCS active, cart has a subscription, a terms page is configured, gateway is scanpay*)
 * but was not accepted.
 *
 * @param array    $data   Posted checkout data.
 * @param WP_Error $errors Accumulated validation errors.
 */
function wcs_scanpay_validate_terms( array $data, WP_Error $errors ): void {
	if ( ! str_starts_with( (string) ( $data['payment_method'] ?? '' ), 'scanpay' ) ) {
		return; // Only the (card) scanpay gateway supports subscriptions.
	}
	if ( ! class_exists( 'WC_Subscriptions_Cart', false ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) || '0' === ( $settings['wcs_terms'] ?? '0' ) ) {
		return; // Terms checkbox is disabled.
	}
	// The checkout nonce is verified by WC_Checkout::process_checkout() before this action.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	if ( empty( $_POST['wcssp-terms'] ) ) {
		$errors->add( 'wcssp-terms', 'Du skal acceptere abonnementsbetingelserne for at gennemføre købet.' );
	}
}

/**
 * Server-side enforcement of the subscription terms checkbox (Blocks / Store API checkout).
 * Action: woocommerce_store_api_checkout_update_order_from_request
 *
 * The woocommerce_after_checkout_validation action does not fire for the Store API checkout
 * used by the Cart/Checkout blocks. The checkbox is rendered inside the Scanpay payment content
 * (checkout.ts) and its state is sent as the 'wcssp-terms' payment_data entry; re-check it
 * here so a crafted request cannot bypass acceptance. Throwing RouteException aborts the
 * checkout with a 400 and surfaces the message to the customer.
 *
 * @param WC_Order        $order   Draft order built from the request (unused; hook signature).
 * @param WP_REST_Request $request Store API checkout request.
 */
function wcs_scanpay_blocks_validate_terms( WC_Order $order, WP_REST_Request $request ): void {
	if ( ! str_starts_with( (string) $request['payment_method'], 'scanpay' ) ) {
		return; // Only the (card) scanpay gateway supports subscriptions.
	}
	if ( ! class_exists( 'WC_Subscriptions_Cart', false ) || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
		return;
	}
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) || '0' === ( $settings['wcs_terms'] ?? '0' ) ) {
		return; // Terms checkbox is disabled.
	}
	$payment_data = array_column( (array) ( $request['payment_data'] ?? [] ), 'value', 'key' );
	if ( empty( $payment_data['wcssp-terms'] ) ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'wcssp_terms_required',
			'Du skal acceptere abonnementsbetingelserne for at gennemføre købet.',
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
		require WC_SCANPAY_DIR . '/upgrade.php';
		delete_transient( 'wc_scanpay_updating' );
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
		add_action( 'woocommerce_review_order_before_submit', 'wcs_scanpay_checkout_terms', 10 );
		add_action( 'woocommerce_after_checkout_validation', 'wcs_scanpay_validate_terms', 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'wcs_scanpay_blocks_validate_terms', 10, 2 );
		add_filter( 'wcs_get_retry_rule_raw', 'wcs_scanpay_retry_rule', 10, 3 );
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

	// TODO: share this check with wc_scanpay_plugins_loaded
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
