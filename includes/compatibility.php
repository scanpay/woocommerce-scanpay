<?php

/*
    Scanpay compatibility checker.
    Used in settings to warn admins of impending doom!
*/

defined('ABSPATH') || exit();

function wc_scanpay_check_plugin_requirements()
{
    // Check PHP version
    if (version_compare(WC_SCANPAY_MIN_PHP, PHP_VERSION) >= 0) {
        return sprintf(
            'requires PHP version <b>%s</b> or higher,
            but your PHP version is <b>%s</b>. Please update PHP.',
            WC_SCANPAY_MIN_PHP,
            PHP_VERSION
        );
    }

    // Check PHP extensions (might not be needed anymore)
    $arr = ['curl'];
    foreach ($arr as $extension) {
        if (!extension_loaded($extension)) {
            return "requires <b>php-$extension</b>. Please install this PHP extension.";
        }
    }

    // Check if WooCommerce is active
    if (!defined('WC_VERSION')) {
        return "requires <b><u>WooCommerce</u></b>. Please install and activate WooCommerce.";
    }

    // Check WooCommerce version
    if (version_compare(WC_SCANPAY_MIN_WC, WC_VERSION) >= 0) {
        return sprintf(
            'requires WooCommerce version <b>%s</b> or higher, but
            your WooCommerce version is <b>%s</b>. Please update WooCommerce.',
            WC_SCANPAY_MIN_WC,
            WC_VERSION
        );
    }

    return false;
}

add_action('admin_notices', function () {
    if ($error = wc_scanpay_check_plugin_requirements()) {
        printf(
            '<div class="error">
                <p><b>WARNING</b>: <i>Scanpay for WooCommerce</i> %s</p>
            </div>',
            $error
        );
    }
}, 0);
