<?php

/**
 * The admin order screens: the bulk actions and the row action that capture before
 * completing, and the Scanpay meta box. Every hook is registered for both the HPOS and the
 * legacy post-based order list. The file is the hook list.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/functions.php';

/** Handle our custom bulk actions in the WC order list. */
function wc_scanpay_handle_bulk_actions( string $redirect_to, string $action, array $ids ): string {
	$capture = true;
	if ( 'scanpay_mark_completed' === $action ) {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
			$capture = false;
		}
	} elseif ( 'scanpay_capture_complete' !== $action ) {
		return $redirect_to; // Not ours; let WooCommerce handle it.
	}
	require_once WC_SCANPAY_DIR . '/admin/hooks/wp-bulk-actions.php';
	return wc_scanpay_handle_bulk_capture( $redirect_to, $ids, $capture );
}
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_handle_bulk_actions', 0, 3 ); // HPOS
add_filter( 'handle_bulk_actions-edit-shop_order', 'wc_scanpay_handle_bulk_actions', 0, 3 ); // Legacy


/**
 * Add our bulk actions to the WC order list, hijacking "Mark as completed" so payments
 * are captured before completion rather than after.
 */
function wc_scanpay_add_bulk_actions( array $actions ): array {
	// Mirror WC's own trash-view restriction. WP_List_Table applies this filter on top of
	// get_bulk_actions(), so our entries would otherwise be re-added to the trash dropdown
	// and capture a trashed order. HPOS reads 'status', the legacy list table 'post_status'.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of which view is rendered; changes no state.
	$view = sanitize_text_field( wp_unslash( $_REQUEST['status'] ?? $_REQUEST['post_status'] ?? '' ) );
	if ( 'trash' === $view ) {
		return $actions;
	}
	// The other half of the same principle: when WooCommerce has withheld its own actions
	// entirely, ours must not be the only entry left. HPOS's get_bulk_actions() returns []
	// for a user without edit_others_posts, and WP_List_Table applies this filter before it
	// tests for emptiness -- so a role with edit_shop_orders but not edit_others_shop_orders
	// would get a dropdown holding exactly one action, ours, which the same capability check
	// in handle_bulk_actions() then rejects silently.
	if ( ! $actions ) {
		return $actions;
	}
	$arr = [];
	foreach ( $actions as $k => $v ) {
		$arr[ 'mark_completed' === $k ? 'scanpay_mark_completed' : $k ] = $v;
	}
	return [ 'scanpay_capture_complete' => __( 'Capture and complete', 'scanpay-for-woocommerce' ) ] + $arr;
}
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_add_bulk_actions', 10, 1 ); // HPOS
// Priority 20, not 10: WC registers its own filter from setup_screen() on current_screen,
// which fires after admin_init, where we register. At an equal priority ours would run
// first, on an array that does not yet hold 'mark_completed'.
add_filter( 'bulk_actions-edit-shop_order', 'wc_scanpay_add_bulk_actions', 20, 1 ); // Legacy


/**
 * Intercept the "mark as completed" row action. Priority 0: WooCommerce's own handler
 * must not run first, or the order completes (and emails) before the capture.
 */
function wc_scanpay_mark_order_status(): void {
	require WC_SCANPAY_DIR . '/admin/hooks/wp-ajax-wc-mark-order-status.php';
}
add_action( 'wp_ajax_woocommerce_mark_order_status', 'wc_scanpay_mark_order_status', 0, 0 );


/** Manual capture from the order meta box, guarded by a per-order nonce. */
function wc_scanpay_ajax_capture(): void {
	require WC_SCANPAY_DIR . '/admin/hooks/wp-ajax-wc-scanpay-capture.php';
}
add_action( 'wp_ajax_wc_scanpay_capture', 'wc_scanpay_ajax_capture', 0, 0 );


/**
 * Render the Scanpay order meta box content.
 *
 * @param WP_Post|WC_Order $post Current object (legacy: WP_Post, HPOS: WC_Order).
 */
