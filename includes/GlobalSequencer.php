<?php

namespace Scanpay;
if (!defined('ABSPATH')) {
    exit;
}

class GlobalSequencer
{
    protected $wpdb;
    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function updateMtime($shopId) {
        $q = $this->wpdb->prepare('UPDATE `scanpay_seq` SET `mtime` = %d ' .
            'WHERE `shopid` = %d', time(), $shopId);
        $this->wpdb->query($q);
    }

    public function insert($shopId)
    {
        if (!is_int($shopId) || $shopId <= 0) {
            error_log('ShopId argument is not an unsigned int');
            return false;
        }
        $q = $this->wpdb->prepare('INSERT IGNORE INTO `scanpay_seq' .
            'SET `shopid` = %d, `seq` = 0, `mtime` = 0 ' , $shopId);
        $this->wpdb->query($q);
    }

    public function save($shopId, $seq)
    {
        if (!is_int($shopId) || $shopId <= 0) {
            error_log('ShopId argument is not an unsigned int');
            return false;
        }

        if (!is_int($seq) || $seq < 0) {
            error_log('Sequence argument is not an unsigned int');
            return false;
        }

        $q = $this->wpdb->prepare('UPDATE `scanpay_seq` SET `seq` = %d, `mtime` = %d ' .
            'WHERE `shopid` = %d AND `seq` < %d', $seq, time(), $shopId, $seq);
        $ret = $this->wpdb->query($q);
        if ($ret === 0) {
            $this->updateMtime($shopId);
        }
        return !!$ret;
    }

    public function load($shopId)
    {
        $q = $this->wpdb->prepare('SELECT * FROM `scanpay_seq` WHERE `shopid` = %d', $shopId);
        $row = $this->wpdb->get_row($q);
        if (!$row) {
            return false;
        }
        return [ 'shopid' => $row['shopid'], 'seq' => $row['seq'], 'mtime' => $row['mtime'] ];
    }

}
