<?php

/**
 * Migration to 3.0.0, run on any shop below it.
 *
 * What changed: the 2.x tables carry columns 3.x stopped writing -- scanpay_meta.method, and
 * retries/nxt/method_id/idem on scanpay_subs from the pre-3.0 charge design -- plus a UNIQUE key
 * declared alongside each table's PRIMARY KEY. Only scanpay_meta.method actually blocks 3.x: it
 * is NOT NULL with no DEFAULT, so under a strict SQL mode every insert fails (MySQL 1364) and the
 * cursor cannot advance past that change. The rest is dead weight, dropped so that a migrated
 * shop and a fresh one carry one schema between them -- three SELECT * readers put these rows on
 * the wire, against types that document the column list field for field.
 *
 * Dropped in place, never DROP TABLE: rows, cursors and revisions all survive. That is what keeps
 * the change free of a re-drain, of a window where capture finds no payment row and renewals find
 * no subscriber, and of anything for a concurrent drain to race. An ALTER against a table the
 * drain is inserting into needs no flock either way: from MySQL 5.6 and MariaDB 10.0 it is online,
 * InnoDB applying the concurrent DML from its log at the end, and below that the table copy holds
 * a metadata lock the drain waits on rather than fails against.
 *
 * What a re-run must tolerate: a half-dropped table. Columns and keys are re-read on every run,
 * so a retry accepts a mixture where some are already gone.
 *
 * Throws if a table cannot be introspected or an ALTER fails: the loader keeps its transient and
 * retries, which is the right answer for a shop whose inserts are failing.
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
// Ahead of the ALTERs below so a shop whose column drop keeps failing still gets the line.
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

/*
 * The obsolete columns per table. scanpay_seq has none -- its 2.x columns are already the 3.x
 * ones -- and is listed anyway for the key drop below.
 *
 * Read before dropped, because MySQL has no DROP COLUMN IF EXISTS at any version and MariaDB's
 * (since 10.0.2) is an extension we cannot rely on. That read is also what makes a re-run
 * idempotent, so it is not worth trading for a version test.
 */
$wcsp_obsolete = [
	'scanpay_seq'  => [],
	'scanpay_meta' => [ 'method' ],
	'scanpay_subs' => [ 'retries', 'nxt', 'method_id', 'idem' ],
];

foreach ( $wcsp_obsolete as $wcsp_name => $wcsp_columns ) {
	$wcsp_tbl  = $wpdb->prefix . $wcsp_name;
	$wcsp_cols = [];
	$wcsp_keys = [];

	// %i binds the identifier; it is WP 6.2 and so under the floor, needing no guard.
	// upgrade.php requires install.php before us, so the table exists and an error here is a
	// real read failure rather than a missing table.
	$wcsp_found = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $wcsp_tbl ) );
	if ( '' !== $wpdb->last_error ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
		throw new Exception( "Could not read the columns of $wcsp_tbl: {$wpdb->last_error}" );
	}
	foreach ( $wcsp_columns as $wcsp_col ) {
		if ( in_array( $wcsp_col, $wcsp_found, true ) ) {
			$wcsp_cols[] = "DROP COLUMN `$wcsp_col`";
		}
	}

	/*
	 * Every key that is not PRIMARY, rather than the three 2.x names: the 3.x schema declares no
	 * secondary index on any of these tables, we own them outright, and the reset endpoint
	 * already recreates them that way -- so "not PRIMARY" is the rule, and MySQL's auto-generated
	 * name for an inline UNIQUE is not worth guessing at.
	 *
	 * What they cost: 2.x declared UNIQUE alongside each PRIMARY KEY, which InnoDB stores as a
	 * secondary index whose every entry is the primary key duplicated -- dead space on
	 * scanpay_meta, plus a second B-tree to write on each sync upsert. 5f4468b dropped them from
	 * install.php; this is the same removal for shops that predate it.
	 *
	 * SHOW INDEX answers one row per indexed column, hence the dedupe. Read by name rather than
	 * by position: MySQL 8.0 appended Visible and Expression to this result, and a column list
	 * that grows is one that can be reordered.
	 */
	$wcsp_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $wcsp_tbl ), ARRAY_A );
	if ( '' !== $wpdb->last_error ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
		throw new Exception( "Could not read the keys of $wcsp_tbl: {$wpdb->last_error}" );
	}
	foreach ( array_unique( array_column( $wcsp_rows, 'Key_name' ) ) as $wcsp_key ) {
		if ( 'PRIMARY' !== $wcsp_key ) {
			$wcsp_keys[] = "DROP INDEX `$wcsp_key`";
		}
	}

	/*
	 * Columns and keys in separate ALTERs, each kind gathered into one: from MySQL 8.0.29 and
	 * MariaDB 10.4 a DROP COLUMN on its own is instant, and a DROP INDEX has only ever touched
	 * InnoDB's metadata, while one statement carrying both forfeits the instant algorithm and
	 * rebuilds the table. Below those versions the column drop copies the table however it is
	 * written, so the split costs a second statement and nothing else. Gathered, because each
	 * instant ALTER spends one of the 64 row versions MySQL allows a table before it insists on
	 * a rebuild.
	 *
	 * No ALGORITHM clause to ask for any of this: it arrived in MySQL 5.6 and the DB floor is
	 * WordPress's 5.5.5, and an explicit ALGORITHM=INSTANT is an error wherever it does not
	 * apply rather than a fallback. Unstated, the server picks the fastest it has.
	 */
	foreach ( [ $wcsp_cols, $wcsp_keys ] as $wcsp_clauses ) {
		if ( ! $wcsp_clauses ) {
			continue;
		}
		$wcsp_alter = "ALTER TABLE $wcsp_tbl " . implode( ', ', $wcsp_clauses );
		// A DDL query returns $wpdb->result, never a row count, so false is the failure.
		if ( false === $wpdb->query( $wcsp_alter ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new Exception( "Could not run '$wcsp_alter': {$wpdb->last_error}" );
		}
	}

	// The clauses that ran, named: this is schema surgery on a live shop, and upgrade.php's
	// "Running the 3.0.0 migration" is written before the fact, so nothing else says what was
	// touched. Silent on a table with nothing to drop, so the retry after a half-applied run
	// reports the remainder rather than repeating the whole list.
	if ( $wcsp_cols || $wcsp_keys ) {
		scanpay_log( 'info', "Dropped from $wcsp_tbl: " . implode( ', ', array_merge( $wcsp_cols, $wcsp_keys ) ) );
	}
}
