<?php
declare(strict_types = 1);

/*
 * Version: 2.0.0
 * Requires at least: 6.3.0
 * Requires PHP: 8.0
 * WC requires at least: 6.9.0
 * WC tested up to: 8.5.1
 * Plugin Name: Scanpay for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/scanpay-for-woocommerce/
 * Description: Accept payments in WooCommerce with a secure payment gateway.
 * Author: Scanpay
 * Author URI: https://scanpay.dk
 * Text Domain: scanpay-for-woocommerce
 * Domain Path: /languages
 * License: MIT License
 * License URI: https://opensource.org/licenses/MIT
 */

/*
	BUGS:
		*   "Renew Now" in "My Account" does not work. This is because the 'woocommerce_scheduled_subscription_*'
			hook is expecting a syncronous response, but we are using an async queue system for charges...
			We can't even disable the feature, because Woo is developed by ... well, you know.

	TODO:
		*   Replace deprecated wc_get_log_file_path() in admin-options.php.
		*   Remove scanpay_tmp_warning()
		*   Convert all strings to __() translations
		*   Improve error messages in payment-link.php
		*   wc:  add support for negative line items (discounts)
		*   wc:  Show last4 and cardtype in order meta.
		*   wcs: Save last4 and cardtype in subscriptions meta.
		*   wcs: Show cardtype + last4 in the change method (filter woocommerce_my_subscriptions_payment_method)
		*   wcs: renew successurl should be the subscription page.
		*   wcs: implement subscription_payment_method_delayed_change
		*   wcs: manual renewals should be instant (bypass queue or force ping/seq)

	OPTIMIZATIONS:
		*   Find a way to disable autoload of settings (OOP BS).
		*   Deprecate old ping URL (wc_scanpay).
		*   Find a better way to load pay.css

*/


defined( 'ABSPATH' ) || exit();

const WC_SCANPAY_VERSION      = '2.0.0';
const WC_SCANPAY_MIN_PHP      = '8.0.0';
const WC_SCANPAY_MIN_WC       = '6.9.0';
const WC_SCANPAY_DASHBOARD    = 'https://dashboard.scanpay.dk/';
const WC_SCANPAY_URI_SETTINGS = 'woocommerce_scanpay_settings';
const WC_SCANPAY_URI_SHOPID   = '_scanpay_shopid';
const WC_SCANPAY_URI_PAYID    = '_scanpay_payid';
const WC_SCANPAY_URI_PTIME    = '_scanpay_payid_time';
const WC_SCANPAY_URI_SUBID    = '_scanpay_subid';
define( 'WC_SCANPAY_DIR', __DIR__ );
define( 'WC_SCANPAY_URL', set_url_scheme( WP_PLUGIN_URL ) . '/scanpay-for-woocommerce' );

function scanpay_log( string $level, string $msg ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $msg, [ 'source' => 'woo-scanpay' ] );
	}
}

function scanpay_tmp_warning(): void {
	add_action( 'admin_notices', function () {
		$link = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' )
		?>
		<div class="notice notice-warning">
			<p>
				The <b>scanpay</b> plugin has received a <b><u>complete</u></b> rewrite with many improvements and changes.
				Please review your plugin <a href="<?php echo $link; ?>">settings</a>. Feedback and bug reports are much appreciated at
				<a target="_blank" href="https://chat.scanpay.dev/">chat.scanpay.dev</a>.
				We will remove this notice in 48 hours.
			</p>
		</div>
		<?php
	} );
}

/*
*   Hooks for Scanpay WC-API requests including ping handler.
*   Shortcut saves us >50kB memory and time.
*/

if ( isset( $_SERVER['HTTP_X_SIGNATURE'], $_SERVER['REQUEST_URI'] ) ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( str_ends_with( $_SERVER['REQUEST_URI'], 'scanpay_ping/' ) ) {
		add_action( 'woocommerce_api_scanpay_ping', function () {
			require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ping.php';
		} );
		return;
	}

	// Deprecated ping URL (will be removed when we have moved all shops to the new URL)
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( str_ends_with( $_SERVER['REQUEST_URI'], 'wc_scanpay/' ) ) {
		add_action( 'woocommerce_api_wc_scanpay', function () {
			require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ping.php';
		} );
		return;
	}
}

if ( isset( $_SERVER['HTTP_X_SCANPAY'] ) ) {
	add_action( 'woocommerce_api_scanpay_ajax_meta', function () {
		require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ajax-meta.php';
	} );
	add_action( 'woocommerce_api_scanpay_ajax_subs', function () {
		require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ajax-subs.php';
	} );
	add_action( 'woocommerce_api_scanpay_ajax_ping_mtime', function () {
		require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ajax-ping-mtime.php';
	} );
	return;
}

