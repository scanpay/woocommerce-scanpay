<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Handle the AJAX "delete data and change API key" action from the settings page.
 * Action: wp_ajax_wc_scanpay_reset (no arguments; the nonce arrives in $_POST)
 *
 * Drops the three custom tables, clears the API key and disables every gateway.
 * install.php then recreates the tables empty; with no key stored it seeds no
 * seq row, leaving the store in a pre-setup state. The whole sequence runs under
 * the old shop's sync lock, and finishes by checking its own postconditions --
 * neither install.php returning nor a truthy DROP proves anything was deleted.
 *
 * Nothing deleted here is lost for good: the tables hold only what the sync API
 * can replay. Entering a key for the same shop reseeds seq=0 and the next ping
 * rebuilds every row -- WC_Scanpay_Sync::upsert_meta() runs outside the
 * already-paid guard, so a replay restores the meta rows without re-firing
 * payment_complete() on orders that are already paid.
 *
 * This is the only path that deletes Scanpay data. Saving the settings form
 * never does (see WC_Gateway_Scanpay_Base::validate_apikey_field(), which
 * refuses to replace a stored key).
 */

if (
	! current_user_can( 'manage_woocommerce' ) ||
	! check_ajax_referer( 'wc-scanpay-reset', 'nonce', false )
) {
	wp_send_json_error( 'forbidden', 403 );
}

global $wpdb;

// The admin bootstrap loads the gateways, not these two: the ping handler is the
// flock's only other caller, and the column read is admin-only by design.
require_once WC_SCANPAY_DIR . '/library/class-scanpay-flock.php';
require_once WC_SCANPAY_DIR . '/library/schema.php';

$wcsp_tables = [ 'scanpay_seq', 'scanpay_meta', 'scanpay_subs' ];
$wcsp_lock   = null;

/*
 * Single exit ramp for every failure. wp_send_json_*() ends in die(), which runs no
 * finally block, so the lock is released here rather than left to a destructor that
 * fires after the response. release() is idempotent, so the success path calls it too.
 * The concrete cause goes to the log; the response carries a short code, which the
 * settings JS shows the merchant verbatim.
 */
$wcsp_fail = static function ( string $code, int $status, string $log ) use ( &$wcsp_lock ): void {
	if ( null !== $wcsp_lock ) {
		$wcsp_lock->release();
	}
	scanpay_log( 'error', "Reset: $log" );
	wp_send_json_error( $code, $status );
};

/*
 * Derive the shop id before the loop below unsets 'apikey': afterwards nothing is
 * left to derive it from, and it names the lock file. The old secret is captured for
 * the same reason -- the loop destroys what the postcondition below compares against.
 */
$wcsp_settings = get_option( WC_SCANPAY_URI_SETTINGS );
$wcsp_shopid   = is_array( $wcsp_settings ) ? (int) strstr( (string) ( $wcsp_settings['apikey'] ?? '' ), ':', true ) : 0;
$wcsp_secret   = (string) ( $wcsp_settings['secret'] ?? '' );

/*
 * Hold the old shop's sync lock across the whole reset. A worker draining a change set
 * carries the old key and shop id in memory and would otherwise insert old-shop rows
 * into the recreated tables. Exclusive deletion is the point here, so contention is an
 * error the merchant retries, not something to work around.
 *
 * The lock covers the drain loop, not every writer: the heartbeat and busy-handoff
 * writes (callback/wc-scanpay-ping.php:197, :227-231) run without it and, racing a
 * reset, either hit a dropped table (500, self-healing on the next keepalive) or the
 * recreated empty one (a 200 acking a stranded ping). Both are acceptable -- this data
 * was deliberately deleted. Scanpay_Flock only coordinates workers that share the
 * lock file's filesystem, so a multi-node deployment must point WP_TEMP_DIR at shared
 * storage; between independent temp dirs it excludes nothing.
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
 * Clear the key first: install.php below reads it to decide whether to seed a
 * seq row, and must not reseed the shop being removed. Disabling the gateways is
 * not cosmetic -- without a key they cannot function, and needs_setup() reports
 * them as unconfigured once 'apikey' is gone.
 *
 * An option that does not exist is a gateway that was never configured: nothing to
 * clear, and nothing to assert about it afterwards.
 *
 * The admin-AJAX secret goes with the key: the reset exists to hand the store to a
 * different Scanpay account, whose predecessor must not keep a working credential.
 * install.php below mints the replacement and stays the only place that does -- its
 * branch is empty()-gated, so a second mint here would survive rather than be
 * overwritten. A failure in between (a drop_failed, say) therefore leaves no secret at
 * all until the merchant retries, and the endpoints' own '' === $secret guard is what
 * makes that window fail closed rather than open. The two secondary options never
 * carry a secret, so unsetting it there is a no-op.
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
	// stored value already matches. Checked here, before anything is dropped, so a store
	// that cannot persist the cleared key still has its data.
	$wcsp_set = get_option( $wcsp_opt );
	if ( ! is_array( $wcsp_set ) || ! empty( $wcsp_set['apikey'] ) || 'no' !== ( $wcsp_set['enabled'] ?? '' ) ) {
		$wcsp_fail( 'settings_failed', 500, "could not clear the API key in '$wcsp_opt'" );
	}
}

/*
 * One statement per table, and each result checked: MySQL applies DDL per table even
 * in 8.0, so a combined DROP can half-succeed with nothing to tell which half. A DDL
 * query returns $wpdb->result, never a row count, so false is the failure. The
 * converse does not hold -- DROP TABLE IF EXISTS answers true for a table that was
 * never there -- which is why the postconditions below exist.
 */
