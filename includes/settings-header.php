<?php

/*
*   settings-header.php
*   Add buttons, links and ping helper to plugin settings
*   Loaded in gateways/Scanpay.php
*/

defined('ABSPATH') || exit();

$lastPingText = '';
$lastPingTime = 0;
$pingdt;

if ($this->shopid) {
    require WC_SCANPAY_DIR . '/includes/SeqDB.php';
    $seqdb = new WC_Scanpay_SeqDB($this->shopid);
    $local_seqobj = $seqdb->get_seq();

    if ($local_seqobj) {
        $lastPingTime = $local_seqobj['mtime'];
    }
    $pingdt = time() - $lastPingTime;
    if (isset($pingdt) && $pingdt < 600) {
        $lastPingText = '<div class="scanpay--admin--nav--lastping">' . $pingdt .
        ($pingdt === 1 ? ' second' : ' seconds') . ' since last received ping</div>';
    }
}

$github_url = 'https://github.com/scanpay/woocommerce-scanpay';
$sendPingURL = 'https://dashboard.scanpay.dk/' . $this->shopid . '/settings/api/setup?module=woocommerce&url=' .
    urlencode(WC()->api_request_url('wc_scanpay'));
$sendPingButton = '<a class="button scanpay--admin--pingbtn" target="_blank" href="' . $sendPingURL . '">' .
    file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg') . 'Send ping</a>';

function scanpay_ping_time_str($dt)
{
    if ($dt < 60) {
        if ($dt <= 1) {
            return '1 second has';
        }
        return $dt . ' seconds have';
    } elseif ($dt < 3600 * 2) {
        return round((int)$dt / 60) . ' minutes have';
    } elseif ($dt < 259200) {
        return round((int)$dt / 3600) . ' hours have';
    } else {
        return round((int)$dt / 86400) . ' days have';
    }
}

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
    <?php echo file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/github.svg') ?>
    Guide
  </a>

  <?php if ($this->shopid) : ?>
  <a class="button" target="_blank" href="<?php echo $sendPingURL ?>">
    <?php echo file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg') ?>
    Send ping
  </a>
  <?php endif; ?>

  <a class="button" href="?page=wc-status&tab=logs&log_file=<?php echo
    basename(wc_get_log_file_path('woo-scanpay')) . '&source=woo-scanpay' ?>">
    Debug logs
  </a>

  <?php echo $lastPingText ?>
</div>

<?php
$instructions = 'Please follow the instructions in the <a target="_blank" href="' . $github_url .
    '#configuration">setup guide</a>.';

if (!$this->shopid) {
    // No API-key show welcome message...
    echo scanpay_admin_warning(
        'Welcome to Scanpay for WooCommerce!',
        $instructions,
        '<a class="scanpay--admin--alert--btn" target="_blank" href="' . $github_url .
        '#configuration">Setup guide</a>'
    );
} elseif (!$lastPingTime) {
    echo scanpay_admin_warning(
        'You have never received any pings from Scanpay. ',
        $instructions,
        '<a class="scanpay--admin--alert--btn" target="_blank" href="' . $sendPingURL .
        '">' . file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg') . 'Send ping</a>'
    );
} elseif ($pingdt < 0) {
    echo scanpay_admin_warning(
        'ERROR: last received ping is in the future!',
        'Something is completely wrong. Please contact support at <a target="_blank" ' .
        'href="mailto:support@scanpay.dk">support@scanpay.dk</a>'
    );
} elseif ($pingdt > 600) {
    echo scanpay_admin_warning(
        'Warning: Your transaction data could be out of sync!',
        scanpay_ping_time_str($pingdt) . ' passed since the last received ping. ' . $instructions,
        '<a class="scanpay--admin--alert--btn" target="_blank" href="' . $sendPingURL .
        '">' . file_get_contents(WC_SCANPAY_DIR . '/public/images/admin/ping.svg') . 'Send ping</a>'
    );
}

?>
