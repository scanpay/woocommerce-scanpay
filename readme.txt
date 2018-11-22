=== Scanpay for WooCommerce ===
Contributors: scanpay
Tags: ecommerce, scanpay, woocommerce
Requires at least: 4.0
Tested up to: 4.9.8
Stable tag: trunk
License: MIT License
License URI: https://opensource.org/licenses/MIT

This plugin adds Scanpay as a checkout payment method.

== Description ==
Scanpay is a new innovative payment gateway with a focus on conversion and security.
This plugin allows you to integrate with Scanpay without writing a single line of code yourself.
Note that you must capture and refund transactions in the Scanpay dashboard.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/woocommerce-scanpay` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->Plugin Name screen to configure the plugin with a valid API-key.
4. Copy the ping URL from the Scanpay plugin settings to your Scanpay dasboard API settings.

== Changelog ==

= 1.0.8 =
Added tax to item fees.

= 1.0.7 =
Verified to work with woocommerce 3.5.0.

= 1.0.6 =
Updated supported wordpress/woocommerce versions.

= 1.0.5 =
Added new ping endpoint to remove slashes, kept support for old one.

= 1.0.4 =
Item fees are now considered.

= 1.0.3 =
Added 'woocommerce_scanpay_newurl_data' filter to allow plugins to customize payment link parameters.

= 1.0.2 =
No longer exits if woocommerce is disabled.

= 1.0.0 =
Updated version compatibility.

= 0.12 =
Fixed double stock reduction.

= 0.11 =
Now uses item line total instead of item price for Scanpay API.

= 0.10 =
Fixed double Scanpay order display.

= 0.09 =
Added MobilePay support.

= 0.07 =
Fixed debug log warnings.

= 0.06 =
Cart now emptied only after payment is complete (before it was emptied at payment redirect).
Item stocks now reduced upon first ping rather than on redirect.

= 0.05 =
Added Scanpay Details panel to orders that have been processed by Scanpay.

= 0.04 =
Autocapture option added. Improved error reporting.

= 0.03 =
Sku field now a string.

= 0.02 =
Fixes.

= 0.01 =
Initial version.
