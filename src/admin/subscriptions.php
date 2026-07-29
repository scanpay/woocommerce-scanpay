<?php

/**
 * The subscription edit screen: the payment-method title and the Scanpay meta box.
 * Required from wc_scanpay_admin_init() only when WC_Subscriptions is loaded.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/functions.php';

/**
 * The payment method title for Scanpay subscriptions. $title is untyped because this sits
 * in a third-party filter chain, where under strict_types an earlier callback returning
 * null would make the declaration an uncatchable TypeError on the subscription screens.
 */
function wcs_scanpay_payment_method_to_display( $title, WC_Subscription $sub ) {
	return $sub->get_payment_method() === 'scanpay'
		? $sub->get_payment_method_title()
		: $title;
}
add_filter( 'woocommerce_subscription_payment_method_to_display', 'wcs_scanpay_payment_method_to_display', 10, 2 );


/**
 * Render the Scanpay subscription meta box content.
 *
 * @param WP_Post|WC_Order $post Current object (unused; the subscription arrives via $args).
 * @param array            $args Meta box args; $args['args'][0] is the WC_Subscription.
 */
function wc_scanpay_create_meta_box_subs( $post, array $args ): void {
	$wc_sub   = $args['args'][0];
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	$secret   = (string) ( is_array( $settings ) ? ( $settings['secret'] ?? '' ) : '' );
	/*
	 * Payment id and time come from the parent order when the subscription has none of its
	 * own, which at checkout is always: the sole writer is wc_scanpay_process_payment(),
	 * and by the time it runs WCS has already copied the parent's meta. WCS hooks
	 * woocommerce_checkout_order_processed and calls wcs_copy_order_meta() there, while
	 * WC_Checkout fires that action before process_order_payment(). Neither key is excluded
	 * from the copy; the copy simply happens first.
	 *
	 * The subscription's own value still wins where one exists. get_parent() returns false,
	 * not null, with no parent, so the falsy test covers it. Writing these keys onto the
	 * subscription instead is not an option: meta there is copied onto every renewal order.
	 */
	$wcs_parent = $wc_sub->get_parent();
	$payid      = (string) $wc_sub->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' );
	$ptime      = (string) $wc_sub->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' );
	if ( $wcs_parent ) {
		if ( '' === $payid ) {
			$payid = (string) $wcs_parent->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' );
		}
		if ( '' === $ptime ) {
			$ptime = (string) $wcs_parent->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' );
		}
	}
	// data-endpoint is the base for the ?x=sub poll. admin_url(), never home_url(), for the
	// CORS-preflight reason wc_scanpay_admin_render_meta_box() gives.
	echo '<div id="wcsp-meta" data-secret="' . esc_attr( $secret ) . '"
		data-endpoint="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '"
		data-subid="' . esc_attr( (string) $wc_sub->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) ) . '"
		data-payid="' . esc_attr( $payid ) . '"
		data-ptime="' . esc_attr( $ptime ) . '">
		<div id="wcsp-meta-head"></div>
		<ul id="wcsp-meta-ul" class="wcsp-meta-ul"></ul>
	</div>';
}

/**
 * Add the Scanpay meta box to the subscription edit screen, for Scanpay subscriptions only.
 *
 * @param WP_Post|WC_Order $wc_order Current object (legacy: WP_Post, HPOS: WC_Order).
 */
function wc_scanpay_add_meta_box_subs( $wc_order ): void {
	if ( ! $wc_order instanceof WC_Order ) {
		$wc_order = wc_get_order( $wc_order->ID ); // Legacy: a WP_Post arrives instead.
		if ( ! $wc_order ) {
			return;
		}
	}
	if ( ! wc_scanpay_is_scanpay_order( $wc_order ) ) {
		return;
	}
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/css/meta.css', [], WC_SCANPAY_VERSION );
	wp_enqueue_script( 'wc-scanpay-subs', WC_SCANPAY_URL . '/admin/assets/js/subs.js', [ 'wp-i18n' ], WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	wp_set_script_translations( 'wc-scanpay-subs', 'scanpay-for-woocommerce', WC_SCANPAY_DIR . '/languages' );
	add_meta_box( 'wcsp-meta-box', 'Scanpay', 'wc_scanpay_create_meta_box_subs', null, 'side', 'high', [ $wc_order ] );
}
add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 ); // HPOS
add_action( 'add_meta_boxes_shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 ); // Legacy
