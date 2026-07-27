<?php

/**
 * Handle Scanpay pings ("callbacks"). Pings contain a sequence number
 * indicating that there are changes to be synchronized from Scanpay.
 *
 * Contract:
 * - HTTP method: POST
 * - Body: JSON object { seq: int, shopid: int }
 * - Header: X-Signature = base64(hmac_sha256(body, apikey))
 * - Pings are retried until we answer 200, and time out after ~7 s.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// Keep draining after Scanpay gives up and disconnects.
ignore_user_abort( true );
set_time_limit( 60 );

$settings = get_option( WC_SCANPAY_URI_SETTINGS );
$apikey   = $settings['apikey'] ?? '';
$shopid   = (int) strstr( $apikey, ':', true );

function wc_scanpay_respond( string $msg, int $code ): void {
	http_response_code( $code );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	header( 'Content-Length: ' . strlen( $msg ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text diagnostic; escaping would change the byte count already sent as Content-Length.
	echo $msg;
	exit;
}

/**
 * Read the shop's sync cursor. Fail loud: a DB error or a missing row must not
 * masquerade as seq=0 -- that would silently replay the full change history and
 * never persist a cursor.
 *
 * The returned seq is only trustworthy for as long as the caller holds the flock --
 * see the re-read in the sync loop below.
 *
 * @return array{seq: int, ping: int} Local cursor and the last recorded ping seq.
 */
function wc_scanpay_read_cursor( int $shopid ): array {
	global $wpdb;
	$row = $wpdb->get_row( "SELECT seq, ping FROM {$wpdb->prefix}scanpay_seq WHERE shopid = $shopid", ARRAY_A );
	if ( '' !== $wpdb->last_error ) {
		scanpay_log( 'error', "seq lookup failed: {$wpdb->last_error}" );
		wc_scanpay_respond( 'database error', 500 );
	}
	if ( null === $row ) {
		// No cursor row for this shop -- install/seed never ran for this API key.
		scanpay_log( 'error', "no scanpay_seq row for shopid $shopid" );
		wc_scanpay_respond( 'shop not configured', 500 );
	}
	return [
		'seq'  => (int) $row['seq'],
		// Nullable in the DDL, but install.php seeds ping = 0 and nothing else
		// inserts, so NULL is unreachable. Keep it that way: the busy path's
		// "ping < $ping_seq" can never match a NULL row, and it would report 0
		// rows rather than an error -- silently killing handoffs for that shop.
		'ping' => (int) $row['ping'],
	];
}

/*
 * Both order-cache modes must be listed: OrderCache::get_object_type() returns
 * 'order_objects' only when the HPOS datastore-caching option is 'yes' and 'orders'
 * otherwise -- and that option is off by default, so 'orders' is the active group on a
 * normal HPOS install.
 *
 * 'orders' is also the expensive one: 'order_objects' is registered non-persistent in
 * the very mode where it is used, while 'orders' is not. On a persistent drop-in
 * (Redis/Memcached) this therefore evicts full WC_Order objects site-wide, not just
 * this request's runtime cache. Accepted: it runs at most once every few sync
 * iterations and the groups are cheap to repopulate. It may equally do nothing --
 * wp_cache_flush_group() delegates straight to the drop-in, and one without
 * flush_group support leaves the backfill with no memory bound at all.
 */
function wc_scanpay_flush_order_runtime_cache(): void {
	wp_cache_flush_group( 'order_objects' );
	wp_cache_flush_group( 'orders' );
	wp_cache_flush_group( 'orders_data' );
	wp_cache_flush_group( 'orders_meta' );
}

function wc_scanpay_memory_usage_debug(): void {
	// Opt-in: this dump is noisy and only useful when profiling large syncs.
	if ( ! ( defined( 'WC_SCANPAY_DEBUG' ) && WC_SCANPAY_DEBUG ) ) {
		return;
	}
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
	// $wp_object_cache->cache is an internal of core's array cache; persistent
	// drop-ins (Redis/Memcached) may not expose it, so bail if it is not there.
	if ( ! is_array( $wp_object_cache->cache ?? null ) ) {
		return;
	}
	$groups = [];
	foreach ( $wp_object_cache->cache as $g => $items ) {
		$groups[ $g ] = is_countable( $items ) ? count( $items ) : 0; }
	arsort( $groups );
	scanpay_log( 'debug', '--- Object cache groups ---' );
	foreach ( array_slice( $groups, 0, 10, true ) as $g => $n ) {
		scanpay_log( 'debug', "cache group $g items=$n" );
	}
	scanpay_log( 'debug', '---------------------------' );
}

// Protocol guard: only POST is valid.
if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
	wc_scanpay_respond( 'method not allowed', 405 );
}

// Config guard: no stored API key means no shop to sync, and no key to verify with.
if ( ! $shopid ) {
	wc_scanpay_respond( 'apikey missing', 403 );
}

