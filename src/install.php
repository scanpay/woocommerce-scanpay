<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

global $wpdb;

// esc_like on all three lookups: '_' is a single-character LIKE wildcard and
// $wpdb->prefix normally contains one ('wp_'), so an unescaped pattern can match a
// table we did not mean.
$seq_tbl = $wpdb->prefix . 'scanpay_seq';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $seq_tbl ) ) ) !== $seq_tbl ) {
	$res = $wpdb->query(
		"CREATE TABLE $seq_tbl (
            shopid INT unsigned NOT NULL,
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

$meta_tbl = $wpdb->prefix . 'scanpay_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $meta_tbl ) ) ) !== $meta_tbl ) {
	$res = $wpdb->query(
		"CREATE TABLE $meta_tbl (
			orderid BIGINT unsigned NOT NULL,
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
			PRIMARY KEY (orderid)
		) CHARSET = latin1;"
	);
	if ( true !== $res ) {
		scanpay_log( 'error', 'Could not create scanpay SQL table' );
		throw new Exception( 'Could not create scanpay SQL table' );
	}
}

$subs_tbl = $wpdb->prefix . 'scanpay_subs';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $subs_tbl ) ) ) !== $subs_tbl ) {
	$res = $wpdb->query(
		"CREATE TABLE $subs_tbl (
			subid INT unsigned,
			rev INT unsigned,
			method VARCHAR(64),
			method_exp BIGINT unsigned,
			PRIMARY KEY (subid)
		) CHARSET = latin1;"
	);
	if ( true !== $res ) {
		scanpay_log( 'error', 'Could not create scanpay SQL table' );
		throw new Exception( 'Could not create scanpay SQL table' );
	}
}

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$shopid   = (int) explode( ':', (string) ( $settings['apikey'] ?? '' ) )[0];

// Decide now, before the secret below creates the settings option, whether this is a
// fresh install with nothing to migrate. Absent settings is the discriminator, not an
// absent version: 1.x never wrote 'wc_scanpay_version' but did write the settings option,
// so stamping a version on "no version" alone would skip upgrade.php's '< 2.0.0' branch
// forever on a 1.x site, leaving capture_on_complete unconverted and auto-capture off.
$fresh_install = false === $settings && false === get_option( 'wc_scanpay_version' );

// Seed this shop's cursor at 0. The ping handler refuses to sync without the row
// ("shop not configured"), and only a stored key tells us which shop to seed.
if ( 0 !== $shopid ) {
	$seq = $wpdb->get_var( "SELECT seq FROM $seq_tbl WHERE shopid = $shopid" );
	if ( null === $seq ) {
		$wpdb->query( "INSERT INTO $seq_tbl (shopid, seq, ping, mtime) VALUES ($shopid, 0, 0, 0)" );
	}
}

// Mint the admin-AJAX auth secret. process_admin_options() only persists form
// fields, and there is no 'secret' field, so without this a fresh install would
// never get one and the lightweight ?x=meta|ping|sub endpoints would 403 forever.
// Runs on both fresh installs and API-key changes; an existing secret is kept.
if ( empty( $settings['secret'] ) ) {
	if ( ! is_array( $settings ) ) {
		$settings = [];
	}
	$settings['secret'] = bin2hex( random_bytes( 32 ) );
	update_option( WC_SCANPAY_URI_SETTINGS, $settings, true );
}

// Nothing to migrate: stamp the version so the loader gate does not run upgrade.php's
// 1.x settings migration over a new install and overwrite the gateway field defaults.
// A no-op in the reset endpoint and in the card gateway's first-key save, which run on a
// shop that already has settings, a version, or both. Not in upgrade.php: its
// fresh-install exit requires this file for exactly this stamp -- on a network activation
// every blog but the activated one arrives there with neither option -- and returns above
// the 1.x branch, so there the stamp is the point rather than a no-op.
if ( $fresh_install ) {
	add_option( 'wc_scanpay_version', WC_SCANPAY_VERSION, '', true );
}
