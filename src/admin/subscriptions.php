<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Display the correct payment method title for Scanpay subscriptions.
 *
 * @param string         $title Current payment method title.
 * @param WC_Subscription $sub  Subscription object.
 * @return string Filtered payment method title.
 */
function wcs_scanpay_payment_method_to_display( string $title, WC_Subscription $sub ): string {
	return $sub->get_payment_method() === 'scanpay'
		? $sub->get_payment_method_title()
		: $title;
}
add_filter( 'woocommerce_subscription_payment_method_to_display', 'wcs_scanpay_payment_method_to_display', 10, 2 );


function wc_scanpay_create_meta_box_subs( $post, $args ) {
	$wc_sub = $args['args'][0];
	$secret = get_option( WC_SCANPAY_URI_SETTINGS )['secret'] ?? '';
	echo '<div id="wcsp-meta" data-secret="' . $secret . '"
		data-subid="' . $wc_sub->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' ) . '"
		data-payid="' . $wc_sub->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' ) . '"
		data-ptime="' . $wc_sub->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' ) . '">
		<div id="wcsp-meta-head"></div>
		<ul id="wcsp-meta-ul" class="wcsp-meta-ul"></ul>
	</div>';
}

// Meta box Subscriptions
function wc_scanpay_add_meta_box_subs( $wc_order ) {
	if ( ! $wc_order instanceof WC_Order ) {
		$wc_order = wc_get_order( $wc_order->ID ); // Legacy support
		if ( ! $wc_order ) {
			return;
		}
	}
	$psp = $wc_order->get_payment_method( 'edit' );
	if ( 'scanpay' !== $psp && ! str_starts_with( $psp, 'scanpay' ) ) {
		return;
	}
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/css/meta.css', null, WC_SCANPAY_VERSION );
	wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/admin/assets/js/subs.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	add_meta_box( 'wcsp-meta-box', 'Scanpay', 'wc_scanpay_create_meta_box_subs', null, 'side', 'high', [ $wc_order ] );
}
add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 ); // HPOS
add_action( 'add_meta_boxes_shop_subscription', 'wc_scanpay_add_meta_box_subs', 9, 1 ); // legacy
