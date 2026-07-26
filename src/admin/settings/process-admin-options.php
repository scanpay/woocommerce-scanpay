<?php

/**
 * Process, validate and save admin options.
 *
 * Included from WC_Gateway_Scanpay_Base::process_admin_options().
 *
 * @return bool
 */

declare(strict_types=1);

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

$saved = parent::process_admin_options();
if ( ! $saved ) {
	return false; // update_option() saved nothing: the posted settings are unchanged.
}
$this->init_settings();

$is_enabled  = ( 'yes' === $this->get_option( 'enabled', 'no' ) );
$key_changed = $is_card && ( (string) $this->get_option( 'apikey', '' ) !== $old_apikey );

/*
 * Validate whenever a key is newly stored -- enabled or not -- and when the
 * gateway goes disabled -> enabled with the key it already had.
 *
 * The enabled state must not gate a newly entered key: skipping the check while
 * disabled would let a well-shaped but invalid key store and mask itself, and by the
 * time the merchant enables the gateway $key_changed is false, so the failure branch
 * below would keep the dead key and only disable the gateway -- leaving the reset
 * button as the only way out.
 *
 * A UX check only: it tells the merchant the key does not work and force-disables the
 * gateway. Nothing destructive hangs off the result -- only the reset button deletes data.
 */
$should_validate = $key_changed || ( $is_enabled && ! $was_enabled );
if ( ! $should_validate ) {
	return true;
}

try {
	// Cheapest call that proves the key: seq(0) reads the change stream from the start
	// and changes nothing. The key is always the card gateway's, shared by all three.
	$primary = get_option( WC_SCANPAY_URI_SETTINGS, [] );
	require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
	$client = new WC_Scanpay_Client( (string) ( $primary['apikey'] ?? '' ) );
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
	 * keeps install.php from seeding a shop row for a key that never validated -- do
	 * not gate validation on the enabled state again without revisiting install.php's
	 * "0 !== $shopid" seq-row seeding.
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
