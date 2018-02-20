<?php
/*
 * Plugin Name: Scanpay for Woocommerce
 * Plugin URI: https://wordpress.org/plugins/scanpay-for-woocommerce/
 * Description: Provides a Scanpay payment method for Woocommerce checkout.
 * Version: 1.0.2
 * Author: Scanpay
 * Author URI: https://scanpay.dk
 * Developer: Christian Thorseth Blach
 * Text Domain: woocommerce-extension
 * Domain Path: /languages
 *
 * Copyright: © 2018 Scanpay.
 * License: MIT License
 * License URI: https://opensource.org/licenses/MIT
 * WC requires at least: 3.0.0
 * WC tested up to: 3.3.1
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
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

function scanpay_log($msg)
{
    $header = date("Y-m-d H:i:s");
    error_log($header . ' - ' . $msg . "\n", 3, WC_SCANPAY_FOR_WOOCOMMERCE_LOGFILE);
}

function initScanpay()
{
    load_plugin_textdomain('woocommerce-scanpay', false, plugin_basename(dirname(__FILE__)) . '/languages');
    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/Gateway.php');
    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/ScanpayClient.php');
    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/GlobalSequencer.php');
    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/OrderUpdater.php');
    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/Settings.php');

    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/gateways/Parent.php');
    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/gateways/Mobilepay.php');

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
