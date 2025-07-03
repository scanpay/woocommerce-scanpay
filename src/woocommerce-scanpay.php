<?php

/**
 * Plugin Name: Scanpay for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/scanpay-for-woocommerce/
 * Description: Accept payments in WooCommerce with a secure payment gateway.
 * Author: Scanpay
 * Author URI: https://scanpay.dk
 * Version: {{ VERSION }}
 * Requires Plugins: woocommerce
 * Requires at least: 4.7.0
 * Requires PHP: 7.4
 * WC requires at least: 3.6.0
 * WC tested up to: {{ WC_VERSION_TESTED }}
 * Text Domain: scanpay-for-woocommerce
 * Domain Path: /languages/
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

defined( 'ABSPATH' ) || exit();

const WC_SCANPAY_VERSION      = '{{ VERSION }}';
const WC_SCANPAY_MIN_PHP      = '7.4.0';
const WC_SCANPAY_MIN_WC       = '3.6.0';
const WC_SCANPAY_DASHBOARD    = 'https://dashboard.scanpay.dk/';
const WC_SCANPAY_URI_SETTINGS = 'woocommerce_scanpay_settings';
const WC_SCANPAY_URI_SHOPID   = '_scanpay_shopid';
const WC_SCANPAY_URI_PAYID    = '_scanpay_payid';
const WC_SCANPAY_URI_PTIME    = '_scanpay_payid_time';
const WC_SCANPAY_URI_SUBID    = '_scanpay_subid';
const WC_SCANPAY_URI_AUTOCPT  = '_scanpay_autocpt';
const WC_SCANPAY_URI_STATUS   = '_scanpay_status';

define( 'WC_SCANPAY_DIR', __DIR__ );
define( 'WC_SCANPAY_URL', set_url_scheme( WP_PLUGIN_URL ) . '/scanpay-for-woocommerce' );

/**
 * Write messages to the WooCommerce log.
 */
function scanpay_log( string $level, string $msg ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $msg, [ 'source' => 'woo-scanpay' ] );
	}
}

/**
 * Handle ping (callback) requests sent to /wc-api/wc_scanpay/.
 */
if (
	isset( $_SERVER['HTTP_X_SIGNATURE'] ) &&
	str_ends_with( $_SERVER['REQUEST_URI'] ?? '', 'wc_scanpay/' )
) {
	function wc_scanpay_handle_ping() {
		require WC_SCANPAY_DIR . '/hooks/wc-scanpay-ping.php';
	}
	add_action( 'woocommerce_api_wc_scanpay', 'wc_scanpay_handle_ping' );
	return; // Exit early
}

/**
 * Endpoints for admin AJAX lookups. Bypassing WordPress/WooCommerce.
 */
if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'], $_GET['s'] ) ) {
	switch ( $_GET['x'] ) {
		case 'meta':
			require WC_SCANPAY_DIR . '/hooks/ajax/wp-scanpay-fetch-meta.php';
			break;
		case 'ping':
			require WC_SCANPAY_DIR . '/hooks/ajax/wp-scanpay-fetch-ping.php';
			break;
		case 'sub':
			require WC_SCANPAY_DIR . '/hooks/ajax/wp-scanpay-fetch-sub.php';
			break;
	}
	return; // Exit early
}

/**
 * Handle the "thank you" page.
 * Triggered when users return after payment.
 */
if ( isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'] ) ) {
	require WC_SCANPAY_DIR . '/hooks/wp-scanpay-thankyou.php';
	return; // Exit early
}

/**
 * Register payment gateways with WooCommerce.
 * Filter: woocommerce_payment_gateways
 */
function wc_scanpay_register_gateways( array $methods ): array {
	$methods[] = 'WC_Scanpay_Gateway';
	$methods[] = 'WC_Scanpay_Gateway_Mobilepay';
	$methods[] = 'WC_Scanpay_Gateway_ApplePay';
	return $methods;
}

/**
 * Register support for WooCommerce Blocks.
 * Action: woocommerce_blocks_payment_method_type_registration
 */
function wc_scanpay_register_blocks( $registry ) {
	require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-blocks-support.php';
	$registry->register( new WC_Scanpay_Blocks_Support() );
}

/**
 * Allow redirects to betal.scanpay.dk.
 * Filter: allowed_redirect_hosts
 */
function wp_scanpay_allowed_redirect_hosts( array $hosts ): array {
	$hosts[] = 'betal.scanpay.dk';
	return $hosts;
}

/**
 * Capture payments when orders are marked as completed.
 * Action: woocommerce_order_status_completed
 */
function wc_scanpay_order_status_completed( int $oid, object $wco ) {
	scanpay_log( 'debug', "[Capture after Complete] processing capture for order #$oid" );
	require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-capture.php';
	$res = WC_Scanpay_Capture::capture( $wco );
	$str = $res['msg'] ?? 'unknown error';

	switch ( $res['status'] ) {
		case 'ok':
			$wco->add_order_note( "Scanpay captured $str.", false, true );
			break;
		case 'failed':
			scanpay_log( 'warning', "Capture failed on order #$oid: $str" );
			$wco->update_status( 'failed', "Scanpay capture failed: $str.", true );
			break;
		case 'aborted':
			scanpay_log( 'warning', "Capture aborted on order #$oid: $str" );
			$wco->update_status( 'failed', "Scanpay capture aborted: $str.", true );
			break;
		case 'skipped':
			scanpay_log( 'debug', "[Capture after Complete] skipped on order #$oid: $str" );
			break;
	}
}

/**
 * Add subscription terms checkbox on the checkout page (for WCS).
 * Action: woocommerce_review_order_before_submit
 */
function wcs_scanpay_checkout_terms() {
	require WC_SCANPAY_DIR . '/hooks/wcs-scanpay-checkout-terms.php';
}

/**
 * Handle scheduled subscription payments (charges).
 * Action: woocommerce_scheduled_subscription_payment_scanpay
 *
 * @param float    $amount  Amount to charge.
 * @param WC_Order $wco     WooCommerce order object.
 */
function wcs_scanpay_scheduled_charge( float $amount, object $wco ) {
	require_once WC_SCANPAY_DIR . '/library/class-wcs-scanpay-sub.php';
	$sub = new WCS_Scanpay_Sub();
	$sub->scheduled_charge( $amount, $wco );
}

/**
 * Main plugin loader.
 * Action: plugins_loaded (runs before init)
 */
function wc_scanpay_plugins_loaded() {
	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		return; // WooCommerce not active
	}
	require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway.php';
	require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-mobilepay.php';
	require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-applepay.php';

	add_filter( 'allowed_redirect_hosts', 'wp_scanpay_allowed_redirect_hosts' );
	add_filter( 'woocommerce_payment_gateways', 'wc_scanpay_register_gateways' );
	add_action( 'woocommerce_order_status_completed', 'wc_scanpay_order_status_completed', 5, 2 );
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'wc_scanpay_register_blocks' );

	// WooCommerce Subscriptions hooks
	if ( class_exists( 'WC_Subscriptions', false ) ) {
		add_action( 'woocommerce_scheduled_subscription_payment_scanpay', 'wcs_scanpay_scheduled_charges', 3, 2 );
		add_action( 'woocommerce_review_order_before_submit', 'wcs_scanpay_checkout_terms', 10 );
	}
}
add_action( 'plugins_loaded', 'wc_scanpay_plugins_loaded', 10 );


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
	require WC_SCANPAY_DIR . '/admin/init.php';
}
add_action( 'admin_init', 'wc_scanpay_admin_init', 0 );
