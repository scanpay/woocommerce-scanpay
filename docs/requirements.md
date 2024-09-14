# Requirements

This document outlines the requirements and dependencies necessary for the successful installation and operation of our WooCommerce plugin.

Features marked with ~~strikethrough~~ are managed using polyfills or other mitigations.

## PHP compatibility table

WordPress and WooCommerce require PHP 7.4, so we have aligned our requirements accordingly. However, PHP 7.4 reached its End-Of-Life on November 28, 2022. Therefore, we are considering upgrading our requirement to PHP 8.0 in the near future.

| PHP Features             | Version |
| :----------------------- | :-----: |
| ~~str_starts_with()~~    |   8.0   |
| ~~str_ends_with()~~      |   8.0   |
| WooCommerce (9.1.4)      | **7.4** |
| WordPress (6.6.2)        |   7.4   |
| Array Spread operator    |   7.4   |
| Typed class properties   |   7.4   |
| Nullable Types           |   7.1   |
| Void return type         |   7.1   |
| Return type declarations |   7.0   |
| Null coalescing operator |   7.0   |

## WooCommerce compatibility table

The plugin requires WooCommerce version 3.6.0 or higher, released in April 2019. We use the `get_country_calling_code()` function to prefix phone numbers with country codes, ensuring compatibility with MobilePay Online.

| WooCommerce                            |  Version  |
| :------------------------------------- | :-------: |
| get_country_calling_code               | **3.6.0** |
| WC:api_request_url                     |   3.2.0   |
| WC_Order:needs_processing              |   3.0.0   |
| WC_Order_Item                          |   3.0.0   |
| WC_Order:save                          |   3.0.0   |
| WC_Order:set_status                    |   3.0.0   |
| WC_Order:update_status                 |   3.0.0   |
| WC_Order:get\*\*                       |   2.6.0   |
| WC_Data:add_meta_data                  |   2.6.0   |
| WC_Data:save_meta_data                 |   2.6.0   |
| WC_Data:get_meta                       |   2.6.0   |
| wc_get_orders                          |   2.6.0   |
| wc_get_order                           |   2.2.0   |
| WC_Order:add_order_note                |   2.2.0   |
| WC_Payment_Gateway                     |   2.1.0   |
| wc_price                               |   1.0.0   |
| WC_Settings_API:generate_settings_html |   1.0.0   |
| WC_Settings_API:get_form_fields        |   1.0.0   |

## WordPress compatibility table

We use `wp_enqueue_script()` with the `defer` attribute, which officially requires WordPress version 6.3.0. However, this feature is backwards compatible with version 2.1.0 because the `$in_footer` parameter, originally a boolean, was overloaded to accept an `$args` parameter of type array.

| WordPress                     |  Version  |
| :---------------------------- | :-------: |
| WooCommerce 3.6.0             | **4.7.0** |
| wp_send_json                  |   4.7.0   |
| wp_send_json_success          |   3.5.0   |
| wc_back_link                  |   3.3.0   |
| wp_kses_post                  |   2.9.0   |
| set_transient & get_transient |   2.8.0   |
| wp_enqueue_style              |   2.6.0   |
| admin_url                     |   2.6.0   |
| add_meta_box                  |   2.5.0   |
| get_temp_dir                  |   2.5.0   |
| wp_safe_redirect              |   2.3.0   |
| wp_enqueue_script             |   2.1.0   |
| wp_cache_flush                |   2.0.0   |
| current_user_can              |   2.0.0   |
| nocache_headers               |   2.0.0   |
| get_option                    |   1.5.0   |
| add_query_arg                 |   1.5.0   |
| add_filter & add_action       |   1.2.0   |
| check_admin_referer           |   1.2.0   |
| wpdb->\*                      |   0.71    |
| wpautop                       |   0.71    |

## libcurl compatibility table

We previously used `CURLOPT_DNS_SHUFFLE_ADDRESSES` (7.60.0), but this is not a requirement at the moment.

| libcurl               | Version |
| :-------------------- | :-----: |
| CURLOPT_TCP_KEEPALIVE | 7.25.0  |

## WooCommerce Subscriptions (optional)

We have full support for [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/).

| WooCommerce Subscriptions       | Version |
| :------------------------------ | :-----: |
| wcs_get_subscription            |  1.0.0  |
| wcs_get_subscriptions_for_order |  1.0.0  |
| wcs_order_contains_renewal      |  1.0.0  |
| wcs_order_contains_subscription |  1.0.0  |
