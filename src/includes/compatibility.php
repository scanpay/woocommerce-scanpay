<?php

/*
 *  Scanpay compatibility checker.
 *  Used in settings to warn admins of impending doom!
 */

defined( 'ABSPATH' ) || exit();

function wc_scanpay_check_plugin_requirements(): ?string {
	// Check PHP version
	if ( version_compare( WC_SCANPAY_MIN_PHP, PHP_VERSION ) >= 0 ) {
		return sprintf(
			'requires PHP version <b>%s</b> or higher,
			but your PHP version is <b>%s</b>. Please update PHP.',
			WC_SCANPAY_MIN_PHP,
			PHP_VERSION
		);
	}

	// Check PHP extensions
	$arr = [ 'curl' ];
	foreach ( $arr as $extension ) {
		if ( ! extension_loaded( $extension ) ) {
			return "requires <b>php-$extension</b>. Please install this PHP extension.";
		}
	}

	// Check if WooCommerce is active
	if ( ! function_exists( 'WC' ) ) {
		return 'requires <b><u>WooCommerce</u></b>. Please install and activate WooCommerce.';
	}

	// Check WooCommerce version
	$wc_version = WC()->version ?? '0.0.0';
	if ( version_compare( WC_SCANPAY_MIN_WC, $wc_version ) >= 0 ) {
		return sprintf(
			'requires WooCommerce version <b>%s</b> or higher, but
			your WooCommerce version is <b>%s</b>. Please update WooCommerce.',
			WC_SCANPAY_MIN_WC,
			$wc_version
		);
	}
	return null;
}

add_action(
	'admin_notices',
	function () {
		$err = wc_scanpay_check_plugin_requirements();
		if ( isset( $err ) ) {
			printf(
				'<div class="error">
				<p><b>WARNING</b>: <i>Scanpay for WooCommerce</i> %s</p>
			</div>',
				$err
			);
		}
	},
	0
);
