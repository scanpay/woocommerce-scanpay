<?php
defined('ABSPATH') || exit();

class WC_Scanpay_SeqDB
{
    private int $shopID;
    private string $tablename;

    public function __construct(int $shopid)
    {
        global $wpdb;
        $this->tablename = $wpdb->prefix . 'woocommerce_scanpay_seq';
        $this->shopID = $shopid;
    }

    public function create_table(): bool
    {
        global $wpdb, $charset_collate;
        require ABSPATH . 'wp-admin/includes/upgrade.php'; // dbDelta()
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
                return true;
            }
        }
        scanpay_log('critical', 'Failed creating table in database');
        return false;
    }

    public function update_mtime(): bool
    {
        global $wpdb;
        $mtime = time();
        $rows_affected = $wpdb->query("
            UPDATE $this->tablename
            SET mtime = $mtime
            WHERE shopid = $this->shopID
        "); // int|bool
        if ($rows_affected === false) {
            // match false. 0 rows updated is not an error
            scanpay_log('critical', 'Failed updating mtime in database');
            return false;
        }
        return true;
    }

    public function set_seq(int $seq): bool
    {
        global $wpdb;
        $mtime = time();
        $rows_affected = $wpdb->query("
            UPDATE $this->tablename
            SET seq = $seq, mtime = $mtime
            WHERE shopid = $this->shopID
        "); // int|bool
        if (!$rows_affected) {
            scanpay_log('critical', 'Failed saving seq to database: ' . $wpdb->last_error);
            return false;
        }
        return true;
    }

    public function get_seq(): array
    {
        global $wpdb;
        $row = $wpdb->get_row("
            SELECT *
            FROM $this->tablename
            WHERE shopid = $this->shopID
        ", ARRAY_A); // array|null
        if (!$row) {
            $this->create_table();
            $row = ['shopid' => $this->shopID];
        }
        return [
            'shopid' => (int) $row['shopid'],
            'seq' => (int) $row['seq'],
            'mtime' => (int) $row['mtime']
        ];
    }
}
