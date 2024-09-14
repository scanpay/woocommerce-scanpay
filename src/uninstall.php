<?php

defined( 'ABSPATH' ) || exit();
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

global $wpdb;

// Delete Scanpay tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_seq" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_meta" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_subs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_queue" );

// Delete old Scanpay tables (should not exist, but just in case)
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_queuedcharges" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_seq" );

// Delete plugin settings
delete_option( 'woocommerce_scanpay_settings' );
delete_option( 'woocommerce_scanpay_mobilepay_settings' );
delete_option( 'woocommerce_scanpay_applepay_settings' );
delete_option( 'wc_scanpay_version' );
