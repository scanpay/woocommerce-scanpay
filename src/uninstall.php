<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

global $wpdb;

// The three tables created by install.php.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_seq" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_meta" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_subs" );

// Legacy 2.x tables: created only by the pre-3.x plugin, never by 3.x. Kept as
// harmless cleanup for sites that uninstall after upgrading from 2.x.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_queuedcharges" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_seq" );

// Early-2.x table, never part of the 3.x schema. Do not remove this drop again:
// c25fa50 did, on the wrong premise that nothing ever created it -- v2.0.0..v2.1.4
// did (git show v2.0.0:includes/install.php), and 11c81b4 dropped the creation with no
// migration. Later 2.x only removed the table when an API-key change rebuilt the
// schema, so merchants who upgraded without changing their key still have it, holding
// order/subscription IDs and amounts.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_queue" );

delete_option( 'woocommerce_scanpay_settings' );
delete_option( 'woocommerce_scanpay_mobilepay_settings' );
delete_option( 'woocommerce_scanpay_applepay_settings' );
delete_option( 'wc_scanpay_version' );

// The upgrade throttle. Self-expires in 5 minutes, so this only matters when
// uninstalling in the middle of a wedged upgrade -- but leave nothing behind.
delete_transient( 'wc_scanpay_updating' );
