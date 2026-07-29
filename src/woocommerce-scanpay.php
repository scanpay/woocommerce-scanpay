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
 * Also requires ext-curl: WC_Scanpay_Client has no fallback transport. No plugin-header
 * field covers PHP extensions, hence the prose.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

const WC_SCANPAY_DIR          = __DIR__;
const WC_SCANPAY_VERSION      = '{{ VERSION }}';
const WC_SCANPAY_DASHBOARD    = 'https://dashboard.scanpay.dk/';
const WC_SCANPAY_URI_SETTINGS = 'woocommerce_scanpay_settings';
const WC_SCANPAY_URI_SHOPID   = '_scanpay_shopid';
const WC_SCANPAY_URI_PAYID    = '_scanpay_payid';
const WC_SCANPAY_URI_PTIME    = '_scanpay_payid_time';
const WC_SCANPAY_URI_SUBID    = '_scanpay_subid';
const WC_SCANPAY_URI_COMPLETE = '_scanpay_complete';

/**
 * Write to the WooCommerce log. Never throws: the logger, its handlers and the message filter
 * are all pluggable and none of them catch, so a failing handler would escape into the flow
 * that was writing the line -- skipping a paid order's on-hold parking, or pinning the sync
 * cursor. No caller can act on that, so error_log() takes the message and the reason both.
 */
function scanpay_log( string $level, string $msg ): void {
	static $logger = null;
	try {
		if ( null === $logger ) {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			$logger = wc_get_logger();
		}
		$logger->log( $level, $msg, [ 'source' => 'wc-scanpay' ] );
	} catch ( \Throwable $e ) {
		// Not memoized: a handler can fail on one message and not the next.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The WooCommerce log is what failed; this is the only channel left.
		error_log( "scanpay [$level]: $msg -- the WooCommerce logger failed: " . $e->getMessage() );
	}
}

/*
 * Ping (callback) endpoint: /wc-api/wc_scanpay/ or ?wc-api=wc_scanpay. The bootstrap is
 * skipped only when the URI really is that endpoint -- an X-Signature header elsewhere
 * must still get a normal plugin load.
 */
if ( isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
	function wc_scanpay_handle_ping(): void {
		// Not left to wc_scanpay_init(): the return below skips the bootstrap. Sync writes
		// three translated order notes from this request, which no later one repairs.
		load_plugin_textdomain( 'scanpay-for-woocommerce', false, basename( WC_SCANPAY_DIR ) . '/languages' );
		require WC_SCANPAY_DIR . '/callback/wc-scanpay-ping.php';
	}
	// Since WC 9.0 fired on parse_request by LegacyRestApiStub, not the removed WC_API.
	add_action( 'woocommerce_api_wc_scanpay', 'wc_scanpay_handle_ping' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only compared with str_ends_with(); never echoed, stored or put in a query.
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	if ( str_ends_with( $uri, 'wc_scanpay/' ) || str_ends_with( $uri, 'wc_scanpay' ) ) {
		return;
	}
}

/*
 * Payment-return ("thank you") page. A genuine return carries all three params and a known
 * type; anything else falls through to a normal load. The handler verifies order-key
 * ownership before it polls.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; the handler checks the order key first.
if ( isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'], $_GET['key'] ) && in_array( $_GET['scanpay_type'], [ 'wc', 'wcs', 'wcs_free' ], true ) ) {
	require WC_SCANPAY_DIR . '/public/wp-scanpay-thankyou.php';
	return;
}

/*
 * Lightweight admin AJAX endpoints, bypassing the WP/WC bootstrap. The shared secret rides in
 * the X-Scanpay header, not the query string, so it stays out of access logs, history and
 * Referer. Dispatch gate only; each endpoint re-verifies it with hash_equals(). A secret
 * rather than a capability because plugin load precedes pluggable.php, and deferring would buy
 * the very bootstrap this exists to skip. Unscoped because the screens that print it already
 * require order-editing access, all-or-nothing on a WooCommerce shop, and the endpoints expose
 * a read-only subset of them. Settled: reopen only for a role granting *partial* order access.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; the endpoints authenticate with the secret, as above.
if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'] ) ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The match arms are the whitelist; anything else yields null and falls through.
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

/**
 * Register the three gateways.
 *
 * The requires belong here, not in wc_scanpay_plugins_loaded(): WC_Payment_Gateways::init()
 * applies this filter immediately before its own class_exists()/new loop, so the four class
 * files are parsed only on the requests that build a gateway list -- classic checkout, the
 * add-payment-method page, the order screens, transactional mail, REST, and any Cart or Mini
 * Cart block -- rather than on every request to the site. WC_SCANPAY_URL, which the
 * constructors read, is defined three lines before this filter is registered.
 *
 * require_once, not require: WC_Settings_Payment_Gateways::save() calls init() a second time
 * after a save, so this filter fires twice in that one request.
 */
function wc_scanpay_register_gateways( array $methods ): array {
	require_once WC_SCANPAY_DIR . '/gateways/abstract-wc-gateway-scanpay-base.php';
	require_once WC_SCANPAY_DIR . '/gateways/class-wc-gateway-scanpay-card.php';
	require_once WC_SCANPAY_DIR . '/gateways/class-wc-gateway-scanpay-mobilepay.php';
	require_once WC_SCANPAY_DIR . '/gateways/class-wc-gateway-scanpay-applepay.php';
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

/** Capture on completion, when the merchant has set autocapture to 'completed'. */
function wc_scanpay_order_status_completed( int $oid, WC_Order $wco ): void {
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
		// 'off' = manual capture; 'on' = already captured at Scanpay.
		return;
	}
	scanpay_log( 'debug', "Order #$oid marked as completed, attempting capture." );
	require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';
	// Failure parks the order 'on-hold', never 'failed'; success leaves 'completed' as-is.
	WC_Scanpay_Capture::capture_or_hold( $wco );
}

/**
 * The subscription-terms page URL, or '' when the checkbox must not be shown.
 *
 * The single predicate behind both renderers (classic and Blocks) and both validators, so the
 * checkbox is never enforced unrendered, or the reverse. Gateway-independent: the consent
 * belongs to the subscription in the cart, so it covers third-party gateways too.
 *
 * Here, not in public/subscriptions.php, which loads only with the full WC_Subscriptions
 * plugin: the Blocks payload builder guards on WC_Subscriptions_Cart, which subscriptions-core
 * ships alone, so behind that guard this would be a fatal.
 *
 * '' folds the disabled states ('0' and a stored '') in with every stale one -- drafted,
 * private, trashed or deleted -- and keeps get_page_link()'s unguarded post dereference
 * inside that guard.
 *
 * Memoized: Blocks builds the payment-method payload on both the checkout and the cart enqueue
 * hooks, and get_page_link() is a permalink build. Nothing writes the setting mid-request.
 */
function wcs_scanpay_terms_url(): string {
	static $url = null;
	if ( null !== $url ) {
		return $url;
	}
	$url      = '';
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) ) {
		return $url;
	}
	$page_id = (int) ( $settings['wcs_terms'] ?? 0 );
	if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
		return $url;
	}
	$url = (string) get_page_link( $page_id );
	return $url;
}

