<?php

/*
 * Plugin Name: Scanpay for Woocommerce
 * Plugin URI: https://wordpress.org/plugins/scanpay-for-woocommerce/
 * Description: Accept payments in WooCommerce with a secure payment gateway.
 * Version: 1.3.15
 * Author: Scanpay
 * Author URI: https://scanpay.dk
 * Developer: Christian Thorseth Blach
 * Text Domain: woocommerce-extension
 * Domain Path: /languages
 * License: MIT License
 * License URI: https://opensource.org/licenses/MIT
 * WC requires at least: 3.0.0
 * WC tested up to: 6.4.1
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

const WC_SCANPAY_PLUGIN_VERSION = '1.3.15';
const WC_SCANPAY_DIR = __DIR__;

function scanpay_log($msg, $backtrace = false)
{
    $logger = wc_get_logger();
    $context = array('source' => 'scanpay-woocommerce');

    if ($backtrace) {
        $caller = debug_backtrace(false, 1)[0];
        $logger->warning($msg . ' : ' . $caller['file'] . ':' . $caller['line']);
    } else {
        $logger->warning($msg, $context);
    }
}

function initScanpay()
{
    if (!class_exists('WooCommerce')) {
        return;
    }

    load_plugin_textdomain('woocommerce-scanpay', false, plugin_basename(dirname(__FILE__)) . '/languages');

    require_once(WC_SCANPAY_DIR . '/includes/Gateway.php');
    require_once(WC_SCANPAY_DIR . '/includes/ScanpayClient.php');
    require_once(WC_SCANPAY_DIR . '/includes/ShopSeqDB.php');
    require_once(WC_SCANPAY_DIR . '/includes/QueuedChargeDB.php');
    require_once(WC_SCANPAY_DIR . '/includes/OrderUpdater.php');
    require_once(WC_SCANPAY_DIR . '/includes/Settings.php');
    require_once(WC_SCANPAY_DIR . '/includes/gateways/Parent.php');
    require_once(WC_SCANPAY_DIR . '/includes/gateways/Mobilepay.php');

    function addScanpayGateway($methods)
    {
        $methods[] = 'WC_Scanpay';
        $methods[] = 'WC_Scanpay_Mobilepay';
        return $methods;
    }

    // Add a 'shortcut' link to settings in the plugin overview
    function addLinkToSettings($links)
    {
        $mylinks = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=scanpay') . '">' .
                __('Settings', 'woocommerce-scanpay') . '</a>'
        ];
        return array_merge($mylinks, $links);
    }

    // Add links to GitHub etc. in the plugin overview
    function addSupportLinks($links, $file)
    {
        if (plugin_basename(__FILE__) === $file) {
            $row_meta = [
                'github' => '<a href="https://github.com/scanpay/woocommerce-scanpay">GitHub</a>',
                'docs' => '<a href="https://docs.scanpay.dk/">API Docs</a>'
            ];
            return array_merge($links, $row_meta);
        }
        return (array) $links;
    }

    add_filter('woocommerce_payment_gateways', 'addScanpayGateway');
    add_filter('plugin_row_meta', 'addSupportLinks', 10, 2);
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'addLinkToSettings');
}

add_action('plugins_loaded', 'initScanpay', 0);