/*
 * Read and bound the body. Pings are tiny, so a hard cap at 512 bytes costs nothing
 * and keeps both memory use and the signature check cheap. Content-Length only sizes
 * the guard -- the read is verified against it below, so an inaccurate header fails.
 */
$cl = (int) sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ?? '' ) );
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

/*
 * SECURITY: authenticate the ping. Base64-encoded HMAC-SHA256 over the raw body with
 * the API key, compared with hash_equals to avoid a timing leak.
 */
$sig = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ?? '' ) );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard base64 HMAC-SHA256 signature encoding, not obfuscation.
if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $apikey, true ) ), $sig ) ) {
	wc_scanpay_respond( 'invalid signature', 403 );
}

// Throw rather than return null on malformed JSON; the 512-byte cap already bounds the
// nesting, so the depth argument is only a backstop.
try {
	$ping = json_decode( $body, true, 16, JSON_THROW_ON_ERROR );
} catch ( JsonException $e ) {
	wc_scanpay_respond( 'invalid json', 400 );
}

// Nothing but { seq: <int>, shopid: <our shop> }; is_int(), so a quoted "5" cannot pass.
if (
	! is_array( $ping ) || ! isset( $ping['seq'], $ping['shopid'] ) ||
	! is_int( $ping['seq'] ) || $shopid !== $ping['shopid']
) {
	wc_scanpay_respond( 'invalid ping', 400 );
}

global $wpdb;
$ping_seq = (int) $ping['seq'];

// Unlocked read: only good enough to pick a branch. The drain path re-reads it
// under the flock before touching anything.
$seq = wc_scanpay_read_cursor( $shopid )['seq'];

if ( $ping_seq < $seq ) {
	// Reject replayed or out-of-order pings.
	wc_scanpay_respond( "invalid ping seq: ping ($ping_seq) < local ($seq)", 400 );
}
if ( $ping_seq === $seq ) {
	// Heartbeat: nothing to sync, but record that Scanpay just reached us so the
	// settings "last sync" indicator stays fresh. mtime is display-only, so we do
	// not fail the ping if this update fails.
	$now = time();
	$wpdb->query( "UPDATE {$wpdb->prefix}scanpay_seq SET mtime = $now WHERE shopid = $shopid" );
	wc_scanpay_respond( 'ok', 200 );
}

require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-client.php';
require_once WC_SCANPAY_DIR . '/library/class-wc-scanpay-sync.php';
require_once WC_SCANPAY_DIR . '/library/class-scanpay-flock.php';

$client = new WC_Scanpay_Client( $apikey );
$sync   = new WC_Scanpay_Sync( $settings, $shopid );
$flock  = new Scanpay_Flock( $shopid );


