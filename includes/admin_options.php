<?php

/*
*   admin-options.php
*   Override WC_Payment_Gateway/WC_Settings_API:: admin_options()
*/

defined('ABSPATH') || exit();

$settings = get_option(WC_SCANPAY_URI_SETTINGS);
$shopid = (int) explode(':', $settings['apikey'])[0];
$pingURL = urlencode(WC()->api_request_url('wc_scanpay'));
$sendPingURL = WC_SCANPAY_DASHBOARD . $shopid . '/settings/api/setup?module=woocommerce&url=' . $pingURL;

echo '<h2>' . esc_html( $this->get_method_title() );
wc_back_link(__('Return to payments', 'woocommerce'), admin_url('admin.php?page=wc-settings&tab=checkout'));
echo '</h2>';
echo wp_kses_post(wpautop($this->get_method_description()));
?>

<div class="scanpay--admin--nav">
  <a class="button" target="_blank" href="https://github.com/scanpay/woocommerce-scanpay">
    <img width="16" height="16" src="<?php echo WC_SCANPAY_URL ?>/public/images/admin/github.svg" class="scanpay--admin--nav--img-git">
    <?php echo __('Guide', 'scanpay-for-woocommerce'); ?>
  </a>

  <?php if ($shopid) : ?>
  <a id="scanpay--admin--ping" class="button" target="_blank" href="<?php echo $sendPingURL ?>">
    <img width="21" height="16" src="<?php echo WC_SCANPAY_URL ?>/public/images/admin/ping.svg" class="scanpay--admin--nav--img-ping">
    <?php echo __('Send ping', 'scanpay-for-woocommerce'); ?>
  </a>
  <?php endif; ?>

  <a class="button" href="?page=wc-status&tab=logs&log_file=<?php echo basename(wc_get_log_file_path('woo-scanpay')) ?>&source=woo-scanpay">
    <?php echo __('Debug logs', 'scanpay-for-woocommerce'); ?>
  </a>
</div>

<div id="scanpay--admin--alert--parent">
    <!-- No API-key -->
    <?php if (!$shopid) : ?>
        <div class="scanpay--admin--alert scanpay--admin--alert--show">
            <div class="scanpay--admin--alert--ico">
                <a class="scanpay--admin--alert--btn" target="_blank" href="https://github.com/scanpay/woocommerce-scanpay#configuration">
                    <?php echo __('Setup guide', 'scanpay-for-woocommerce'); ?>
                </a>
            </div>
            <div>
                <div class="scanpay--admin--alert--title">
                    <?php echo __('Welcome to Scanpay for WooCommerce!', 'scanpay-for-woocommerce') ?>
                </div>
                <?php echo __('Please follow the instructions in the setup guide.', 'scanpay-for-woocommerce'); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- No pings received -->
    <div class="scanpay--admin--alert scanpay--admin--alert--no-pings">
        <div class="scanpay--admin--alert--ico">
            <a class="scanpay--admin--alert--btn" target="_blank" href="<?php echo $sendPingURL ?>">
                <img width="21" height="16" src="<?php echo WC_SCANPAY_URL ?>/public/images/admin/ping-white.svg">
                <?php echo __('Send ping', 'scanpay-for-woocommerce'); ?>
            </a>
        </div>
        <div>
            <div class="scanpay--admin--alert--title">
                <?php echo __('You have never received any pings from Scanpay', 'scanpay-for-woocommerce') ?>
            </div>
            <?php echo __('Please perform a test ping to finalize the installation.', 'scanpay-for-woocommerce') ?>
        </div>
    </div>

    <!-- Long time since last ping -->
    <div class="scanpay--admin--alert scanpay--admin--alert--last-ping">
        <div class="scanpay--admin--alert--ico">
            <a class="scanpay--admin--alert--btn" target="_blank" href="<?php echo $sendPingURL ?>">
                <img width="21" height="16" src="<?php echo WC_SCANPAY_URL ?>/public/images/admin/ping-white.svg">
                <?php echo __('Send ping', 'scanpay-for-woocommerce'); ?>
            </a>
        </div>
        <div>
            <div class="scanpay--admin--alert--title">
                <?php echo __('Warning: Your transaction data could be out of sync!', 'scanpay-for-woocommerce') ?>
            </div>
            <span id="scanpay-ping"></span>
            <?php echo __(' minutes have passed since the last synchronization. Please check your API-key and send a ping.', 'scanpay-for-woocommerce') ?>
        </div>
    </div>
</div>

<?php

// From WC_Settings_API::admin_options()
$className = 'form-table';
if (isset($this->settings['subscriptions_enabled']) && $this->settings['subscriptions_enabled'] === 'no') {
    $className = 'form-table scanpay--admin--no-subs';
}
echo '<table class="' . $className . '">' . $this->generate_settings_html($this->get_form_fields(), false) . '</table>';
