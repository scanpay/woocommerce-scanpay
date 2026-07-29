<?php

/**
 * Migration to 2.0.0, run on any shop below it -- in practice a 1.x one, since 2.0.0 is where
 * the plugin began writing 'wc_scanpay_version'.
 *
 * What changed: 2.0.0 replaced the two 1.x tables and renamed three settings keys. The tables
 * are dropped, since the 3.x ones install.php created hold the same data from the next sync
 * onwards; the keys are converted in place.
 *
 * What a re-run must tolerate: its own output. The version is stamped last by design, so an
 * upgrade interrupted further down retries this file with the 1.x names already gone -- hence
 * each key below reads back what a previous run stored before it falls through to a default.
 *
 * What it hands on: 'capture_on_complete', which the 2.5.0 migration converts. It goes through
 * the stored option and not through this scope -- upgrade.php requires the two files in the
 * same breath, but only one of them may be due.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

global $wpdb;

// The legacy 1.x/2.x tables. Their names do not collide with the 3.x ones install.php just
// created, so dropping them after it is safe.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_queuedcharges" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}woocommerce_scanpay_seq" );

// An absent or scalar option -- a partially restored database, a wp option delete -- is an
// offset TypeError, not a skipped step, and it would take down the whole upgrade: every
// migration after this one, the version stamp included, retried and re-thrown forever.
$settings = get_option( WC_SCANPAY_URI_SETTINGS );
if ( ! is_array( $settings ) ) {
	$settings = [];
}

// The two field names 2.0.0 moved, both 'yes'/'no' checkboxes whose 1.x label states the same
// behaviour the 3.x one does. Every other key either kept its name or has a default that
// resolves an absent one -- WC_Settings_API::get_option() on the settings screen, '?? no' at
// each call site, default_title() for the checkout title. Writing those defaults here would
// pin the English strings past translation and make an upgraded shop differ from a fresh one,
// which carries none of these keys either.
//
// Newest key first, for the reason the header gives: the update_option() below drops the 1.x
// names, so a second run must read back what the first stored rather than fall through to 'no'.
$settings['wcs_complete_renewal'] = $settings['wcs_complete_renewal'] ?? $settings['autocomplete_renewalorders'] ?? 'no';
$settings['wc_complete_virtual']  = $settings['wc_complete_virtual'] ?? $settings['autocomplete_virtual'] ?? 'no';
unset( $settings['autocomplete_renewalorders'], $settings['autocomplete_virtual'] );

// The one exception to that, hence set rather than left absent: 1.x captured on order
// completion unless the merchant turned it off, so an option predating the checkbox means
// 'yes', not the 'off' the 2.5.0 migration would otherwise derive from an absent key.
$settings['capture_on_complete'] = $settings['capture_on_complete'] ?? 'yes';

// Written here rather than left for 2.5.0 to write: the two are separate migrations, and only
// a stored value survives an interruption between them.
update_option( WC_SCANPAY_URI_SETTINGS, $settings, true );