/*
 * Concurrency control: one sync process at a time. acquire() returns false on genuine
 * contention (another worker holds the lock); it throws only when the lock file cannot
 * be created (e.g. a read-only temp dir). The latter is not contention -- no worker is
 * draining the queue -- so surface it as a retryable 503, not a success-ish "busy".
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
	$now      = time();
	$res_ping = $wpdb->query(
		"UPDATE {$wpdb->prefix}scanpay_seq
		SET ping = $ping_seq, mtime = $now
		WHERE shopid = $shopid AND ping < $ping_seq"
	);
	if ( false === $res_ping ) {
		// If we cannot hand the ping off, the running worker will not see it --
		// fail loud so the backend retries rather than stranding the ping.
		scanpay_log( 'error', "failed to record pending ping: {$wpdb->last_error}" );
		wc_scanpay_respond( 'database error', 500 );
	}
	wc_scanpay_respond( "busy: seq=$seq", 200 );
}

try {
	$start = microtime( true );

	$n = 0;
	// Seeded from $start so the first renewal is due 30 s into the drain; $start itself
	// stays the anchor of the elapsed-time debug line below.
	$limit_renewed = $start;
	do {
		/*
		 * We hold the lock now, so re-read the cursor: the read that picked this branch
		 * happened before acquire(), and an incumbent worker may have advanced it in
		 * between. Everything below -- above all the exactly-one-row invariant of the
		 * cursor UPDATE -- only holds for a $seq read under a continuously-held lock.
		 * Runs after every successful acquire(): the initial one and the re-acquire below.
		 *
		 * $target is how far this run must get: this ping, or a newer one a busy worker
		 * handed off in the ping column. It is the loop's single notion of "caught up",
		 * so $ping_seq keeps meaning exactly "the seq this ping announced".
		 *
		 * Having nothing outstanding is deliberately not special-cased: the loop is
		 * simply skipped and the release-and-recheck at the bottom acks. An early exit on
		 * $target <= $seq would read the ping column exactly once, at a moment a busy
		 * worker can still write to it -- the very race the recheck exists to close.
		 */
		$cursor = wc_scanpay_read_cursor( $shopid );
		$seq    = $cursor['seq'];
		$target = max( $ping_seq, $cursor['ping'] );

		while ( $target > $seq ) {
			$res     = $client->seq( $seq );
			$changes = $res['changes'];

			if ( [] === $changes ) {
				/*
				 * Protocol violation: seq() enforces monotonicity, so empty changes
				 * mean the backend served "you are at the end of the stream" for a
				 * cursor the ping said had data beyond it. The pinger and /v1/seq are
				 * consistent -- there is no visibility window -- so both cannot be
				 * true. Fail loud instead of acking a drain that never happened.
				 */
				throw new Exception( "backend announced seq $target but /v1/seq/$seq served no changes" );
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

			$seq     = (int) $res['seq'];
			$now     = time();
			$res_seq = $wpdb->query(
				"UPDATE {$wpdb->prefix}scanpay_seq SET seq = $seq, mtime = $now WHERE shopid = $shopid AND seq < $seq"
			);
			/*
			 * Fail loud: the flock makes us the only writer, so an advancing cursor must
			 * touch exactly one row. If it did not persist, do not ack -- the next ping
			 * would replay everything from the stale cursor.
			 */
			if ( false === $res_seq || $wpdb->rows_affected < 1 ) {
				throw new Exception( "failed to persist sync cursor to seq $seq: {$wpdb->last_error}" );
			}

			if ( $target > $seq ) {
				/*
				 * Long backfill: two bounds, on two cadences, deliberately not the same one.
				 *
				 * The grant is a time bound, and rounds are no proxy for it -- one round is a
				 * /v1/seq call, whose client-side budget is WC_Scanpay_Client::request()'s
				 * default $timeout = 40, plus a page of changes that can each build a WC_Order
				 * and write to the database. Renewing every sixth round can therefore outlast
				 * the 60 s last granted, and the kill lands mid-page: sync() calls
				 * payment_complete() per change while the cursor UPDATE runs only after the
				 * whole page, so the next ping replays a page whose orders it then skips for
				 * already carrying a transaction id -- wasted work, ending the same way, on
				 * every keepalive. set_time_limit() resets the counter rather than adding to
				 * it, so renewing before it is due costs nothing.
				 *
				 * The flush is a memory bound, and there rounds are a fair proxy for allocation.
				 */
				if ( microtime( true ) - $limit_renewed >= 30 ) {
					set_time_limit( 60 );
					$limit_renewed = microtime( true );
				}
				if ( ++$n > 5 ) {
					wc_scanpay_memory_usage_debug();
					wc_scanpay_flush_order_runtime_cache();
					$n = 0;
				}
			} else {
				// Caught up: check if we blocked a newer ping while syncing.
				$blocked_ping = wc_scanpay_read_cursor( $shopid )['ping'];
				if ( $blocked_ping > $seq ) {
					scanpay_log( 'debug', "Resuming sync to blocked ping seq $blocked_ping (current seq $seq)" );
					$target = $blocked_ping;
				}
			}
			$elapsed = microtime( true ) - $start;
			scanpay_log( 'debug', "Sync loop: updated to seq $seq; elapsed time: $elapsed" );
		}

		/*
		 * Close the busy-path race: a ping recorded between the final in-loop read and
		 * release() would otherwise be stranded -- the busy pinger already got a 200 and
		 * will not retry. After releasing, re-read the ping column; if it names a seq we
		 * have not reached, re-acquire and resume. If another worker took the lock
		 * meanwhile it owns the drain (it re-reads the same column), so we can stop.
		 */
		$flock->release();
		$target = max( $target, wc_scanpay_read_cursor( $shopid )['ping'] );
		if ( $target <= $seq ) {
			break;
		}
		scanpay_log( 'debug', "Re-acquiring lock for blocked ping seq $target (current seq $seq)" );
	} while ( $flock->acquire() );
	/*
	 * The inner loop only exits once $seq reached $target, so "completed" holds for every
	 * exit but one: a failed re-acquire hands an outstanding ping to the worker that took
	 * the lock, leaving us short. That ping is always in the ping column -- $target can
	 * only exceed $seq here by way of the re-read above -- so the new holder will see it.
	 */
	if ( $seq < $target ) {
		scanpay_log( 'info', "Sync handed off at seq $seq: another worker holds the lock for ping seq $target" );
	} else {
		scanpay_log( 'info', "Sync completed to seq $seq" );
	}
	wc_scanpay_respond( 'ok', 200 );
} catch ( Throwable $e ) {
	$flock->release();
	$errmsg = 'error: ' . trim( $e->getMessage() );
	scanpay_log( 'error', $errmsg );
	wc_scanpay_respond( $errmsg, 500 );
}
