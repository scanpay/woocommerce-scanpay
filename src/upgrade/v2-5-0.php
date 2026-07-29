<?php

/**
 * Migration to 2.5.0, run on any shop below it.
 *
 * What changed: 2.5.0 turned the 'capture_on_complete' checkbox into the 'wc_autocapture'
 * dropdown, which has a third state the checkbox had no room for -- capture immediately.
 *
 * Where the input comes from: the stored settings option. On a 1.x shop the 2.0.0 migration
 * ran first and left 'capture_on_complete' there for exactly this step; on a 2.0.x..2.4.x shop
 * it is the merchant's own checkbox. A shop already on 2.5.0 or later never gets here.
 *
 * What a re-run must tolerate: its own output. An upgrade interrupted further down retries
 * this file with 'capture_on_complete' already unset, and the isset() guard below is what
 * keeps that retry from deriving 'off' over the value the first run computed.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// An absent or scalar option is an offset TypeError, not a skipped step; see v2-0-0.php.
$settings = get_option( WC_SCANPAY_URI_SETTINGS );
if ( ! is_array( $settings ) ) {
	$settings = [];
}

// The one migrated key that must be written rather than left to a default: an absent
// wc_autocapture reads as 'completed' in generate-payment-link.php but as off in the order
// screens.
if ( ! isset( $settings['wc_autocapture'] ) ) {
	$settings['wc_autocapture'] = ( 'yes' === ( $settings['capture_on_complete'] ?? '' ) ) ? 'completed' : 'off';
}
unset( $settings['capture_on_complete'] );

update_option( WC_SCANPAY_URI_SETTINGS, $settings, true );
