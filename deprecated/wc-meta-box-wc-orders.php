<?php

/*
*   wc-meta-box-wc-orders.php
*   Add transaction info to WooCommerce order view
*/

defined( 'ABSPATH' ) || exit();
require WC_SCANPAY_DIR . '/library/math.php';

function wc_scanpay_meta_alert( string $type, string $msg ): void {
	echo "<div class='scanpay--alert scanpay--alert-$type'>";
	if ( 'pending' === $type ) {
		echo '<img class="scanpay--alert--spin" width="18" height="18"
			src="' . WC_SCANPAY_URL . '/public/images/admin/spinner.svg">';
	} elseif ( 'error' === $type ) {
		echo '<strong>ERROR</strong>: ';
	}
	echo $msg . '</div>';
}

function wc_scanpay_status( array $totals ): string {
	if ( $totals['voided'] === $totals['authorized'] ) {
		return 'voided';
	}
	if ( '0' === $totals['authorized'] ) {
		return 'unpaid';
	}
	if ( '0' === $totals['captured'] ) {
		return 'authorized';
	}
	if ( '0' === $totals['refunded'] ) {
		if ( $totals['captured'] === $totals['authorized'] ) {
			return 'fully captured';
		}
		return 'partially captured';
	}
	if ( $totals['captured'] === $totals['refunded'] ) {
		return 'fully refunded';
	}
	return 'partially refunded';
}

function wc_scanpay_echo_payment_method( $method ) {
	if ( is_array( $method ) && isset( $method['type'] ) ) {
		echo '<li class="sp--widget--li">
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
		echo '</div></li>';
	}
}


