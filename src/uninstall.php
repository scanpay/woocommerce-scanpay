<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

/**
 * Delete every Scanpay artefact belonging to whichever blog is active right now.
 *
 * All of it is blog-scoped -- $wpdb->prefix for the tables, the options table for the
 * settings and the version, the same table again for the transient -- so on a network
 * this runs once per blog, between a switch_to_blog() and its restore.
 *
 * WordPress loads uninstall.php on its own, with none of the plugin's constants,
 * classes or helpers defined, so the option names are spelled out here. Deliberately
 * left behind: the per-order _scanpay_* meta. That is order history rather than
 * credentials, and reaching it would mean an unbounded order sweep on every blog.
 */
function wc_scanpay_uninstall_blog(): void {
	global $wpdb;

	// Read after the caller's switch, never hoisted: $wpdb->prefix is what names the
	// active blog's tables, and a stale copy would delete one blog's data N times.
	$prefix = $wpdb->prefix;

	// The three tables created by install.php.
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}scanpay_seq" );
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}scanpay_meta" );
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}scanpay_subs" );

	// Legacy 2.x tables: created only by the pre-3.x plugin, never by 3.x. Kept as
	// harmless cleanup for sites that uninstall after upgrading from 2.x.
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}woocommerce_scanpay_queuedcharges" );
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}woocommerce_scanpay_seq" );

	// Early-2.x table, never part of the 3.x schema. Do not remove this drop again:
	// c25fa50 did, on the wrong premise that nothing ever created it -- v2.0.0..v2.1.4
	// did (git show v2.0.0:includes/install.php), and 11c81b4 dropped the creation with no
	// migration. Later 2.x only removed the table when an API-key change rebuilt the
	// schema, so merchants who upgraded without changing their key still have it, holding
	// order/subscription IDs and amounts.
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}scanpay_queue" );

	// One option per gateway: WC_Settings_API::get_option_key() is
	// 'woocommerce_' . $gateway_id . '_settings', for ids scanpay, scanpay_mobilepay
	// and scanpay_applepay. Each holds an API key.
	delete_option( 'woocommerce_scanpay_settings' );
	delete_option( 'woocommerce_scanpay_mobilepay_settings' );
	delete_option( 'woocommerce_scanpay_applepay_settings' );
	delete_option( 'wc_scanpay_version' );

	// The upgrade throttle. Self-expires in 5 minutes, so this only matters when
	// uninstalling in the middle of a wedged upgrade -- but leave nothing behind.
	delete_transient( 'wc_scanpay_updating' );
}

if ( ! is_multisite() ) {
	wc_scanpay_uninstall_blog();
	return;
}

/*
 * Network uninstall: WordPress includes this file once, in the context of one blog,
 * while the API keys, tables and payment metadata sit on every blog that ever
 * activated the plugin -- and activation state is not something to assume, since a
 * deactivated blog keeps all of it.
 *
 * Paginated explicitly, by id: get_sites() answers at most 100 sites per call, so a
 * single unbounded call would leave site 101 onwards holding live credentials -- the
 * same silent truncation this loop exists to close. Ids, not WP_Site objects: nothing
 * here needs the rest of the row.
 */
$wcsp_page   = 100;
$wcsp_offset = 0;
do {
	$wcsp_blogs = get_sites(
		[
			'fields'  => 'ids',
			'number'  => $wcsp_page,
			'offset'  => $wcsp_offset,
			'orderby' => 'id',
			'order'   => 'ASC',
		]
	);
	$wcsp_found = count( $wcsp_blogs );
	foreach ( $wcsp_blogs as $wcsp_blog ) {
		switch_to_blog( (int) $wcsp_blog );
		try {
			wc_scanpay_uninstall_blog();
		} finally {
			// In a finally, so one blog's database error cannot strand the whole
			// uninstall in the wrong blog context.
			restore_current_blog();
		}
	}
	$wcsp_offset += $wcsp_page;
	// A short page is the last one; a full page means there may be more.
} while ( $wcsp_found === $wcsp_page );
