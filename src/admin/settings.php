<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Expose the plugin version to WC admin JS.
 *
 * @param array $settings Shared WC admin settings.
 * @return array Updated settings.
 */
function wc_scanpay_admin_add_version( array $settings ): array {
	$settings['scanpay'] = WC_SCANPAY_VERSION;
	return $settings;
}
add_filter( 'woocommerce_admin_shared_settings', 'wc_scanpay_admin_add_version', 10, 1 );


/**
 * Enqueue admin JS/CSS on WC > Settings > Payments pages.
 *
 * @param string $hook_suffix Admin page hook.
 */
function wc_scanpay_admin_assets( string $hook_suffix ): void {
	if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
		return;
	}
	if ( 'checkout' !== ( $_GET['tab'] ?? '' ) ) {
		return;
	}
	$section = (string) ( $_GET['section'] ?? '' );
	if ( '' === $section || ! str_starts_with( $section, 'scanpay' ) ) {
		return;
	}
	wp_enqueue_script( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/js/settings.js', [], WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	wp_enqueue_style( 'wc-scanpay-settings', WC_SCANPAY_URL . '/public/css/settings.css', [], WC_SCANPAY_VERSION );
}
add_action( 'admin_enqueue_scripts', 'wc_scanpay_admin_assets' );


/**
 * Add a "Settings" link to the Scanpay plugin entry.
 *
 * @param array $links Plugin action links.
 * @return array Modified links.
 */
function wc_scanpay_admin_settings_link( array $links ): array {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' );
	array_unshift( $links, '<a href="' . $url . '">' . __( 'Settings', 'scanpay-for-woocommerce' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_scanpay-for-woocommerce/woocommerce-scanpay.php', 'wc_scanpay_admin_settings_link' );
