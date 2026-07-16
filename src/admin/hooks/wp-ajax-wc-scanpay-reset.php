<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Handle the AJAX "delete data and change API key" action from the settings page.
 *
 * Drops the three custom tables, clears the API key and disables every gateway.
 * install.php then recreates the tables empty; with no key stored it seeds no
 * seq row, leaving the store in a pre-setup state.
 *
 * Nothing deleted here is lost for good: the tables hold only what the sync API
 * can replay. Entering a key for the same shop reseeds seq=0 and the next ping
 * rebuilds every row -- WC_Scanpay_Sync::upsert_meta() runs outside the
 * already-paid guard, so a replay restores the meta rows without re-firing
 * payment_complete() on orders that are already paid.
 *
 * This is the only path that deletes Scanpay data. Saving the settings form
 * never does (see WC_Gateway_Scanpay_Base::validate_apikey_field(), which
 * refuses to replace a stored key).
 *
 * @hook wp_ajax_wc_scanpay_reset
 * This hook passes no arguments; the nonce arrives in $_POST.
 */

if (
	! current_user_can( 'manage_woocommerce' ) ||
	! check_ajax_referer( 'wc-scanpay-reset', 'nonce', false )
) {
	wp_send_json_error( 'forbidden', 403 );
}

global $wpdb;

/*
 * Clear the key first: install.php below reads it to decide whether to seed a
 * seq row, and must not reseed the shop being removed. Disabling the gateways is
 * not cosmetic -- without a key they cannot function, and needs_setup() reports
 * them as unconfigured once 'apikey' is gone.
 */
foreach (
	[
		WC_SCANPAY_URI_SETTINGS,
		'woocommerce_scanpay_mobilepay_settings',
		'woocommerce_scanpay_applepay_settings',
	] as $wcsp_opt
) {
	$wcsp_set = get_option( $wcsp_opt );
	if ( ! is_array( $wcsp_set ) ) {
		continue;
	}
	unset( $wcsp_set['apikey'] );
	$wcsp_set['enabled'] = 'no';
	update_option( $wcsp_opt, $wcsp_set );
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_seq" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_meta" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_subs" );

try {
	// Recreate the tables empty. Leaving them dropped would break every other
	// reader: the order meta box and the polling endpoints still query
	// scanpay_meta for old Scanpay orders even with no key configured.
	require WC_SCANPAY_DIR . '/install.php';
} catch ( Throwable $e ) {
	scanpay_log( 'error', 'Reset: could not recreate the tables: ' . $e->getMessage() );
	wp_send_json_error( 'install_failed', 500 );
}

scanpay_log( 'notice', 'Scanpay data deleted and API key cleared by user #' . get_current_user_id() );
wp_send_json_success();
