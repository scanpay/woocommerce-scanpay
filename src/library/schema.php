<?php

/**
 * Column introspection for our own tables, for upgrade.php and the reset endpoint.
 * Kept out of library/functions.php, which far more code loads: only these two need a
 * column list.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * $table must be a $wpdb->prefix-derived name: an identifier cannot be bound, only the
 * LIKE pattern can. A missing table is MySQL error 1146 and yields [], so a caller that
 * must tell "absent" from "read failed" reads $wpdb->last_error.
 *
 * @return string[]
 */
function wc_scanpay_table_columns( string $table ): array {
	global $wpdb;
	return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", '%' ) ) );
}
