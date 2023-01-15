<?php

defined('ABSPATH') || exit();
defined('WP_UNINSTALL_PLUGIN') || die();

global $wpdb;
$seq_table = $wpdb->prefix . 'woocommerce_scanpay_seq';
$sub_table = $wpdb->prefix . 'woocommerce_scanpay_queuedcharges';

// delete database table
$wpdb->query("DROP TABLE IF EXISTS {$seq_table}");
$wpdb->query("DROP TABLE IF EXISTS {$sub_table}");

// Delete plugin settings
delete_option('woocommerce_scanpay_settings');

// TODO: Remove metadata from orders
