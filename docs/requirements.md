# Requirements

This document records the PHP, WordPress, WooCommerce and libcurl APIs the plugin
depends on, and the version each one was introduced in. It is the rationale for
the minimums declared in `package.json` — check it before reaching for a newer
API.

Versions come from the `@since` tags in the WordPress and WooCommerce source.
Features marked with ~~strikethrough~~ are managed using polyfills or other
mitigations.

## Declared minimums

| Requirement | Minimum | Tested up to |
| :---------- | :-----: | :----------: |
| PHP         |   8.0   |      —       |
| WordPress   |   6.3   |     7.0      |
| WooCommerce |   3.6   |     10.9     |

WooCommerce Subscriptions is optional; every entry point into it is guarded.

## PHP compatibility table

We require PHP 8.0. WordPress and WooCommerce both still run on 7.4, so this is
our own floor, not theirs: `str_starts_with()`, `str_ends_with()` and the
`CurlHandle` type all need 8.0, and PHP 7.4 reached End-Of-Life on 28 November
2022.

| PHP Features             | Version |
| :----------------------- | :-----: |
| str_starts_with()        | **8.0** |
| str_ends_with()          |   8.0   |
| CurlHandle type          |   8.0   |
| match expression         |   8.0   |
| WooCommerce (10.9)       |   7.4   |
| WordPress (7.0)          |   7.4   |
| Typed class properties   |   7.4   |
| JSON_THROW_ON_ERROR      |   7.3   |
| Nullable Types           |   7.1   |
| Void return type         |   7.1   |
| Return type declarations |   7.0   |
| Null coalescing operator |   7.0   |

## WooCommerce compatibility table

The plugin requires WooCommerce version 3.6.0 or higher, released in April 2019.
We use the `get_country_calling_code()` function to prefix phone numbers with
country codes, ensuring compatibility with MobilePay Online.

`WC_Order` and `WC_Data` accessors not listed below are all 3.0.0 or older.

| WooCommerce                            |  Version  |
| :------------------------------------- | :-------: |
| get_country_calling_code               | **3.6.0** |
| WC:api_request_url                     |   3.2.0   |
| wc_get_logger                          |   3.2.0   |
| wc_doing_it_wrong                      |   3.0.0   |
| WC_Order:needs_processing              |   3.0.0   |
| WC_Order_Item                          |   3.0.0   |
| WC_Order:save                          |   3.0.0   |
| WC_Order:set_status                    |   3.0.0   |
| WC_Order:update_status                 |   3.0.0   |
| WC_Order:is_paid                       |   3.0.0   |
| WC_Order:get\*\*                       |   2.6.0   |
| WC_Data:add_meta_data                  |   2.6.0   |
| WC_Data:save_meta_data                 |   2.6.0   |
| WC_Data:get_meta                       |   2.6.0   |
| wc_get_orders                          |   2.6.0   |
| wc_get_price_decimals                  |   2.3.0   |
| wc_get_order                           |   2.2.0   |
| WC_Order:add_order_note                |   2.2.0   |
| WC_Order:get_refunds                   |   2.2.0   |
| WC_Payment_Gateway                     |   2.1.0   |
| wc_format_decimal                      |   2.1.0   |
| WC_Settings_API:generate_settings_html |   1.0.0   |
| WC_Settings_API:get_form_fields        |   1.0.0   |

## Guarded WooCommerce APIs

These are all newer than 3.6.0. Each one sits behind a runtime guard — an
explicit `class_exists()` / `function_exists()` check, or a hook that only fires
when the subsystem is present — so none of them raises the declared minimum.
Keep it that way: a new modern API needs a guard, not a version bump.

| API                                             | Guard                                                    |
| :---------------------------------------------- | :------------------------------------------------------- |
| FeaturesUtil::declare_compatibility (HPOS)      | `class_exists( …, true )` — autoloads, we may load before WC |
| OrderUtil::custom_orders_table_usage_is_enabled | `class_exists()`                                          |
| AbstractPaymentMethodType (Blocks)              | only loaded from `woocommerce_blocks_payment_method_type_registration` |
| woocommerce_store_api_register_endpoint_data    | `function_exists()`                                       |
| StoreApi\Exceptions\RouteException              | only reachable from a Store API checkout hook             |
| wc_get_logger                                   | `function_exists()`                                       |

## WordPress compatibility table

We use `wp_enqueue_script()` with the `defer` strategy, which officially requires
WordPress version 6.3.0. However, this feature is backwards compatible with
version 2.1.0 because the `$in_footer` parameter, originally a boolean, was
overloaded to accept an `$args` parameter of type array.

Escaping and sanitising helpers (`esc_*`, `sanitize_*`) are all 3.0.0 or older
and are not listed individually.

| WordPress                     |  Version  |
| :---------------------------- | :-------: |
| WooCommerce 10.9              | ~~6.9.0~~ |
| wp_enqueue_script:defer       | **6.3.0** |
| wp_cache_flush_group          |   6.1.0   |
| wp_cache_supports             |   6.1.0   |
| WooCommerce 3.6               |   4.7.0   |
| wp_send_json                  |   4.7.0   |
| wp_add_inline_script          |   4.5.0   |
| wp_json_encode                |   4.1.0   |
| wpdb->esc_like                |   4.0.0   |
| wp_unslash                    |   3.6.0   |
| wp_send_json_success          |   3.5.0   |
| wp_magic_quotes               |   3.0.0   |
| get_current_user_id           |   3.0.0   |
| wp_kses_post                  |   2.9.0   |
| set_transient & get_transient |   2.8.0   |
| wp_enqueue_style              |   2.6.0   |
| admin_url                     |   2.6.0   |
| add_meta_box                  |   2.5.0   |
| get_temp_dir                  |   2.5.0   |
| wp_safe_redirect              |   2.3.0   |
| wp_parse_args                 |   2.2.0   |
| wp_enqueue_script             |   2.1.0   |
| wp_register_script            |   2.1.0   |
| wp_get_referer                |   2.0.4   |
| wp_create_nonce               |   2.0.3   |
| check_ajax_referer            |   2.0.3   |
| register_activation_hook      |   2.0.0   |
| wp_cache_flush                |   2.0.0   |
| current_user_can              |   2.0.0   |
| nocache_headers               |   2.0.0   |
| get_option                    |   1.5.0   |
| add_query_arg                 |   1.5.0   |
| get_pages                     |   1.5.0   |
| get_page_link                 |   1.5.0   |
| add_filter & add_action       |   1.2.0   |
| add_option                    |   1.0.0   |
| wpdb->\*                      |   0.71    |

## libcurl compatibility table

We previously used `CURLOPT_DNS_SHUFFLE_ADDRESSES` (7.60.0), but this is not a
requirement at the moment.

| libcurl               | Version |
| :-------------------- | :-----: |
| CURLOPT_TCP_KEEPALIVE | 7.25.0  |
| CURLOPT_NOSIGNAL      | 7.10.0  |

## WooCommerce Subscriptions (optional)

We have full support for [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/).
Every call site is guarded by `class_exists( 'WC_Subscriptions', false )` or the
equivalent, so the plugin runs unchanged when Subscriptions is absent.

| WooCommerce Subscriptions                                     | Version |
| :------------------------------------------------------------ | :-----: |
| wcs_get_subscription                                           |  1.0.0  |
| wcs_order_contains_subscription                                |  1.0.0  |
| WC_Subscriptions_Cart::cart_contains_subscription              |  1.0.0  |
| WC_Subscriptions_Product::is_subscription                      |  1.0.0  |
| WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment | 1.0.0 |
