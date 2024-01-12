<?php
declare(strict_types = 1);

/*
 * Version: 2.0.0
 * Requires at least: 4.7
 * Requires PHP: 7.1
 * WC requires at least: 6.9.0
 * WC tested up to: 8.0.2
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

const WC_SCANPAY_VERSION      = '2.0.0';
const WC_SCANPAY_MIN_PHP      = '7.4.0';
const WC_SCANPAY_MIN_WC       = '6.9.0';
const WC_SCANPAY_BASE         = 'scanpay-for-woocommerce/woocommerce-scanpay.php';
const WC_SCANPAY_DASHBOARD    = 'https://dashboard.scanpay.dk/';
const WC_SCANPAY_URI_SETTINGS = 'woocommerce_scanpay_settings';
const WC_SCANPAY_URI_SHOPID   = '_scanpay_shopid';
const WC_SCANPAY_URI_PAYID    = '_scanpay_payid';
const WC_SCANPAY_URI_PTIME    = '_scanpay_payid_time';
const WC_SCANPAY_URI_SUBID    = '_scanpay_subid';
const WC_SCANPAY_URI_IDEM     = '_scanpay_idem';

define( 'WC_SCANPAY_DIR', __DIR__ );
define( 'WC_SCANPAY_URL', set_url_scheme( WP_PLUGIN_URL ) . '/scanpay-for-woocommerce' );


function scanpay_log( string $level, string $msg ): void {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $msg, [ 'source' => 'woo-scanpay' ] );
	}
}

add_action(
	'woocommerce_blocks_loaded',
	function () {
		if (
		class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) &&
		! defined( 'DOING_CRON' ) && ! defined( 'DOING_AJAX' ) && ! wp_is_json_request()
		) {
			require WC_SCANPAY_DIR . '/hooks/class-wc-scanpay-blocks-support.php';
		}
	}
);

add_action(
	'activate_' . WC_SCANPAY_BASE,
	function () {
		require WC_SCANPAY_DIR . '/includes/install.php';
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return require WC_SCANPAY_DIR . '/includes/compatibility.php';
		}
		require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway.php';
		require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-mobilepay.php';
		require WC_SCANPAY_DIR . '/gateways/class-wc-scanpay-gateway-applepay.php';

		add_filter(
			'woocommerce_payment_gateways',
			function ( $methods ) {
				$methods[] = 'WC_Scanpay_Gateway';
				$methods[] = 'WC_Scanpay_Gateway_Mobilepay';
				$methods[] = 'WC_Scanpay_Gateway_ApplePay';
				return $methods;
			}
		);

		add_action(
			'woocommerce_order_status_completed',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			function ( string $order_id ) {
				require WC_SCANPAY_DIR . '/hooks/wc-order-status-completed.php';
			},
			0
		); // priority 0 to run before other plugins

		// Subscription hooks
		add_action(
			'woocommerce_scheduled_subscription_payment_scanpay',
			function ( float $amount, object $order ) { // phpcs:ignore
				require WC_SCANPAY_DIR . '/hooks/wcs-scheduled-payment.php';
			},
			10,
			2
		);

		add_action(
			'woocommerce_subscription_failing_payment_method_updated_scanpay',
			function ( $old_order, $new_order ) { // phpcs:ignore
				scanpay_log( 'info', 'woocommerce_subscription_failing_payment_method_updated_scanpay!!!' );
			},
			10,
			2
		);

		add_filter(
			'woocommerce_subscription_payment_meta',
			function ( array $meta, object $sub ) {
				$meta['scanpay'] = [
					'post_meta' => [
						WC_SCANPAY_URI_SHOPID => [
							'value' => $sub->parent->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' ),
							'label' => 'Scanpay shop id',
						],
						WC_SCANPAY_URI_SUBID  => [
							'value' => $sub->parent->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ),
							'label' => 'Scanpay subscriber id',
						],
					],
				];
				return $meta;
			},
			10,
			2
		);

		add_filter(
			'woocommerce_subscription_validate_payment_meta',
			function ( $method_id, $meta ) {
				if ( 'scanpay' === $method_id ) {
					if (
					! isset( $meta['post_meta'][ WC_SCANPAY_URI_SHOPID ] ) ||
					empty( $meta['post_meta'][ WC_SCANPAY_URI_SHOPID ] )
					) {
						throw new Exception( __( 'A Scanpay shopid is required.', 'scanpay-for-woocommerce' ) );
					}
					if (
					! isset( $meta['post_meta'][ WC_SCANPAY_URI_SUBID ] ) ||
					empty( $meta['post_meta'][ WC_SCANPAY_URI_SUBID ] )
					) {
						throw new Exception( __( 'A Scanpay subscriberid is required.', 'scanpay-for-woocommerce' ) );
					}
					scanpay_log( 'info', 'woocommerce_subscription_validate_payment_meta!!!' );
				}
			},
			10,
			2
		);

		if ( is_admin() ) {
			// Add plugin version number to JS: wcSettings.admin
			add_filter(
				'woocommerce_admin_shared_settings',
				function ( $settings ) {
					$settings['scanpay'] = WC_SCANPAY_VERSION;
					return $settings;
				}
			);

			global $pagenow;
			if ( 'admin.php' === $pagenow ) {
				add_action(
					'admin_enqueue_scripts',
					function ( string $page ) {
						switch ( $page ) {
							case 'woocommerce_page_wc-settings':
								global $current_section;
								if ( substr( $current_section, 0, 7 ) === 'scanpay' ) {
									wp_enqueue_script(
										'wc-scanpay-settings',
										WC_SCANPAY_URL . '/public/js/settings.js',
										false, // no deps
										WC_SCANPAY_VERSION,
										[ 'strategy' => 'defer' ]
									);
									wp_register_style( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/css/settings.css', null, WC_SCANPAY_VERSION );
									wp_enqueue_style( 'wc-scanpay-settings' );
								}
								break;
							case 'woocommerce_page_wc-orders--shop_subscription':
							case 'woocommerce_page_wc-orders':
								wp_enqueue_script(
									'wc-scanpay-meta',
									WC_SCANPAY_URL . '/public/js/meta.js',
									false, // no deps
									WC_SCANPAY_VERSION,
									[ 'strategy' => 'defer' ]
								);
								wp_register_style( 'wc-scanpay-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION );
								wp_enqueue_style( 'wc-scanpay-meta' );
								break;
						}
					}
				);

				add_action(
					'add_meta_boxes_woocommerce_page_wc-orders',
					function () {
						add_meta_box(
							'scanpay-info',
							'Scanpay',
							function ( $order ) {
								$payid = $order->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' );
								$ptime = $order->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' );
								echo '<div id="scanpay-meta" data-payid="' . $payid . '" data-ptime="' . $ptime . '"></div>';
							},
							wc_get_page_screen_id( 'shop-order' ),
							'side',
							'high'
						);
					}
				);

				add_action(
					'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription',
					function () {
						// require WC_SCANPAY_DIR . '/hooks/wcs-meta-box-subscription.php';
					}
				);
			} elseif ( 'plugins.php' === $pagenow ) {
				// Add helpful links to the plugins table and check compatibility
				add_filter(
					'plugin_action_links_' . WC_SCANPAY_BASE,
					function ( $links ) {
						return array_merge(
							[
								'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' ) . '">'
								. __( 'Settings', 'scanpay-for-woocommerce' ) . '</a>',
							],
							$links
						);
					},
					'active'
				);
				require WC_SCANPAY_DIR . '/includes/compatibility.php';
			}
			return;
		}

		// Ping and AJAX endpoints
		add_action(
			'woocommerce_api_scanpay_ping',
			function () {
				require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ping.php';
			}
		);
		add_action(
			'woocommerce_api_scanpay_ajax_meta',
			function () {
				require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ajax-meta.php';
			}
		);
		add_action(
			'woocommerce_api_scanpay_ajax_ping_mtime',
			function () {
				require WC_SCANPAY_DIR . '/hooks/wc-api-scanpay-ajax-ping-mtime.php';
			}
		);

		add_action(
			'wp_enqueue_scripts',
			function () {
				if ( is_checkout() ) {
					wp_register_style( 'wc-scanpay', WC_SCANPAY_URL . '/public/css/pay.css', null, WC_SCANPAY_VERSION );
					wp_enqueue_style( 'wc-scanpay' );
				}
			}
		);
	},
	11
);

// Declare support for High-Performance Order Storage (custom_order_tables)
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
