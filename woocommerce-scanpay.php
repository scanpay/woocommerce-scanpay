<?php
declare(strict_types = 1);

/*
 * Version: 2.1.0
 * Requires at least: 6.3.0
 * Requires PHP: 7.4
 * WC requires at least: 6.9.0
 * WC tested up to: 8.6.1
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

defined( 'ABSPATH' ) || exit();

const WC_SCANPAY_VERSION      = '2.1.0';
const WC_SCANPAY_MIN_PHP      = '7.4.0';
const WC_SCANPAY_MIN_WC       = '6.9.0';
const WC_SCANPAY_DASHBOARD    = 'https://dashboard.scanpay.dk/';
const WC_SCANPAY_URI_SETTINGS = 'woocommerce_scanpay_settings';
const WC_SCANPAY_URI_SHOPID   = '_scanpay_shopid';
const WC_SCANPAY_URI_PAYID    = '_scanpay_payid';
const WC_SCANPAY_URI_PTIME    = '_scanpay_payid_time';
const WC_SCANPAY_URI_SUBID    = '_scanpay_subid';
const WC_SCANPAY_URI_AUTOCPT  = '_scanpay_autocpt';

define( 'WC_SCANPAY_DIR', __DIR__ );
define( 'WC_SCANPAY_URL', set_url_scheme( WP_PLUGIN_URL ) . '/scanpay-for-woocommerce' );

function scanpay_log( string $level, string $msg ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $msg, [ 'source' => 'woo-scanpay' ] );
	}
}

/*
	Polyfill for PHP 8.0 functions
*/

if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}
		return 0 === strpos( $haystack, $needle );
	}
}

if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $haystack, $needle ) {
		if ( '' === $haystack ) {
			return '' === $needle;
		}
		$len = strlen( $needle );
		return substr( $haystack, -$len, $len ) === $needle;
	}
}

/*
	JavaScript endpoints /wp-scanpay/fetch?x={ping,meta,sub}&s=$secret  ...
	Bypass WooCommerce and WordPress. Saves >40 ms and a lot of resources
*/

if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'], $_GET['s'] ) ) {
	switch ( $_GET['x'] ) {
		case 'meta':
			require WC_SCANPAY_DIR . '/hooks/wp-scanpay-fetch-meta.php';
			break;
		case 'ping':
			require WC_SCANPAY_DIR . '/hooks/wp-scanpay-fetch-ping.php';
			break;
		case 'sub':
			require WC_SCANPAY_DIR . '/hooks/wp-scanpay-fetch-sub.php';
			break;
	}
}

/*
	Ping handler /wc-api/wc_scanpay/
	Sadly, Woo has made an OOP nightmare. There are no shortcuts here :/
*/
if ( isset( $_SERVER['HTTP_X_SIGNATURE'], $_SERVER['REQUEST_URI'] ) ) {
	if ( str_ends_with( $_SERVER['REQUEST_URI'], 'wc_scanpay/' ) ) {
		add_action( 'woocommerce_api_wc_scanpay', function () {
			require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-sync.php';
			$sync = new WC_Scanpay_Sync();
			$sync->handle_ping();
		} );
		return;
	}
}

