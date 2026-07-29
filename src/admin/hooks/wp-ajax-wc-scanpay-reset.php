<?php

/**
 * The "delete data and change API key" button on the settings screen, hooked to
 * wp_ajax_wc_scanpay_reset. Drops the three custom tables, clears the API key and disables
 * every gateway; install.php then recreates the tables empty and, with no key stored,
 * seeds no seq row, leaving the store in a pre-setup state. The whole sequence runs under
 * the old shop's sync lock and ends by checking its own postconditions -- neither
 * install.php returning nor a truthy DROP proves anything was deleted.
 *
 * Contract: POST nonce. Answers WooCommerce's JSON envelope with a short error code the
 * settings JS shows verbatim; the concrete cause goes to the log.
 *
 * Nothing deleted here is lost for good: the tables hold only what the sync API can replay. A
 * key for the same shop reseeds seq=0 and the next ping rebuilds every row, without re-firing
 * payment_complete() on orders that are already paid. This is the only path that deletes
 * Scanpay data; saving the settings form never does.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

if (
	! current_user_can( 'manage_woocommerce' ) ||
	! check_ajax_referer( 'wc-scanpay-reset', 'nonce', false )
) {
	wp_send_json_error( 'forbidden', 403 );
}

global $wpdb;

// The admin bootstrap loads the gateways, not this one: the flock's only other caller is
// the ping handler.
require_once WC_SCANPAY_DIR . '/library/class-scanpay-flock.php';

$wcsp_tables = [ 'scanpay_seq', 'scanpay_meta', 'scanpay_subs' ];
$wcsp_lock   = null;

/*
 * Single exit ramp for every failure. wp_send_json_*() ends in die(), which runs no finally
 * block, so the lock is released here rather than left to a destructor firing after the
 * response. release() is idempotent, so the success path calls it too.
 */
$wcsp_fail = static function ( string $code, int $status, string $log ) use ( &$wcsp_lock ): void {
	if ( null !== $wcsp_lock ) {
		$wcsp_lock->release();
	}
	scanpay_log( 'error', "Reset: $log" );
	wp_send_json_error( $code, $status );
};

/*
 * Derived before the loop below unsets 'apikey': afterwards nothing is left to derive the
 * shop id from, and it names the lock file. The old secret is captured for the same reason
 * -- the loop destroys what the postcondition below compares against.
 */
$wcsp_settings = get_option( WC_SCANPAY_URI_SETTINGS );
$wcsp_shopid   = is_array( $wcsp_settings ) ? (int) strstr( (string) ( $wcsp_settings['apikey'] ?? '' ), ':', true ) : 0;
$wcsp_secret   = (string) ( $wcsp_settings['secret'] ?? '' );

/*
 * Hold the old shop's sync lock across the whole reset. A worker mid-drain carries the old
 * key and shop id in memory and would otherwise insert old-shop rows into the recreated
 * tables. Exclusive deletion is the point, so contention is an error the merchant retries.
 *
 * The lock covers the drain loop, not every writer: the ping handler's heartbeat and
 * busy-handoff writes run without it and, racing a reset, either hit a dropped table or the
 * recreated empty one. Both are acceptable -- this data was deliberately deleted.
 * Scanpay_Flock only coordinates workers sharing the lock file's filesystem, so a
 * multi-node deployment must point WP_TEMP_DIR at shared storage.
 *
 * With no valid key there is no shop to coordinate with, so no lock is taken.
 */
if ( $wcsp_shopid > 0 ) {
	$wcsp_lock     = new Scanpay_Flock( $wcsp_shopid );
	$wcsp_acquired = false;
	try {
		$wcsp_acquired = $wcsp_lock->acquire();
	} catch ( RuntimeException $e ) {
		// The lock file could not even be opened, so nobody is draining: distinct from
		// contention, and not something a retry fixes.
		$wcsp_fail( 'lock_failed', 500, $e->getMessage() );
	}
	if ( ! $wcsp_acquired ) {
		$wcsp_fail( 'sync_busy', 409, "shop #$wcsp_shopid is syncing; nothing was deleted" );
	}
}

/*
 * Clear the key first: install.php below reads it to decide whether to seed a seq row, and
 * must not reseed the shop being removed. Disabling the gateways is not cosmetic -- without
 * a key they cannot function. An option that does not exist is a gateway that was never
 * configured: nothing to clear, nothing to assert afterwards.
 *
 * The admin-AJAX secret goes with the key: the outgoing account must not keep a working
 * credential. install.php mints the replacement and stays the only place that does, its
 * branch being empty()-gated, so a second mint here would survive rather than be
 * overwritten. A failure in between leaves no secret at all until the merchant retries, and
 * the endpoints' own '' === $secret guard is what makes that window fail closed. The two
 * secondary options never carry a secret, so unsetting it there is a no-op.
 */
