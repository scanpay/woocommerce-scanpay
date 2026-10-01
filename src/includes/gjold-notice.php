<?php

/*
	gjold-notice.php
	Inform merchants that Scanpay is becoming Gjold. Shown as a banner in the
	plugin settings and as a dismissible notice in the rest of wp-admin.
*/

defined( 'ABSPATH' ) || exit();

// Change the ID when the notice changes, to show it again to users who dismissed it
const WC_SCANPAY_NOTICE_ID  = 'gjold-1';
const WC_SCANPAY_URI_NOTICE = 'wc_scanpay_dismissed_notice'; // user meta

function wc_scanpay_gjold_notice_body(): string {
	$old = '<b>dashboard.scanpay.dk</b>';
	$new = '<b>dashboard.gjold.com</b>';
	return '<p>' . esc_html__( 'Scanpay is changing its name to Gjold. This version of the plugin already uses our new gjold.com domain. No action is required.', 'scanpay-for-woocommerce' ) . '</p>' .
		'<p>' . sprintf(
			// translators: %1$s is the current dashboard address, %2$s is the new dashboard address
			esc_html__( 'You can continue to use the dashboard at %1$s. In a few days, it will also be available at %2$s, and both addresses will work. Over the coming weeks, we will phase out %1$s, so please switch to %2$s once it is available.', 'scanpay-for-woocommerce' ),
			$old,
			$new
		) . '</p>' .
		'<p>' . esc_html__( 'In a few weeks, we will also release a new and much improved Gjold plugin for WooCommerce. We will let you know when it is ready.', 'scanpay-for-woocommerce' ) . '</p>';
}

// [hook] admin_notices
function wc_scanpay_gjold_admin_notice(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ||
		WC_SCANPAY_NOTICE_ID === get_user_meta( get_current_user_id(), WC_SCANPAY_URI_NOTICE, true ) ) {
		return;
	}
	// The plugin settings show the notice as a banner (admin-options.php)
	if ( 'wc-settings' === ( $_GET['page'] ?? '' ) && str_starts_with( $_GET['section'] ?? '', 'scanpay' ) ) {
		return;
	}
	$url = wp_nonce_url( add_query_arg( 'wcsp_dismiss_notice', '1' ), 'wcsp_dismiss_notice' );
	echo '<div class="notice notice-info"><p><strong>' .
		esc_html__( 'Scanpay is becoming Gjold', 'scanpay-for-woocommerce' ) . '</strong></p>' .
		wc_scanpay_gjold_notice_body() .
		'<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss this notice', 'scanpay-for-woocommerce' ) . '</a></p>
	</div>';
}

// [hook] admin_init: handle the "Dismiss this notice" link
function wc_scanpay_gjold_notice_dismiss(): void {
	check_admin_referer( 'wcsp_dismiss_notice' );
	if ( current_user_can( 'manage_woocommerce' ) ) {
		update_user_meta( get_current_user_id(), WC_SCANPAY_URI_NOTICE, WC_SCANPAY_NOTICE_ID );
	}
	wp_safe_redirect( remove_query_arg( [ 'wcsp_dismiss_notice', '_wpnonce' ] ) );
	exit;
}
