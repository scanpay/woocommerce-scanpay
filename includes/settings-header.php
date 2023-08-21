<?php

/*
*   settings-header.php
*   Add buttons, links and ping helper to plugin settings
*   Loaded in gateways/Scanpay.php
*/

defined('ABSPATH') || exit();

$pingURL = urlencode(WC()->api_request_url('wc_scanpay'));
$sendPingURL = WC_SCANPAY_DASHBOARD . $this->shopid . '/settings/api/setup?module=woocommerce&url=' . $pingURL;
$logsURL = basename(wc_get_log_file_path('woo-scanpay'));
?>

<div class="scanpay--admin--nav">
  <a class="button" target="_blank" href="https://github.com/scanpay/woocommerce-scanpay">
    <img width="16" height="16" src="<?php echo WC_SCANPAY_URL ?>/public/images/admin/github.svg" class="scanpay--admin--nav--img-git">
    <?php echo __('Guide', 'scanpay-for-woocommerce'); ?>
  </a>

  <?php if ($this->shopid) : ?>
  <a id="scanpay--admin--ping" class="button" target="_blank" href="<?php echo $sendPingURL ?>">
    <img width="21" height="16" src="<?php echo WC_SCANPAY_URL ?>/public/images/admin/ping.svg" class="scanpay--admin--nav--img-ping">
    <?php echo __('Send ping', 'scanpay-for-woocommerce'); ?>
  </a>
  <?php endif; ?>

  <a class="button" href="?page=wc-status&tab=logs&log_file=<?php echo $logsURL; ?>&source=woo-scanpay">
    <?php echo __('Debug logs', 'scanpay-for-woocommerce'); ?>
  </a>
</div>

<div id="scanpay--admin--alert--parent">
    <!-- No API-key -->
    <?php if (!$this->shopid) : ?>
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
