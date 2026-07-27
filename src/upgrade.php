<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

global $wpdb;
$version    = (string) get_option( 'wc_scanpay_version', '0.0.0' );
$wcs_exists = class_exists( 'WC_Subscriptions', false );
set_time_limit( 60 );
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
		// Preserve an existing secret so an interrupted re-run does not invalidate the
		// in-flight admin-AJAX auth token; only mint one on the true first run.
		'secret'               => $old['secret'] ?? bin2hex( random_bytes( 32 ) ),
	];
	update_option( WC_SCANPAY_URI_SETTINGS, $arr, true );
} elseif ( version_compare( $version, '2.2.0', '<' ) ) {
	// Backfill the settings added in 2.2.0; array_merge lets stored values win.
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
 *  Version: 2.1.3
 *  1.x tracked the subscriber id in its own '_scanpay_subscriber_id' meta. Adopt that id
 *  when it is the higher of the two, unless the subscription's current subid already
 *  carries the newer transaction -- in which case 1.x's copy is the stale one.
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

/*
 *  Version: 2.5.0
 *  - Setting 'capture_on_complete' (checkbox) changed to 'wc_autocapture' (dropdown)
 */
if ( version_compare( $version, '2.5.0', '<' ) ) {
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( ! is_array( $settings ) ) {
		$settings = [];
	}
	// Idempotent: only derive wc_autocapture when it is not already set, so an
	// interrupted re-run (capture_on_complete already unset) cannot silently flip it
	// to 'off' and disable auto-capture.
	if ( ! isset( $settings['wc_autocapture'] ) ) {
		$settings['wc_autocapture'] = ( isset( $settings['capture_on_complete'] ) && 'yes' === $settings['capture_on_complete'] ) ? 'completed' : 'off';
	}
	unset( $settings['capture_on_complete'] );
	update_option( WC_SCANPAY_URI_SETTINGS, $settings, true );
}

/*
 *  Version: 3.0.0
 *  The released 2.x schema carries columns v3 stopped writing: scanpay_meta.method,
 *  and retries/nxt/method_id/idem on scanpay_subs from the pre-3.0 charge design.
 *  scanpay_meta.method is NOT NULL with no DEFAULT, so under a strict SQL mode every
 *  v3 insert fails outright (MySQL 1364) and the cursor cannot advance past that
 *  change. Drop them in place: rows, cursors, revisions and method data all survive.
 *  (2.x also declared UNIQUE alongside the PRIMARY KEY on all three tables, dropped
 *  in 5f4468b. Redundant, not harmful, and left alone here.)
 */
if ( version_compare( $version, '3.0.0', '<' ) ) {
	// Creates from the v3 schema whichever table this site never had; a no-op for the
	// rest. It cannot stamp the version early -- reaching this branch means settings or
	// a version exist, either of which makes install.php's $fresh_install false.
	require WC_SCANPAY_DIR . '/install.php';
	require_once WC_SCANPAY_DIR . '/library/schema.php';

	// Presence is re-read on every run, so a retry after an interrupted migration
	// accepts a mixture where some columns are already gone. Not DROP COLUMN IF EXISTS:
	// that needs MySQL 8.0.29 / MariaDB 10.5, well above the oldest server the
	// WordPress minimum supports.
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

// Reread rather than trust the return: update_option() also answers false when the
// stored value already matches, which a concurrent request can arrange. Throwing hands
// the failure to the loader, which keeps its five-minute transient and retries the
// whole migration -- far better than reporting a version this site does not have.
if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION ) {
	throw new Exception( 'Could not store the new plugin version' );
}
scanpay_log( 'info', 'Scanpay plugin upgrade complete' );