function wc_scanpay_meta_box( object $order ): void {
	global $wpdb;
	$order_id = $order->get_id();
	$meta     = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}scanpay_meta WHERE orderid = $order_id", ARRAY_A );

	if ( empty( $meta ) ) {
		wc_scanpay_meta_alert( 'notice', __( 'No payment details found!', 'scanpay-for-woocommerce' ) );
		return;
	}

	echo '<span id="scanpay--data" data-order="' . $order->get_id() . '" data-rev="' . $meta['rev'] . '"></span>';

	// B) Order is awaiting a ping (e.g. after capture)
	if ( 0 === (int) $meta['rev'] ) {
		wc_scanpay_meta_alert( 'pending', __( 'Awaiting scanpay synchronization.', 'scanpay-for-woocommerce' ) );
		return;
	}

	if ( ! isset( $meta['authorized'] ) ) {
		// C) Subsciption w/o initial charge ??
		if ( isset( $meta['subid'] ) ) {
			echo '<ul class="sp--widget--ul">
				<li class="sp--widget--li">
					<div class="sp--widget--li--title">' . __( 'Status', 'scanpay-for-woocommerce' ) . ':</div>
					<b class="sp--widget--listatus--subscribed">subscribed</b>
				</li>';
				wc_scanpay_echo_payment_method( $meta['method'] );
			echo '<li class="sp--widget--li">
					<div class="sp--widget--li--title">Expires:</div>
					' . $meta['method'] . '
				</li>
			</ul>';
			return;
		}

		// D) Order has not been paid (yet).
		$minutes_ago = (int) ( ( time() - strtotime( $order->get_date_created() ) ) / 60 );
		$payid       = $order->get_meta( WC_SCANPAY_URI_PAYID, true, 'edit' );
		if ( $minutes_ago < 30 ) {
			wc_scanpay_meta_alert(
				'notice',
				__(
					'Awaiting payment. The customer has 30 minutes to complete the payment.',
					'scanpay-for-woocommerce'
				)
			);
		} else {
			wc_scanpay_meta_alert(
				'warning',
				__(
					'The order was not paid and the payment link has expired.',
					'scanpay-for-woocommerce'
				)
			);
		}

		echo '<ul class="sp--widget--ul">
			<li class="sp--widget--li">
				<div class="sp--widget--li--title">' . __( 'Status', 'scanpay-for-woocommerce' ) . ':</div>
				<b class="sp--widget--listatus--unpaid">unpaid</b>
			</li>
			<li class="sp--widget--li">
				<div class="sp--widget--li--title">PayID:</div>
				<a href="' . WC_SCANPAY_DASHBOARD . 'logs?payid= ' . $payid . '">' . $payid . '</a>
			</li>
		</ul>';
		// TODO: Add button to create new payment link
		return;
	}

	$currency   = $order->get_currency();
	$link       = WC_SCANPAY_DASHBOARD . $meta['shopid'] . '/' . $meta['id'];
	$net        = wc_scanpay_submoney( substr( $meta['captured'], 0, -4 ), substr( $meta['refunded'], 0, -4 ) );
	$woo_net    = wc_scanpay_submoney( strval( $order->get_total() ), strval( $order->get_total_refunded() ) );
	$woo_status = $order->get_status();
	$mismatch   = 'processing' !== $woo_status && wc_scanpay_cmpmoney( $net, $woo_net ) !== 0;

	// Alert Boxes
	if ( 0 === $meta['nacts'] && ( 'cancelled' === $woo_status || 'refunded' === $woo_status ) ) {
		// Tell merchant to void the payment.
		wc_scanpay_meta_alert(
			'notice',
			__(
				'Void the payment to release the reserved amount in the customer\'s bank account. Reservations last for 7-28 days.',
				'scanpay-for-woocommerce'
			)
		);
	} elseif ( $mismatch ) {
		// Net payment mismatch
		wc_scanpay_meta_alert(
			'warning',
			sprintf(
				'There is a discrepancy between the order net payment (%s) and your actual net payment (%s).',
				wc_price( $woo_net, [ 'currency' => $currency ] ),
				wc_price( $net, [ 'currency' => $currency ] )
			)
		);

		$refund_mismatch = wc_scanpay_cmpmoney( substr( $meta['refunded'], 0, -4 ), (string) $order->get_total_refunded() );
		if ( 0 !== $refund_mismatch && empty( $refunded ) ) {
			// Merchant likely forgot to refund in our dashboard
			wc_scanpay_meta_alert(
				'notice',
				sprintf(
					'For security reasons, payments can only be refunded in the scanpay %s.',
					'<a target="_blank" href="' . $link . '/refund">' . __( 'dashboard', 'scanpay-for-woocommerce' ) . '</a>'
				)
			);
		}
	}

	/*
		Transaction details
	*/
	$status = wc_scanpay_status( $meta );

	echo '
	<ul class="sp--widget--ul">
		<li class="sp--widget--li">
			<div class="sp--widget--li--title">' . __( 'Status', 'scanpay-for-woocommerce' ) . ':</div>
			<b class="sp--widget--listatus--' . preg_replace( '/\s+/', '-', $status ) . '">' . $status . '</b>
		</li>';
	wc_scanpay_echo_payment_method( $meta['method'] );

	echo '<li class="sp--widget--li">
			<div class="sp--widget--li--title">' . __( 'Authorized', 'scanpay-for-woocommerce' ) . ':</div>
			<b>' . wc_price( substr( $meta['authorized'], 0, -4 ), [ 'currency' => $currency ] ) . '</b>
		</li>';

	if ( $meta['captured'] > 0 ) {
		echo '<li class="sp--widget--li">
			<div class="sp--widget--li--title">' . __( 'Captured', 'scanpay-for-woocommerce' ) . ':</div>
			<b>' . wc_price( substr( $meta['captured'], 0, -4 ), [ 'currency' => $currency ] ) . '</b>
		</li>';
	}

	if ( $mismatch || $meta['refunded'] > 0 ) {
		echo '<li class="sp--widget--li">
			<div class="sp--widget--li--title">' . __( 'Refunded', 'scanpay-for-woocommerce' ) . ':</div>
			<span class="sp--widget--li--refunded">&minus;' .
				wc_price( substr( $meta['refunded'], 0, -4 ), [ 'currency' => $currency ] ) . '</span>
		</li>';
	}

	echo '<li class="sp--widget--li">
		<div class="sp--widget--li--title">' . __( 'Net payment', 'scanpay-for-woocommerce' ) . ':</div>
		<b>' . wc_price( $net, [ 'currency' => $currency ] ) . '</b>
	</li></ul>';

	if ( 'fully refunded' !== $status && 'unpaid' !== $status ) {
		echo '<div class="scanpay--actions">
				<div class="scanpay--actions--expand">
					<a target="_blank" href="' . $link . '">
						<img width="22" height="22" src="' . WC_SCANPAY_URL . '/public/images/admin/link.svg">
					</a>
				</div>';
		if ( $meta['captured'] > 0 ) {
			echo '<a target="_blank" class="sp--widget--lirefund" href="' . $link . '/refund">' .
				__( 'Refund', 'scanpay-for-woocommerce' ) . '</a>';
		} elseif ( 'authorized' === $status ) {
			echo '<a target="_blank" class="sp--widget--lirefund" href="' . $link . '">' .
				__( 'Void payment', 'scanpay-for-woocommerce' ) . '</a>';
		}
		echo '</div>';
	}
}

add_meta_box(
	'scanpay-info',
	'Scanpay',
	'wc_scanpay_meta_box',
	wc_get_page_screen_id( 'shop-order' ),
	'side',
	'high'
);
