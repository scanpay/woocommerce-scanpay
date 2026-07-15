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

function wc_scanpay_respond( string $msg, int $code ): void {
	http_response_code( $code );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'Connection: close' );
	header( 'Content-Length: ' . strlen( $msg ) );
	echo $msg;
	exit;
}

function wc_scanpay_flush_order_runtime_cache(): void {
	wp_cache_flush_group( 'order_objects' );
	wp_cache_flush_group( 'orders_data' );
	wp_cache_flush_group( 'orders_meta' );
}

function wc_scanpay_memory_usage_debug(): void {
	$php_mem      = memory_get_usage( false );
	$php_mem_real = memory_get_usage( true );
	$php_peak     = memory_get_peak_usage( true );
	$rusage       = getrusage();
	$maxrss       = (int) ( $rusage['ru_maxrss'] ?? 0 ); // kB

	scanpay_log(
		'debug',
		sprintf(
			'Memory usage: php=%.1fMB real=%.1fMB peak=%.1fMB maxrss=%.1fMB',
			$php_mem / 1048576,
			$php_mem_real / 1048576,
			$php_peak / 1048576,
			$maxrss / 1024
		)
	);

	global $wp_object_cache;
	$groups = [];
	foreach ( $wp_object_cache->cache as $g => $items ) {
		$groups[ $g ] = count( $items ); }
	arsort( $groups );
	scanpay_log( 'debug', '--- Object cache groups ---' );
	foreach ( array_slice( $groups, 0, 10, true ) as $g => $n ) {
		scanpay_log( 'debug', "cache group $g items=$n" );
	}
	scanpay_log( 'debug', '---------------------------' );
}



// Protocol guard: only POST is valid.
if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	wc_scanpay_respond( 'method not allowed', 405 );
}

// Config guard: must have valid apikey and shopid.
if ( ! $shopid ) {
	wc_scanpay_respond( 'apikey missing', 403 );
}

/**
 * Read and constrain body size. We are behind Nginx/Apache, so we can trust
 * Content-Length to be accurate. Hard cap at 512 bytes to avoid memory
 * abuse and keep signature checks cheap.
 */
$cl = (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 );
if ( $cl <= 0 ) {
	wc_scanpay_respond( 'invalid content-length', 400 );
}
if ( $cl > 512 ) {
	wc_scanpay_respond( 'payload too large', 413 );
}
$body = file_get_contents( 'php://input' );
if ( false === $body || strlen( $body ) !== $cl ) {
	wc_scanpay_respond( 'body read failed', 400 );
}

/**
 * SECURITY: Authenticate the ping.
 * Compute base64-encoded HMAC-SHA256 over the raw body with the API key.
 * We use hash_equals to avoid timing leaks on comparison.
 */
$sig = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $apikey, true ) ), $sig ) ) {
	wc_scanpay_respond( 'invalid signature', 403 );
}

/**
 * Parse JSON strictly and fail fast on errors.
 * Limit recursion depth to reduce risk from malicious inputs.
 */
try {
	$ping = json_decode( $body, true, 16, JSON_THROW_ON_ERROR );
} catch ( JsonException $e ) {
	wc_scanpay_respond( 'invalid json', 400 );
}

// Validate ping structure
if (
	! is_array( $ping ) || ! isset( $ping['seq'], $ping['shopid'] ) ||
	! is_int( $ping['seq'] ) || $shopid !== $ping['shopid']
) {
	wc_scanpay_respond( 'invalid ping', 400 );
}

global $wpdb;
$ping_seq = (int) $ping['seq'];

/**
 * Read the shop's sync cursor. Fail loud: a DB error or a missing row must not
 * masquerade as seq=0 — that would silently replay the full change history and
 * never persist a cursor.
 */
