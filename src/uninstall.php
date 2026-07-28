<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

// Granted before anything runs, as upgrade.php:9 and callback/wc-scanpay-ping.php:20 do.
// Nothing upstream supplies one: neither uninstall_plugin() nor delete_plugins() does any
// time management of its own, so without this the run gets whatever max_execution_time
// the host happens to set, commonly 30.
set_time_limit( 60 );

/**
 * Delete the Scanpay settings of whichever blog is active right now.
 *
 * Kept apart from the table drops, and always run first, because the two hold different
 * things: each of these options carries a live API key, the tables carry order and
 * subscriber ids and amounts. This file can be killed part-way (see the walker below), so
 * the split is what decides whether an interrupted uninstall leaves order history or a
 * credential.
 *
 * WordPress loads uninstall.php on its own, with none of the plugin's constants, classes
 * or helpers defined, so the option names are spelled out here.
 */
function wc_scanpay_uninstall_blog_options(): void {
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

/**
 * Drop the Scanpay tables of whichever blog is active right now.
 *
 * Deliberately left behind: the per-order _scanpay_* meta. That is order history rather
 * than credentials, and reaching it would mean an unbounded order sweep on every blog.
 */
function wc_scanpay_uninstall_blog_tables(): void {
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
}

/**
 * Apply $callback to every blog on the network, between a switch_to_blog() and its restore.
 *
 * Paginated explicitly, by id: get_sites() answers at most 100 sites per call
 * (WP_Site_Query's 'number' default), so a single unbounded call would leave site 101
 * onwards untouched -- the silent truncation this loop exists to close. The offset is safe
 * because $callback deletes options and tables, never sites, so the result set cannot shrink
 * underneath it.
 */
function wc_scanpay_uninstall_network( callable $callback ): void {
	// Granted here as well as at the top of the file, so each pass starts its marker
	// against a full 60 rather than one the previous pass already half spent -- and so the
	// threshold below races that 60 rather than a host default of 30, where the two would
	// be equal and the renewal unreachable. set_time_limit() resets the counter rather
	// than adding to it, so renewing before it is due costs nothing.
	set_time_limit( 60 );
	$renewed = microtime( true );

	$page   = 100;
	$offset = 0;
	do {
		$blogs = get_sites(
			[
				'fields'                 => 'ids',
				'number'                 => $page,
				'offset'                 => $offset,
				'orderby'                => 'id',
				'order'                  => 'ASC',
				// Ids are all this loop switches on; it never reads a WP_Site or its meta.
				// Both default to true, which is two extra queries per page and every site
				// object held in the object cache for the rest of a run already fighting for
				// time. Costs the second pass nothing: WP_Site_Query drops both flags, and
				// 'fields', from its cache key, so the id list is a hit either way.
				'update_site_cache'      => false,
				'update_site_meta_cache' => false,
			]
		);
		$found = count( $blogs );
		foreach ( $blogs as $blog ) {
			// Per blog, not per page: a full page is 100 switch_to_blog() calls and up to
			// 600 DDL statements, which can outlast the 60 last granted on its own.
			if ( microtime( true ) - $renewed >= 30 ) {
				set_time_limit( 60 );
				$renewed = microtime( true );
			}
			switch_to_blog( (int) $blog );
			try {
				$callback();
			} finally {
				// In a finally, so one blog's database error cannot strand the whole
				// uninstall in the wrong blog context.
				restore_current_blog();
			}
		}
		$offset += $page;
		// A short page is the last one; a full page means there may be more.
	} while ( $found === $page );
}

if ( ! is_multisite() ) {
	wc_scanpay_uninstall_blog_options();
	wc_scanpay_uninstall_blog_tables();
	return;
}

/*
 * Network uninstall: WordPress includes this file once, in the context of one blog, while
 * the API keys, tables and payment metadata sit on every blog that ever activated the
 * plugin -- and activation state is not something to assume, since a deactivated blog
 * keeps all of it.
 *
 * Two passes over the network rather than one, because a kill leaves everything the loop
 * has not reached intact and says nothing about where it stopped. The credentials pass is
 * the cheapest one there is -- five option writes per blog, no DDL -- so completing it
 * first clears every API key on the network before the first DROP TABLE runs.
 */
wc_scanpay_uninstall_network( 'wc_scanpay_uninstall_blog_options' );
wc_scanpay_uninstall_network( 'wc_scanpay_uninstall_blog_tables' );