foreach (
	[
		WC_SCANPAY_URI_SETTINGS,
		'woocommerce_scanpay_mobilepay_settings',
		'woocommerce_scanpay_applepay_settings',
	] as $wcsp_opt
) {
	$wcsp_set = get_option( $wcsp_opt );
	if ( ! is_array( $wcsp_set ) ) {
		continue;
	}
	unset( $wcsp_set['apikey'], $wcsp_set['secret'] );
	$wcsp_set['enabled'] = 'no';
	update_option( $wcsp_opt, $wcsp_set );

	// Reread rather than trust the return: update_option() also answers false when the
	// stored value already matches. Checked before anything is dropped, so a store that
	// cannot persist the cleared key still has its data.
	$wcsp_set = get_option( $wcsp_opt );
	if ( ! is_array( $wcsp_set ) || ! empty( $wcsp_set['apikey'] ) || 'no' !== ( $wcsp_set['enabled'] ?? '' ) ) {
		$wcsp_fail( 'settings_failed', 500, "could not clear the API key in '$wcsp_opt'" );
	}
}

/*
 * One statement per table, each result checked: a combined DROP can half-succeed with
 * nothing to tell which half. A DDL query returns $wpdb->result, never a row count, so
 * false is the failure. The converse does not hold -- DROP TABLE IF EXISTS answers true for
 * a table that was never there -- which is why the postconditions below exist.
 */
foreach ( $wcsp_tables as $wcsp_tbl ) {
	if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}$wcsp_tbl" ) ) {
		$wcsp_fail( 'drop_failed', 500, "could not drop {$wpdb->prefix}$wcsp_tbl: {$wpdb->last_error}" );
	}
}

try {
	// Recreate the tables empty: the order meta box and the polling endpoints still query
	// scanpay_meta for old Scanpay orders even with no key configured.
	require WC_SCANPAY_DIR . '/install.php';
} catch ( Throwable $e ) {
	$wcsp_fail( 'install_failed', 500, 'could not recreate the tables: ' . $e->getMessage() );
}

/*
 * install.php returning is not evidence of a reset. It creates a table only when SHOW
 * TABLES LIKE finds none, so a failed DROP leaves the old one standing and creation is
 * skipped -- and that survivor can be 2.x-shaped, quietly restoring the NOT NULL
 * scanpay_meta.method that fails every insert under a strict SQL mode. Hence shape and
 * emptiness, not existence.
 *
 * Sorted, because SHOW COLUMNS answers in table order, which a migrated table need not
 * match. A column added to install.php must be added here, or every reset verify_fails.
 */
$wcsp_schema = [
	'scanpay_seq'  => [ 'mtime', 'ping', 'seq', 'shopid' ],
	'scanpay_meta' => [ 'authorized', 'captured', 'currency', 'id', 'nacts', 'orderid', 'refunded', 'rev', 'shopid', 'subid', 'voided' ],
	'scanpay_subs' => [ 'method', 'method_exp', 'rev', 'subid' ],
];
foreach ( $wcsp_schema as $wcsp_tbl => $wcsp_want ) {
	$wcsp_full = $wpdb->prefix . $wcsp_tbl;
	// %i binds the identifier; it is WP 6.2 and so under the floor, needing no guard. A
	// missing table is MySQL error 1146 and yields [], which the last_error read below tells
	// apart from a failed one.
	$wcsp_have = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $wcsp_full ) );
	sort( $wcsp_have );
	if ( $wcsp_have !== $wcsp_want ) {
		$wcsp_fail( 'verify_failed', 500, "$wcsp_full is missing or does not carry the current schema (" . implode( ', ', $wcsp_have ) . ") {$wpdb->last_error}" );
	}
	// Any surviving row is an old-shop row, and in scanpay_seq it would also be a
	// cursor seeded for a key that is gone.
	if ( null !== $wpdb->get_var( "SELECT 1 FROM $wcsp_full LIMIT 1" ) || '' !== $wpdb->last_error ) {
		$wcsp_fail( 'verify_failed', 500, "$wcsp_full still holds rows {$wpdb->last_error}" );
	}
}

// install.php rewrites the primary settings option to mint the secret, and touches no
// other, so it is the only one worth rereading. Confirm that write neither resurrected the
// key nor re-enabled the gateway.
$wcsp_settings = get_option( WC_SCANPAY_URI_SETTINGS );
if ( ! empty( $wcsp_settings['apikey'] ) || 'yes' === ( $wcsp_settings['enabled'] ?? 'no' ) ) {
	$wcsp_fail( 'verify_failed', 500, 'the API key is still configured after the reset' );
}
// An unchanged secret means the loop's unset did not persist, an absent one that
// install.php's mint did not. Either way the outgoing account may still hold a working
// credential.
$wcsp_new_secret = (string) ( $wcsp_settings['secret'] ?? '' );
if ( '' === $wcsp_new_secret || $wcsp_new_secret === $wcsp_secret ) {
	$wcsp_fail( 'verify_failed', 500, 'the admin-AJAX secret was not rotated' );
}

$wcsp_lock?->release();
scanpay_log( 'notice', 'Scanpay data deleted and API key cleared by user #' . get_current_user_id() );
wp_send_json_success();
