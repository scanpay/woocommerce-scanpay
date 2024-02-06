# Requirements

* PHP version >= 7.4.
* php-curl (libcurl >= 7.25.0).
* WooCommerce >= 6.9.0
* WordPress >= 6.3.0


### PHP compatibility table

| PHP Features                              | Version |
| :---------------------------------------- | :-----: |
| ~~str_starts_with()~~                     | 8.0     |
| ~~str_ends_with()~~                       | 8.0     |
| WooCommerce (8.2)                         | **7.4** |
| Array Spread operator                     | 7.4     |
| Typed class properties                    | 7.4     |
| Nullable Types                            | 7.1     |
| Void return type                          | 7.1     |
| WordPress (6.3)                           | 7.0     |
| Return type declarations                  | 7.0     |
| Null coalescing operator                  | 7.0     |


### WooCommerce compatibility table

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


### WordPress compatibility table

| WordPress                                 | Version  |
| :---------------------------------------- | :------: |
| WooCommerce (8.2)                         | 6.3.0    |
| wp_enqueue_script async/defer             | 6.3.0    |
| wp_send_json                              | 4.7.0    |
| wp_send_json_success                      | 3.5.0    |
| current_user_can                          | 2.0.0    |
| get_option                                | 1.5.0    |


### libcurl compatibility table

**Note**: We might at some point require `CURLOPT_DNS_SHUFFLE_ADDRESSES`.

| libcurl                                   | Version |
| :---------------------------------------- | :-----: |
| ~~CURLOPT_DNS_SHUFFLE_ADDRESSES~~         | ~~7.60.0~~|
| CURLOPT_TCP_KEEPALIVE                     | 7.25.0  |