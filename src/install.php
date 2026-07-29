<?php

/**
 * Creates the three custom tables, seeds this shop's sync cursor and mints the admin-AJAX
 * secret. Required -- and thereby run -- from three places: upgrade.php, the card gateway's
 * first-key save and the reset endpoint. Idempotent, needs no WooCommerce runtime, and throws
 * if a table cannot be created or this shop's cursor row cannot be seeded.
 *
 * Migrations and the 'wc_scanpay_version' option are upgrade.php's alone -- two of the three
 * callers run on a shop that is not being upgraded, so a stamp from here would claim
 * "migrated" on a site nothing migrated -- and there is no activation hook: the loader gate
 * that runs upgrade.php is the install path too.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

global $wpdb;

// esc_like on all three lookups: '_' is a single-character LIKE wildcard and $wpdb->prefix
// normally contains one, so an unescaped pattern can match a table we did not mean.
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

// 'method' is the payment-method *type*, not the pretty card label the order screen prints.
// No retry count, idempotency key or lock column, deliberately: charging is lock-free, the
// key is derived per renewal, and WCS owns retry scheduling.
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

// Seed this shop's cursor at 0. The ping handler refuses to sync without the row
// ("shop not configured"), and only a stored key tells us which shop to seed.
if ( 0 !== $shopid ) {
	$seq = $wpdb->get_var( "SELECT seq FROM $seq_tbl WHERE shopid = $shopid" );
	if ( null === $seq ) {
		$wpdb->query( "INSERT INTO $seq_tbl (shopid, seq, ping, mtime) VALUES ($shopid, 0, 0, 0)" );
		// Re-read rather than test the INSERT's return: two racing installs lose the
		// duplicate-key race harmlessly, and it is the row's presence that matters, not who
		// wrote it. Throwing matters here -- without the row the merchant gets a successful
		// key save, a green settings screen and a shop that never syncs.
		if ( null === $wpdb->get_var( "SELECT seq FROM $seq_tbl WHERE shopid = $shopid" ) ) {
			scanpay_log( 'error', "Could not seed the scanpay_seq row for shop $shopid: {$wpdb->last_error}" );
			throw new Exception( 'Could not seed the scanpay sequence row' );
		}
	}
}

// Mint the admin-AJAX auth secret. process_admin_options() only persists form fields, and
// there is no 'secret' field, so without this the ?x=meta|ping|sub endpoints would 403
// forever. An existing secret is kept. Note that this write creates the settings option on a
// shop that had none, which is the discriminator upgrade.php reads before requiring us.
if ( empty( $settings['secret'] ) ) {
	if ( ! is_array( $settings ) ) {
		$settings = [];
	}
	$settings['secret'] = bin2hex( random_bytes( 32 ) );
	update_option( WC_SCANPAY_URI_SETTINGS, $settings, true );
}
