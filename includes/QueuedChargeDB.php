<?php

namespace Scanpay;
if (!defined('ABSPATH')) {
    exit;
}

class QueuedChargeDB
{
    protected $tablename;

    public function __construct()
    {
        global $wpdb;
        $this->tablename = $wpdb->prefix . 'woocommerce_scanpay_queuedcharges';
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->tablename'") != $this->tablename) {
            $sql = "CREATE TABLE $this->tablename (
                orderid BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE COMMENT 'Order Id'
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    public function save($orderid)
    {
        global $wpdb;
        $q = $wpdb->prepare("INSERT IGNORE INTO `$this->tablename`" .
            'SET `orderid` = %d ' , $orderid);
        $wpdb->query($q);
    }

    public function loadall()
    {
        global $wpdb;
        $col = $wpdb->get_col("SELECT `orderid` FROM `$this->tablename`");
        if (!$col) {
            return false;
        }
        return $col;
    }

    public function delete($orderid)
    {
        global $wpdb;
        $q = $wpdb->prepare("DELETE IGNORE FROM `$this->tablename`" .
            'WHERE `orderid` = %d ' , $orderid);
        $wpdb->query($q);
    }

}
