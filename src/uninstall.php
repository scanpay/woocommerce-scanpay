<?php

defined( 'ABSPATH' ) || exit();
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

global $wpdb;

// The three real 3.x tables created by install.php.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_seq" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_meta" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_subs" );

// Legacy 2.x tables: created only by the pre-3.x plugin, never by 3.x. Kept as
// harmless cleanup for sites that uninstall after upgrading from 2.x.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_queuedcharges" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_seq" );

// Delete plugin settings
delete_option( 'woocommerce_scanpay_settings' );
delete_option( 'woocommerce_scanpay_mobilepay_settings' );
delete_option( 'woocommerce_scanpay_applepay_settings' );
delete_option( 'wc_scanpay_version' );

// The upgrade throttle. Self-expires in 5 minutes, so this only matters when
// uninstalling in the middle of a wedged upgrade -- but leave nothing behind.
delete_transient( 'wc_scanpay_updating' );
