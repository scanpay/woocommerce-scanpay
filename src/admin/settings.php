<?php

/**
 * The admin surface around the gateway settings screens: asset enqueue, the reset
 * endpoint's registration, the Plugins-screen link and the footer-text suppression. Hooks
 * are registered at require time -- the file is the hook list.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/** Whether this request targets one of the plugin's own WC > Settings > Payments screens. */
function wc_scanpay_is_settings_screen(): bool {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection; no state change.
	$page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
	$tab     = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( 'wc-settings' !== $page || 'checkout' !== $tab ) {
		return false;
	}
	// WooCommerce renders a gateway's admin_options() for either section spelling: the id,
	// or sanitize_title( get_class( $gateway ) ). Its own links use the id, but the
	// class-name alias is live, and on it our assets would not enqueue -- unstyled page, no
	// last-sync indicator, inert reset button.
	return str_starts_with( $section, 'scanpay' ) || str_starts_with( $section, 'wc_gateway_scanpay' );
}


/** Enqueue admin JS/CSS on the plugin's own WC > Settings > Payments screens. */
function wc_scanpay_admin_assets( string $hook_suffix ): void {
	if ( 'woocommerce_page_wc-settings' !== $hook_suffix || ! wc_scanpay_is_settings_screen() ) {
		return;
	}
	wp_enqueue_script( 'wc-scanpay-settings', WC_SCANPAY_URL . '/admin/assets/js/settings.js', [ 'wp-i18n' ], WC_SCANPAY_VERSION, [ 'strategy' => 'defer' ] );
	// The directory load_plugin_textdomain() reads from; the script consumes the
	// window.wp.i18n runtime the dependency above guarantees.
	wp_set_script_translations( 'wc-scanpay-settings', 'scanpay-for-woocommerce', WC_SCANPAY_DIR . '/languages' );
	wp_enqueue_style( 'wc-scanpay-settings', WC_SCANPAY_URL . '/admin/assets/css/settings.css', [], WC_SCANPAY_VERSION );
}
add_action( 'admin_enqueue_scripts', 'wc_scanpay_admin_assets' );


/**
 * An admin notice on the gateway settings screen. Declared here rather than in
 * admin-options.php, which calls it: that file is required bare, because the include is the
 * render call, so anything it declared would fatally redeclare on a second render.
 *
 * @param string $msg  Pre-escaped HTML message.
 * @param string $type 'info', 'warning', 'error' or 'success'.
 */
function wc_scanpay_admin_notice( string $msg, string $type = 'info' ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $msg is trusted, pre-escaped HTML assembled by the callers in admin/settings/admin-options.php.
	echo '<div class="notice notice-' . esc_attr( $type ) . ' wcsp-notice"><p>' . $msg . '</p></div>';
}


/**
 * Delete all Scanpay data and clear the API key. Guarded by a nonce and
 * manage_woocommerce, a higher bar than the capture handler's edit_shop_orders, because
 * this rewrites gateway configuration.
 */
function wc_scanpay_ajax_reset(): void {
	require WC_SCANPAY_DIR . '/admin/hooks/wp-ajax-wc-scanpay-reset.php';
}
add_action( 'wp_ajax_wc_scanpay_reset', 'wc_scanpay_ajax_reset', 0, 0 );


/** Add a "Settings" link to the Scanpay entry on the Plugins screen. */
function wc_scanpay_admin_settings_link( array $links ): array {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=scanpay' );
	// Escaped for the same blind spot as the gateway icons: PHPCS misses it because the
	// value is returned, not echoed. Both halves are third-party surface -- admin_url() runs
	// a filter, __() runs gettext -- and row_actions() echoes the result verbatim.
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'scanpay-for-woocommerce' ) . '</a>' );
	return $links;
}
// Derived, not a literal: the hook carries the get_plugins() key, and the directory is
// whatever the install calls it. See wc_scanpay_init() for why basename() suffices.
add_filter( 'plugin_action_links_' . basename( WC_SCANPAY_DIR ) . '/woocommerce-scanpay.php', 'wc_scanpay_admin_settings_link' );


/**
 * Hide WooCommerce's promotional footer text, on our own settings screens only: blanking
 * admin_footer_text globally is a site-wide UI change, and the WordPress.org review flags
 * it. Priority 11, after WC's own filter at 1, so there is text to clear.
 */
function wc_scanpay_admin_footer_text( $text ) {
	return wc_scanpay_is_settings_screen() ? '' : $text;
}
add_filter( 'admin_footer_text', 'wc_scanpay_admin_footer_text', 11 );
