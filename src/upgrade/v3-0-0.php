<?php

/**
 * Migration to 3.0.0, run on any shop below it.
 *
 * What changed: the 2.x tables carry columns 3.x stopped writing -- scanpay_meta.method, and
 * retries/nxt/method_id/idem on scanpay_subs from the pre-3.0 charge design. scanpay_meta.method
 * is NOT NULL with no DEFAULT, so under a strict SQL mode every 3.x insert fails (MySQL 1364)
 * and the cursor cannot advance past that change.
 *
 * Dropped and recreated rather than ALTERed away: wiping the local tables is recoverable by
 * design, so this needs to know only the current schema -- install.php's -- and not 2.x's. The
 * sync rebuilds every row from Scanpay's changelog and skips any order already carrying a
 * transaction id, so payment_complete() does not re-fire and the cost is a re-drain, not data.
 * scanpay_seq goes with them so install.php reseeds the cursor at 0; kept, it would leave the
 * emptied tables filling with future changes only. The re-drain is incremental and self-healing:
 * the ping persists the cursor after each page, and the five-minute keepalive resumes whatever a
 * killed request left behind.
 *
 * What a re-run must tolerate: any point in the sequence. DROP TABLE IF EXISTS is a no-op on an
 * absent table and install.php creates only what is missing, so a retry converges -- at worst
 * re-draining from a cursor that never advanced.
 *
 * Throws if a drop fails: the loader keeps its transient and retries, which is the right answer
 * for a shop whose inserts are failing.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

global $wpdb;

// v2.0.0..v2.1.4 created scanpay_queue, later 2.x stopped creating it without ever dropping
// it, and 3.x reads it nowhere -- so a shop that passed through that range still carries it,
// and any row in it is a capture or charge the old runtime never got to. Reported rather than
// dropped or replayed: Scanpay is the source of truth and either can still be done by hand,
// but nothing else would ever tell the merchant the rows were there. uninstall.php owns
// removing the table.
//
// Ahead of the drops below so a shop whose recreate keeps failing still gets the line.
// esc_like for the reason install.php states: '_' is a LIKE wildcard.
$queue_tbl = $wpdb->prefix . 'scanpay_queue';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $queue_tbl ) ) ) === $queue_tbl ) {
	$queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $queue_tbl" );
	if ( $queued > 0 ) {
		scanpay_log(
			'warning',
			"$queued unprocessed row(s) in the legacy $queue_tbl are not carried over: " .
			'3.x never reads that table, so any pending capture or charge must be repeated by hand.'
		);
	}
}

// One statement per table, each result checked: a combined DROP can half-succeed with nothing
// to tell which half. A DDL query returns $wpdb->result, never a row count, so false is the
// failure.
foreach ( [ 'scanpay_seq', 'scanpay_meta', 'scanpay_subs' ] as $wcsp_tbl ) {
	if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}$wcsp_tbl" ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
		throw new Exception( "Could not drop {$wpdb->prefix}$wcsp_tbl: {$wpdb->last_error}" );
	}
}

// Recreates all three at the current schema and reseeds this shop's cursor at 0, throwing if
// either fails. Already required once by upgrade.php, and idempotent, so running it again is
// only the three SHOW TABLES LIKE it costs on a shop that has everything.
require WC_SCANPAY_DIR . '/install.php';

scanpay_log( 'info', 'Scanpay tables rebuilt at the 3.0.0 schema; the sync will re-drain the change history from seq 0.' );
