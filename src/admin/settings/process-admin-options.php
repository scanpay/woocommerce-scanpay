<?php

/**
 * Process, validate and save admin options.
 *
 * Included from WC_Gateway_Scanpay_Base::process_admin_options().
 *
 * @return bool
 */

defined( 'ABSPATH' ) || exit();

// Capture pre-save state so we can detect enable-transitions and key changes.
$was_enabled = ( 'yes' === $this->get_option( 'enabled', 'no' ) );
$old_apikey  = (string) $this->get_option( 'apikey', '' );

// Save changes.
$saved = parent::process_admin_options();
if ( ! $saved ) {
	// Nothing was changed.
	return false;
}

// Reload settings after save.
$this->init_settings();

$is_enabled  = ( 'yes' === $this->get_option( 'enabled', 'no' ) );
$key_changed = ( (string) $this->get_option( 'apikey', '' ) !== $old_apikey );

// Validate the API key when enabling the gateway (no -> yes) or when the key
// changed while the gateway is enabled. The card gateway drops and recreates
// the SQL tables whenever the shop ID changes (see
// WC_Gateway_Scanpay_Card::process_admin_options()), so the new key must be
// confirmed to work before that destructive step runs.
if ( ! $is_enabled || ( $was_enabled && ! $key_changed ) ) {
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
	// Invalid key: force-disable the gateway (keeping the entered settings) and
	// flag the failure so the card gateway skips the table drop/recreate.
	$this->settings['enabled']    = 'no';
	$this->scanpay_apikey_invalid = true;
	update_option( $this->get_option_key(), $this->settings );
	WC_Admin_Settings::add_error(
		__( 'Error: Invalid Scanpay API key. Please check your key and try again.', 'scanpay-for-woocommerce' )
	);
}
return true;
