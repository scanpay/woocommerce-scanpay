=== Scanpay for WooCommerce ===
Contributors: scanpay
Tags: woocommerce, payments, subscriptions, scanpay, mobilepay
Requires at least: 4.7.0
Requires PHP: 7.4
Tested up to: {{ WP_VERSION_TESTED }}
Stable tag: {{ VERSION }}
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept payments in WooCommerce with a reliable and secure Scandinavian payment gateway.

== Description ==

Easily accept payments in WooCommerce with [Scanpay](https://scanpay.dk), a secure and reliable Scandinavian payment gateway.

This official payment plugin is developed, maintained, and supported by Scanpay. Follow or contribute to its development on [GitHub](https://github.com/scanpay/woocommerce-scanpay).

## Features

* Accepts Dankort, Visa, Mastercard, JCB, Amex, Diners, and more
* Supports MobilePay and Apple Pay
* Full compatibility with WooCommerce Subscriptions
* Option for automatic payment capture
* Option to auto-complete orders
* Supports HPOS and WooCommerce Blocks
* Optimized, lightweight, and thoroughly tested
* Dedicated support via email, phone, and [IRC](https://chat.scanpay.dev/)

### Why Choose Scanpay?

#### Acquirer-Agnostic Flexibility

Scanpay is a neutral, acquirer-agnostic payment gateway that connects to multiple acquiring banks — meaning you're never locked into a single acquirer. This flexibility allows you to optimize your acquiring costs and set up a multi-acquirer configuration with automatic failover, ensuring uninterrupted payment processing and greater reliability.

#### Transparent, Low-Cost Pricing

No setup fees, no monthly fees, and no hidden charges. You only pay 0.25 DKK (~€0.034) per transaction.

#### Security by Design

Our platform is built in C with a security-first approach, prioritizing a small, auditable codebase for maximum security, efficiency, and stability.

#### Performance

Scanpay is engineered for speed and scalability, handling millions of transactions with ease. We take pride in delivering one of the most performant payment platforms available today.

== Installation ==

**Installation guide:**

1. Log in to your WordPress dashboard and navigate to `Plugins > Add New`. Search for *"Scanpay for WooCommerce"* and install the plugin.

2. Click *"Activate"* to activate the plugin.

3. Navigate to `WooCommerce > Settings > Payments` to manage your WooCommerce payment settings. Find the *"Scanpay"* option and click *"Set up"* to open the configuration page.

4. Insert your Scanpay API key in the *"API key"* field and click *"Save changes"*. You can generate an API key [here](https://dashboard.scanpay.dk/settings/api).

5. A yellow box will appear at the top of the page. Click *"Send ping"* to initiate the synchronization process.

6. In your Scanpay dashboard, save the *"Ping URL"*. Scanpay will then perform an initial synchronization and show the result.

7. Your WooCommerce store is now connected and synchronized with Scanpay. When ready, change the status from *"Disabled"* to *"Enabled"* to activate the payment gateway in your checkout.

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
Yes, this plugin supports MobilePay. You must enable MobilePay in both the plugin and our dashboard.

= How do I contact support? =
You can e-mail us at support@scanpay.dk, call us at +45 32727232 or chat with us on IRC.

== Changelog ==
= 2.9.0 - 2025-02-04 =
* Add - Add localization
* Add - Add danish localization

= 2.8.0 - 2025-02-03 =
* Fix - Fix issue with WCS coupons

= 2.7.0 - 2024-12-06 =
* Fix - Fix currency issue in admin meta box
* Fix - Add missing whitespace to out-of-sync warning

= 2.6.2 - 2024-09-24 =
* Fix - Fix issue with variable subscriptions

= 2.6.1 - 2024-09-14 =
* Update - Display plugin version warning only if a newer version is available.

= 2.6.0 - 2024-09-14 =
* Fix - Resolved an issue where an HPOS incompatibility warning was incorrectly triggered.
* Fix - Fixed misleading warning message in settings before the first successful ping is received.
* Update - Refactored JavaScript to TypeScript for improved maintainability and code quality.
* Performance - Minified CSS and JavaScript files.

= 2.5.1 - 2024-08-16 =
* Fix - Undefined variable in subscription charge (since 2.5.0)

= 2.5.0 - 2024-08-15 =
* Update - Refactor auto-capture settings to use dropdown instead of checkbox
* Tweak - Trim server error messages
* Performance - Save needs_processing transient (WCS)

[See changelog for all versions](https://raw.githubusercontent.com/scanpay/woocommerce-scanpay/master/changelog.txt).
