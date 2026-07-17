<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/*
 * Payment-return page: the customer is redirected here from the external payment
 * window, so requests carry no nonce — they are authenticated by the WooCommerce
 * order key via hash_equals below, not by a nonce.
 */
// phpcs:disable WordPress.Security.NonceVerification

/**
 * Ensures payment details are available before rendering the WooCommerce ThankYou page.
 * This introduces a short wait to make sure payment data is fully saved, reducing
 * race conditions and unnecessary resource usage.
 *
 * Hooked on 'woocommerce_init' so the wait runs before anything loads the order:
 * later hooks like 'woocommerce_thankyou_order_id' are prone to race conditions,
 * and by then the order object has already been built and cached.
 *
 * Everything below therefore reads the database directly and never touches a WC
 * order API. Two independent reasons, both load-bearing:
 *
 *  1. 'woocommerce_init' fires inside 'init' priority 0, but order types are not
 *     registered until 'init' priority 5. wc_get_order() bails out with
 *     wc_doing_it_wrong() and returns false before then, so a gate built on it
 *     would return on every request and the wait would never run at all.
 *  2. Loading the order here would poison the page: under HPOS it seeds the
 *     OrderCache, under legacy CPT the posts/post_meta caches, in both cases with
 *     the pre-sync order. WC_Shortcode_Checkout::order_received() re-reads the
 *     order afterwards and would render that stale copy despite the wait.
 *
 * $wpdb reads are uncached, so nothing is cached prematurely and WooCommerce still
 * builds the order object exactly once, after the wait.
 *
 * Note: The delay happens while the user is still on the Scanpay payment page,
 * so it has minimal impact on the user experience.
 */

$order_type = sanitize_key( wp_unslash( $_GET['scanpay_type'] ?? '' ) );

/**
 * Whether orders live in the HPOS tables rather than the legacy posts tables.
 *
 * Safe at init:0 — it only reads an option through the container, which is built
 * at 'plugins_loaded'.
 *
 * @return bool
 */