function scanpay_admin_hooks() {
	// Check if plugin needs to be upgraded
	if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION && ! get_transient( 'wc_scanpay_updating' ) ) {
		set_transient( 'wc_scanpay_updating', true, 5 * 60 );  // Set a transient for 5 minutes
		require WC_SCANPAY_DIR . '/includes/upgrade.php';
		delete_transient( 'wc_scanpay_updating' );
	}

	// Add plugin version number to JS: wcSettings.admin
	add_filter( 'woocommerce_admin_shared_settings', function ( $settings ) {
		$settings['scanpay'] = WC_SCANPAY_VERSION;
		return $settings;
	} );

	// Meta box
	function wc_scanpay_add_meta_box( $wc_order ) {
		if ( ! $wc_order instanceof WC_Order ) {
			$wc_order = wc_get_order( $wc_order->ID ); // Legacy support
			if ( ! $wc_order ) {
				return;
			}
		}
		$psp = $wc_order->get_payment_method();
		if ( 'scanpay' !== $psp && ! str_starts_with( $psp, 'scanpay' ) ) {
			return;
		}
		wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION );
		wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/public/js/meta.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );

		add_meta_box(
			'wcsp-meta-box',
			'Scanpay',
			function ( $post, $args ) {
				$wc_order = $args['args'][0];
				$oid      = $wc_order->get_id();
				$secret   = get_option( WC_SCANPAY_URI_SETTINGS )['secret'] ?? '';
				$status   = $wc_order->get_status();
				$total    = $wc_order->get_total() - $wc_order->get_total_refunded();
				$subid    = $wc_order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
				$payid    = $wc_order->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' );
				$ptime    = $wc_order->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' );
				echo "<div id='wcsp-meta' data-id='$oid' data-secret='$secret' data-status='$status' data-total='$total' data-subid='$subid' data-payid='$payid' data-ptime='$ptime'></div>";
			},
			null,
			'side',
			'high',
			[ $wc_order ]
		);
	}

	// Meta box Subscriptions
	function wc_scanpay_add_meta_box_subs( $wc_order ) {
		if ( ! $wc_order instanceof WC_Order ) {
			$wc_order = wc_get_order( $wc_order->ID ); // Legacy support
			if ( ! $wc_order ) {
				return;
			}
		}
		$psp = $wc_order->get_payment_method();
		if ( 'scanpay' !== $psp && ! str_starts_with( $psp, 'scanpay' ) ) {
			return;
		}
		wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION );
		wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/public/js/subs.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );

		add_meta_box(
			'wcsp-meta-box',
			'Scanpay',
			function ( $post, $args ) {
				$wc_sub = $args['args'][0];
				$secret = get_option( WC_SCANPAY_URI_SETTINGS )['secret'] ?? '';
				echo '<div id="wcsp-meta" data-secret="' . $secret . '"
					data-subid="' . $wc_sub->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) . '"
					data-payid="' . $wc_sub->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' ) . '"
					data-ptime="' . $wc_sub->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' ) . '"></div>';
			},
			null,
			'side',
			'high',
			[ $wc_order ]
		);
	}

	global $pagenow;
	if ( 'plugins.php' === $pagenow || ! class_exists( 'WooCommerce' ) ) {
		// Add helpful links to the plugins table and check compatibility
		add_filter( 'plugin_action_links_scanpay-for-woocommerce/woocommerce-scanpay.php', function ( $links ) {
			if ( ! is_array( $links ) ) {
				return $links;
			}
			return array_merge([
				'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' ) . '">Settings</a>',
			], $links);
		});
		require WC_SCANPAY_DIR . '/includes/compatibility.php';
	} elseif ( 'admin.php' === $pagenow ) {
		// Add CSS and JavaScript to the settings page
		add_action( 'admin_print_styles-woocommerce_page_wc-settings', function () {
			global $current_section;
			if ( str_starts_with( $current_section, 'scanpay' ) ) {
				wp_enqueue_script( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/js/settings.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
				wp_enqueue_style( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/css/settings.css', null, WC_SCANPAY_VERSION );
			}
		}, 0, 0 );

		// Add metaboxes (HPOS enabled)
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'wc_scanpay_add_meta_box', 9, 1 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 );
	} elseif ( 'post.php' === $pagenow ) {
		// Add metabox (HPOS disabled)
		add_action( 'add_meta_boxes_shop_order', 'wc_scanpay_add_meta_box', 9, 1 );
		add_action( 'add_meta_boxes_shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 );
	}
}

add_action( 'plugins_loaded', function () {
	//  [hook] Returns the list of gateways. Always called before gateways are needed
	add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
		if ( ! class_exists( 'WC_Scanpay_Gateway', false ) ) {
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway.php';
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-mobilepay.php';
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-applepay.php';
		}
		return [ ...$methods, 'WC_Scanpay_Gateway', 'WC_Scanpay_Gateway_Mobilepay', 'WC_Scanpay_Gateway_ApplePay' ];
	} );

	function wc_scanpay_load_sync() {
		// Load the sync class and let it handle the hooks (switch to singleton pattern?)
		if ( ! class_exists( 'WC_Scanpay_Sync', false ) ) {
			require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-sync.php';
			new WC_Scanpay_Sync(); // will handle the hooks
			remove_filter( 'woocommerce_scheduled_subscription_payment_scanpay', 'wc_scanpay_load_sync', 1, 0 );
			remove_filter( 'woocommerce_order_status_completed', 'wc_scanpay_load_sync', 1, 0 );
		}
	}

	// [hook] Manual and Recurring renewals (cron|admin|user)
	add_action( 'woocommerce_scheduled_subscription_payment_scanpay', 'wc_scanpay_load_sync', 1, 0 );

	// [hook] Order status changed to completed (cron|admin|plugin)
	add_action( 'woocommerce_order_status_completed', 'wc_scanpay_load_sync', 1, 0 );

	add_action( 'woocommerce_before_thankyou', function ( $order_id ) {
		require WC_SCANPAY_DIR . '/hooks/wc-before-thankyou.php';
	}, 3, 1);

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
