<?php
defined( 'ABSPATH' ) || exit();

require WC_SCANPAY_DIR . '/admin/meta-box.php';


// Add plugin version number to JS: wcSettings.admin
function wc_scanpay_admin_add_version( $settings ) {
	$settings['scanpay'] = WC_SCANPAY_VERSION;
	return $settings;
}
add_filter( 'woocommerce_admin_shared_settings', 'wc_scanpay_admin_add_version' );


/**
 * Filter the payment method title for subscriptions.
 * This is used to display the correct payment method title in the subscription details.
 */
function wcs_scanpay_payment_method_to_display( $s, $sub ) {
	return $sub->get_payment_method() === 'scanpay' ? $sub->get_payment_method_title() : $s;
}
add_filter( 'woocommerce_subscription_payment_method_to_display', 'wcs_scanpay_payment_method_to_display', 10, 2 );


// [hook] Handle the custom bulk action
function wc_scanpay_handle_bulk_actions( string $redirect_to, string $action, array $ids ) {
	return require WC_SCANPAY_DIR . '/hooks/capture/wp-bulk-actions.php';
}

// [hook] Add custom bulk action to the order list
function wc_scanpay_add_bulk_actions( array $actions ): array {
	$arr = [ 'scanpay_capture_complete' => 'Capture and complete' ];
	foreach ( $actions as $k => $v ) {
		$arr[ ( 'mark_completed' === $k ) ? 'scanpay_mark_completed' : $k ] = $v;
	}
	return $arr;
}

global $pagenow;
if ( 'admin.php' === $pagenow ) {
	// Add CSS and JavaScript to the settings page
	function wc_scanpay_admin_scripts() {
		global $current_section;
		if ( str_starts_with( $current_section, 'scanpay' ) ) {
			wp_enqueue_script( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/js/settings.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
			wp_enqueue_style( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/css/settings.css', null, WC_SCANPAY_VERSION );
		}
	}
	add_action( 'admin_print_styles-woocommerce_page_wc-settings', 'wc_scanpay_admin_scripts', 0, 0 );

	// Add metaboxes (HPOS enabled)
	add_action( 'add_meta_boxes_woocommerce_page_wc-orders', 'wc_scanpay_add_meta_box', 9, 1 );
	add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 );

	// [hook] Add custom bulk action to the order list (HPOS enabled)
	add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_add_bulk_actions', 10, 1 );

	// [hook] Handle the custom bulk action (HPOS enabled)
	add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'wc_scanpay_handle_bulk_actions', 0, 3 );
	return;
}

if ( 'plugins.php' === $pagenow ) {
	// Add helpful links to the plugins table and check compatibility
	function wc_scanpay_admin_helpful_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links; // Some plugins do not return the correct type (array)
		}
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' );
		return array_merge( [ "<a href='$url'>" . __( 'Settings', 'scanpay-for-woocommerce' ) . '</a>' ], $links );
	}
	add_filter( 'plugin_action_links_scanpay-for-woocommerce/woocommerce-scanpay.php', 'wc_scanpay_admin_helpful_links' );

	require WC_SCANPAY_DIR . '/includes/compatibility.php';
	return;
}

if ( 'admin-ajax.php' === $pagenow ) {
	// [hook] Ajax action to mark order status
	function wc_scanpay_mark_order_status() {
		require WC_SCANPAY_DIR . '/hooks/capture/wp-ajax-wc-mark-order-status.php';
	}
	add_action( 'wp_ajax_woocommerce_mark_order_status', 'wc_scanpay_mark_order_status', 0, 0 );
	return;
}

/**
 * HPOS disabled: The following code will be removed in a future version.
 */
if ( 'edit.php' === $pagenow ) {
	// [hook] Add custom bulk action to the order list (HPOS disabled)
	add_filter( 'bulk_actions-edit-shop_order', 'wc_scanpay_add_bulk_actions', 10, 1 );

	// [hook] Handle the custom bulk action (HPOS disabled)
	add_filter( 'handle_bulk_actions-edit-shop_order', 'wc_scanpay_handle_bulk_actions', 0, 3 );
	return;
}

if ( 'post.php' === $pagenow ) {
	// Add metabox (HPOS disabled)
	add_action( 'add_meta_boxes_shop_order', 'wc_scanpay_add_meta_box', 9, 1 );
	add_action( 'add_meta_boxes_shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 );
	return;
}
