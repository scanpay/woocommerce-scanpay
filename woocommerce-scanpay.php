<?php
/*
 * Plugin Name: Scanpay for Woocommerce
 * Plugin URI: https://wordpress.org/plugins/scanpay-for-woocommerce/
 * Description: Provides a Scanpay payment method for Woocommerce checkout.
 * Version: 1.2.2
 * Author: Scanpay
 * Author URI: https://scanpay.dk
 * Developer: Christian Thorseth Blach
 * Text Domain: woocommerce-extension
 * Domain Path: /languages
 *
 * Copyright: © 2019 Scanpay.
 * License: MIT License
 * License URI: https://opensource.org/licenses/MIT
 * WC requires at least: 3.0.0
 * WC tested up to: 3.9.1
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('get_plugins')) {
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
}

if (!function_exists('get_home_path')) {
    require_once( ABSPATH . 'wp-admin/includes/file.php' );
}

define('WC_SCANPAY_PLUGIN_VERSION', get_plugin_data( __FILE__ )['Version']);
define('WC_SCANPAY_FOR_WOOCOMMERCE_DIR', rtrim(plugin_dir_path(__FILE__), '/'));
define('WC_SCANPAY_FOR_WOOCOMMERCE_LOGFILE', get_home_path() . 'wp-content/scanpay-for-woocommerce.log');

function scanpay_log($msg, $caller=null)
{
    if (is_null($caller)) {
        $caller = debug_backtrace(FALSE, 1)[0];
    }
    $header = date("Y-m-d H:i:s") . ' ' . basename($caller['file']) . ':' . $caller['line'];
    error_log($header . ' - ' . $msg . "\n", 3, WC_SCANPAY_FOR_WOOCOMMERCE_LOGFILE);
}

function initScanpay()
{
    load_plugin_textdomain('woocommerce-scanpay', false, plugin_basename(dirname(__FILE__)) . '/languages');
    if (!class_exists('WooCommerce')) {
        return;
    }
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/Gateway.php');
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/ScanpayClient.php');
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/ShopSeqDB.php');
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/QueuedChargeDB.php');
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/OrderUpdater.php');
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/Settings.php');
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/gateways/Parent.php');
    require_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/gateways/Mobilepay.php');
}
add_action('plugins_loaded', 'initScanpay', 0);

function addScanpayGateway($methods)
{
    $methods[] = 'WC_Scanpay';
    $methods[] = 'WC_Scanpay_Mobilepay';
    return $methods;
}
add_filter('woocommerce_payment_gateways', 'addScanpayGateway');

/* Add a link to settings in the plugin overview */
function addScanpayPluginLinks($links)
{
    $mylinks [] ='<a href="'.admin_url('admin.php?page=wc-settings&tab=checkout&section=scanpay' ) . '">' . __( 'Settings', 'woocommerce-scanpay' ) . '</a>';
    // Merge our new link with the default ones
    return array_merge($mylinks, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'addScanpayPluginLinks');
