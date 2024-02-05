<?php
defined( 'ABSPATH' ) || exit();

global $wpdb;
$version = get_option( 'wc_scanpay_version' );

if ( ! $version || version_compare( $version, '2.0.0', '<' ) ) {
	scanpay_log( 'debug', 'Upgrading Scanpay plugin to ' . WC_SCANPAY_VERSION );

	// Delete all tables
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_queuedcharges" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_seq" );

	require WC_SCANPAY_DIR . '/includes/install.php';

	// Migrate old settings to new settings
	$old = get_option( WC_SCANPAY_URI_SETTINGS );
	$arr = [
		'enabled'              => $old['enabled'] ?? 'no',
		'apikey'               => $old['apikey'] ?? '',
		'title'                => $old['title'] ?? __( 'Pay by card.', 'scanpay-for-woocommerce' ),
		'description'          => $old['description'] ?? __( 'Pay with card through Scanpay.', 'scanpay-for-woocommerce' ),
		'card_icons'           => $old['card_icons'] ?? [ 'visa', 'mastercard' ],
		'capture_on_complete'  => $old['capture_on_complete'] ?? 'yes',
		'wcs_complete_initial' => 'no',
		'wcs_complete_renewal' => $old['autocomplete_renewalorders'] ?? 'no',
		'stylesheet'           => 'yes',
		'secret'               => bin2hex( random_bytes( 32 ) ),
	];
	update_option( WC_SCANPAY_URI_SETTINGS, $arr, true );
}

update_option( 'wc_scanpay_version', WC_SCANPAY_VERSION, true ); // with autoload
