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
 * WC tested up to: 9.6.0
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

// Add polyfills for PHP < 8.0
if ( ! function_exists( 'str_starts_with' ) ) {
	require WC_SCANPAY_DIR . '/includes/polyfill.php';
}

function scanpay_log( string $level, string $msg ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $msg, [ 'source' => 'woo-scanpay' ] );
	}
}

/*
	Ping handler /wc-api/wc_scanpay/
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

/*
	Load translations (i18n)
*/
add_action('plugins_loaded', function () {
	load_plugin_textdomain( 'scanpay-for-woocommerce', false, 'scanpay-for-woocommerce/languages' );
});


/*
	JavaScript endpoints /wp-scanpay/fetch?x={ping,meta,sub}&s=$secret  ...
	Bypass WooCommerce and WordPress. Saves >40 ms and a lot of resources
*/

if ( isset( $_SERVER['HTTP_X_SCANPAY'], $_GET['x'], $_GET['s'] ) ) {
	switch ( $_GET['x'] ) {
		case 'meta':
			return require WC_SCANPAY_DIR . '/hooks/wp-scanpay-fetch-meta.php';
		case 'ping':
			return require WC_SCANPAY_DIR . '/hooks/wp-scanpay-fetch-ping.php';
		case 'sub':
			return require WC_SCANPAY_DIR . '/hooks/wp-scanpay-fetch-sub.php';
	}
}

/*
*   Order received page (thankyou).
*/
if ( isset( $_GET['scanpay_thankyou'], $_GET['scanpay_type'] ) ) {
	return require WC_SCANPAY_DIR . '/hooks/wp-scanpay-thankyou.php';
}

// Meta box
function wc_scanpay_add_meta_box( $wc_order ) {
	if ( ! $wc_order instanceof WC_Order ) {
		$wc_order = wc_get_order( $wc_order->ID ); // Legacy support
		if ( ! $wc_order ) {
			return;
		}
	}
	$psp = $wc_order->get_payment_method( 'edit' );
	if ( 'scanpay' !== $psp && ! str_starts_with( $psp, 'scanpay' ) ) {
		return;
	}
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION );
	wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/public/js/order.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );

	add_meta_box(
		'wcsp-meta-box',
		'Scanpay',
		function ( $post, $args ) {
			$wc_order = $args['args'][0];
			$oid      = $wc_order->get_id();
			$secret   = get_option( WC_SCANPAY_URI_SETTINGS )['secret'] ?? '';
			$status   = $wc_order->get_status( 'edit' );
			$total    = $wc_order->get_total( 'edit' ) - $wc_order->get_total_refunded();
			$currency = $wc_order->get_currency();
			$subid    = $wc_order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
			$payid    = $wc_order->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' );
			$ptime    = $wc_order->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' );
			echo "<div id='wcsp-meta' data-id='$oid' data-secret='$secret' data-status='$status' data-total='$total' data-currency='$currency' data-subid='$subid' data-payid='$payid' data-ptime='$ptime'>
				<div id='wcsp-meta-head'></div>
				<ul id='wcsp-meta-ul' class='wcsp-meta-ul'></ul>
				<div id='wcsp-meta-foot'></div>
			</div>";
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
	$psp = $wc_order->get_payment_method( 'edit' );
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
				data-ptime="' . $wc_sub->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' ) . '">
				<div id="wcsp-meta-head"></div>
				<ul id="wcsp-meta-ul" class="wcsp-meta-ul"></ul>
			</div>';
		},
		null,
		'side',
		'high',
		[ $wc_order ]
	);
}

