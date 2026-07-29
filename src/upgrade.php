<?php

/**
 * Every migration, run in version order from the loader gate whenever the stored version
 * differs from WC_SCANPAY_VERSION. Each branch is idempotent and the version is stamped
 * last, so an interrupted run simply re-runs from the start on the next request.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

global $wpdb;
$version = (string) get_option( 'wc_scanpay_version', '0.0.0' );
set_time_limit( 60 );

/*
 * A blog with no history at all, which is not an upgrade. activate_plugin() fires
 * activate_{$plugin} once however wide the activation is -- $network_wide is an argument
 * to the hook, not a loop over the network -- so a network activation runs install.php for
 * one blog's prefix. Every other blog first meets the plugin at the loader gate with both
 * options absent, and would fall into the '< 2.0.0' branch: 1.x defaults written over a
 * site that never ran 1.x.
 *
 * Above the log line on purpose: a blog with no history must not report an upgrade "from
 * 0.0.0" it never ran, and that line is the only record of this path anyone sees.
 *
 * The options are read here rather than $version, which defaults to '0.0.0' and so cannot
 * tell absent from stored. Same two reads as install.php, where the reason is written down
 * -- absent *settings* is the discriminator. Simplifying either side to a version test
 * alone re-opens that bug.
 */
if ( false === get_option( WC_SCANPAY_URI_SETTINGS ) && false === get_option( 'wc_scanpay_version' ) ) {
	// Creates this blog's tables and stamps the version through its own $fresh_install
	// path. Re-read for the reason the tail of this file gives, which this return skips.
	// The throw lands in the loader's catch, which keeps its five-minute transient, and
	// install.php is idempotent -- the retry costs three SHOW TABLES LIKE.
	require WC_SCANPAY_DIR . '/install.php';
	if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION ) {
		throw new Exception( 'Could not store the new plugin version' );
	}
	return;
}

scanpay_log( 'info', "Upgrading Scanpay plugin from $version to " . WC_SCANPAY_VERSION );

if ( version_compare( $version, '2.0.0', '<' ) ) {
	// Drop the legacy 1.x/2.x tables before (re)installing the current 3.x schema.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_queuedcharges" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_seq" );

	require WC_SCANPAY_DIR . '/install.php';

	// Rebuild the settings option on the 3.x field names, keeping the 1.x/2.x values.
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
		// Preserve an existing secret, or an interrupted re-run invalidates the in-flight
		// admin-AJAX token.
		'secret'               => $old['secret'] ?? bin2hex( random_bytes( 32 ) ),
	];
	update_option( WC_SCANPAY_URI_SETTINGS, $arr, true );
} elseif ( version_compare( $version, '2.2.0', '<' ) ) {
	// Backfill the settings added in 2.2.0; array_merge lets stored values win.
	$old = get_option( WC_SCANPAY_URI_SETTINGS );
	// An absent or scalar option -- a partially restored database, a wp option delete -- is
	// an array_merge() TypeError, not a skipped merge, and it would take down the whole
	// file: every branch below, the version stamp included, retried and re-thrown forever.
	if ( ! is_array( $old ) ) {
		$old = [];
	}
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
 *  Version: 2.5.0
 *  - Setting 'capture_on_complete' (checkbox) changed to 'wc_autocapture' (dropdown)
 */
if ( version_compare( $version, '2.5.0', '<' ) ) {
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) ) {
		$settings = [];
	}
	// Only derive wc_autocapture when unset, so an interrupted re-run -- capture_on_complete
	// already gone -- cannot silently flip it to 'off'.
	if ( ! isset( $settings['wc_autocapture'] ) ) {
		$settings['wc_autocapture'] = ( isset( $settings['capture_on_complete'] ) && 'yes' === $settings['capture_on_complete'] ) ? 'completed' : 'off';
	}
	unset( $settings['capture_on_complete'] );
	update_option( WC_SCANPAY_URI_SETTINGS, $settings, true );
}

/*
 *  Version: 3.0.0
 *  The 2.x schema carries columns v3 stopped writing: scanpay_meta.method, and
 *  retries/nxt/method_id/idem on scanpay_subs from the pre-3.0 charge design.
 *  scanpay_meta.method is NOT NULL with no DEFAULT, so under a strict SQL mode every v3
 *  insert fails (MySQL 1364) and the cursor cannot advance past that change. Dropped in
 *  place, so rows, cursors, revisions and method data all survive. The redundant UNIQUE
 *  keys 2.x declared alongside each PRIMARY KEY are harmless and left alone.
 */
if ( version_compare( $version, '3.0.0', '<' ) ) {
	// Creates whichever table this site never had, from the v3 schema. It cannot stamp the
	// version early -- reaching this branch means settings or a version exist, either of
	// which makes install.php's $fresh_install false.
	require WC_SCANPAY_DIR . '/install.php';
	require_once WC_SCANPAY_DIR . '/library/schema.php';

	// Presence is re-read on every run, so a retry after an interrupted migration accepts a
	// mixture where some columns are already gone. Not DROP COLUMN IF EXISTS: MySQL has no
	// such clause at any version, and MariaDB's (since 10.0.2) is an extension we cannot
	// rely on. Reading the columns first is the only portable way.
	$obsolete = [
		$wpdb->prefix . 'scanpay_meta' => [ 'method' ],
		$wpdb->prefix . 'scanpay_subs' => [ 'retries', 'nxt', 'method_id', 'idem' ],
	];
	foreach ( $obsolete as $tbl => $columns ) {
		$found = wc_scanpay_table_columns( $tbl );
		if ( '' !== $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new Exception( "Could not read the columns of $tbl: " . $wpdb->last_error );
		}
		$drops = [];
		foreach ( $columns as $col ) {
			if ( in_array( $col, $found, true ) ) {
				$drops[] = "DROP COLUMN `$col`";
			}
		}
		// One ALTER per table: fewer rebuilds, and MySQL applies it as a unit.
		if ( $drops ) {
			// A DDL query returns $wpdb->result, never a row count, so false is the failure.
			$res = $wpdb->query( "ALTER TABLE $tbl " . implode( ', ', $drops ) );
			if ( false === $res ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
				throw new Exception( "Could not drop obsolete columns from $tbl: " . $wpdb->last_error );
			}
		}
	}
}

// Stamped last: an interrupted upgrade must re-run from the start on the next request.
// Autoloaded, because the loader gate reads it on every request.
update_option( 'wc_scanpay_version', WC_SCANPAY_VERSION, true );

// Reread rather than trust the return: update_option() also answers false when the stored
// value already matches, which a concurrent request can arrange. Throwing hands the failure
// to the loader, which keeps its transient and retries the whole migration -- better than
// reporting a version this site does not have.
if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION ) {
	throw new Exception( 'Could not store the new plugin version' );
}
scanpay_log( 'info', 'Scanpay plugin upgrade complete' );
