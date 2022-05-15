# Scanpay for WooCommerce

WooCommerce is an open-source e-commerce plugin for WordPress. 
Scanpay has developed a payment plugin for [WooCommerce](https://woocommerce.com/), that allows you to accept payments on your WooCommerce shop by using Scanpay's [API](https://docs.scanpay.dk/) key. Scanpay's plugin is compatible with WooCommerce Subscriptions.


Follow the description below for a successful instalment and configuration in your WordPress dashboard. For support in the process, forward an e-mail to [help@scanpay.dk](mailto:help@scanpay.dk) or start a chat on IRC at libera.chat #scanpay ([webchat](https://web.libera.chat/#scanpay)).

<br>

## Installation

1. Install the plugin directly from your WordPress dashboard. Log in to your WordPress dashboard and navigate to `Plugins > Add New`. Search for *"scanpay"* and install the following plugin:

<img src="https://docs.scanpay.dk/img/woocommerce/install-scanpay.png?1" width="700" alt="Install Scanpay for WooCommerce">


2. Activate the plugin after the instalment for further configuration. You can also navigate to `Plugins > Installed Plugins` and activate the *"Scanpay for WooCommerce"* plugin under this setting.
<br>


## Configuration
<br>
 
Navigate to `WooCommerce > Settings > Payments` to manage your WooCommerce payment settings. Find the *"Scanpay"* option and click *"Set up"* to open the Scanpay configuration page. Save and use this page throughout the configuration:

<br>

<img src="https://docs.scanpay.dk/img/woocommerce/plugin-configuration.png?v1" width="800" alt="Configuration of Scanpay plugin for WooCommerce">

1. Ensure that you have **NOT** enabled Scanpay for WooCommerce before the configuration is completed.
2. Generate an API key in Scanpay's dashboard [here](https://dashboard.scanpay.dk/settings/api). Make sure to keep the API key private and secure. 
3. Copy the key and return to the scanpay configuration page in the WordPress dashboard. 
4. Insert your Scanpay API key in the *"API key"* field and click *"Save changes"*.
5. Click the *"send ping"* button at the top of the configuration page. Click *"save"*, check validation and close the window. Scanpay's dashboard will automatically configure your ping URL.
6. You have now completed the installation and configuration of our WooCommerce plugin. Remember to enable Scanpay for Woocommerce to accept payments. We recommend performing a test order to ensure that everything is working as intended.
<br>

## Additional information 

**How to enable MobilePay in Scanpay and WooCommerce**

Enable MobilePay Online in Scanpay's dashboard by following this [link](https://dashboard.scanpay.dk/settings/acquirers). Then open WooCommerce in your WordPress dashboard and navigate to `WooCommerce > Settings > Payments` to enable *"MobilePay (Scanpay)"*.

<br>

**How to enable WooCommerce Subscriptions**

Scanpay's plugin is compatible with [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/). 
To enable WooCommerce Subscriptions navigate to `WooCommerce > Settings > Payments`. Find the *"Scanpay"* option and click *"Set up"* to open the Scanpay configuration page. Go to the bottom at the page and enable the WooCommerce Subscription. 

<img src="https://docs.scanpay.dk/img/woocommerce/subscriptions.png" width="700" alt="Enable WooCommerce Subscriptions for Scanpay">
