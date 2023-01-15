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
        $this->shopID = (int) $shopid;
        if ($shopid <= 0) {
            scanpay_log('critical', 'ShopID argument is not an unsigned int');
            return false;
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
