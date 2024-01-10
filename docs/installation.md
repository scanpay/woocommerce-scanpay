# Installation guide

Installation guide for Scanpay for WooCommerce:

## Install the plugin

1. You can install the plugin directly from your WordPress dashboard. Log in to your WordPress dashboard and navigate to `Plugins > Add New`. Search for *"Scanpay for WooCommerce"* and install the plugin.
2. Click *"Activate"* to activate the plugin.
3. Navigate to `WooCommerce > Settings > Payments` to manage your WooCommerce payment settings. Find the *"Scanpay"* option and click *"Set up"* to open the Scanpay configuration page.
4. Insert your Scanpay API key in the *"API key"* field and click *"Save changes"*. You can generate an API key in our dashboard [here](https://dashboard.scanpay.dk/settings/api).
5. A yellow box should appear at the top of the page. Click *"Send ping"* to initiate the synchronization process.
6. Now, in the scanpay dashboard, save the *"ping URL"*. The system will perform an initial synchronization and show you the result.
7. Your WooCommerce store is now connected and synchronized with Scanpay. When ready, change the status from *"Disabled"* to *"Enabled"* to enable the extension in your checkout.

## Update the plugin

1. Navigate to `Plugins > Installed plugins`.
2. Find *"Scanpay for WooCommerce"* in the list and click *"Update"*.

## Uninstall the plugin

> [!NOTE]
> It is safe to uninstall the plugin. Transaction details are restored from the Scanpay backend if you reinstall the plugin.

1. Navigate to `Plugins > Installed plugins`.
2. Find *"Scanpay for WooCommerce"* in the list.
3. Click *"Deactivate"* and then *"Delete"*.
4. The plugin will now uninstall itself and remove all stored data.
