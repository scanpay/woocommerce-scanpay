<?php

/**
 * Process, validate and save admin options.
 *
 * Included from WC_Gateway_Scanpay_Base::process_admin_options().
 *
 * @return bool
 */

defined( 'ABSPATH' ) || exit();

// Check if gateway was enabled before the save.
$was_enabled = ( 'yes' === $this->get_option( 'enabled', 'no' ) );

// Save changes.
$saved = parent::process_admin_options();
if ( ! $saved ) {
	// Nothing was changed.
	return false;
}

// Reload settings after save.
$this->init_settings();

// Only validate when enabling the gateway (transition no -> yes).
if ( $was_enabled || 'no' === $this->get_option( 'enabled', 'no' ) ) {
	return true;
}

try {
	// All gateways require a valid API key to function.
	// Let's do a simple API call to verify the key.
	$primary = get_option( WC_SCANPAY_URI_SETTINGS, [] );
	require WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
	$client = new WC_Scanpay_Client( $primary['apikey'] ?? '' );
	$res    = $client->seq( 0 );
} catch ( Exception $e ) {
	// Force-disable gateway but keep entered settings.
	$this->settings['enabled'] = 'no';
	update_option( $this->get_option_key(), $this->settings );
	WC_Admin_Settings::add_error(
		__( 'Error: Invalid Scanpay API key. Please check your key and try again.', 'scanpay-for-woocommerce' )
	);
}
return true;
