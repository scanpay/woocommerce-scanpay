<?php
defined( 'ABSPATH' ) || exit();

global $wpdb;

// Seq table
$seq_tbl = $wpdb->prefix . 'scanpay_seq';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$seq_tbl'" ) !== $seq_tbl ) {
	$res = $wpdb->query(
		"CREATE TABLE $seq_tbl (
            shopid INT unsigned NOT NULL UNIQUE,
            seq INT unsigned NOT NULL,
            ping INT unsigned,
            mtime BIGINT unsigned NOT NULL,
            PRIMARY KEY  (shopid)
        ) CHARSET = latin1;"
	);
	if ( true !== $res ) {
		scanpay_log( 'error', 'Could not create scanpay SQL table' );
		throw new Exception( 'Could not create scanpay SQL table' );
	}
}

// Meta table
$meta_tbl = $wpdb->prefix . 'scanpay_meta';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$meta_tbl'" ) !== $meta_tbl ) {
	$res = $wpdb->query(
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
	if ( true !== $res ) {
		scanpay_log( 'error', 'Could not create scanpay SQL table' );
		throw new Exception( 'Could not create scanpay SQL table' );
	}
}

// Subscriptions table
$subs_tbl = $wpdb->prefix . 'scanpay_subs';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$subs_tbl'" ) !== $subs_tbl ) {
	$res = $wpdb->query(
		"CREATE TABLE $subs_tbl (
			subid INT unsigned UNIQUE,
			rev INT unsigned,
			retries INT unsigned,
			nxt BIGINT unsigned,
			method VARCHAR(64),
			method_id VARCHAR(64),
			method_exp BIGINT unsigned,
			idem VARCHAR(64),
			PRIMARY KEY (subid)
		) CHARSET = latin1;"
	);
	if ( true !== $res ) {
		scanpay_log( 'error', 'Could not create scanpay SQL table' );
		throw new Exception( 'Could not create scanpay SQL table' );
	}
}

// Insert shopid into seq table
$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$shopid   = (int) explode( ':', $settings['apikey'] ?? '' )[0];

if ( 0 !== $shopid ) {
	$seq = $wpdb->get_var( "SELECT seq FROM $seq_tbl WHERE shopid = $shopid" );
	if ( null === $seq ) {
		$wpdb->query( "INSERT INTO $seq_tbl (shopid, seq, ping, mtime) VALUES ($shopid, 0, 0, 0)" );
	}
}
