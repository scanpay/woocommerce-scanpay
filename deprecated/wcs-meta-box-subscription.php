<?php

/*
*   wcs_meta_box_subscription.php
*   Add transaction info to WooCommerce order view
*/

defined( 'ABSPATH' ) || exit();

function wcs_scanpay_meta_alert( string $type, string $msg ): void {
	echo "<div class='scanpay--alert scanpay--alert-$type'>";
	if ( 'pending' === $type ) {
		echo '<img class="scanpay--alert--spin" width="18" height="18"
			src="' . WC_SCANPAY_URL . '/public/images/admin/spinner.svg">';
	} elseif ( 'error' === $type ) {
		echo '<strong>ERROR</strong>: ';
	}
	echo $msg . '</div>';
}

function wcs_scanpay_meta_box( object $subscriber ): void {
	if ( 'scanpay' !== $subscriber->payment_method ) {
		wcs_scanpay_meta_alert( 'notice', __( 'Payment method is not Scanpay.', 'scanpay-for-woocommerce' ) );
		return;
	}
	// $method = (array) $subscriber->parent->get_meta( WC_SCANPAY_URI_PAYMENT_METHOD, true, 'edit' );

	echo '<ul class="sp--widget--ul">
	<li class="sp--widget--li">
		<div class="sp--widget--li--title">' . __( 'Subscription', 'scanpay-for-woocommerce' ) . ':</div>
		<b class="sp--widget--listatus--subscribed">Active</b>
	</li>
	<li class="sp--widget--li">
		<div class="sp--widget--li--title">' . __( 'Method', 'scanpay-for-woocommerce' ) . ':</div>
		<div class="sp--widget--li--card">';

	if ( 'applepay' === $method['type'] ) {
		echo '<img class="sp--widget--li--card--applepay" title="Apple Pay"
			src="' . WC_SCANPAY_URL . '/public/images/admin/methods/applepay.svg">';
	} elseif ( 'mobilepay' === $method['type'] ) {
		echo '<img class="sp--widget--li--card--mobilepay" title="MobilePay"
			src="' . WC_SCANPAY_URL . '/public/images/admin/methods/mobilepay.svg">';
	}
	if ( isset( $method['card']['brand'], $method['card']['last4'] ) ) {
		echo '<img class="sp--widget--li--card--' . $method['card']['brand'] . '" title="' . $method['card']['brand'] . '"
				src="' . WC_SCANPAY_URL . '/public/images/admin/methods/' . $method['card']['brand'] . '.svg">
			<span class="sp--widget--li--card--dots">•••</span><b>' . $method['card']['last4'] . '</b>';
	}
	echo '</div></li>
	<li class="sp--widget--li">
		<div class="sp--widget--li--title">Expires:</div>
		' . gmdate( 'd/m/Y', $method['card']['exp'] ) . '
	</li></ul>';
}

add_meta_box( 'scanpay-info', 'Scanpay', 'wcs_scanpay_meta_box', null, 'side', 'high' );
