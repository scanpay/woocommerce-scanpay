<?php
/*
 * Plugin Name: Scanpay for Woocommerce
 * Plugin URI: http://woocommerce.com
 * Description: Provides a Scanpay payment method for Woocommerce checkout.
 * Version: 0.01
 * Author: Scanpay
 * Author URI: https:/scanpay.dk
 * Developer: Christian Thorseth Blach
 * Text Domain: woocommerce-extension
 * Domain Path: /languages
 *
 * Copyright: © 2016 Scanpay.
 * License: MIT License
 * License URI: https://opensource.org/licenses/MIT
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    exit;
}
if ( ! function_exists( 'get_plugins' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
}

$woocommerce_for_scanpay_plugin_version = get_plugin_data( __FILE__ )['Version'];
$woocommerce_for_scanpay_dir = rtrim(plugin_dir_path(__FILE__), '/');

function initScanpay()
{
    global $woocommerce_for_scanpay_dir;
    load_plugin_textdomain('woocommerce-scanpay', false, plugin_basename(dirname(__FILE__)) . '/languages');
	include_once($woocommerce_for_scanpay_dir . '/includes/Gateway.php');
	include_once($woocommerce_for_scanpay_dir . '/includes/Money.php');
	include_once($woocommerce_for_scanpay_dir . '/includes/ScanpayClient.php');
    include_once($woocommerce_for_scanpay_dir . '/includes/GlobalSequencer.php');
    include_once($woocommerce_for_scanpay_dir . '/includes/OrderUpdater.php');
    include_once($woocommerce_for_scanpay_dir . '/includes/Settings.php');
}
add_action('plugins_loaded', 'initScanpay', 0);


function addScanpayGateway($methods)
{
	$methods[] = 'ScanpayGateway';
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