foreach ( $wcsp_tables as $wcsp_tbl ) {
	if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}$wcsp_tbl" ) ) {
		$wcsp_fail( 'drop_failed', 500, "could not drop {$wpdb->prefix}$wcsp_tbl: {$wpdb->last_error}" );
	}
}

try {
	// Recreate the tables empty. Leaving them dropped would break every other
	// reader: the order meta box and the polling endpoints still query
	// scanpay_meta for old Scanpay orders even with no key configured.
	require WC_SCANPAY_DIR . '/install.php';
} catch ( Throwable $e ) {
	$wcsp_fail( 'install_failed', 500, 'could not recreate the tables: ' . $e->getMessage() );
}

/*
 * install.php returning is not evidence of a reset. It creates a table only when
 * SHOW TABLES LIKE finds none, so a DROP that failed leaves the old table standing and
 * creation is skipped -- and since the 2.x schema migration that survivor can be
 * 2.x-shaped, quietly restoring the NOT NULL scanpay_meta.method that fails every
 * insert under a strict SQL mode. So check the shape and the emptiness, not existence.
 *
 * The expected sets are install.php's v3 schema, sorted: SHOW COLUMNS answers in table
 * order, which a migrated table need not match. Adding a column there means adding it
 * here, or every reset reports verify_failed.
 */
$wcsp_schema = [
	'scanpay_seq'  => [ 'mtime', 'ping', 'seq', 'shopid' ],
	'scanpay_meta' => [ 'authorized', 'captured', 'currency', 'id', 'nacts', 'orderid', 'refunded', 'rev', 'shopid', 'subid', 'voided' ],
	'scanpay_subs' => [ 'method', 'method_exp', 'rev', 'subid' ],
];
foreach ( $wcsp_schema as $wcsp_tbl => $wcsp_want ) {
	$wcsp_full = $wpdb->prefix . $wcsp_tbl;
	$wcsp_have = wc_scanpay_table_columns( $wcsp_full );
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

// install.php rewrites the primary settings option to mint the admin-AJAX secret --
// the only option it touches, hence the only one worth rereading here. Confirm that
// write neither resurrected the key nor re-enabled the gateway.
$wcsp_settings = get_option( WC_SCANPAY_URI_SETTINGS );
if ( ! empty( $wcsp_settings['apikey'] ) || 'yes' === ( $wcsp_settings['enabled'] ?? 'no' ) ) {
	$wcsp_fail( 'verify_failed', 500, 'the API key is still configured after the reset' );
}
// The rotation itself: an unchanged secret means the loop's unset did not persist, and
// an absent one means install.php's mint did not. Either way the outgoing account may
// still hold a working credential, which is the whole point of clearing it.
$wcsp_new_secret = (string) ( $wcsp_settings['secret'] ?? '' );
if ( '' === $wcsp_new_secret || $wcsp_new_secret === $wcsp_secret ) {
	$wcsp_fail( 'verify_failed', 500, 'the admin-AJAX secret was not rotated' );
}

$wcsp_lock?->release();
scanpay_log( 'notice', 'Scanpay data deleted and API key cleared by user #' . get_current_user_id() );
wp_send_json_success();