function scanpay_admin_hooks() {
	// Add plugin version number to JS: wcSettings.admin
	add_filter( 'woocommerce_admin_shared_settings', function ( $settings ) {
		$settings['scanpay'] = WC_SCANPAY_VERSION;
		return $settings;
	} );

	global $pagenow;
	if ( 'admin.php' === $pagenow ) {
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

		// [hook] Add custom bulk action to the order list (HPOS enabled)
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', function ( array $actions ) {
			$arr = [ 'scanpay_capture_complete' => 'Capture and complete' ];
			foreach ( $actions as $k => $v ) {
				$arr[ ( 'mark_completed' === $k ) ? 'scanpay_mark_completed' : $k ] = $v;
			}
			return $arr;
		}, 10, 1 );

		// [hook] Handle the custom bulk action (HPOS enabled)
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', function ( string $redirect_to, string $action, array $ids ) {
			return require WC_SCANPAY_DIR . '/hooks/wp-bulk-actions.php';
		}, 0, 3 );

		// [hook] Ajax action to mark order status (HPOS enabled)
		add_action( 'wp_ajax_woocommerce_mark_order_status', function () {
			require WC_SCANPAY_DIR . '/hooks/wp-ajax-wc-mark-order-status.php';
		}, 0, 0);
		return;
	}

	if ( 'edit.php' === $pagenow ) {
		// [hook] Add custom bulk action to the order list (HPOS disabled)
		add_filter( 'bulk_actions-edit-shop_order', function ( array $actions ) {
			$arr = [ 'scanpay_capture_complete' => 'Capture and complete' ];
			foreach ( $actions as $k => $v ) {
				$arr[ ( 'mark_completed' === $k ) ? 'scanpay_mark_completed' : $k ] = $v;
			}
			return $arr;
		}, 10, 1 );

		// [hook] Handle the custom bulk action (HPOS disabled)
		add_filter( 'handle_bulk_actions-edit-shop_order', function ( string $redirect_to, string $action, array $ids ) {
			return require WC_SCANPAY_DIR . '/hooks/wp-bulk-actions.php';
		}, 0, 3 );

		// [hook] Ajax action to mark order status (HPOS disabled)
		add_action( 'wp_ajax_woocommerce_mark_order_status', function () {
			require WC_SCANPAY_DIR . '/hooks/wp-ajax-wc-mark-order-status.php';
		}, 0, 0);
		return;
	}

	if ( 'post.php' === $pagenow ) {
		// Add metabox (HPOS disabled)
		add_action( 'add_meta_boxes_shop_order', 'wc_scanpay_add_meta_box', 9, 1 );
		add_action( 'add_meta_boxes_shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 );
		return;
	}

	if ( 'plugins.php' === $pagenow ) {
		// Add helpful links to the plugins table and check compatibility
		add_filter( 'plugin_action_links_scanpay-for-woocommerce/woocommerce-scanpay.php', function ( $links ) {
			if ( ! is_array( $links ) ) {
				return $links; // Some plugins do not return the correct type (array)
			}
			$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' );
			return array_merge( [ "<a href='$url'>" . __( 'Settings', 'scanpay-for-woocommerce' ) . '</a>' ], $links );
		});
		return require WC_SCANPAY_DIR . '/includes/compatibility.php';
	}
}

add_action( 'plugins_loaded', function () {
	// [hook] Returns the list of gateways. Always called before gateways are needed
	add_filter( 'woocommerce_payment_gateways', function ( array $methods ) {
		if ( ! class_exists( 'WC_Scanpay_Gateway', false ) ) {
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway.php';
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-mobilepay.php';
			require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-applepay.php';
		}
		$methods[] = 'WC_Scanpay_Gateway';
		$methods[] = 'WC_Scanpay_Gateway_Mobilepay';
		$methods[] = 'WC_Scanpay_Gateway_ApplePay';
		return $methods;
	} );

	// [hook] Load WC_Scanpay_Sync class (will process the hooks)
	add_action( 'woocommerce_scheduled_subscription_payment_scanpay', 'wc_scanpay_load_sync', 1, 0 );
	add_action( 'woocommerce_order_status_completed', 'wc_scanpay_load_sync', 1, 0 );
	function wc_scanpay_load_sync() {
		// Load the sync class and let it handle the hooks (switch to singleton pattern?)
		if ( ! class_exists( 'WC_Scanpay_Sync', false ) ) {
			require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-sync.php';
			new WC_Scanpay_Sync(); // will handle the hooks
		}
	}

	// Ignore JSON requests
	if ( defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) || str_starts_with( $_SERVER['HTTP_ACCEPT'] ?? '', 'application/json' ) ) {
		return true;
	}

	add_filter( 'allowed_redirect_hosts', function ( array $hosts ) {
		$hosts[] = 'betal.scanpay.dk';
		return $hosts;
	} );

	add_action( 'woocommerce_review_order_before_submit', function () {
		if ( class_exists( 'WC_Subscriptions_Cart', false ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			$settings = get_option( WC_SCANPAY_URI_SETTINGS );
			if ( $settings && isset( $settings['wcs_terms'] ) && '0' !== $settings['wcs_terms'] ) {
				$url = get_page_link( $settings['wcs_terms'] );
				$txt = 'Jeg accepterer <a href="' . $url . ' ">abonnementsbetingelserne</a>.';

				woocommerce_form_field( 'wcssp-terms-field', [
					'type'  => 'hidden',
					'value' => '1',
				] );
				woocommerce_form_field( 'wcssp-terms', [
					'type'        => 'checkbox',
					'class'       => [ 'form-row wcssp-terms' ],
					'label_class' => [ 'woocommerce-form__label woocommerce-form__label-for-checkbox checkbox' ],
					'input_class' => [ 'woocommerce-form__input woocommerce-form__input-checkbox input-checkbox' ],
					'required'    => true,
					'label'       => $txt,
				]);
			}
		}
	}, 10 );

	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-blocks-support.php';
		$registry->register( new WC_Scanpay_Blocks_Support() );
	} );

	if ( ( defined( 'WP_ADMIN' ) && WP_ADMIN ) ) {
		scanpay_admin_hooks();
	}
}, 11);


// Declare support for High-Performance Order Storage (custom_order_tables)
add_action( 'before_woocommerce_init', function () {
	// Note: Our plugin may load before WC, so class_exists is set to autoload to ensure the class is available.
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class, true ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