function scanpay_admin_hooks() {
	global $pagenow;
	if ( 'plugins.php' === $pagenow || ! class_exists( 'WooCommerce' ) ) {
		// Add helpful links to the plugins table and check compatibility
		add_filter( 'plugin_action_links_scanpay-for-woocommerce/woocommerce-scanpay.php', function ( $links ) {
			return [
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' ) . '">'
				. __( 'Settings', 'scanpay-for-woocommerce' ) . '</a>',
				...$links,
			];
		}, 'active' );
		scanpay_tmp_warning();
		require WC_SCANPAY_DIR . '/includes/compatibility.php';
	}

	if ( 'index.php' === $pagenow ) {
		scanpay_tmp_warning();
	}

	// Add plugin version number to JS: wcSettings.admin
	add_filter( 'woocommerce_admin_shared_settings', function ( $settings ) {
		$settings['scanpay'] = WC_SCANPAY_VERSION;
		return $settings;
	} );

	add_action( 'admin_enqueue_scripts', function ( string $page ) {
		switch ( $page ) {
			case 'woocommerce_page_wc-settings':
				global $current_section;
				if ( str_starts_with( $current_section, 'scanpay' ) ) {
					wp_enqueue_script( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/js/settings.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
					wp_enqueue_style( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/css/settings.css', null, WC_SCANPAY_VERSION );
				}
				break;
			case 'woocommerce_page_wc-orders--shop_subscription':
			case 'woocommerce_page_wc-orders':
				wp_enqueue_script( 'wc-scanpay-meta', WC_SCANPAY_URL . '/public/js/meta.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
				wp_enqueue_style( 'wc-scanpay-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION );
				break;
		}
	} );

	add_action( 'woocommerce_order_status_completed', function ( string $order_id ) {
		require WC_SCANPAY_DIR . '/hooks/wc-order-status-completed.php';
	}, 0 ); // priority 0 to run before other plugins

	add_action( 'add_meta_boxes_woocommerce_page_wc-orders', function () {
		add_meta_box(
			'wcsp-meta-box',
			'Scanpay',
			function ( $order ) {
				echo '<div id="wcsp-meta" data-subid="' . $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' )
					. ' data-payid="' . $order->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' )
					. '" data-ptime="' . $order->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' ) . '"></div>';
			},
			'woocommerce_page_wc-orders',
			'side',
			'high'
		);
	} );

	add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', function () {
		add_meta_box(
			'wcsp-meta-box',
			'Scanpay',
			function ( $order ) {
				echo '<div id="wcsp-meta"
					data-subid="' . $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) . '"
					data-payid="' . $order->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' ) . '"
					data-ptime="' . $order->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' ) . '"></div>';
			},
			'woocommerce_page_wc-orders--shop_subscription',
			'side',
			'high'
		);
	} );

	add_action( 'woocommerce_scheduled_subscription_payment_scanpay', function ( float $x, object $order ) {
		global $wpdb;
		$orderid = (int) $order->get_id();
		$subid   = (int) $order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		$sql     = "INSERT INTO {$wpdb->prefix}scanpay_queue SET orderid = $orderid, subid = $subid";
		if ( ! $wpdb->query( $sql ) ) {
			return scanpay_log( 'error', "Failed to insert order #$orderid into queue" );
		}
	}, 10, 2 );

	add_action( 'woocommerce_subscription_failing_payment_method_updated_scanpay', function ( $old_order, $new_order ) {
		// This hook is fired when a customer updates their payment method from the My Account page (I think??)
		scanpay_log( 'info', 'woocommerce_subscription_failing_payment_method_updated_scanpay!!!' );
	}, 10, 2 );

	// Validate payment meta changes made by admin.
	add_filter( 'woocommerce_subscription_validate_payment_meta_scanpay', function ( $meta, $sub ) {
		scanpay_log( 'info', 'woocommerce_subscription_validate_payment_meta!!!' );
		if ( empty( $meta['post_meta'][ WC_SCANPAY_URI_SHOPID ] ) ) {
			throw new Exception( 'A Scanpay shopid is required.' );
		}
		if ( empty( $meta['post_meta'][ WC_SCANPAY_URI_SUBID ] ) ) {
			throw new Exception( 'A Scanpay subscriber ID is required.' );
		}
	}, 10, 2 );

	add_filter( 'woocommerce_subscription_payment_meta', function ( array $meta, object $sub ) {
		$meta['scanpay'] = [
			'post_meta' => [
				WC_SCANPAY_URI_SHOPID => [
					'value' => $sub->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ),
					'label' => 'Scanpay Shop ID',
				],
				WC_SCANPAY_URI_SUBID  => [
					'value' => $sub->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ),
					'label' => 'Scanpay Subscriber ID',
				],
			],
		];
		return $meta;
	}, 10, 2 );

	// Check if plugin needs to be upgraded
	if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION && ! get_transient( 'wc_scanpay_updating' ) ) {
		set_transient( 'wc_scanpay_updating', true, 5 * 60 );  // Set a transient for 5 minutes
		require WC_SCANPAY_DIR . '/includes/upgrade.php';
		delete_transient( 'wc_scanpay_updating' );
	}
}

add_action( 'plugins_loaded', function () {

	add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
		if ( ! class_exists( 'WC_Scanpay_Gateway', false ) ) {
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway.php';
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-mobilepay.php';
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-applepay.php';
		}
		return [ ...$methods, 'WC_Scanpay_Gateway', 'WC_Scanpay_Gateway_Mobilepay', 'WC_Scanpay_Gateway_ApplePay' ];
	} );

	if ( defined( 'DOING_AJAX' ) || wp_is_json_request() ) {
		return;
	}

	if ( ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || defined( 'DOING_CRON' ) ) {
		return scanpay_admin_hooks();
	}

	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-blocks-support.php';
		$registry->register( new WC_Scanpay_Blocks_Support() );
	} );
}, 11);


// Declare support for High-Performance Order Storage (custom_order_tables)
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, false ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
