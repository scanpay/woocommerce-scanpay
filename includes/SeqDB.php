<?php

defined('ABSPATH') || exit();

class WC_Scanpay_SeqDB
{
    private $shopID;
    private $tablename;

    public function __construct($shopid)
    {
        global $wpdb;
        $this->tablename = $wpdb->prefix . 'woocommerce_scanpay_seq';
        $this->shopID = (int) $shopid;
        if ($shopid <= 0) {
            scanpay_log('critical', 'ShopID argument is not an unsigned int');
            return false;
        }
    }

    public function create_tables()
    {
        global $wpdb, $charset_collate;
        require ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE $this->tablename (
                shopid INT UNSIGNED NOT NULL,
                seq INT UNSIGNED NOT NULL,
                mtime BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (shopid)
            ) $charset_collate;"
        );

        $wpdb->query(
            "INSERT IGNORE INTO $this->tablename SET shopid = $this->shopID, seq = 0, mtime = 0"
        );
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
        if ($row) {
            return [
                'shopid' => (int) $row['shopid'],
                'seq' => (int) $row['seq'],
                'mtime' => (int) $row['mtime']
            ];
        }
        return false;
    }
}
