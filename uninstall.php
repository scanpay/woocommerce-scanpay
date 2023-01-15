<?php

defined('ABSPATH') || exit();
defined('WP_UNINSTALL_PLUGIN') || die();

// delete database table
global $wpdb;
$table_name = $wpdb->prefix .'woocommerce_scanpay_seq';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete plugin settings
delete_option('woocommerce_scanpay_settings');

// TODO: Remove metadata from orders
