<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/**
 * Reads the column names of one of our own tables. Two admin-only callers need it:
 * upgrade.php decides which obsolete 2.x columns to drop, and the reset endpoint
 * checks the recreated tables carry the v3 column set. It stays out of
 * library/functions.php, which the card gateway loads on every front-end request.
 *
 * $table must be a $wpdb->prefix-derived name: an identifier cannot be bound, only
 * the LIKE pattern can. A missing table is a MySQL error (1146) and yields [], so a
 * caller that must tell "absent" from "read failed" reads $wpdb->last_error.
 *
 * @return string[]
 */
function wc_scanpay_table_columns( string $table ): array {
	global $wpdb;
	return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", '%' ) ) );
}
