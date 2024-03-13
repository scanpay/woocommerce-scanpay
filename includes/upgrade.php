<?php
defined( 'ABSPATH' ) || exit();

global $wpdb;
$version    = get_option( 'wc_scanpay_version', '0.0.0' );
$wcs_exists = class_exists( 'WC_Subscriptions', false );
set_time_limit( 60 );
scanpay_log( 'info', "Upgrading Scanpay plugin from $version to " . WC_SCANPAY_VERSION );

if ( version_compare( $version, '2.0.0', '<' ) ) {
	// Delete all tables
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_queuedcharges" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_seq" );

	require WC_SCANPAY_DIR . '/includes/install.php';

	// Migrate old settings to new settings
	$old = get_option( WC_SCANPAY_URI_SETTINGS );
	$arr = [
		'enabled'              => $old['enabled'] ?? 'no',
		'apikey'               => $old['apikey'] ?? '',
		'title'                => $old['title'] ?? 'Pay by card.',
		'description'          => $old['description'] ?? 'Pay with card through Scanpay.',
		'card_icons'           => $old['card_icons'] ?? [ 'visa', 'mastercard' ],
		'capture_on_complete'  => $old['capture_on_complete'] ?? 'yes',
		'wc_complete_virtual'  => 'no',
		'wcs_complete_initial' => 'no',
		'wcs_complete_renewal' => $old['autocomplete_renewalorders'] ?? 'no',
		'stylesheet'           => 'yes',
		'secret'               => bin2hex( random_bytes( 32 ) ),
	];
	update_option( WC_SCANPAY_URI_SETTINGS, $arr, true );
} elseif ( version_compare( $version, '2.2.0', '<' ) ) {
	// make sure that new options exists
	$old      = get_option( WC_SCANPAY_URI_SETTINGS );
	$settings = array_merge(
		[
			'wc_complete_virtual'  => 'no',
			'wcs_complete_initial' => 'no',
			'wcs_complete_renewal' => 'no',
		],
		$old
	);
	update_option( WC_SCANPAY_URI_SETTINGS, $settings, true );
}

/*
	Temporary fix for bug in old plugin (1.x.x)
*/
if ( $wcs_exists && version_compare( $version, '2.1.3', '<' ) ) {
	$args    = [
		'type'     => 'shop_subscription',
		'status'   => 'all',
		'return'   => 'ids',
		'meta_key' => '_scanpay_subscriber_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'limit'    => -1,
	];
	$wc_subs = wc_get_orders( $args );

	foreach ( $wc_subs as $oid ) {
		$wc_sub = wcs_get_subscription( $oid );
		if ( ! $wc_sub || ! str_starts_with( $wc_sub->get_payment_method(), 'scanpay' ) ) {
			continue;
		}
		$subid       = (int) $wc_sub->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
		$black_subid = (int) $wc_sub->get_meta( '_scanpay_subscriber_id', true, 'edit' );
		if ( $black_subid > $subid ) {
			if ( $subid ) {
				$trn       = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}scanpay_meta WHERE subid = $subid ORDER BY id DESC LIMIT 1" );
				$black_trn = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}scanpay_meta WHERE subid = $black_subid ORDER BY id DESC LIMIT 1" );
				if ( $trn && $trn > $black_trn ) {
					continue;
				}
			}
			scanpay_log( 'info', "change subid on #$oid (from '$subid' to '$black_subid'" );
			$wc_sub->update_meta_data( WC_SCANPAY_URI_SUBID, $black_subid );
			$wc_sub->save_meta_data();
			wp_cache_flush();
		}
	}
}

update_option( 'wc_scanpay_version', WC_SCANPAY_VERSION, true ); // with autoload
