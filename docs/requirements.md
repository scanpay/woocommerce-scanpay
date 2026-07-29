# Requirements

This document records the PHP, WordPress, WooCommerce, database, libcurl and browser
APIs the plugin depends on, and the version each one was introduced in. It is the
rationale for the minimums declared in `package.json`; `phpcs.xml` carries its own copy
as `testVersion` and `minimum_supported_wp_version` — change one, change the other, or
PHPCompatibility silently stops enforcing the floor.

Versions come from the `@since` tags in the WordPress, WooCommerce and WooCommerce
Subscriptions source (the `php-stubs/*` dev packages are the copy actually consulted).
WooCommerce omits `@since` on a lot of its older surface; those entries were pinned
against release tags instead, and anything that could not be pinned is simply left out
rather than guessed at. Features marked with ~~strikethrough~~ are managed using
polyfills or other mitigations.

## PHP

We require PHP 8.0. WordPress and WooCommerce both still run on 7.4 (`$required_php_version`
in `wp-includes/version.php`, `Requires PHP` in WooCommerce's `readme.txt`), so this is our
own floor, not theirs: `str_starts_with()`, `str_ends_with()`, `match`, the `mixed` type
and the `CurlHandle` type all need 8.0, and PHP 7.4 reached End-Of-Life on 28 November
2022.

| PHP feature                                   | Version | Used by                             |
| :-------------------------------------------- | :-----: | :---------------------------------- |
| str_starts_with(), str_ends_with()            | **8.0** | sync, thankyou, router              |
| match expression                              | **8.0** | the AJAX dispatch in the router     |
| mixed type declaration                        | **8.0** | `WC_Scanpay_Sync` payload parsers   |
| CurlHandle type declaration                   | **8.0** | `WC_Scanpay_Client`                 |
| Numeric literal separator (`400_000`)         |   7.4   | thankyou poll, sync timestamp guard |
| Typed class properties                        |   7.4   | every class                         |
| JSON_THROW_ON_ERROR, JsonException            |   7.3   | client, ping                        |
| Class constant visibility (`private const`)   |   7.1   | `WC_Scanpay_Sync`                   |
| Nullable types, void return type              |   7.1   | throughout                          |
| declare(strict_types=1), Throwable, `??`      |   7.0   | every file                          |
| intdiv(), random_bytes()                      |   7.0   | idempotency key, secret minting     |
| hash_equals()                                 |   5.6   | ping HMAC, admin-AJAX secret        |

No 8.1+ syntax is used: no `readonly`, `enum`, `never`, first-class callables, or `new`
in initializers. Adding any of them raises the floor and must be a deliberate decision,
not a side effect.

## WordPress

The floor is 6.3.0, set by a single call: `wp_enqueue_script()` with the `defer`
strategy. WordPress did not add a parameter for it — it overloaded the existing
`$in_footer` boolean to also accept an `$args` array — so an older WordPress reads our
array as truthy and simply enqueues in the footer without deferring. The floor is
therefore soft: 6.3.0 buys the `defer`, nothing breaks below it.

Rows marked *(param)* are dated by the argument we pass, not by when the function first
appeared. Escaping and sanitising helpers (`esc_*`, `sanitize_*`) are 2.8.0/2.9.0 and
are listed once rather than individually.

| WordPress                                 |  Version  |
| :---------------------------------------- | :-------: |
| Plugin header `Requires Plugins:`         | ~~6.5.0~~ |
| wp_enqueue_script: `strategy` (param)     | **6.3.0** |
| wp_cache_flush_group                      |   6.1.0   |
| WooCommerce 3.6                           |   4.7.0   |
| wp_send_json\*: `$status_code` (param)    |   4.7.0   |
| wp_add_inline_script                      |   4.5.0   |
| status_header: `$description` (param)     |   4.4.0   |
| update_option: `$autoload` (param)        |   4.2.0   |
| wp_json_encode                            |   4.1.0   |
| wpdb->esc_like                            |   4.0.0   |
| wp_unslash                                |   3.6.0   |
| wp_send_json, \_success, \_error          |   3.5.0   |
| DAY/HOUR/MINUTE_IN_SECONDS                |   3.5.0   |
| remove_menu_page                          |   3.1.0   |
| get_current_user_id, sanitize_key         |   3.0.0   |
| wp_kses_post, sanitize_text_field         |   2.9.0   |
| esc_html\*, esc_attr\*, esc_url\*         |   2.8.0   |
| set/get/delete_transient                  |   2.8.0   |
| wp_enqueue_style, admin_url, plugins_url  |   2.6.0   |
| add_meta_box, get_temp_dir, absint        |   2.5.0   |
| wp_safe_redirect                          |   2.3.0   |
| wp_parse_args, untrailingslashit          |   2.2.0   |
| wp_enqueue_script, wp_register_script     |   2.1.0   |
| wp_get_referer                            |   2.0.4   |
| wp_create_nonce, check_ajax_referer       |   2.0.3   |
| current_user_can, get_post_status         |   2.0.0   |
| nocache_headers, wp_cache_flush           |   2.0.0   |
| is_admin                                  |   1.5.1   |
| get_option, get_pages, get_page_link      |   1.5.0   |
| add_query_arg, load_plugin_textdomain     |   1.5.0   |
| add_action, do_action, remove_action      |   1.2.0   |
| delete_option                             |   1.2.0   |
| add_filter, apply_filters                 |   0.71    |
| wpdb->\*                                  |   0.71    |

`Requires Plugins: woocommerce` is the one header above the floor. WordPress below 6.5
ignores unknown headers, so the only loss is the dependency check in the admin — the
loader's own `class_exists( 'WC_Payment_Gateway' )` gate is what actually keeps us safe
without WooCommerce.

## WooCommerce

The plugin requires WooCommerce 3.6.0 or higher, released in April 2019. The binding
call is `WC()->countries->get_country_calling_code()`, used to prefix phone numbers with
country codes for MobilePay Online.

Every symbol below was verified present in WooCommerce 3.6.0. `WC_Order`, `WC_Data` and
`WC_Settings_API` members not listed are 3.0.0 or older.

| WooCommerce                                    |  Version  |
| :--------------------------------------------- | :-------: |
| WC_Countries::get_country_calling_code         | **3.6.0** |
| WC_Payment_Gateway::needs_setup                |   3.4.0   |
| WooCommerce::api_request_url                   |   3.2.0   |
| wc_get_logger                                  |   3.0.0   |
| WC_Log_Handler_File::get_log_file_path         |   3.0.0   |
| WC_Order:needs_processing                      |   3.0.0   |
| WC_Order:set_transaction_id, set_date_paid     |   3.0.0   |
| WC_Order:set_payment_method(\_title)           |   3.0.0   |
| WC_Order:save, set_status, update_status       |   3.0.0   |
| WC_Product:get_virtual, get_downloadable       |   3.0.0   |
| WC_Order_Item, WC_Order_Item_Product           |   3.0.0   |
| WC_DateTime                                    |   3.0.0   |
| WC_Order:get\*\*                               |   2.6.0   |
| WC_Data:add/update/get_meta, save_meta_data    |   2.6.0   |
| WC_Settings_API:get_option_key                 |   2.6.0   |
| wc_get_orders                                  |   2.6.0   |
| WC_Order:is_paid                               |   2.5.0   |
| wc_get_price_decimals                          |   2.3.0   |
| wc_get_order                                   |   2.2.0   |
| WC_Order:add_order_note, get_refunds           |   2.2.0   |
| WC_Payment_Gateway:get_transaction_url         |   2.2.0   |
| WC(), WC()->version, WC()->payment_gateways()  |   2.1.0   |
| WC_Admin_Settings::add_error                   |   2.1.0   |
| is_checkout, is_checkout_pay_page              |   2.1.0   |
| woocommerce_form_field                         |   2.1.0   |
| WC_Payment_Gateway                             |   2.1.0   |
| wc_format_decimal                              |   2.1.0   |
| WC_Settings_API:generate_settings_html         |   1.0.0   |
| WC_Settings_API:get_form_fields                |   1.0.0   |

`wc_get_logger` is the one call below the floor that still carries a `function_exists()`
guard, and the guard is about load order, not the version: `scanpay_log()` can run before
WooCommerce is loaded, because the ping endpoint short-circuits the bootstrap. Do not
remove it on the grounds that 3.0.0 < 3.6.0.

## Guarded APIs above the floor

Each of these is newer than the declared minimums and sits behind a runtime guard — an
explicit `class_exists()` / `function_exists()` check, or a hook that only fires when
the subsystem is present — so none of them raises a floor. Keep it that way: a new
modern API needs a guard, not a version bump.

| API                                             |  Since   | Guard                                                    |
| :---------------------------------------------- | :------: | :------------------------------------------------------- |
| FeaturesUtil::declare_compatibility (HPOS)      | WC 7.1.0 | `class_exists( …, true )` — autoloads, we may load before WC |
| OrderUtil::custom_orders_table_usage_is_enabled | WC 7.0.0 | `class_exists()`                                         |
| `wc_orders`, `wc_order_operational_data` (SQL)  | WC 7.1.0 | only read once `custom_orders_table_usage_is_enabled()` says HPOS is on |
| `*-woocommerce_page_wc-orders` admin hooks      | WC 7.1.0 | HPOS-only screens; the legacy `*-shop_order` twin is registered alongside |
| AbstractPaymentMethodType (Blocks)              | Blocks 3.0.0 | only loaded from `woocommerce_blocks_payment_method_type_registration` |
| woocommerce_store_api_register_endpoint_data    | Blocks 7.2.0 | `function_exists()`                                  |
| StoreApi\Exceptions\RouteException              | Blocks   | only reachable from a Store API checkout hook            |
| woocommerce_store_api_checkout_update_order_from_request | Blocks | the action simply never fires without the Store API |

## WooCommerce Subscriptions (optional)

We have full support for [WooCommerce Subscriptions](https://woocommerce.com/products/woocommerce-subscriptions/).
Every call site is guarded by `class_exists( 'WC_Subscriptions', false )` or the
equivalent, so the plugin runs unchanged when Subscriptions is absent.

Beware the version numbering: `woocommerce-subscriptions-core` restamped the whole
codebase as `@since 1.0.0 - Migrated from WooCommerce Subscriptions vX.Y`. The table
below uses the real, historical Subscriptions version — the `vX.Y` in that note.

| WooCommerce Subscriptions                                             | Version |
| :-------------------------------------------------------------------- | :-----: |
| wcs_get_retry_rule_raw (filter)                                       | **2.1** |
| wcs_get_subscription                                                  | **2.0** |
| WC_Subscription (type hint, get_parent, get_payment_method_title)     | **2.0** |
| woocommerce_scheduled_subscription_payment\_{gateway} (action)         |   2.0   |
| woocommerce_subscription_payment_method_to_display (filter)            |   2.0   |
| WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment |   1.4   |
| WC_Subscriptions_Cart::cart_contains_subscription                     |   1.0   |
| WC_Subscriptions_Product::is_subscription                             |   1.0   |
| WC_Subscriptions (the class_exists guard itself)                      |   1.0   |

The effective floor where Subscriptions is installed is therefore 2.0, not 1.0. The one
entry above it, the 2.1 retry filter, needs no guard of its own: `add_filter()` on a
hook that no version ever fires is an inert no-op, so a 2.0 site just keeps
Subscriptions' own retry interval.

## Database (MySQL / MariaDB)

The plugin owns `scanpay_seq`, `scanpay_meta` and `scanpay_subs` outright, so it issues
its own DDL in `install.php` and reaches them through `$wpdb` — see the `phpcs.xml`
exclusions for why. Nothing below uses anything newer than WordPress's own floor
(`$required_mysql_version`, still 5.5.5 on trunk), so we add no requirement of our own.

The effective floor on a real shop is WooCommerce's: **MySQL 8.0 or MariaDB 10.6**, with
MySQL 5.6 / MariaDB 10.4 kept as a legacy note and marked End-Of-Life. We inherit that
number rather than add to it, and it moves without us — Core's June 2025 database policy
makes only LTS releases eligible as future minimums, so the next step is MariaDB 10.11
and nothing in between.

PHP 8.0 does not move it either way. mysqlnd speaks the 4.1 protocol, so no PHP version
rules out an old server, and the one coupling that exists runs upward:
`caching_sha2_password`, MySQL 8.0's default authentication, has been supported since PHP
7.4 — already below our floor. Declaring a higher floor of our own would be unenforceable
anyway, since WordPress has no `Requires MySQL` plugin header, and MariaDB prefixes its
version string with `5.5.5-`, so `$wpdb->db_version()` reports 5.5.5 for every MariaDB
10.x and a real check would have to parse `db_server_info()`.

| SQL feature                                | MySQL | MariaDB |
| :----------------------------------------- | :---: | :-----: |
| INSERT … ON DUPLICATE KEY UPDATE           |  4.1  |   5.1   |
| VALUES() inside the UPDATE clause          |  4.1  |   5.1   |
| SHOW TABLES LIKE, DROP TABLE IF EXISTS     |  3.23 |   5.1   |
| SHOW COLUMNS, SHOW INDEX                   |  3.23 |   5.1   |
| ALTER TABLE … DROP COLUMN / DROP INDEX     |  3.23 |   5.1   |
| LEFT JOIN, MAX( CASE WHEN … )              |  3.23 |   5.1   |
| `CHARSET = latin1`                         |  3.23 |   5.1   |

The tables are `latin1` on purpose: every column holds protocol data that is ASCII by
construction (ids, revisions, currency codes, decimal amount strings), so a wider
charset would only cost index bytes.

`VALUES()` in `ON DUPLICATE KEY UPDATE` — `WC_Scanpay_Sync::sync()` — is **deprecated as
of MySQL 8.0.20** and subject to removal; it still works in 8.4. The replacement, a row
alias (`… AS new … SET rev = new.rev`), needs MySQL 8.0.19 and is not supported by
MariaDB at all, so adopting it would raise this floor from 5.5.5 to 8.0.19 and break
MariaDB hosts. The deprecated form stays until MySQL actually removes it — and the escape
then is not the alias but repeating the bound value in the UPDATE clause, which
`subscriber()` already does on the `scanpay_subs` upsert. That is a code change, not a
floor bump.

The 3.0.0 migration's `ALTER TABLE` carries **no `ALGORITHM=` clause**: the clause arrived
in MySQL 5.6, below which it is a syntax error, and an explicit `INSTANT` is an error
wherever it does not apply rather than a fallback — so the server chooses, and it chooses
the fastest it has. The migration drops columns and keys in separate statements to leave it
that choice: from MySQL 8.0.29 and MariaDB 10.4 a lone `DROP COLUMN` is instant and a
`DROP INDEX` is metadata-only, while one statement carrying both falls back to an in-place
rebuild. Raising the floor would buy no more than that, since none of it needs to be asked
for by name.

Separately, the migration reads `SHOW COLUMNS` and `SHOW INDEX` before it drops anything:
`DROP COLUMN IF EXISTS` exists in MariaDB (10.0.2) but in **no** MySQL version, 8.4
included, so introspection is the only portable way — and being a read of the live schema
it makes a re-run idempotent, which a version test would not.

## libcurl

`WC_Scanpay_Client` is the only code that talks to the API, and the real floor is not
ours: **PHP 8.0 raised ext/curl's minimum libcurl to 7.29.0**, so every option we set is
already covered by the PHP requirement. The table exists so a future addition can be
checked against that number rather than rediscovered.

We previously used `CURLOPT_DNS_SHUFFLE_ADDRESSES` (7.60.0), but this is not a
requirement at the moment.

| libcurl                   | Version |
| :------------------------ | :-----: |
| CURLOPT_TCP_KEEPALIVE     | 7.25.0  |
| curl_reset()              | 7.12.1  |
| curl_strerror()           | 7.12.0  |
| CURLINFO_RESPONSE_CODE    | 7.10.8  |
| CURLOPT_NOSIGNAL          | 7.10.0  |
| CURLOPT_DNS_CACHE_TIMEOUT |  7.9.3  |
| CURL_HTTP_VERSION_1_1     |  7.9.1  |

## Browser (frontend and admin assets)

`tsc` type-checks against `target`/`lib` ES2020 and emits nothing; `build.sh` runs
esbuild with `--bundle --minify` and no `--target`, so esbuild does not downlevel
anything. The shipped JavaScript is exactly the syntax written in `src`, and ES2020 is
enforced only by `tsc` — bump `tsconfig.json` and the browser floor moves with it,
silently.

| Browser API                           | Since                       |
| :------------------------------------ | :-------------------------- |
| Optional chaining `?.`, nullish `??`  | **ES2020** — the binding one |
| Promise, URL, URLSearchParams         | ES6                         |
| fetch, localStorage                   | WHATWG, pre-ES6             |
| ApplePaySession.canMakePayments()     | Safari only                 |

`ApplePaySession` is feature-detected with `window.ApplePaySession?.canMakePayments()`,
which is the whole reason the Apple Pay method is not offered elsewhere — the optional
chain is load-bearing, not defensive.

The Blocks bundle is not self-contained: it reads `window.wp.element`, `window.wp.data`,
`window.wc.wcSettings`, `window.wc.wcBlocksRegistry` and `window.wc.blocksCheckout`, and
writes to the `wc/store/validation` and `wc/store/checkout` stores. Those are declared as
script dependencies (`wc-blocks-registry`, `wc-blocks-checkout`, `wc-settings`, `wp-data`,
`wp-element`) in `WC_Scanpay_Blocks_Support::get_payment_method_script_handles()`; adding
a global without adding its handle there is a race, not a missing file.
