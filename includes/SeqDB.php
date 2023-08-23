<?php
defined('ABSPATH') || exit();

class WC_Scanpay_SeqDB
{
    private $shopID;
    private $tablename;

    public function __construct(int $shopid)
    {
        global $wpdb;
        $this->tablename = $wpdb->prefix . 'woocommerce_scanpay_seq';
        $this->shopID = $shopid;
    }

    public function create_table()
    {
        global $wpdb, $charset_collate;
        require ABSPATH . 'wp-admin/includes/upgrade.php'; // dbDelta()
        // dbDelta: returns an array with completed SQL statements
        $array = dbDelta("
            CREATE TABLE $this->tablename (
                shopid INT UNSIGNED NOT NULL,
                seq INT UNSIGNED NOT NULL,
                mtime BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (shopid)
            ) $charset_collate;
        "); // array of successful SQL operations
        if (!empty($array)) {
            scanpay_log('info', $array[0]);
            $rows_affected = $wpdb->query("
                INSERT IGNORE INTO $this->tablename
                SET shopid = $this->shopID, seq = 0, mtime = 0
            "); // int|bool
            if ($rows_affected) {
                return;
            }
        }
        scanpay_log('critical', 'Failed creating table in database');
    }

    public function update_mtime()
    {
        global $wpdb;
        $mtime = time();
        $rows_affected = $wpdb->query("
            UPDATE $this->tablename
            SET mtime = $mtime
            WHERE shopid = $this->shopID
        "); // int|bool
        if (!$rows_affected) {
            scanpay_log('critical', 'Failed updating mtime in database');
            return false;
        }
        return true;
    }

    public function set_seq(int $seq)
    {
        global $wpdb;
        $mtime = time();
        $rows_affected = $wpdb->query("
            UPDATE $this->tablename
            SET seq = $seq, mtime = $mtime
            WHERE shopid = $this->shopID
        "); // int|bool
        if (!$rows_affected) {
            scanpay_log('critical', 'Failed saving seq to database');
            return false;
        }
        return true;
    }

    public function get_seq()
    {
        global $wpdb;
        $row = $wpdb->get_row("
            SELECT *
            FROM $this->tablename
            WHERE shopid = $this->shopID
        ", ARRAY_A); // array|null|object
        if (!$row) {
            scanpay_log('error', 'Failed fetching seq from database');
            return false;
        }
        return [
            'shopid' => (int) $row['shopid'],
            'seq' => (int) $row['seq'],
            'mtime' => (int) $row['mtime']
        ];
    }
}
