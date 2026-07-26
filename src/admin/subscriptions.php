<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Display the correct payment method title for Scanpay subscriptions.
 *
 * $title is deliberately untyped: this sits in a third-party filter chain, and with
 * strict_types a callback hooked earlier returning null would make the type declaration
 * an uncatchable TypeError on the subscription screens. WCS core passes a string;
 * non-Scanpay values are handed straight back. wcs_scanpay_retry_rule() does the same.
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
	echo '<div id="wcsp-meta" data-secret="' . esc_attr( $secret ) . '"
		data-subid="' . esc_attr( (string) $wc_sub->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) ) . '"
		data-payid="' . esc_attr( (string) $wc_sub->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' ) ) . '"
		data-ptime="' . esc_attr( (string) $wc_sub->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' ) ) . '">
		<div id="wcsp-meta-head"></div>
		<ul id="wcsp-meta-ul" class="wcsp-meta-ul"></ul>
	</div>';
}

/**
 * Add the Scanpay meta box to the subscription edit screen, but only for
 * Scanpay subscriptions.
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
	wp_enqueue_script( 'wc-scanpay-subs', WC_SCANPAY_URL . '/admin/assets/js/subs.js', [], WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	add_meta_box( 'wcsp-meta-box', 'Scanpay', 'wc_scanpay_create_meta_box_subs', null, 'side', 'high', [ $wc_order ] );
}
add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 ); // HPOS
add_action( 'add_meta_boxes_shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 ); // Legacy
