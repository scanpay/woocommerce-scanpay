# Scanpay for WooCommerce

WooCommerce is an open-source e-commerce plugin for WordPress. At Scanpay, we have developed a payment plugin for [WooCommerce](https://woocommerce.com/), allowing you to accept payments in your WooCommerce store using Scanpay's payment platform. The plugin is compatible with WooCommerce Subscriptions.

Follow the description below for a successful installation and configuration. For support or questions, feel free to e-mail us at [support@scanpay.dk](mailto:support@scanpay.dk) or chat with us on IRC at irc.scanpay.dev:6697 ([webchat](https://irc.scanpay.dev)).

You can try a demo of the plugin at [woocommerce.scanpay.dev](https://woocommerce.scanpay.dev).

## Installation

1. Install the plugin directly from your WordPress dashboard. Log in to your WordPress dashboard and navigate to `Plugins > Add New`. Search for *"Scanpay for WooCommerce"* and install the following plugin:

<img src="https://docs.scanpay.dk/img/woocommerce/install-scanpay.png?1" width="700" height="234" alt="Install Scanpay for WooCommerce">

2. Activate the plugin after the installation for further configuration. You can also navigate to `Plugins > Installed Plugins` and activate the *"Scanpay for WooCommerce"* plugin under this setting.

## Configuration

Navigate to `WooCommerce > Settings > Payments` to manage your WooCommerce payment settings. Find the *"Scanpay"* option and click *"Set up"* to open the Scanpay configuration page. Save and use this page throughout the configuration:

<img src="https://docs.scanpay.dk/img/woocommerce/plugin-configuration.png?v1" width="700" height="368" alt="Configuration of Scanpay plugin for WooCommerce">

1. Ensure that you have **NOT** enabled *"Scanpay for WooCommerce"* before the configuration is completed.
2. Generate an API key in Scanpay's dashboard [here](https://dashboard.scanpay.dk/settings/api). Make sure to keep the API key private and secure.
3. Copy the key and return to the scanpay configuration page in the WordPress dashboard.
4. Insert your Scanpay API key in the *"API key"* field and click *"Save changes"*.
5. Click the *"Send ping"* button at the top of the configuration page. Click *"save"*, check validation and close the window. Scanpay's dashboard will automatically configure your ping URL.
6. You have now completed the installation and configuration of our WooCommerce plugin. Remember to enable *"Scanpay for Woocommerce"* when you are ready to accept payments through Scanpay. We recommend performing a test order to ensure that everything is working as intended.

## Frequently asked questions

**How to enable MobilePay in Scanpay and WooCommerce**

Enable MobilePay Online in Scanpay's dashboard by following this [link](https://dashboard.scanpay.dk/settings/acquirers). Then open WooCommerce in your WordPress dashboard and navigate to `WooCommerce > Settings > Payments` to enable *"MobilePay (Scanpay)"*.

**How to enable WooCommerce Subscriptions**

Scanpay's plugin is compatible with [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/).
To enable WooCommerce Subscriptions navigate to `WooCommerce > Settings > Payments`. Find the *"Scanpay"* option and click *"Set up"* to open the Scanpay configuration page. Go to the bottom at the page and enable WooCommerce Subscriptions.

<img src="https://docs.scanpay.dk/img/woocommerce/subscriptions.png" width="700" height="126" alt="Enable WooCommerce Subscriptions for Scanpay">


## Requirements

Null coalescing operator
