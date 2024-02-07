=== Scanpay for WooCommerce ===
Contributors: scanpay
Tags: ecommerce, e-commerce, woocommerce, payments, subscriptions
Requires at least: 6.3
Requires PHP: 7.4
Tested up to: 6.4.3
Stable tag: 2.0.11
License: MIT License
License URI: https://opensource.org/licenses/MIT

Accept payments in WooCommerce with a reliable and secure Scandinavian payment gateway.

== Description ==

With this plugin, you can quickly and easily accept payments in WooCommerce through Scanpay, a reliable and secure Scandinavian payment gateway.

**Update 2024-02:** The plugin has received a complete rewrite. It is now more user-friendly, it uses fewer resources, and we have added support for new features and payment methods.

= Supported payment methods =
* Dankort, Visa, Mastercard and Maestro
* JCB, American Express and Diners
* MobilePay and Apple Pay

= Supported options =
* Auto-capture when order status is _'Completed'_
* Auto-complete orders after payment

= Supported plugins =
* WooCommerce Subscriptions
* WooCommerce Blocks
* WooCommerce HPOS

== Installation ==

You can create a scanpay account [here](https://scanpay.dk/opret). Please follow the steps in the [installation and configuration guide](https://github.com/scanpay/woocommerce-scanpay#scanpay-for-woocommerce).

== Frequently Asked Questions ==
= Which countries does this payment gateway support? =
Available for merchants in all european countries.

= Where can I find the instructions? =
Please follow the [installation and configuration guide](https://github.com/scanpay/woocommerce-scanpay#scanpay-for-woocommerce).


== Changelog ==

= 2.0.7 =
Bugfix: typo in plugins.php caused TypeError in PHP 7.4

= 2.0.3 =
Bugfix: Only show meta boxes in scanpay orders.
Bugfix: Refund button disappearing
Add warning if order total does not match net payment (more warnings will come soon)

= 2.0.1 =
Add CSS height to card icons

= 2.0.0 =
Complete rewrite with many improvements

= 1.3.15 =
Fixed crash if autocapture was empty.

= 1.3.14 =
Refined autocapture option.

= 1.3.13 =
Refined trim method used to compare totals.

= 1.3.12 =
No longer requires php bcmath (bccomp requires it).

= 1.3.11 =
Fixed more total mismatch cases.

= 1.3.10 =
Fixed total mismatch being triggered sometimes when it should not.

= 1.3.9 =
Added debug info for total mismatch cases.

= 1.3.8 =
No longer errors on negative items. Instead replaces all items with 'Discounted cart'.

= 1.3.7 =
Capture on complete: Try transaction id stored in scanpay table first.

= 1.3.6 =
Added payid link.

= 1.3.5 =
Added shopid to dashboard pingurl configuration url

= 1.3.4 =
Made it possible to change payment method for subscriptions with no scanpay subscriber id registered.

= 1.3.3 =
Fixed subscription bug.

= 1.3.2 =
Fixed scanpay details not being saved, if order status was changed manually before seq.

= 1.3.1 =
Fixed send ping in module settings.

= 1.3.0 =
Added support for card icons in checkout.
Improved plugin settings and setup guide.

= 1.2.4 =
Fixed payment method change upon failed subscriptions

= 1.2.3 =
Fixed an issue where Scanpay details sometimes would not show.

= 1.2.2 =
Fixed switch statement warning.

= 1.2.1 =
Fixed bug causing "time since last pingurl" to display wrongly in settings.

= 1.2.0 =
Added support for capture on complete.

= 1.1.1 =
Fixed scanpay client header merge.
Fixed plugin load order.

= 1.1.0 =
Added BETA support for subscriptions. Requires the use of the WooCommerce Subscriptions plugin.
Added support for autocompletion (including autocapture) of orders with virtual goods.

= 1.0.10 =
Fixed some cases where the code added in 1.0.9 would kick in when it should not.

= 1.0.9 =
Added support for hooks changing total. This will make coupon plugins that modifies the grand total also change the actualy paid amount.

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
