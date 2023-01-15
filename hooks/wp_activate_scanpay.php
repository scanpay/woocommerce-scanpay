<?php

/*
*   Hook: activate_PLUGINNAME
*   Called when admin activates the plugin.
*   We use the hook to create our tables.
*/

defined('ABSPATH') || exit();

global $wpdb, $charset_collate;
require ABSPATH . 'wp-admin/includes/upgrade.php';
$tablename = $wpdb->prefix . 'woocommerce_scanpay_seq';

dbDelta(
    "CREATE TABLE $tablename (
        shopid INT UNSIGNED NOT NULL,
        seq INT UNSIGNED NOT NULL,
        mtime BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY  (shopid)
    ) $charset_collate;"
);
