<?php

/**
 * Process, validate and save admin options.
 *
 * Included from WC_Gateway_Scanpay_Base::process_admin_options().
 *
 * @return bool
 */

defined( 'ABSPATH' ) || exit();

// Only the card gateway has an 'apikey' field; the key it stores is shared by all
// three. Reading it off MobilePay/Apple Pay would plant a phantom one: WC's
// get_option() injects any missing key into $this->settings as a side effect, and
// the error branch below writes that array back verbatim. $key_changed is also
// structurally always false for those gateways.
$is_card = ( 'scanpay' === $this->id );

// Capture pre-save state so we can detect enable-transitions and key changes.
$was_enabled = ( 'yes' === $this->get_option( 'enabled', 'no' ) );
$old_apikey  = $is_card ? (string) $this->get_option( 'apikey', '' ) : '';

// Save changes.
$saved = parent::process_admin_options();
if ( ! $saved ) {
	// Nothing was changed.
	return false;
}

// Reload settings after save.
$this->init_settings();

$is_enabled  = ( 'yes' === $this->get_option( 'enabled', 'no' ) );
$key_changed = $is_card && ( (string) $this->get_option( 'apikey', '' ) !== $old_apikey );

// Validate the API key when enabling the gateway (no -> yes) or when a key is
// first set while the gateway is enabled. This is a UX check only: it tells the
// merchant the key does not work and force-disables the gateway. Nothing
// destructive hangs off the result -- data is only ever deleted by the reset
// button, so a skipped check here cannot cost anything.
if ( ! $is_enabled || ( $was_enabled && ! $key_changed ) ) {
	return true;
}

try {
	// All gateways require a valid API key to function.
	// Let's do a simple API call to verify the key.
	$primary = get_option( WC_SCANPAY_URI_SETTINGS, [] );
	require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
	$client = new WC_Scanpay_Client( $primary['apikey'] ?? '' );
	$client->seq( 0 );
} catch ( Exception $e ) {
	// Invalid key: force-disable the gateway, keeping the entered settings.
	$this->settings['enabled'] = 'no';
	/*
	 * Drop the key we just stored, so generate_apikey_html() renders the input again
	 * and the merchant can do what the error below tells them to. Otherwise the field
	 * switches to its masked, input-less form and a one-character typo can only be
	 * undone through the reset button, whose copy is about deleting data.
	 *
	 * Only when the key changed in this save: an already-stored, working key must
	 * survive a transient failure of the check above. The card gateway also reads the
	 * key back after this to decide whether to seed the tables, so clearing it here
	 * keeps install.php from seeding a shop row for a key that never validated.
	 */
	if ( $key_changed ) {
		$this->settings['apikey'] = '';
	}
	update_option( $this->get_option_key(), $this->settings );
	WC_Admin_Settings::add_error(
		__( 'Error: Invalid Scanpay API key. Please check your key and try again.', 'scanpay-for-woocommerce' )
	);
}
return true;
