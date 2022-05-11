# Scanpay for WooCommerce
We have developed a payment plugin for [WooCommerce](https://woocommerce.com/), that allows you to accept payments on your WooCommerce store via our [API](https://docs.scanpay.dk/). 
WooCommerce is an open-source e-commerce plugin for WordPress. Our plugin is compatible with WooCommerce Subscriptions.

You can always e-mail us at [help@scanpay.dk](mailto:help@scanpay.dk) or chat with us on IRC at libera.chat #scanpay ([webchat](https://web.libera.chat/#scanpay)).

## Installation
You can install the plugin directly from your WordPress. Log in to your WordPress admin area and navigate to `Plugins > Add New`. Search for *"scanpay"* and install the following plugin:

![Scanpay for WooCommerce](https://docs.scanpay.dk/img/woocommerce/install-scanpay.png)

### Configuration
Before you begin, you need to generate an API key in our dashboard ([here](https://dashboard.scanpay.dk/settings/api)). Always keep your API key private and secure.

1. Navigate to `Plugins > Installed Plugins` and activate the *"Scanpay for WooCommerce"* plugin.
2. Navigate to `WooCommerce > Settings > Payments`. Here you manage your WooCommerce payment settings. Find the *"Scanpay"* option and click *"Set up"* to open the Scanpay configuration page.
3. Insert your Scanpay API key in the *"API key"* field and click *"Save changes"*.
4. Click the *"send ping"* button at the top of the configuration page. Follow the instructions, and our dashboard will automatically configure your ping endpoint.
5. You have now completed the installation and configuration of our WooCommerce plugin. We recommend performing a test order to ensure that everything is working as intended.

## Frequently asked questions

**How to enable MobilePay Online?**\
First, you need to enable MobilePay Online in our dashboard ([link](https://dashboard.scanpay.dk/settings/acquirers)). Then open WooCommerce and navigate to `WooCommerce > Settings > Payments` and enable *"MobilePay (Scanpay)"*.

**Does this plugin support WooCommerce Subscriptions?**\
Yes. The plugin is compatible with [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/).
