=== Scanpay for WooCommerce ===
Contributors: scanpay
Tags: ecommerce, scanpay, woocommerce
Requires at least: 4.0
Tested up to: 4.6.1
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

== Changelog ==

= 0.01 =
Initial version.

= 0.02 =
Fixes.

= 0.03 =
Sku field now a string.

= 0.04 =
Autocapture option added. Improved error reporting.

= 0.05 =
Added Scanpay Details panel to orders that have been processed by Scanpay.

= 0.06 =
Cart now emptied only after payment is complete (before it was emptied at payment redirect).
Item stocks now reduced upon first ping rather than on redirect.

= 0.07 =
Fixed debug log warnings.

= 0.09 =
Added MobilePay support.
