<?php

defined( 'ABSPATH' ) || exit();
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

global $wpdb;

// delete database table
$wpdb->query(
	$wpdb->prepare(
		'DROP TABLE IF EXISTS %s',
		$wpdb->prefix . 'woocommerce_scanpay_seq'
	)
);
$wpdb->query(
	$wpdb->prepare(
		'DROP TABLE IF EXISTS %s',
		$wpdb->prefix . 'scanpay_seq'
	)
);
$wpdb->query(
	$wpdb->prepare(
		'DROP TABLE IF EXISTS %s',
		$wpdb->prefix . 'woocommerce_scanpay_queuedcharges'
	)
);

// Delete plugin settings
delete_option( 'woocommerce_scanpay_settings' );
