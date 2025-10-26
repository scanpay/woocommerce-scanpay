<?php

/**
 * Handle Scanpay pings ("callbacks"). Pings contain a sequence number
 * indicating that there are changes to be synchronized from Scanpay.
 *
 * Contract:
 * - HTTP method: POST
 * - Body: JSON object { seq: int, shopid: int }
 * - Header: X-Signature = base64(hmac_sha256(body, apikey))
 * - Pings are retried until we respond with "ok" (200 OK).
 * - Pings timeout after ~7 seconds
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// Ignore ping timeout
ignore_user_abort( true );
set_time_limit( 60 );

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$apikey   = $settings['apikey'] ?? '';
$shopid   = (int) strstr( $apikey, ':', true );

function respond( string $msg, int $code ): void {
	http_response_code( $code );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'Connection: close' );
	header( 'Content-Length: ' . strlen( $msg ) );
	echo $msg;
	exit;
}

function scanpay_flush_order_runtime_cache(): void {
	wp_cache_flush_group( 'order_objects' );
	wp_cache_flush_group( 'orders_data' );
	wp_cache_flush_group( 'orders_meta' );
}

function scanpay_memory_usage_debug(): void {
	$php_mem     = memory_get_usage(false);
	$php_mem_real = memory_get_usage(true);
	$php_peak    = memory_get_peak_usage(true);
	$rusage      = getrusage();
	$maxrss      = (int) ($rusage['ru_maxrss'] ?? 0); // kB

	scanpay_log(
		'debug',
		sprintf(
			"Memory usage: php=%.1fMB real=%.1fMB peak=%.1fMB maxrss=%.1fMB",
			$php_mem / 1048576,
			$php_mem_real / 1048576,
			$php_peak / 1048576,
			$maxrss / 1024
		)
	);

	global $wp_object_cache;
	$groups = [];
	foreach ($wp_object_cache->cache as $g => $items) { $groups[$g] = count($items); }
	arsort($groups);
	scanpay_log('debug', "--- Object cache groups ---");
	foreach (array_slice($groups, 0, 10, true) as $g => $n) {
		scanpay_log('debug', "cache group $g items=$n");
	}
	scanpay_log('debug', "---------------------------");
}



// Protocol guard: only POST is valid.
if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	respond( 'method not allowed', 405 );
}

// Config guard: must have valid apikey and shopid.
if ( ! $shopid ) {
	respond( 'apikey missing', 403 );
}

/**
 * Read and constrain body size. We are behind Nginx/Apache, so we can trust
 * Content-Length to be accurate. Hard cap at 512 bytes to avoid memory
 * abuse and keep signature checks cheap.
 */
$cl = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
if ( $cl <= 0 ) {
	respond( 'invalid content-length', 400 );
}
if ( $cl > 512 ) {
	respond( 'payload too large', 413 );
}
$body = file_get_contents( 'php://input' );
if ( false === $body || strlen( $body ) !== $cl ) {
	respond( 'body read failed', 400 );
}

/**
 * SECURITY: Authenticate the ping.
 * Compute base64-encoded HMAC-SHA256 over the raw body with the API key.
 * We use hash_equals to avoid timing leaks on comparison.
 */
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $apikey, true ) ), $sig ) ) {
	respond( 'invalid signature', 403 );
}

/**
 * Parse JSON strictly and fail fast on errors.
 * Limit recursion depth to reduce risk from malicious inputs.
 */
try {
	$ping = json_decode( $body, true, 16, JSON_THROW_ON_ERROR );
} catch ( JsonException $e ) {
	respond( 'invalid json', 400 );
}

// Validate ping structure
if (
	! is_array( $ping ) || ! isset( $ping['seq'], $ping['shopid'] ) ||
	! is_int( $ping['seq'] ) || $shopid !== $ping['shopid']
) {
	respond( 'invalid ping', 400 );
}

global $wpdb;
$ping_seq = (int) $ping['seq'];
$seq      = (int) $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );

if ( $ping_seq < $seq ) {
	// Reject replayed or out-of-order pings.
	respond( "invalid ping seq: ping ($ping_seq) < local ($seq)", 400 );
}
if ( $ping_seq === $seq ) {
	respond( 'ok', 200 );
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-sync.php';
require_once WC_SCANPAY_DIR . '/library/class-scanpay-flock.php';

$client = new WC_Scanpay_Client( $apikey );
$sync   = new WC_Scanpay_Sync( $settings, $shopid );
$flock  = new Scanpay_Flock( $shopid );


/**
 * Concurrency control: Allow only one sync process at a time.
 * If locked, record the latest ping and return 200 for retry.
 */
if ( ! $flock->acquire() ) {
	$wpdb->query(
		"UPDATE {$wpdb->prefix}scanpay_seq
		SET ping = $ping_seq
		WHERE shopid = $shopid AND ping < $ping_seq"
	);
	respond( "busy: seq=$seq", 200 );
}

try {
	$start = microtime( true );

	$n = 0;
	while ( $ping_seq > $seq ) {
		$res     = $client->seq( $seq );
		$changes = $res['changes'];

		if ( [] === $changes ) {
			// Prevent infinite loop on bad ping_seq
			break;
		}
		foreach ( $changes as $c ) {
			$ctype = $c['type'] ?? null;
			if ( ! is_string( $ctype ) ) {
				throw new Exception( 'invalid change type from seq' );
			}
			if ( 'transaction' === $ctype || 'charge' === $ctype ) {
				$sync->payment( $c );
			} elseif ( 'subscriber' === $ctype ) {
				$sync->subscriber( $c );
			}
		}

		// Save new sequence number to the database
		$seq = (int) $res['seq'];
		$wpdb->query(
			"UPDATE {$wpdb->prefix}scanpay_seq SET seq = $seq WHERE shopid = $shopid AND seq < $seq"
		);

		if ( $ping_seq > $seq ) {
			/**
			 * Prevent timeout and memory exhaustion on long sync loops
			 */
			if ( ++$n > 5 ) {
				set_time_limit( 60 );
				scanpay_memory_usage_debug();
				scanpay_flush_order_runtime_cache();
				$n = 0;
			}
		} else {
			// Check if we blocked a newer ping while syncing
			$blocked_ping = (int) $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
			if ( $blocked_ping > $ping_seq ) {
				scanpay_log( 'debug', "Resuming sync to blocked ping seq $blocked_ping (current seq $seq)" );
				$ping_seq = $blocked_ping;
			}
		}
		$elapsed = microtime( true ) - $start;
		scanpay_log( 'debug', "Sync loop: updated to seq $seq; elapsed time: $elapsed" );
	}

	scanpay_log( 'info', "Sync completed to seq $seq" );
	$flock->release();
	respond( 'ok', 200 );
} catch ( Throwable $e ) {
	$flock->release();
	$errmsg = 'error: ' . trim( $e->getMessage() );
	scanpay_log( 'error', $errmsg );
	respond( $errmsg, 500 );
}