function wc_scanpay_thankyou_hpos(): bool {
	return defined( 'WC_VERSION' )
		&& class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Reads order_key, payment_method and transaction_id straight from the database.
 *
 * @param int  $oid  Order id.
 * @param bool $hpos Whether HPOS storage is in use.
 * @return array|null Row with order_key/payment_method/transaction_id, or null if absent.
 */
function wc_scanpay_thankyou_read( int $oid, bool $hpos ): ?array {
	global $wpdb;
	$sql = $hpos
		? "SELECT o.payment_method, o.transaction_id, d.order_key
			FROM {$wpdb->prefix}wc_orders o
			LEFT JOIN {$wpdb->prefix}wc_order_operational_data d ON d.order_id = o.id
			WHERE o.id = %d"
		: "SELECT
			MAX( CASE WHEN meta_key = '_payment_method' THEN meta_value END ) AS payment_method,
			MAX( CASE WHEN meta_key = '_transaction_id' THEN meta_value END ) AS transaction_id,
			MAX( CASE WHEN meta_key = '_order_key' THEN meta_value END ) AS order_key
			FROM {$wpdb->postmeta} WHERE post_id = %d
			AND meta_key IN ( '_payment_method', '_transaction_id', '_order_key' )";
	return $wpdb->get_row( $wpdb->prepare( $sql, $oid ), ARRAY_A ) ?: null;
}

/**
 * Reads a subscription's status and parent order id straight from the database.
 *
 * Deliberately not wcs_get_subscription(): that function is undefined when
 * WooCommerce Subscriptions is deactivated, which would fatal a bookmarked
 * thank-you URL, and it cannot resolve an order at init:0 anyway.
 *
 * @param int  $wcsid Subscription id.
 * @param bool $hpos  Whether HPOS storage is in use.
 * @return array|null Row with status/parent_order_id, or null if absent.
 */
function wc_scanpay_thankyou_read_sub( int $wcsid, bool $hpos ): ?array {
	global $wpdb;
	$sql = $hpos
		? "SELECT status, parent_order_id FROM {$wpdb->prefix}wc_orders WHERE id = %d"
		: "SELECT post_status AS status, post_parent AS parent_order_id FROM {$wpdb->posts} WHERE ID = %d";
	return $wpdb->get_row( $wpdb->prepare( $sql, $wcsid ), ARRAY_A ) ?: null;
}

/**
 * Waits for payment data to become available in WC and WCS orders.
 * Hook: woocommerce_init
 */
function wc_scanpay_init_thankyou(): void {
	$oid = absint( wp_unslash( $_GET['scanpay_thankyou'] ?? '' ) );
	if ( ! $oid ) {
		return;
	}
	$hpos = wc_scanpay_thankyou_hpos();
	$i    = 0;
	while ( true ) {
		$row = wc_scanpay_thankyou_read( $oid, $hpos );
		if ( ! $row ) {
			return; // No such order.
		}
		// Ownership gate: only busy-poll for a genuine thank-you request. The success URL
		// carries WooCommerce's order key (get_checkout_order_received_url()); require it to
		// match before spending any workers on an order that may not exist.
		if ( 0 === $i && (
			! hash_equals( (string) $row['order_key'], (string) wp_unslash( $_GET['key'] ?? '' ) )
			|| ! str_starts_with( (string) $row['payment_method'], 'scanpay' )
		) ) {
			return;
		}
		// Wait on the order's own transaction_id, not on a scanpay_meta row: sync()
		// inserts that row before it loads the order and sets transaction_id,
		// payment_method_title and payment_complete() — a window of hundreds of ms that
		// would routinely let the page render before the title is written.
		if ( '' !== (string) $row['transaction_id'] ) {
			return; // Synced: sync() writes transaction_id and payment_method_title together.
		}
		if ( ++$i >= 17 ) {
			return; // Give up; the page renders without payment data.
		}
		usleep( 1 === $i ? 400_000 : (int) ( 20_000 + 10_000 * pow( 1.3, $i ) ) );
	}
}

if ( 'wcs' === $order_type || 'wc' === $order_type ) {
	add_action( 'woocommerce_init', 'wc_scanpay_init_thankyou', 99, 0 );
	return;
}

/**
 * Waits for payment data to become available in free WCS orders (e.g. free trial).
 * Hook: woocommerce_init
 */
function wcs_scanpay_init_thankyou_free(): void {
	$oid = absint( wp_unslash( $_GET['scanpay_thankyou'] ?? '' ) );
	if ( ! $oid ) {
		return;
	}
	$hpos = wc_scanpay_thankyou_hpos();
	$row  = wc_scanpay_thankyou_read( $oid, $hpos );
	// Ownership gate on the parent order, whose key is in the success URL. (No
	// transaction-id bail here: a free-trial parent has a zero total and may never
	// carry one — this branch polls subscription activation instead.)
	if (
		! $row
		|| ! hash_equals( (string) $row['order_key'], (string) wp_unslash( $_GET['key'] ?? '' ) )
		|| ! str_starts_with( (string) $row['payment_method'], 'scanpay' )
	) {
		return;
	}
	$ref = sanitize_text_field( wp_unslash( $_GET['scanpay_ref'] ?? '' ) );
	if ( ! str_starts_with( $ref, 'wcs[]' ) ) {
		return;
	}
	$subs  = explode( ',', substr( $ref, 5 ) );
	$wcsid = (int) end( $subs );
	if ( ! $wcsid ) {
		return;
	}
	// The subscription must belong to the (already key-verified) parent order, so a
	// key-holder cannot point scanpay_ref at an arbitrary subscription id.
	$sub = wc_scanpay_thankyou_read_sub( $wcsid, $hpos );
	if ( ! $sub || (int) $sub['parent_order_id'] !== $oid ) {
		return;
	}
	$i = 0;
	while ( true ) {
		// 'wc-active' is a conservative, late-enough signal for the title this page
		// renders: subscriber() sets the parent's payment_method_title before flipping
		// the parent to completed, and that transition is what drives WCS to activate.
		if ( 'wc-active' === (string) $sub['status'] ) {
			return;
		}
		if ( ++$i >= 8 ) {
			return; // Give up; the page renders without payment data.
		}
		usleep( 1 === $i ? 450_000 : (int) ( 20_000 + 10_000 * pow( 1.3, $i ) ) );
		$sub = wc_scanpay_thankyou_read_sub( $wcsid, $hpos );
		if ( ! $sub ) {
			return;
		}
	}
}

if ( 'wcs_free' === $order_type ) {
	add_action( 'woocommerce_init', 'wcs_scanpay_init_thankyou_free', 99, 0 );
}
