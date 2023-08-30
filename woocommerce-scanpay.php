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

defined('ABSPATH') || exit();

const WC_SCANPAY_VERSION = '2.0.0';
const WC_SCANPAY_MIN_PHP = '7.1.0';
const WC_SCANPAY_MIN_WC = '6.9.0';
const WC_SCANPAY_DASHBOARD = 'https://dashboard.scanpay.dk/';
const WC_SCANPAY_URI_SETTINGS = 'woocommerce_scanpay_settings';
const WC_SCANPAY_URI_SHOPID = '_scanpay_shopid';
const WC_SCANPAY_URI_TRNID = '_scanpay_transaction_id';
const WC_SCANPAY_URI_REV = '_scanpay_rev';
const WC_SCANPAY_URI_NACTS = '_scanpay_nacts';
const WC_SCANPAY_URI_PAYID = '_scanpay_payid';
const WC_SCANPAY_URI_TOTALS = '_scanpay_totals';
const WC_SCANPAY_URI_PAYMENT_METHOD = '_scanpay_payment_method';

const WC_SCANPAY_URI_SUBSCRIBER_TIME = '_scanpay_subscriber_time';
const WC_SCANPAY_URI_SUBSCRIBER_ID = '_scanpay_subscriber_id';
const WC_SCANPAY_URI_SUBSCRIBER_CHARGE_IDEM = '_scanpay_subscriber_charge_idem';
const WC_SCANPAY_URI_SUBSCRIBER_INITIALPAYMENT_NTRIES = '_scanpay_subscriber_initialpayment_ntries';


define('WC_SCANPAY_DIR', __DIR__);
define('WC_SCANPAY_URL', plugins_url('', __FILE__));
define('WC_SCANPAY_NAME', plugin_basename(__FILE__));

function scanpay_log(string $level, string $msg): void
{
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->log($level, $msg, ['source' => 'woo-scanpay']);
    }
}

function scanpay_admin_init(): void
{
    add_action('admin_enqueue_scripts', function (string $page) {
        switch ($page) {
            case 'woocommerce_page_wc-settings':
                global $current_section;
                if ($current_section === 'scanpay' || $current_section === 'scanpay_mobilepay') {
                    wp_enqueue_script('wc-scanpay-settings', WC_SCANPAY_URL . '/public/js/settings.js', false, WC_SCANPAY_VERSION, true);
                    wp_register_style('wc-scanpay-settings', WC_SCANPAY_URL . '/public/css/settings.css', null, WC_SCANPAY_VERSION);
                    wp_enqueue_style('wc-scanpay-settings');
                }
                break;
            case 'woocommerce_page_wc-orders':
                wp_enqueue_script('wc-scanpay-meta', WC_SCANPAY_URL . '/public/js/meta.js', false, WC_SCANPAY_VERSION, true);
                wp_register_style('wc-scanpay-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION);
                wp_enqueue_style('wc-scanpay-meta');
                break;
        }
    });

    add_action('add_meta_boxes_woocommerce_page_wc-orders', function () {
        require WC_SCANPAY_DIR . '/hooks/add_meta_box.php';
    });

    global $pagenow;
    if ($pagenow === 'plugins.php') {
        // Add helpful links to the plugins table and check compatibility
        add_filter('plugin_action_links_' . WC_SCANPAY_NAME, function ($links) {
            return array_merge([
                '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=scanpay') . '">'
                . __('Settings') . '</a>'
            ], $links);
        }, 'active');
        require WC_SCANPAY_DIR . '/includes/compatibility.php';
    }
}

add_action('init', function () {
    load_plugin_textdomain('scanpay-for-woocommerce', false, dirname(WC_SCANPAY_NAME) . '/languages/');
});


add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return require WC_SCANPAY_DIR . '/includes/compatibility.php';
    }

    add_filter('woocommerce_payment_gateways', function ($methods) {
        $methods[] = 'WC_Scanpay_Gateway_Scanpay';
        $methods[] = 'WC_Scanpay_Gateway_Mobilepay';
        $methods[] = 'WC_Scanpay_Gateway_ApplePay';
        return $methods;
    });
    require WC_SCANPAY_DIR . '/gateways/Scanpay.php';
    require WC_SCANPAY_DIR . '/gateways/Mobilepay.php';
    require WC_SCANPAY_DIR . '/gateways/ApplePay.php';

    add_action('woocommerce_order_status_completed', function ($order_id) {
        require WC_SCANPAY_DIR . '/hooks/wc_order_status_completed.php'; // CoC
    });

    if (is_admin()) {
        return scanpay_admin_init();
    }

    // Ping endpoint
    add_action('woocommerce_api_wc_scanpay', function () {
        require WC_SCANPAY_DIR . '/hooks/wc_api_wc_scanpay.php';
    });

    // AJAX endpoints
    add_action('woocommerce_api_scanpay_get_rev', function () {
        require WC_SCANPAY_DIR . '/hooks/wc_ajax_scanpay_get_rev.php';
    });
    add_action('woocommerce_api_scanpay_last_ping', function () {
        require WC_SCANPAY_DIR . '/hooks/wc_ajax_scanpay_last_ping.php';
    });

    add_action('wp_enqueue_scripts', function () {
        if (is_checkout()) {
            wp_register_style('wc-scanpay', WC_SCANPAY_URL . '/public/css/pay.css', null, WC_SCANPAY_VERSION);
            wp_enqueue_style('wc-scanpay');
        }
    });
}, 11);

// Declare support for High-Performance Order Storage (custom_order_tables)
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