function wc_scanpay_admin_render_meta_box( $post ): void {
	global $wpdb;
	$wco = wc_get_order( $post );
	if ( ! $wco ) {
		return;
	}
	if ( ! wc_scanpay_is_scanpay_order( $wco ) ) {
		return;
	}
	// The stylesheet is enqueued in wc_scanpay_add_meta_box(), so it lands in the head. The
	// script stays here, next to the inline payload it carries: both print in the footer.
	wp_enqueue_script( 'wc-scanpay-order', WC_SCANPAY_URL . '/admin/assets/js/order.js', [ 'wp-i18n' ], WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	wp_set_script_translations( 'wc-scanpay-order', 'scanpay-for-woocommerce', WC_SCANPAY_DIR . '/languages' );

	$oid      = $wco->get_id();
	$meta     = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid LIMIT 1", ARRAY_A );
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	$shopid   = (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
	$tid      = (int) $wco->get_transaction_id( 'edit' );
	// Refunds are dashboard-only -- can_refund_order() is false and sync reflects them
	// read-only -- so the meta box links there rather than issuing one itself.
	$dashboard = ( $shopid && $tid )
		? WC_SCANPAY_DASHBOARD . rawurlencode( (string) $shopid ) . '/' . rawurlencode( (string) $tid )
		: '';
	// Only what order.ts reads: $tid and $shopid stay local, they exist to build $dashboard.
	$props = [
		'oid'         => $oid,
		'wc_decimals' => wc_get_price_decimals(),
		'meta'        => $meta,
		'currency'    => $wco->get_currency( 'edit' ),
		'secret'      => (string) ( is_array( $settings ) ? ( $settings['secret'] ?? '' ) : '' ),
		'dashboard'   => $dashboard,
		'nonce'       => wp_create_nonce( 'scanpay-order-' . $oid ),
		/*
		 * The base for the ?x= polls. The router dispatches on the X-Scanpay header and ?x=
		 * alone, so any URL that boots WordPress works -- but a relative path only resolves
		 * through WordPress's catch-all front controller, which on Apache is the mod_rewrite
		 * block written only when a permalink structure is set. With plain permalinks that is
		 * a filesystem 404 and PHP never runs. No rewrite rule instead: that means a flush,
		 * and this endpoint deliberately does not depend on WordPress's routing.
		 *
		 * admin_url(), never home_url(): the custom X-Scanpay header makes the poll
		 * CORS-preflighted the moment its origin differs from the screen fetching it, and
		 * WordPress answers no preflight. home_url() and the admin origin part company on
		 * ordinary setups -- FORCE_SSL_ADMIN over an http home, WP_SITEURL on its own host.
		 * Raw, not esc_url(): wp_json_encode() below owns the escaping.
		 */
		'endpoint'    => admin_url( 'admin-ajax.php' ),
	];
	wp_add_inline_script(
		'wc-scanpay-order',
		'window.ScanpayOrderData = ' . wp_json_encode( $props, JSON_UNESCAPED_SLASHES ) . ';',
		'before'
	);
	echo '<div id="wcsp-meta"></div>';
}

/**
 * Add the Scanpay meta box to the order edit screen, for Scanpay orders only: registering
 * it unconditionally shows an empty "Scanpay" side box on every other gateway's orders.
 *
 * @param WP_Post|WC_Order $wc_order Current object (legacy: WP_Post, HPOS: WC_Order).
 */
function wc_scanpay_add_meta_box( $wc_order ): void {
	if ( ! $wc_order instanceof WC_Order ) {
		$wc_order = wc_get_order( $wc_order->ID ); // Legacy: a WP_Post arrives instead.
		if ( ! $wc_order ) {
			return;
		}
	}
	if ( ! wc_scanpay_is_scanpay_order( $wc_order ) ) {
		return;
	}
	// Enqueued here, not in the render callback: this hook runs before admin_head, so the
	// stylesheet reaches the head rather than being deferred to the footer by
	// print_late_styles() and flashing the box unstyled.
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/css/meta.css', [], WC_SCANPAY_VERSION );
	add_meta_box(
		'wcsp-meta-box',
		'Scanpay',
		'wc_scanpay_admin_render_meta_box',
		null,
		'side',
		'high',
	);
}
add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'wc_scanpay_add_meta_box', 9, 1 ); // HPOS
add_action( 'add_meta_boxes_shop_order', 'wc_scanpay_add_meta_box', 9, 1 ); // Legacy
