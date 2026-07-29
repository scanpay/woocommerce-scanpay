# Scanpay for WooCommerce

[![Latest Stable Version](https://img.shields.io/github/v/release/scanpay/woocommerce-scanpay?cacheSeconds=600)](https://github.com/scanpay/woocommerce-scanpay/releases)
[![License](https://img.shields.io/github/license/scanpay/woocommerce-scanpay?cacheSeconds=6000)](https://github.com/scanpay/woocommerce-scanpay/blob/master/LICENSE)
[![CodeFactor](https://www.codefactor.io/repository/github/scanpay/woocommerce-scanpay/badge)](https://www.codefactor.io/repository/github/scanpay/woocommerce-scanpay)

Accept payments in your WooCommerce store through [Scanpay](https://scanpay.dk), a secure and reliable Scandinavian payment gateway. This is the official plugin, developed and supported by Scanpay.

-   Dankort, Visa, Mastercard, JCB, Amex, Diners and more
-   MobilePay Online and Apple Pay
-   WooCommerce Subscriptions, including automatic renewal charges
-   Optional automatic capture and order auto-completion
-   Compatible with HPOS and WooCommerce Blocks

If you have any questions, concerns or ideas, please do not hesitate to e-mail us at [support@scanpay.dk](mailto:support@scanpay.dk). Feel free to join our IRC server `irc.scanpay.dev:6697 #support` or chat with us at [irc.scanpay.dev](https://irc.scanpay.dev).

## Requirements

The floors below mirror `requires` in `package.json`, which `build.sh` substitutes into the plugin header and `readme.txt`. That is the authoritative copy; this list is a convenience.

-   WooCommerce >= 3.6 ([details](./docs/requirements.md#woocommerce))
-   WordPress >= 6.3 ([details](./docs/requirements.md#wordpress))
-   PHP >= 8.0 ([details](./docs/requirements.md#php))
-   MySQL >= 5.5.5 or MariaDB ([details](./docs/requirements.md#database-mysql--mariadb))
-   libcurl >= 7.29 ([details](./docs/requirements.md#libcurl))
-   WooCommerce Subscriptions >= 2.0 — optional ([details](./docs/requirements.md#woocommerce-subscriptions-optional))
-   A [Scanpay](https://scanpay.dk) account.

## Installation

To install the plugin in your WooCommerce store, please follow the instructions in the [installation guide](https://wordpress.org/plugins/scanpay-for-woocommerce/#installation).

## Development

`src/` is the source and `build/` is a generated artifact: `./build.sh` wipes and regenerates `build/` on every run, so edits made there are lost without warning. Always edit `src/`.

```bash
pnpm install      # pnpm, not npm
composer install  # dev-only tooling; nothing from vendor/ is shipped
./build.sh
```

`./build.sh` ends by asking whether to push the result to Scanpay's internal test server. Answer `N`.

If you symlink a checkout into a WordPress install's `wp-content/plugins/` instead of copying `build/` there, **name the symlink after the directory it points at**. The plugin derives its own directory name from the resolved path, so a symlink under a different name leaves it looking for translations and its Plugins-screen entry under the wrong name: strings stay English and the "Settings" link disappears. Symlinking `wp-content` itself, or the plugin directory under its own name, is fine.

There is no test suite — the linters are the validation, and all four should be clean before you open a pull request:

```bash
pnpm phpcs        # pnpm phpcbf fixes most of what it reports
pnpm lint:js
pnpm lint:style
pnpm exec tsc     # type-check only, emits nothing
```

They cover syntax, style and types. Anything that depends on a running shop has to be verified against one.

## Contribution

We greatly appreciate all contributions to this project, whether you are fixing bugs, adding features, improving documentation or providing feedback. Please feel free to submit pull requests, report issues or suggest enhancements.

One thing is worth knowing before you start: the plugin is deliberately procedural and modular rather than object-oriented, has no autoloader, and ships with zero runtime dependencies. That is a standing design decision, not legacy code. [AGENTS.md](AGENTS.md) explains the reasoning along with the rest of the conventions.

## License

Everything in this repository is licensed under the [GPLv3 or later](LICENSE).