/**
 * Whether WCS is changing a subscription's payment method rather than paying for an order.
 *
 * The "order" our payment code is then handed is the subscription itself -- see
 * wc_scanpay_subref(). One definition, because several callers must agree on it.
 */
function wcs_scanpay_is_payment_method_change(): bool {
	return class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false )
		&& WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
}

/**
 * Whether a payment attempt asks WooCommerce to force the 'completed' status once the
 * payment syncs, per wcs_complete_initial / wcs_complete_renewal.
 *
 * Request-time policy, persisted in WC_SCANPAY_URI_COMPLETE by the three paths that create a
 * payment attempt; sync obeys the persisted value, so toggling the setting mid-payment cannot
 * reinterpret an attempt the store already accepted. Deliberately not the payload's autocapture
 * expression, which folds in wc_complete_virtual: mirroring it would complete every virtual
 * renewal with the setting off. Callers AND this with the attempt's autocapture flag -- an
 * uncaptured order must never be completed.
 *
 * @param string $flow 'renewal' for either renewal path, anything else for an initial payment.
 */
function wcs_scanpay_wants_completion( array $settings, string $flow ): bool {
	$key = 'renewal' === $flow ? 'wcs_complete_renewal' : 'wcs_complete_initial';
	return 'yes' === ( $settings[ $key ] ?? 'no' );
}

/**
 * Mark a renewal failed without ever throwing.
 *
 * The one place in the renewal flow that writes 'failed'. Action Scheduler reads an escaping
 * Throwable as a failed action, leaving the renewal neither charged nor marked failed, so even
 * a failing status write is contained here and appended to the same log entry. $diagnostic is
 * internal; $reason reaches the merchant, so never pass a backend message.
 *
 * update_status() also fails without throwing: it catches Exception itself (leaving a logger
 * line and an "Update status event failed." note) and returns false outright for an unsaved
 * order, hence the two wordings below. WCS hangs payment_failed() off the 'failed' transition
 * in WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment(), so a missed write
 * leaves the renewal pending, the subscription unsuspended and no retry scheduled.
 */
function wcs_scanpay_fail_renewal( WC_Order $wco, string $diagnostic, string $reason ): void {
	try {
		if ( ! $wco->update_status( 'failed', $reason ) ) {
			// Not the catch below -- false never throws. This is WooCommerce declining.
			$diagnostic .= ' -- and WooCommerce refused the failed status write';
		}
	} catch ( \Throwable $e ) {
		$diagnostic .= ' -- and the failed status could not be saved: ' . $e->getMessage();
	}
	scanpay_log( 'error', $diagnostic );
}

