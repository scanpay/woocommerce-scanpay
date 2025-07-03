<?php
defined( 'ABSPATH' ) || exit();

/**
 * Handle Scanpay ping (callback) requests.
 * Validates input, signature, sequence numbers, and ensures synchronized processing with file-based locking.
 */

ignore_user_abort( true );

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$apikey   = $settings['apikey'] ?? '';
$shopid   = (int) strstr( $apikey, ':', true );

if ( empty( $shopid ) ) {
	scanpay_log( 'error', 'Ping rejected: missing or invalid shop ID.' );
	wp_send_json( [ 'error' => 'invalid shopid' ], 400 );
	return;
}

$body = file_get_contents( 'php://input', false, null, 0, 512 );
if ( false === $body ) {
	scanpay_log( 'error', 'Failed to read input from php://input' );
	wp_send_json( [ 'error' => 'failed to read input' ], 400 );
	return;
}

if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $apikey, true ) ), $_SERVER['HTTP_X_SIGNATURE'] ) ) {
	wp_send_json( [ 'error' => 'invalid signature' ], 403 );
	return;
}

$ping = json_decode( $body, true );
if (
	! is_array( $ping ) || ! isset( $ping['seq'], $ping['shopid'] ) ||
	! is_int( $ping['seq'] ) || $shopid !== $ping['shopid']
) {
	scanpay_log( 'error', 'Invalid ping from server' );
	wp_send_json( [ 'error' => 'invalid ping' ], 403 );
	return;
}

global $wpdb;
$seq = (int) $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $shopid );

if ( $ping['seq'] === $seq ) {
	$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . " WHERE shopid = $shopid" );
	wp_send_json_success();
	return;
}

if ( $ping['seq'] < $seq ) {
	scanpay_log( 'error', "Scanpay Sync Error: local seq ($seq) is greater than ping seq ({$ping['seq']})" );
	wp_send_json( [ 'error' => "local seq ($seq) is greater than ping seq ({$ping['seq']})" ], 400 );
	return;
}

require WC_SCANPAY_DIR . '/library/class-scanpay-flock.php';
$flock = new Scanpay_Flock( $shopid );

if ( ! $flock->acquire() ) {
	// Another process is running; save ping.
	$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET ping = GREATEST(ping, $ping[seq]) WHERE shopid = $shopid" );
	wp_send_json( [ 'error' => 'busy' ], 423 );
	return;
}

require WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
require WC_SCANPAY_DIR . '/library/class-wc-scanpay-sync.php';

$client = new WC_Scanpay_Client( $apikey );
$sync   = new WC_Scanpay_Sync( $settings, $shopid );

try {
	while ( $ping['seq'] > $seq ) {
		$res = $client->seq( $seq );
		$sync->process_changes( $res['changes'], $seq );
		$seq = $res['seq'];

		// Update the seq in the database
		$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = " . time() . ", seq = $seq WHERE shopid = " . $shopid );

		if ( $ping['seq'] > $seq ) {
			$flock->renew();
			set_time_limit( 60 );
			wp_cache_flush();
		} else {
			// Check if ping seq has changed while we were processing changes
			$ping['seq'] = (int) $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = " . $shopid );
		}
	}
	$flock->release();
	wp_send_json_success();
} catch ( Exception $e ) {
	$flock->release();
	$str = trim( $e->getMessage() );
	scanpay_log( 'error', "Scanpay Sync Error: $str" );
	wp_send_json( [ 'error' => $str ], 500 );
}
