<?php

defined('ABSPATH') || exit();

class WC_Scanpay_SeqDB
{
    protected $shopID;
    protected $tablename;

    public function __construct($shopid)
    {
        global $wpdb;
        $this->tablename = $wpdb->prefix . 'woocommerce_scanpay_seq';
        $this->shopID = (int)$shopid;
        if ($shopid <= 0) {
            scanpay_log('critical', 'ShopID argument is not an unsigned int');
            return false;
        }
    }

    public function createTable()
    {
        // maybe use ... maybe_create_table ?
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->tablename'") != $this->tablename) {
            $sql = "CREATE TABLE $this->tablename (
                shopid BIGINT UNSIGNED NOT NULL PRIMARY KEY UNIQUE COMMENT 'Shop Id',
                seq BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Scanpay Events Sequence Number',
                mtime BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Modification Time'
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";

            // Not sure why this is included...
            dbDelta($sql);
        }
    }

    public function update_mtime()
    {
        global $wpdb;
        $mtime = time();
        $wpdb->query(
            "UPDATE $this->tablename SET mtime = $mtime WHERE shopid = $this->shopID"
        );
    }

    public function set_seq($seq)
    {
        global $wpdb;
        if (!is_int($seq)) {
            scanpay_log('critical', 'Seq is not an unsigned int');
            return false;
        }
        $mtime = time();
        $ret = $wpdb->query(
            "UPDATE $this->tablename SET seq = $seq, mtime = $mtime WHERE shopid = $this->shopID"
        );

        if ($ret == false) {
            scanpay_log('critical', 'Failed saving seq to database');
            return false;
        }
        return (int)!!$ret;
    }

    public function get_seq()
    {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT * FROM $this->tablename WHERE shopid = $this->shopID",
            ARRAY_A
        );
        return [
            'shopid' => (int)$row['shopid'],
            'seq' => (int)$row['seq'],
            'mtime' => (int)$row['mtime']
        ];
    }
}
