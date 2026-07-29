<?php

/**
 * Migration to 3.0.0, run on any shop below it.
 *
 * What changed: the 2.x tables carry columns 3.x stopped writing -- scanpay_meta.method, and
 * retries/nxt/method_id/idem on scanpay_subs from the pre-3.0 charge design. scanpay_meta.method
 * is NOT NULL with no DEFAULT, so under a strict SQL mode every 3.x insert fails (MySQL 1364)
 * and the cursor cannot advance past that change. The columns are dropped in place, so rows,
 * cursors, revisions and method data all survive. The redundant UNIQUE keys 2.x declared
 * alongside each PRIMARY KEY are harmless and left alone.
 *
 * What a re-run must tolerate: a half-dropped table. Column presence is re-read on every run,
 * so a retry accepts a mixture where some are already gone.
 *
 * Throws if a table's columns cannot be read or an ALTER fails: the loader keeps its transient
 * and retries, which is the right answer for a shop whose inserts are failing.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

global $wpdb;

require_once WC_SCANPAY_DIR . '/library/schema.php';

// v2.0.0..v2.1.4 created scanpay_queue, later 2.x stopped creating it without ever dropping
// it, and 3.x reads it nowhere -- so a shop that passed through that range still carries it,
// and any row in it is a capture or charge the old runtime never got to. Reported rather than
// dropped or replayed: Scanpay is the source of truth and either can still be done by hand,
// but nothing else would ever tell the merchant the rows were there. uninstall.php owns
// removing the table.
//
// Ahead of the ALTERs below so a shop whose column drop keeps failing still gets the line.
// esc_like for the reason install.php states: '_' is a LIKE wildcard.
$queue_tbl = $wpdb->prefix . 'scanpay_queue';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $queue_tbl ) ) ) === $queue_tbl ) {
	$queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_tbl" );
	if ( $queued > 0 ) {
		scanpay_log( 'warning', "$queued unprocessed row(s) in the legacy $queue_tbl are not carried over: 3.x never reads that table, so any pending capture or charge must be repeated by hand." );
	}
}

// Not DROP COLUMN IF EXISTS: MySQL has no such clause at any version, and MariaDB's (since
// 10.0.2) is an extension we cannot rely on. Reading the columns first is the only portable way.
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
