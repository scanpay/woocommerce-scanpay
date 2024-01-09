<?php
defined( 'ABSPATH' ) || exit();

global $wpdb;
$seq_tbl  = $wpdb->prefix . 'scanpay_seq';
$meta_tbl = $wpdb->prefix . 'scanpay_meta';
$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$apikey   = (string) $settings['apikey'];
$shopid   = (int) explode( ':', $apikey )[0];

/*
*   1) Delete old tables if they exist
*/
$old_seq_tbl   = $wpdb->prefix . 'woocommerce_scanpay_seq';
$old_queue_tbl = $wpdb->prefix . 'woocommerce_scanpay_queuedcharges';
$wpdb->query( "DROP TABLE IF EXISTS $old_seq_tbl" );
$wpdb->query( "DROP TABLE IF EXISTS $old_queue_tbl" );

// Drop new tables too (DEV)
$wpdb->query( "DROP TABLE IF EXISTS $seq_tbl" );
$wpdb->query( "DROP TABLE IF EXISTS $meta_tbl" );

/*
*   2) Check if seq table exists or create it
*/

if ( $wpdb->get_var( "SHOW TABLES LIKE '$seq_tbl'" ) !== $seq_tbl ) {
	$wpdb->query(
		"CREATE TABLE $seq_tbl (
            shopid INT unsigned NOT NULL UNIQUE,
            seq INT unsigned NOT NULL,
            ping INT unsigned,
            mtime BIGINT unsigned NOT NULL,
            PRIMARY KEY  (shopid)
        ) CHARSET = latin1;"
	);

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$seq_tbl'" ) !== $seq_tbl ) {
		scanpay_log( 'error', 'Could not create scanpay SQL table' );
		throw new Exception( 'Could not create scanpay SQL table' );
	}
}

/*
*   3) Check if meta table exists or create it
*/

if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_tbl'" ) !== $meta_tbl ) {
	$wpdb->query(
		"CREATE TABLE $meta_tbl (
			orderid BIGINT unsigned NOT NULL UNIQUE,
			shopid INT unsigned NOT NULL,
			subid INT unsigned,
			id INT unsigned NOT NULL,
			rev INT unsigned NOT NULL,
			nacts INT unsigned NOT NULL,
			currency CHAR(3) NOT NULL,
			authorized VARCHAR(64) NOT NULL,
			captured VARCHAR(64) NOT NULL,
			refunded VARCHAR(64) NOT NULL,
			voided VARCHAR(64) NOT NULL,
			method VARCHAR(64) NOT NULL,
			PRIMARY KEY (orderid)
		) CHARSET = latin1;"
	);

	if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_tbl'" ) !== $meta_tbl ) {
		scanpay_log( 'error', 'Could not create scanpay SQL table' );
		throw new Exception( 'Could not create scanpay SQL table' );
	}
}


/*
*   3) Insert row if apikey/shopid is set
*/
if ( 0 !== $shopid ) {
	$seq = $wpdb->get_var( "SELECT seq FROM $seq_tbl WHERE shopid = $shopid" );
	if ( null === $seq ) {
		$wpdb->query( "INSERT INTO $seq_tbl (shopid, seq, ping, mtime) VALUES ($shopid, 0, 0, 0)" );
	}
}
