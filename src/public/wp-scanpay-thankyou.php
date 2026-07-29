<?php

/**
 * The payment-return ("thank you") page, dispatched from woocommerce-scanpay.php before the
 * rest of the bootstrap. Both handlers hold the request until sync has written what the page
 * renders -- a transaction id for a paid order, an activated subscription for a free trial --
 * so it does not render half-finished. The wait overlaps the redirect from the payment window,
 * so the customer rarely sees it.
 *
 * Contract: GET scanpay_thankyou, scanpay_type and WooCommerce's own key. No nonce -- the
 * customer arrives from an external site -- so the order key is the authentication.
 *
 * They hook woocommerce_init, ahead of anything that loads the order: a later hook such as
 * woocommerce_thankyou_order_id races the sync and finds the order already built. Which is
 * also why everything here reads $wpdb directly and touches no WC order API:
 *
 *  1. woocommerce_init fires inside init:0, and order types are not registered until init:5,
 *     so wc_get_order() bails with wc_doing_it_wrong() and returns false. A gate built on it
 *     would return on every request and the wait would never run.
 *  2. Loading the order would seed the OrderCache (HPOS) or the post caches (legacy) with the
 *     pre-sync order, which WC_Shortcode_Checkout::order_received() then re-reads -- rendering
 *     the stale copy despite the wait.
 *
 * $wpdb reads are uncached, so WooCommerce still builds the order exactly once, after the wait.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();
// phpcs:disable WordPress.Security.NonceVerification -- No nonce by design; the order key is the authentication, as the file header explains.

$order_type = sanitize_key( wp_unslash( $_GET['scanpay_type'] ?? '' ) );

/**
 * Whether orders live in the HPOS tables rather than the legacy posts tables. Safe at
 * init:0 -- it only reads an option through the container, built at 'plugins_loaded'.
 */
function wc_scanpay_thankyou_hpos(): bool {
	return defined( 'WC_VERSION' )
		&& class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Reads order_key, payment_method and transaction_id straight from the database.
 *
 * The two branches do not answer alike for a missing order. HPOS is a plain row select and
 * returns null; the legacy query is a bare aggregate over postmeta, which MySQL answers
 * with one all-NULL row -- a non-empty array `?: null` does not convert. So on the legacy
 * branch the empty order_key is what says "no such order", and the caller's ownership gate
 * is where that is caught.
 *
 * @return array|null Null only on the HPOS branch, when there is no such order.
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
 * Reads a subscription's status and parent order id straight from the database. Not
 * wcs_get_subscription(): that is undefined with Subscriptions deactivated, which would
 * fatal a bookmarked thank-you URL, and it cannot resolve an order at init:0 anyway.
 *
 * @return array|null Null when there is no such subscription.
 */
function wc_scanpay_thankyou_read_sub( int $wcsid, bool $hpos ): ?array {
	global $wpdb;
	$sql = $hpos
		? "SELECT status, parent_order_id FROM {$wpdb->prefix}wc_orders WHERE id = %d"
		: "SELECT post_status AS status, post_parent AS parent_order_id FROM {$wpdb->posts} WHERE ID = %d";
	return $wpdb->get_row( $wpdb->prepare( $sql, $wcsid ), ARRAY_A ) ?: null;
}

/**
 * Waits for sync to write the payment data on a WC or WCS order.
 * Action: woocommerce_init
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
			return; // No such order, on the HPOS branch only; the legacy branch's all-NULL
			// row is rejected by the empty-order_key clause below.
		}
		// Ownership gate: only busy-poll for a genuine thank-you request, or a PHP worker is
		// tied up on an order that may not be ours. The stored key must be non-empty in its
		// own right, because order_key is nullable in the HPOS operational-data table and
		// both branches can hand back NULL -- without that clause hash_equals( '', '' ) lets
		// a bare "key=" through, which the router's isset() does not stop.
		if ( 0 === $i && (
			'' === (string) $row['order_key']
			|| ! hash_equals( (string) $row['order_key'], (string) wp_unslash( $_GET['key'] ?? '' ) )
			|| ! str_starts_with( (string) $row['payment_method'], 'scanpay' )
		) ) {
			return;
		}
		// Wait on the order's own transaction_id, not on a scanpay_meta row: sync() inserts
		// that row hundreds of ms before it sets transaction_id and payment_method_title,
		// which would routinely let the page render before the title is written.
		if ( '' !== (string) $row['transaction_id'] ) {
			return;
		}
		if ( ++$i >= 17 ) {
			return; // Give up after ~3.5s of waiting; the page renders without payment data.
		}
		usleep( 1 === $i ? 400_000 : (int) ( 20_000 + 10_000 * pow( 1.3, $i ) ) );
	}
}

if ( 'wcs' === $order_type || 'wc' === $order_type ) {
	add_action( 'woocommerce_init', 'wc_scanpay_init_thankyou', 99, 0 );
	return;
}

/**
 * Waits for a zero-total WCS order (free trial) to have its subscription activated.
 * Action: woocommerce_init
 */
function wcs_scanpay_init_thankyou_free(): void {
	$oid = absint( wp_unslash( $_GET['scanpay_thankyou'] ?? '' ) );
	if ( ! $oid ) {
		return;
	}
	$hpos = wc_scanpay_thankyou_hpos();
	$row  = wc_scanpay_thankyou_read( $oid, $hpos );
	// Ownership gate on the parent order, with the same non-empty precondition
	// wc_scanpay_init_thankyou() explains. No transaction-id bail: a free-trial parent has a
	// zero total and may never carry one, so this branch polls activation instead.
	if (
		! $row
		|| '' === (string) $row['order_key']
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
		// 'wc-active' is a late-enough signal for the title this page renders: subscriber()
		// sets the parent's payment_method_title before flipping it to completed, and that
		// transition is what drives WCS to activate.
		if ( 'wc-active' === (string) $sub['status'] ) {
			return;
		}
		if ( ++$i >= 8 ) {
			return; // Give up after ~0.8s of waiting; the page renders without payment data.
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
