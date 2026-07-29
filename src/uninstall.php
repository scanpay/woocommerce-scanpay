<?php

/**
 * Everything the plugin ever wrote, removed: the three settings options, the version and
 * throttle, and the custom tables -- on every blog of a network, not just the one
 * WordPress includes this file in. Credentials go first, tables second.
 *
 * WordPress loads this file with none of the plugin's constants, classes or helpers
 * defined, which is why every name here is spelled out.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

// Nothing upstream grants one: neither uninstall_plugin() nor delete_plugins() does any
// time management, so without this the run gets whatever max_execution_time the host sets,
// commonly 30.
set_time_limit( 60 );

/**
 * Delete the Scanpay settings of whichever blog is active right now.
 *
 * Kept apart from the table drops, and always run first, because these options carry live
 * API keys while the tables carry order history. The file can be killed part-way, so the
 * split decides which of the two an interrupted uninstall leaves behind.
 */
function wc_scanpay_uninstall_blog_options(): void {
	// One option per gateway, named as WC_Settings_API::get_option_key() spells it. Each
	// holds an API key.
	delete_option( 'woocommerce_scanpay_settings' );
	delete_option( 'woocommerce_scanpay_mobilepay_settings' );
	delete_option( 'woocommerce_scanpay_applepay_settings' );
	delete_option( 'wc_scanpay_version' );

	// The upgrade throttle: self-expiring, so this only matters mid-wedged-upgrade.
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

	// Legacy 2.x tables, for sites that uninstall after upgrading from 2.x.
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}woocommerce_scanpay_queuedcharges" );
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}woocommerce_scanpay_seq" );

	// Do not remove this drop again on the premise that nothing created the table:
	// v2.0.0..v2.1.4 did, and the creation was later dropped with no migration. Only an
	// API-key change rebuilt the schema after that, so a merchant who upgraded without
	// changing their key still has it, holding order ids and amounts.
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}scanpay_queue" );
}

/**
 * Apply $callback to every blog on the network, between a switch_to_blog() and its restore.
 *
 * Paginated by id, because WP_Site_Query's 'number' defaults to 100 and an unbounded
 * get_sites() would silently leave site 101 onwards untouched. The offset is safe: the
 * callbacks delete options and tables, never sites.
 */
function wc_scanpay_uninstall_network( callable $callback ): void {
	// Granted again here so each pass starts its marker against a full 60, and so the
	// threshold below races that 60 rather than a host default of 30, where the two would be
	// equal and the renewal unreachable.
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
				// Ids are all this loop switches on. Both flags default to true, which is
				// two extra queries per page and every site object held in cache for the
				// rest of the run. Costs the second pass nothing: WP_Site_Query drops both,
				// and 'fields', from its cache key, so the id list is a hit either way.
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
 * WordPress includes this file once, for one blog, while the keys and tables sit on every
 * blog that ever activated the plugin -- and a deactivated blog keeps all of it.
 *
 * Two passes rather than one, because a kill leaves everything the loop has not reached
 * intact and says nothing about where it stopped. The credentials pass is the cheap one, so
 * completing it first clears every API key on the network before the first DROP TABLE.
 */
wc_scanpay_uninstall_network( 'wc_scanpay_uninstall_blog_options' );
wc_scanpay_uninstall_network( 'wc_scanpay_uninstall_blog_tables' );
