<?php

/**
 * Runs whichever migrations a shop is behind on, oldest first, and stamps the new version
 * last. Required from the loader gate whenever the stored version differs from
 * WC_SCANPAY_VERSION.
 *
 * One file per migration under upgrade/, listed below. This file is the machinery -- the
 * fresh-install exit, the order, the logging and the stamp -- and carries no history of its
 * own; what a given version changed belongs in that version's file. install.php runs first
 * and unconditionally, so every migration can assume the current schema, and each is
 * idempotent with the stamp last, so an interrupted run simply re-runs from the start on the
 * next request.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// Read raw, and derive the display string from it: get_option()'s '0.0.0' default cannot tell
// an absent option from a stored one, which is half of $fresh_install below.
$stored_version = get_option( 'wc_scanpay_version' );
$version        = (string) ( $stored_version ?: '0.0.0' );

// Guarded because a host can disable set_time_limit(), and PHP 8 removes a disabled function
// from the function table: the call is an Error, not the warning it was. Unguarded it lands
// above every branch and above the version stamp, and the loader catches Throwable and keeps
// its transient -- so such a shop would fatal here every five minutes forever, never migrate
// and keep serving a 2.x schema. The host's own time budget is the fallback.
if ( function_exists( 'set_time_limit' ) ) {
	set_time_limit( 60 );
}

/*
 * A blog with no history at all, which is not an upgrade but the ordinary route for every new
 * install: nothing runs at activation, so a new site first meets the plugin at the loader gate
 * with both options absent, and on multisite every blog arrives the same way. Without this the
 * 2.0.0 migration below would run over a site that never ran 1.x.
 *
 * Absent *settings* is the discriminator, not an absent version: 1.x never wrote
 * 'wc_scanpay_version' but did write the settings option, so a version test alone would take
 * every 1.x site for a new one and leave capture_on_complete unconverted forever.
 *
 * Derived above the require below, which mints the admin-AJAX secret and so creates the
 * settings option: after that write the two cases are indistinguishable.
 */
$fresh_install = false === $stored_version && false === get_option( WC_SCANPAY_URI_SETTINGS );

// Creates whichever of the three tables this blog never had, seeds the cursor and mints the
// admin-AJAX secret. Idempotent -- on a shop that has them all it costs three SHOW TABLES
// LIKE -- so it is cheaper to require once up here than to have each migration decide.
require WC_SCANPAY_DIR . '/install.php';

// Neither log line fires for a fresh install. An upgrade "from 0.0.0" it never ran would
// otherwise be the only record of that path anyone sees.
if ( ! $fresh_install ) {
	scanpay_log( 'info', "Upgrading Scanpay plugin from $version to " . WC_SCANPAY_VERSION );

	/*
	 * Every migration, oldest first, keyed by the version that introduced it. This list is the
	 * gate: the version test lives here, so each file is one migration start to finish, and
	 * dropping a migration when a floor rises is a line plus a git rm. Not a scan of the
	 * directory -- the order would then come from the filesystem, and an editor's backup copy
	 * would be a migration.
	 *
	 * A required file shares this scope, hence the $wcsp_ prefix on what the loop owns: a
	 * migration that assigned $version or $wcsp_mfile would silently skip the rest of the list.
	 * Nothing is handed over in scope either -- a step the next one depends on writes to the
	 * database, as 2.0.0 does with capture_on_complete.
	 */
	$wcsp_migrations = [
		'2.0.0' => 'v2-0-0.php',
		'2.5.0' => 'v2-5-0.php',
		'3.0.0' => 'v3-0-0.php',
	];
	foreach ( $wcsp_migrations as $wcsp_mver => $wcsp_mfile ) {
		if ( version_compare( $version, $wcsp_mver, '<' ) ) {
			// One line per step. The retry after an interrupted upgrade logs the same "Upgrading
			// from" line as the first attempt, so without this nothing says how far it got.
			scanpay_log( 'info', "Running the $wcsp_mver migration" );
			require WC_SCANPAY_DIR . '/upgrade/' . $wcsp_mfile;
		}
	}
}

// Stamped last, and only here: an interrupted upgrade must re-run from the start on the next
// request, and install.php also runs on shops that are not being upgraded at all.
// update_option() creates a missing option, which is what a fresh install needs; autoloaded,
// because the loader gate reads it on every request.
update_option( 'wc_scanpay_version', WC_SCANPAY_VERSION, true );

// Reread rather than trust the return: update_option() also answers false when the stored
// value already matches, which a concurrent request can arrange. Throwing hands the failure
// to the loader, which keeps its transient and retries the whole migration -- better than
// reporting a version this site does not have.
if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION ) {
	throw new Exception( 'Could not store the new plugin version' );
}
if ( ! $fresh_install ) {
	scanpay_log( 'info', 'Scanpay plugin upgrade complete' );
}
