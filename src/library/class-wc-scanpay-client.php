<?php

/*
 *  Scanpay module client lib
 *  Version 4.1.0 (2026-07-15)
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

/** Client for the Scanpay API: the only code here that talks to api.scanpay.dk. */
final class WC_Scanpay_Client {
	private \CurlHandle $ch;
	private array $headers;
	private bool $idem         = false;
	private string $idemstatus = '';

	/** Initializes the API client. */
	public function __construct( string $apikey ) {
		$this->ch      = curl_init();
		$this->headers = [
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, which RFC 7617 defines as base64; not obfuscation.
			'Authorization: Basic ' . base64_encode( $apikey ),
			'X-Shop-Plugin: WC-' . WC_SCANPAY_VERSION . '/' . WC()->version . '; PHP-' . PHP_VERSION,
			'Accept: application/json',
			'Expect: ', // Avoid the 100-continue round trip.
		];
	}

	/** Closes the cURL handle. */
	public function __destruct() {
		if ( isset( $this->ch ) ) {
			curl_close( $this->ch );
		}
	}

	/**
	 * Records the Idempotency-Status response header.
	 *
	 * @return int Bytes consumed; libcurl aborts the transfer on any other value.
	 */
	private function header_callback( \CurlHandle $ch, string $line ): int {
		if ( stripos( $line, 'Idempotency-Status:' ) === 0 ) {
			$this->idem       = true;
			$this->idemstatus = strtolower( trim( substr( $line, 19 ) ) );
		}
		return strlen( $line );
	}

	/**
	 * Reduces a response body to a short, single-line error message; masks multiline
	 * or oversized bodies (proxy/WAF error pages).
	 */
	private function error_body( string $body ): string {
		$body = rtrim( $body, "\r\n" );
		if ( '' === $body || strlen( $body ) > 512 || false !== strpbrk( $body, "\r\n" ) ) {
			return 'server error';
		}
		return $body;
	}

	/**
	 * Sends a request to the Scanpay API. $timeout is the total budget in seconds, so it
	 * also bounds the connect phase; a non-null $data makes the request a JSON POST.
	 *
	 * @throws \JsonException On invalid JSON.
	 * @throws \RuntimeException On transport or API errors.
	 */
	private function request( string $path, ?array $data = null, array $hdrs = [], int $timeout = 40 ): array {
		$this->idem  = false;
		$expect_idem = false;

		$headers  = $this->headers;
		$curlopts = [
			CURLOPT_URL               => 'https://api.scanpay.dk' . $path,
			CURLOPT_TCP_KEEPALIVE     => 1,
			CURLOPT_RETURNTRANSFER    => 1,
			CURLOPT_CONNECTTIMEOUT    => 20,
			CURLOPT_TIMEOUT           => $timeout,
			CURLOPT_DNS_CACHE_TIMEOUT => 180,
			CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_1_1,
			// No signals in PHP SAPIs. On sync-resolver builds (rare; threaded
			// is the default since 2017) timeouts cannot interrupt DNS lookups;
			// accepted over re-enabling thread-unsafe SIGALRM handling.
			CURLOPT_NOSIGNAL          => 1,
		];
		if ( null !== $data ) {
			$headers[] = 'Content-Type: application/json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode()'s only addition is a sanity-check fallback that rewrites invalid UTF-8; a payment payload must fail loudly instead, which JSON_THROW_ON_ERROR is here to do.
			$curlopts[ CURLOPT_POSTFIELDS ] = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE );
		}
		if ( ! empty( $hdrs ) ) {
			foreach ( $hdrs as $k => $v ) {
				$headers[] = $k . ': ' . $v;
			}
			if ( isset( $hdrs['Idempotency-Key'] ) ) {
				$expect_idem                        = true;
				$curlopts[ CURLOPT_HEADERFUNCTION ] = [ $this, 'header_callback' ];
			}
		}
		$curlopts[ CURLOPT_HTTPHEADER ] = $headers;

		curl_reset( $this->ch );
		curl_setopt_array( $this->ch, $curlopts );
		$result = curl_exec( $this->ch );
		if ( false === $result ) {
			$err    = curl_strerror( curl_errno( $this->ch ) );
			$detail = curl_error( $this->ch );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( $detail ? "$err: $detail" : $err );
		}

