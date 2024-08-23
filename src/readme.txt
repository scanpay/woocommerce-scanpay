=== Scanpay for WooCommerce ===
Contributors: scanpay
Tags: woocommerce, payments, subscriptions, scanpay, mobilepay
Requires at least: 4.7.0
Requires PHP: 7.4
Tested up to: 6.6.2
Stable tag: 2.5.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments in WooCommerce with a reliable and secure Scandinavian payment gateway.

== Description ==

With this plugin, you can quickly and easily accept payments in WooCommerce through [Scanpay](https://scanpay.dk), a reliable and secure Scandinavian payment gateway.

This is an official payment plugin developed, maintained and supported by Scanpay. Feel free to follow or contribute to the development on [GitHub](https://github.com/scanpay/woocommerce-scanpay).

## Features

* Dankort, Visa, Mastercard, JCB, Amex, Diners, et. al.
* MobilePay, Apple Pay and soon Google Pay _(Q4-2024)_
* Full WooCommerce Subscriptions support
* Option to automatically capture payments
* Option to auto-complete orders
* HPOS and WooCommerce Blocks support
* An optimized, bloat-free and thoroughly tested plugin
* E-mail, phone and [IRC](https://chat.scanpay.dev/) support

### Why choose Scanpay?

#### Acquirer agnostic

Scanpay is a neutral and acquirer-agnostic payment gateway with connections to several acquiring banks. With our platform, you can have multiple acquirers and easily add or remove an acquirer. This allows you to obtain the best prices in the market and acquirer failover _(redundancy)_.

#### Low pricing

We do not charge any setup, monthly or hidden fees. Our only fee is a transaction fee of 0.25 DKK (~ €0.034).

#### Security

Our platform is programmed in C and designed with a security-by-design approach, making security considerations the core of our engineering process. The emphasis has been on creating a secure, stable, efficient platform with a small and auditable codebase.

#### Performance

We believe we have created the most performant payment platform in the world. The platform is battle-tested and capable of handling millions of transactions. As engineers, this is something we are genuinely proud of.


== Installation ==

**Installation guide:**

1. Log in to your WordPress dashboard and navigate to `Plugins > Add New`. Search for *"Scanpay for WooCommerce"* and install the plugin.

2. Click *"Activate"* to activate the plugin.

3. Navigate to `WooCommerce > Settings > Payments` to manage your WooCommerce payment settings. Find the *"Scanpay"* option and click *"Set up"* to open the configuration page.

4. Insert your Scanpay API key in the *"API key"* field and click *"Save changes"*. You can generate an API key [here](https://dashboard.scanpay.dk/settings/api).

5. A yellow box should appear at the top of the page. Click *"Send ping"* to initiate the synchronization process.

6. Now, in the scanpay dashboard, save the *"ping URL"*. The system will perform an initial synchronization and show you the result.

7. Your WooCommerce store is now connected and synchronized with Scanpay. When ready, change the status from *"Disabled"* to *"Enabled"* to enable the extension in your checkout.


**Update the plugin**

1. Navigate to `Plugins > Installed plugins`.

2. Find *"Scanpay for WooCommerce"* in the list and click *"Update"*.


**Uninstall the plugin**

1. Navigate to `Plugins > Installed plugins`.

2. Find *"Scanpay for WooCommerce"* in the list.

3. Click *"Deactivate"* and then *"Delete"*.

4. The plugin will now uninstall itself and remove all stored data.


== Frequently Asked Questions ==

= Which countries does this payment gateway support? =
Available for merchants in all European countries.

= Do you support MobilePay? =
Yes, this plugin supports MobilePay. You must enable MobilePay in both the plugin and the [dashboard](https://dashboard.scanpay.dk).

= How do I contact support? =
You can e-mail us at support@scanpay.dk, call us at +45 32727232 or chat with us on [IRC](https://irc.scanpay.dev/).

== Changelog ==

= 2.5.1 - 2024-08-16 =
* Fix - Undefined variable in subscription charge (since 2.5.0)

= 2.5.0 - 2024-08-15 =
* Update - Refactor auto-capture settings to use dropdown instead of checkbox
* Tweak - Trim server error messages
* Performance - Save needs_processing transient (WCS)

= 2.4.1 - 2024-05-31 =
* Add - Add Forbrugsforeningen (payment method)

= 2.4.0 - 2024-05-13 =
* Add - Add payment details on thankyou page
* Add - Add lifetime parameter to payment links
* Fix - Avoid unnecessary wp_cache_flush in synchronization
* Fix - Optimize and tune thankyou page loading

= 2.3.0 - 2024-05-10 =
* Add - Add support for Initial Charge in WooCommerce Subscriptions
* Add - Add support for free trials in WooCommerce Subscriptions
* Add - Add WordPress plugin dependency header
* Fix - Refactor `thankyou` page to be much faster and use fewer resources
* Fix - Refactor WooCommerce Subscriptions (optimization)
* Fix - Various optimizations and smaller changes

= 2.2.2 - 2024-04-23 =
* Add - Add extra ToS checkbox for Subscriptions (optional)
* Fix - Minor fixes

= 2.2.1 - 2024-03-17 =
* Add - Add support for old versions of WooCommerce
* Fix - Improve some error messages
* Fix - Minor optimizations

= 2.2.0 - 2024-03-13 =
* Add - Add bulk action: Capture and Complete
* Add - WooCommerce Subscriptions retry failed charges
* Fix - 'Capture on Complete' now tries to capture before the order status changes (i.e. before e-mails)
* Fix - Various performance optimizations
* Fix - Fix PHP warnings

= 2.1.4 - 2024-03-02 =
* Add - Add default values to all options
* Fix - Fix PHP warning

= 2.1.3 - 2024-03-01 =
* Add - Improve WCS redirect by adding payment window to allowed_redirect_hosts
* Fix - Fix Woo Subscriptions bug caused by our old plugin (<= 1.3.15)

= 2.1.2 - 2024-03-01 =
* Fix - Fix missing type cast.

= 2.1.1 - 2024-02-21 =
* Fix - Fix conflict when multiple payments have the same orderid

= 2.1.0 - 2024-02-20 =
* Add - Add support for all WooCommerce Subscription features
* Add - Add option to auto-complete virtual orders
* Add - Send Subscription items to the scanpay dashboard
* Fix - Display metaboxes in shops without HPOS.
* Fix - Use preemptive synchronization after capture and charge.
* Fix - Performance optimizations

= 2.0.7 =
* Fix - Fix TypeError in plugins.php (PHP 7.4 only)

= 2.0.3 =
* Fix - Only show meta boxes in scanpay orders.
* Fix - Refund button disappearing when it should not.
* Add - Add warning if order total does not match net payment.

= 2.0.1 =
* Add - Add CSS height to card icons

[See changelog for all versions](https://raw.githubusercontent.com/scanpay/woocommerce-scanpay/master/changelog.txt).
