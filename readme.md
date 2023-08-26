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

<img src="https://docs.scanpay.dev/img/woocommerce/subscriptions.png" width="700" height="126" alt="Enable WooCommerce Subscriptions for Scanpay">


## Compatibility table

| PHP Features                              | Version |
| :---------------------------------------- | :-----: |
| WooCommerce (8.0)                         | **7.3** |
| Nullable Types                            | 7.1     |
| Void return type                          | 7.1     |
| WordPress (6.3)                           | 7.0     |
| Return type declarations                  | 7.0     |
| Null coalescing operator                  | 7.0     |
| hash_equals                               | 5.6     |
| curl_strerror                             | 5.5     |
| Array, short syntax                       | 5.4     |
| Namespaces                                | 5.3.0   |
| json_decode                               | 5.2.0   |
| curl_setopt_array                         | 5.1.3   |
| hash_hmac                                 | 5.1.2   |
| Exception class                           | 5.1.0   |
| Default function parameters               | 5.0.0   |


| WooCommerce                               | Version  |
| :---------------------------------------- | :------: |
| wc_get_page_screen_id                     | **6.9.0**|
| get_country_calling_code                  | 3.6.0    |
| wc_nocache_headers                        | 3.2.4    |
| WC:api_request_url                        | 3.2.0    |
| WC_Order_Item                             | 3.0.0    |
| WC_Order:save                             | 3.0.0    |
| WC_Order:*                                | 2.7.0    |
| WC_Data:get_meta                          | 2.6.0    |
| wc_set_time_limit                         | 2.6.0    |
| wc_get_log_file_path                      | 2.2.0    |
| WC_Payment_Gateway                        | 2.1.0    |


| WordPress                                 | Version  |
| :---------------------------------------- | :------: |
| WooCommerce (8.0)                         | **6.2.0**|
| wp_send_json                              | 4.7.0    |
| wp_send_json_success                      | 3.5.0    |
| current_user_can                          | 2.0.0    |
| get_option                                | 1.5.0    |


## License

Everything in this repository is licensed under the [MIT license](LICENSE).