$seq_row = $wpdb->get_var( "SELECT seq FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
if ( '' !== $wpdb->last_error ) {
	scanpay_log( 'error', "seq lookup failed: {$wpdb->last_error}" );
	wc_scanpay_respond( 'database error', 500 );
}
if ( null === $seq_row ) {
	// No cursor row for this shop — install/seed never ran for this API key.
	scanpay_log( 'error', "no scanpay_seq row for shopid $shopid" );
	wc_scanpay_respond( 'shop not configured', 500 );
}
$seq = (int) $seq_row;

if ( $ping_seq < $seq ) {
	// Reject replayed or out-of-order pings.
	wc_scanpay_respond( "invalid ping seq: ping ($ping_seq) < local ($seq)", 400 );
}
if ( $ping_seq === $seq ) {
	wc_scanpay_respond( 'ok', 200 );
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-sync.php';
require_once WC_SCANPAY_DIR . '/library/class-scanpay-flock.php';

$client = new WC_Scanpay_Client( $apikey );
$sync   = new WC_Scanpay_Sync( $settings, $shopid );
$flock  = new Scanpay_Flock( $shopid );


/**
 * Concurrency control: Allow only one sync process at a time.
 * acquire() returns false on genuine contention (another worker holds the
 * lock); it throws only when the lock file cannot be created (e.g. read-only
 * temp dir). The latter is not contention — no worker is draining the queue —
 * so surface it as a retryable 503 instead of a success-ish "busy".
 */
try {
	$locked = $flock->acquire();
} catch ( Throwable $e ) {
	scanpay_log( 'error', 'lock init failed: ' . trim( $e->getMessage() ) );
	wc_scanpay_respond( 'lock unavailable', 503 );
}

if ( ! $locked ) {
	// Contention: record the latest ping so the running worker drains it, then
	// 200 so the backend stops retrying this delivery.
	$res_ping = $wpdb->query(
		"UPDATE {$wpdb->prefix}scanpay_seq
		SET ping = $ping_seq
		WHERE shopid = $shopid AND ping < $ping_seq"
	);
	if ( false === $res_ping ) {
		// If we cannot hand the ping off, the running worker will not see it —
		// fail loud so the backend retries rather than stranding the ping.
		scanpay_log( 'error', "failed to record pending ping: {$wpdb->last_error}" );
		wc_scanpay_respond( 'database error', 500 );
	}
	wc_scanpay_respond( "busy: seq=$seq", 200 );
}

try {
	$start = microtime( true );

	$n = 0;
	do {
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
				if ( 'transaction' === $ctype ) {
					$sync->transaction( $c );
				} elseif ( 'charge' === $ctype ) {
					$sync->charge( $c );
				} elseif ( 'subscriber' === $ctype ) {
					$sync->subscriber( $c );
				}
			}

			// Save new sequence number to the database
			$seq     = (int) $res['seq'];
			$res_seq = $wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_seq SET seq = $seq WHERE shopid = $shopid AND seq < $seq"
			);
			/**
			 * Fail loud: the flock makes us the only writer, so an advancing cursor
			 * must touch exactly one row. If it did not persist, do not ack —
			 * otherwise the next ping replays everything from the stale cursor.
			 */
			if ( false === $res_seq || $wpdb->rows_affected < 1 ) {
				throw new Exception( "failed to persist sync cursor to seq $seq: {$wpdb->last_error}" );
			}

			if ( $ping_seq > $seq ) {
				/**
				 * Prevent timeout and memory exhaustion on long sync loops
				 */
				if ( ++$n > 5 ) {
					set_time_limit( 60 );
					wc_scanpay_memory_usage_debug();
					wc_scanpay_flush_order_runtime_cache();
					$n = 0;
				}
			} else {
				// Check if we blocked a newer ping while syncing
				$blocked_ping = (int) $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
				if ( '' !== $wpdb->last_error ) {
					throw new Exception( "ping lookup failed: {$wpdb->last_error}" );
				}
				if ( $blocked_ping > $ping_seq ) {
					scanpay_log( 'debug', "Resuming sync to blocked ping seq $blocked_ping (current seq $seq)" );
					$ping_seq = $blocked_ping;
				}
			}
			$elapsed = microtime( true ) - $start;
			scanpay_log( 'debug', "Sync loop: updated to seq $seq; elapsed time: $elapsed" );
		}

		/**
		 * Close the busy-path race: a ping recorded between the final in-loop
		 * read above and release() would otherwise be stranded — the busy pinger
		 * already got a 200 and will not retry. After releasing, re-read the ping
		 * column; if a ping newer than this run targeted arrived, re-acquire and
		 * resume. If another worker took the lock meanwhile, it owns the drain
		 * (it re-reads the same column), so we can stop. Compare against $ping_seq,
		 * not $seq, so the empty-changes escape hatch above cannot re-trigger.
		 */
		$flock->release();
		$blocked_ping = (int) $wpdb->get_var( "SELECT ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid" );
		if ( '' !== $wpdb->last_error ) {
			throw new Exception( "ping lookup failed: {$wpdb->last_error}" );
		}
		if ( $blocked_ping <= $ping_seq ) {
			break;
		}
		$ping_seq = $blocked_ping;
		scanpay_log( 'debug', "Re-acquiring lock for blocked ping seq $blocked_ping (current seq $seq)" );
	} while ( $flock->acquire() );
	scanpay_log( 'info', "Sync completed to seq $seq" );
	wc_scanpay_respond( 'ok', 200 );
} catch ( Throwable $e ) {
	$flock->release();
	$errmsg = 'error: ' . trim( $e->getMessage() );
	scanpay_log( 'error', $errmsg );
	wc_scanpay_respond( $errmsg, 500 );
}
