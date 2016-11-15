<?php

namespace Scanpay;
if (!defined('ABSPATH')) {
    exit;
}

class GlobalSequencer
{
    protected $tablename;

    public function __construct()
    {
        global $wpdb;
        $this->tablename = $wpdb->prefix . 'woocommerce_scanpay_seq';
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->tablename'") != $this->tablename) {
        	$sql = "CREATE TABLE $this->tablename (
        		shopid BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE COMMENT 'Shop Id',
        		seq BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Scanpay Events Sequence Number',
                mtime BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Modification Time'
        	) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
        	require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        	dbDelta($sql);
        }
    }

    public function updateMtime($shopId)
    {
        global $wpdb;
        $q = $wpdb->prepare("UPDATE `$this->tablename` SET `mtime` = %d " .
            'WHERE `shopid` = %d', time(), $shopId);
        $wpdb->query($q);
    }

    public function insert($shopId)
    {
        global $wpdb;
        if (!is_int($shopId) || $shopId <= 0) {
            scanpay_log('ShopId argument is not an unsigned int');
            return false;
        }
        $q = $wpdb->prepare("INSERT IGNORE INTO `$this->tablename`" .
            'SET `shopid` = %d, `seq` = 0, `mtime` = 0 ' , $shopId);
        $wpdb->query($q);
    }

    public function save($shopId, $seq)
    {
        global $wpdb;
        if (!is_int($shopId) || $shopId <= 0) {
            scanpay_log('ShopId argument is not an unsigned int');
            return false;
        }

        if (!is_int($seq) || $seq < 0) {
            scanpay_log('Sequence argument is not an unsigned int');
            return false;
        }

        $q = $wpdb->prepare("UPDATE `$this->tablename` SET `seq` = %d, `mtime` = %d " .
            'WHERE `shopid` = %d AND `seq` < %d', $seq, time(), $shopId, $seq);
        $ret = $wpdb->query($q);
        if ($ret === 0) {
            $this->updateMtime($shopId);
        }
        return !!$ret;
    }

    public function load($shopId)
    {
        global $wpdb;
        $q = $wpdb->prepare("SELECT * FROM `$this->tablename` WHERE `shopid` = %d", $shopId);
        $row = $wpdb->get_row($q, ARRAY_A);
        if (!$row) {
            return false;
        }
        return [ 'shopid' => $row['shopid'], 'seq' => $row['seq'], 'mtime' => $row['mtime'] ];
    }

}
