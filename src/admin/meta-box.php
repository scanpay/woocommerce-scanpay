<?php
defined( 'ABSPATH' ) || exit();


function wc_scanpay_create_meta_box( $post, $args ) {
	$wc_order = $args['args'][0];
	$oid      = $wc_order->get_id();
	$secret   = get_option( WC_SCANPAY_URI_SETTINGS )['secret'] ?? '';
	$status   = $wc_order->get_status( 'edit' );
	$total    = $wc_order->get_total( 'edit' ) - $wc_order->get_total_refunded();
	$currency = $wc_order->get_currency();
	$subid    = $wc_order->get_meta( WC_SCANPAY_URI_SUBID, true, 'edit' );
	$payid    = $wc_order->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' );
	$ptime    = $wc_order->get_meta( WC_SCANPAY_URI_PTIME, true, 'edit' );
	echo "<div id='wcsp-meta' data-id='$oid' data-secret='$secret' data-status='$status' data-total='$total' data-currency='$currency' data-subid='$subid' data-payid='$payid' data-ptime='$ptime'>
		<div id='wcsp-meta-head'></div>
		<ul id='wcsp-meta-ul' class='wcsp-meta-ul'></ul>
		<div id='wcsp-meta-foot'></div>
	</div>";
}

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

// Meta box
function wc_scanpay_add_meta_box( $wc_order ) {
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
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION );
	wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/public/js/order.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	add_meta_box( 'wcsp-meta-box', 'Scanpay', 'wc_scanpay_create_meta_box', null, 'side', 'high', [ $wc_order ] );
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
	wp_enqueue_style( 'wcsp-meta', WC_SCANPAY_URL . '/public/css/meta.css', null, WC_SCANPAY_VERSION );
	wp_enqueue_script( 'wcsp-meta', WC_SCANPAY_URL . '/public/js/subs.js', false, WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	add_meta_box( 'wcsp-meta-box', 'Scanpay', 'wc_scanpay_create_meta_box_subs', null, 'side', 'high', [ $wc_order ] );
}
