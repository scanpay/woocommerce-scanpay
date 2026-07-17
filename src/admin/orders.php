<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Handle our custom bulk actions in the WC order list.
 *
 * @param string $redirect_to Redirect URL after processing.
 * @param string $action      Bulk action key.
 * @param array  $ids         Selected order IDs.
 * @return string Modified redirect URL.
 */
function wc_scanpay_handle_bulk_actions( string $redirect_to, string $action, array $ids ): string {
	$capture = true;
	if ( 'scanpay_mark_completed' === $action ) {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		if ( ! is_array( $settings ) || 'completed' !== ( $settings['wc_autocapture'] ?? '' ) ) {
			$capture = false;
		}
	} elseif ( 'scanpay_capture_complete' !== $action ) {
		return $redirect_to; // Not our action; Let WC handle it
	}
	require_once WC_SCANPAY_DIR . '/admin/hooks/wp-bulk-actions.php';
	return wc_scanpay_handle_bulk_capture( $redirect_to, $ids, $capture );
}
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_handle_bulk_actions', 0, 3 ); // HPOS
add_filter( 'handle_bulk_actions-edit-shop_order', 'wc_scanpay_handle_bulk_actions', 0, 3 ); // Legacy


/**
 * Add our bulk actions to the WC order list. Hijack "Mark as completed"
 * so payments can be captured before order completion, not after.
 *
 * @param array $actions Existing bulk actions.
 * @return array Modified bulk actions.
 */
function wc_scanpay_add_bulk_actions( array $actions ): array {
	// Mirror WC's own trash-view restriction (Restore/Delete only). WP_List_Table
	// applies this filter on top of get_bulk_actions(), so our entries would
	// otherwise be re-added to the trash dropdown and capture a trashed order.
	// HPOS reads 'status', the legacy list table 'post_status'.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of which view is rendered; changes no state.
	$view = sanitize_text_field( wp_unslash( $_REQUEST['status'] ?? $_REQUEST['post_status'] ?? '' ) );
	if ( 'trash' === $view ) {
		return $actions;
	}
	$arr = [];
	foreach ( $actions as $k => $v ) {
		$arr[ 'mark_completed' === $k ? 'scanpay_mark_completed' : $k ] = $v;
	}
	// Prepend our custom action "Capture and complete"
	return [ 'scanpay_capture_complete' => __( 'Capture and complete', 'scanpay-for-woocommerce' ) ] + $arr;
}
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_add_bulk_actions', 10, 1 ); // HPOS
// Priority 20, not 10: WC registers its own filter from setup_screen() on
// current_screen, which fires after admin_init, where we register. At an equal
// priority ours would run first, on an array that does not yet hold
// 'mark_completed', and the rename would match nothing.
add_filter( 'bulk_actions-edit-shop_order', 'wc_scanpay_add_bulk_actions', 20, 1 ); // Legacy


/**
 * Intercept the AJAX request to mark an order as completed.
 * This is to capture the payment before the order is set to completed.
 */
function wc_scanpay_mark_order_status(): void {
	require WC_SCANPAY_DIR . '/admin/hooks/wp-ajax-wc-mark-order-status.php';
}
add_action( 'wp_ajax_woocommerce_mark_order_status', 'wc_scanpay_mark_order_status', 0, 0 );


/**
 * Manual capture from the order meta box (the "Capture" button in order.ts).
 * Guarded by the per-order nonce injected into window.ScanpayOrderData.
 */
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
	// The stylesheet is enqueued in wc_scanpay_add_meta_box() so it lands in the
	// head. The script stays here, next to the inline payload it carries: both are
	// printed in the footer, after this callback has run.
	wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/js/order.js', [], WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );

	$oid      = $wco->get_id();
	$meta     = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $oid LIMIT 1", ARRAY_A );
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	$shopid   = (int) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true, 'edit' );
	$tid      = (int) $wco->get_transaction_id( 'edit' );
	// Refunds are performed in the Scanpay dashboard (the gateway declares
	// can_refund_order() === false and the plugin only reflects refunds via sync),
	// so the meta box links there rather than issuing a refund itself.
	$dashboard = ( $shopid && $tid )
		? WC_SCANPAY_DASHBOARD . rawurlencode( (string) $shopid ) . '/' . rawurlencode( (string) $tid )
		: '';
	$props     = [
		'oid'         => $oid,
		'tid'         => $tid,
		'subid'       => (int) $wco->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ),
		'shopid'      => $shopid,
		'payid'       => $wco->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' ),
		'wc_decimals' => wc_get_price_decimals(),
		'meta'        => $meta ?? null,
		'currency'    => $wco->get_currency( 'edit' ),
		'secret'      => (string) ( is_array( $settings ) ? ( $settings['secret'] ?? '' ) : '' ),
		'dashboard'   => $dashboard,
		'nonce'       => wp_create_nonce( 'scanpay-order-' . $oid ),
	];
	wp_add_inline_script(
		'wcsp-meta',
		'window.ScanpayOrderData = ' . wp_json_encode( $props, JSON_UNESCAPED_SLASHES ) . ';',
		'before'
	);
	echo '<div id="wcsp-meta"></div>';
}

/**
 * Add the Scanpay meta box to the order edit screen, but only for Scanpay orders.
 *
 * Registering the box unconditionally shows an empty "Scanpay" side box on
 * PayPal/etc. orders, so gate on the payment method here (as the subscription
 * meta box in admin/subscriptions.php already does).
 *
 * @param WP_Post|WC_Order $wc_order Current object (legacy: WP_Post, HPOS: WC_Order).
 */
function wc_scanpay_add_meta_box( $wc_order ): void {
	if ( ! $wc_order instanceof WC_Order ) {
		$wc_order = wc_get_order( $wc_order->ID ); // Legacy support
		if ( ! $wc_order ) {
			return;
		}
	}
	if ( ! wc_scanpay_is_scanpay_order( $wc_order ) ) {
		return;
	}
	// Enqueued here, not in the render callback: this hook runs before admin_head,
	// so the stylesheet is printed in the head rather than being deferred to the
	// footer by print_late_styles() and flashing the box unstyled.
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/css/meta.css', [], WC_SCANPAY_VERSION );
	add_meta_box(
		'wcsp-meta-box',
		__( 'Scanpay', 'scanpay-for-woocommerce' ),
		'wc_scanpay_admin_render_meta_box',
		null,
		'side',
		'high',
	);
}
add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'wc_scanpay_add_meta_box', 9, 1 ); // HPOS
add_action( 'add_meta_boxes_shop_order', 'wc_scanpay_add_meta_box', 9, 1 ); // legacy