function wc_scanpay_plugins_loaded() {
	if ( defined( 'WC_SCANPAY_LOADED' ) ) {
		return; // Already initialized.
	}
	define( 'WC_SCANPAY_LOADED', true );
	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		return; // WooCommerce not active.
	}

	// Version-gated install/migrations; the option is autoloaded, so the check is free. The
	// guard above means WC core is loaded, so upgrade.php may use wc_get_orders(). The
	// transient serializes two requests racing it; upgrade.php's steps are idempotent.
	if (
		get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION && ! get_transient( 'wc_scanpay_updating' )
	) {
		set_transient( 'wc_scanpay_updating', true, 5 * MINUTE_IN_SECONDS );
		try {
			require WC_SCANPAY_DIR . '/upgrade.php';
			delete_transient( 'wc_scanpay_updating' );
		} catch ( Throwable $e ) {
			// Keep the transient on failure: it throttles a failing upgrade to one attempt
			// per 5 minutes, rather than fatalling plugins_loaded on every request and taking
			// out wp-admin. upgrade.php stamps the version last, so a retry redoes it all.
			scanpay_log( 'error', 'Upgrade failed: ' . $e->getMessage() );
		}
	}

	// Down here: ping, payment return and admin AJAX all return before this, and never read a
	// URL. plugins_url(), not WP_PLUGIN_URL . basename(): mu-plugins, symlinks, https proxies.
	define( 'WC_SCANPAY_URL', untrailingslashit( plugins_url( '', __FILE__ ) ) );

	add_filter( 'allowed_redirect_hosts', 'wc_scanpay_allowed_redirect_hosts' );
	add_filter( 'woocommerce_payment_gateways', 'wc_scanpay_register_gateways' );
	// Priority 5 is load-bearing: every WooCommerce listener here runs at 10 or later -- the
	// transactional emails, wc_downloadable_product_permissions, the stock and sales counts.
	// Capturing first parks a failure 'on-hold' before the customer is mailed "completed" and
	// granted downloads for unpaid goods. WC's own PayPal gateway captures at 10; we do not.
	add_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5, 2 );
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'wc_scanpay_register_blocks' );

	// The full WCS plugin, not subscriptions-core: see that file's header.
	if ( class_exists( 'WC_Subscriptions', false ) ) {
		require WC_SCANPAY_DIR . '/public/subscriptions.php';
	}
}
add_action( 'plugins_loaded', 'wc_scanpay_plugins_loaded', 10 );

/**
 * Installation hook; needs no WooCommerce runtime. We avoid register_activation_hook()
 * as it would add unnecessary overhead. Note that basename() does not work if the plugin is
 * symlinked under a different name in the plugins dir.
 */
function wc_scanpay_activate(): void {
	require WC_SCANPAY_DIR . '/install.php';
}
add_action( 'activate_' . basename( WC_SCANPAY_DIR ) . '/woocommerce-scanpay.php', 'wc_scanpay_activate' );


/**
 * Declare HPOS compatibility -- the only feature worth declaring: features default to
 * compatible and WC's incompatibility notice lists explicit *negative* declarations only, so
 * the absent cart_checkout_blocks declaration is a no-op rather than an oversight.
 */
function wc_scanpay_before_woocommerce_init() {
	// $autoload = true: this may run before WooCommerce's own classes are loaded.
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, true ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'wc_scanpay_before_woocommerce_init' );


/**
 * Load the translations on 'init', the earliest load_plugin_textdomain() may run. The
 * directory is basename( WC_SCANPAY_DIR ), not plugin_basename( __FILE__ ): they differ only
 * for a checkout symlinked into plugins/ under another name, which README.md tells developers
 * to avoid. Neither that fix nor caching the result is worth its per-request cost.
 */
function wc_scanpay_init() {
	load_plugin_textdomain( 'scanpay-for-woocommerce', false, basename( WC_SCANPAY_DIR ) . '/languages' );
}
add_action( 'init', 'wc_scanpay_init', 0 );


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
 * Its slug is the very WooCommerce > Settings > Payments screen that already holds the gateway
 * list, so keeping both only makes the setup path ambiguous. Deliberate and confirmed; the
 * rest is disclosure, not a reopening. It is a site-wide change to another plugin's menu on
 * every admin page load -- the one place this plugin does what wc_scanpay_admin_footer_text()
 * refuses to, and expected to trip the same WordPress.org review that comment cites.
 *
 * The slug is matched literally (from= is telemetry, not a route) and still matches at WC
 * 11.1 via PaymentsController::add_menu(); should either half change, this becomes a silent
 * no-op. Priority 999 because remove_menu_page() needs the entry registered: the entry itself
 * is added at the default 10, but WooCommerce spreads its admin_menu registrations across 1 to
 * 99, so leave the margin. Do not lower it.
 */
function scanpay_remove_wc_payments_menu() {
	remove_menu_page( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' );
}
add_action( 'admin_menu', 'scanpay_remove_wc_payments_menu', 999 );
