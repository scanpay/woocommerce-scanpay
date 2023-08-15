<?php

/*
*   settings-header.php
*   Add buttons, links and ping helper to plugin settings
*   Loaded in gateways/Scanpay.php
*/

defined('ABSPATH') || exit();

wp_enqueue_script(
    'wc-scanpay-admin',
    WC_SCANPAY_URL . '/public/js/settings.js',
    false,
    WC_SCANPAY_VERSION,
    true    // in footer
);

$lastPingText = '';
$lastPingTime = 0;
$pingdt;

if ($this->shopid) {
    require_once WC_SCANPAY_DIR . '/includes/SeqDB.php';
    $seqdb = new WC_Scanpay_SeqDB($this->shopid);
    $local_seqobj = $seqdb->get_seq();

    if ($local_seqobj) {
        $lastPingTime = $local_seqobj['mtime'];
    } else {
        // Create seq table
        $seqdb->create_tables();
        $local_seqobj = $seqdb->get_seq();
    }

    $pingdt = time() - $lastPingTime;
    if (isset($pingdt) && $pingdt < 600) {
        $lastPingText = sprintf(
            _n(
                '%s second since last synchronization.',
                '%s seconds since last synchronization.',
                $pingdt,
                'scanpay-for-woocommerce'
            ),
            $pingdt
        );
    }
}

$github_url = 'https://github.com/scanpay/woocommerce-scanpay';

$sendPingURL = WC_SCANPAY_DASHBOARD . $this->shopid . '/settings/api/setup?module=woocommerce&url=' .
    urlencode(WC()->api_request_url('wc_scanpay'));

$sendPingButton = '<a class="button scanpay--admin--pingbtn" target="_blank" href="' . $sendPingURL . '">' .
    file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg') . __( 'Send ping', 'scanpay-for-woocommerce') . '</a>';


function scanpay_admin_warning($title, $str, $ico = '')
{
    return '<div class="scanpay--admin--alert">
        <div class="scanpay--admin--alert--ico">' . $ico . '</div>
        <div>
            <div class="scanpay--admin--alert--title">' . $title . '</div>
            ' . $str . '
        </div>
    </div>';
}
?>

<div class="scanpay--admin--nav">
  <a class="button" target="_blank" href="<?php echo $github_url ?>">
    <?php
        echo file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/github.svg');
        echo __('Guide', 'scanpay-for-woocommerce');
    ?>
  </a>

  <?php if ($this->shopid) : ?>
  <a class="button" target="_blank" href="<?php echo $sendPingURL ?>">
    <?php
        echo file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg');
        echo __('Send ping', 'scanpay-for-woocommerce');
    ?>
  </a>
  <?php endif; ?>

  <a class="button" href="?page=wc-status&tab=logs&log_file=<?php echo
    basename(wc_get_log_file_path('woo-scanpay')) . '&source=woo-scanpay' ?>">
    <?php echo __('Debug logs', 'scanpay-for-woocommerce'); ?>
  </a>
  <div class="scanpay--admin--nav--lastping">
    <?php echo $lastPingText ?>
  </div>
</div>

<?php

if (!$this->shopid) {
    // No API-key show welcome message...
    echo scanpay_admin_warning(
        __('Welcome to Scanpay for WooCommerce!', 'scanpay-for-woocommerce'),
        __(
            'Please follow the instructions in the setup guide.',
            'scanpay-for-woocommerce'
        ),
        '<a class="scanpay--admin--alert--btn" target="_blank" href="' . $github_url .
        '#configuration">' . __('Setup guide', 'scanpay-for-woocommerce') . '</a>'
    );
} elseif (!$lastPingTime) {
    echo scanpay_admin_warning(
        __('You have never received any pings from Scanpay', 'scanpay-for-woocommerce'),
        __(
            'Please perform a test ping to finalize the installation.',
            'scanpay-for-woocommerce'
        ),
        '<a class="scanpay--admin--alert--btn" target="_blank" href="' . $sendPingURL .
        '">' . file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg') . __('Send ping', 'scanpay-for-woocommerce') . '</a>'
    );
} elseif ($pingdt < 0) {
    echo scanpay_admin_warning(
        'ERROR: last received ping is in the future!',
        'Something is completely wrong. Please contact support at <a target="_blank" ' .
        'href="mailto:support@scanpay.dk">support@scanpay.dk</a>'
    );
} elseif ($pingdt > 600) {
    echo scanpay_admin_warning(
        __('Warning: Your transaction data could be out of sync!', 'scanpay-for-woocommerce'),
        sprintf(__(
            '%s minutes have passed since the last received ping.',
            'scanpay-for-woocommerce'
        ), round($pingdt / 60)),
        '<a class="scanpay--admin--alert--btn" target="_blank" href="' . $sendPingURL .
        '">' . file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg') . __('Send ping', 'scanpay-for-woocommerce') . '</a>'
    );
}

?>