		$status_code = (int) curl_getinfo( $this->ch, CURLINFO_RESPONSE_CODE );
		if ( 200 !== $status_code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( $status_code . ' ' . $this->error_body( (string) $result ) );
		}
		if ( $expect_idem && ! $this->idem ) {
			throw new \RuntimeException( 'Missing Idempotency-Status header' );
		}
		if ( $this->idem && 'ok' !== $this->idemstatus ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not browser output.
			throw new \RuntimeException( 'Server failed to provide idempotency: ' . $this->error_body( (string) $result ) );
		}
		$json = json_decode( (string) $result, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Invalid JSON response from server' );
		}
		return $json;
	}

	/**
	 * Builds the X-Cardholder-IP header for the current request.
	 *
	 * REMOTE_ADDR is normally the SAPI's own TCP peer address and safe to trust, but
	 * sites behind a CDN or proxy very commonly run a plugin that overwrites it from
	 * a client-controlled header (X-Forwarded-For, CF-Connecting-IP) without
	 * validating. libcurl does not sanitize header values, so a CRLF in there would
	 * append real headers to this authenticated request -- letting a customer force
	 * e.g. an Idempotency-Key onto a call that never expects one. Validate the
	 * address and simply omit the header when it is not an IP.
	 */
	private function cardholder_ip_header(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- filter_var( FILTER_VALIDATE_IP ) is the validation; anything else yields false and the header is omitted.
		$ip = filter_var( $_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP );
		return is_string( $ip ) ? [ 'X-Cardholder-IP' => $ip ] : [];
	}

	/**
	 * Creates a payment link and returns its URL.
	 *
	 * @throws \JsonException On invalid JSON.
	 * @throws \RuntimeException On transport, API, or validation errors.
	 */
	public function new_url( array $data ): string {
		$res = $this->request( '/v1/new', $data, $this->cardholder_ip_header(), 10 );
		if ( isset( $res['url'] ) && filter_var( $res['url'], FILTER_VALIDATE_URL ) ) {
			return $res['url'];
		}
		throw new \RuntimeException( 'Invalid response from server' );
	}

	/**
	 * Gets the changes after sequence number $n, enforcing monotonicity: the returned seq
	 * must advance when there are changes, and equal $n when there are none.
	 *
	 * @throws \JsonException On invalid JSON.
	 * @throws \RuntimeException On transport, API, or validation errors.
	 */
	public function seq( int $n ): array {
		$res     = $this->request( "/v1/seq/$n" );
		$seq     = $res['seq'] ?? null;
		$changes = $res['changes'] ?? null;
		if ( ! is_int( $seq ) || ! is_array( $changes ) ) {
			throw new \RuntimeException( 'received invalid seq' );
		}
		$has = ( [] !== $changes );
		if ( ( $has && $seq <= $n ) || ( ! $has && $seq !== $n ) ) {
			throw new \RuntimeException( 'invalid seq monotonicity' );
		}
		return $res;
	}

	/**
	 * Captures a transaction.
	 *
	 * @throws \JsonException On invalid JSON.
	 * @throws \RuntimeException On transport or API errors.
	 */
	public function capture( int $trnid, array $data ): array {
		return $this->request( "/v1/transactions/$trnid/capture", $data, [], 20 );
	}

	/**
	 * Charges a subscriber under an idempotency key, which Scanpay binds for 24h.
	 *
	 * @throws \JsonException On invalid JSON.
	 * @throws \RuntimeException On transport, API, or validation errors, including a
	 *                           response that does not confirm the key was honored.
	 */
	public function charge( int $subid, array $data, string $idemkey ): array {
		$hdr = [ 'Idempotency-Key' => $idemkey ];
		$res = $this->request( "/v1/subscribers/$subid/charge", $data, $hdr, 120 );
		if ( ( $res['type'] ?? null ) === 'charge' && is_int( $res['id'] ?? null ) ) {
			return $res;
		}
		throw new \RuntimeException( 'Invalid response from server' );
	}

	/**
	 * Creates a subscriber renewal link and returns its URL.
	 *
	 * @throws \JsonException On invalid JSON.
	 * @throws \RuntimeException On transport, API, or validation errors.
	 */
	public function renew( int $subid, array $data ): string {
		$res = $this->request( "/v1/subscribers/$subid/renew", $data, $this->cardholder_ip_header(), 10 );
		if ( isset( $res['url'] ) && filter_var( $res['url'], FILTER_VALIDATE_URL ) ) {
			return $res['url'];
		}
		throw new \RuntimeException( 'Invalid response from server' );
	}
}
