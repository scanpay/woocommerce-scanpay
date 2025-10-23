<?php

/*
 *  Scanpay module client lib
 *  Version 4.0.0 (2025-10-23)
 */

declare(strict_types=1);

class WC_Scanpay_Client {
	private \CurlHandle $ch;
	private array $headers;
	private bool $idem         = false;
	private string $idemstatus = '';

	public function __construct( string $apikey ) {
		$this->ch      = curl_init();
		$this->headers = [
			'Authorization: Basic ' . base64_encode( $apikey ),
			'X-Shop-Plugin: WC-' . WC_SCANPAY_VERSION . '/' . WC()->version . '; PHP-' . PHP_VERSION,
			'Accept: application/json',
			'Expect: ', // avoid 100-continue roundtrip (hack)
		];
	}

	public function __destruct() {
		if ( isset( $this->ch ) ) {
			curl_close( $this->ch );
		}
	}

	private function header_callback( $ch, string $line ): int {
		if ( stripos( $line, 'Idempotency-Status:' ) === 0 ) {
			$this->idem       = true;
			$this->idemstatus = strtolower( trim( substr( $line, 19 ) ) );
		}
		return strlen( $line );
	}

	private function request( string $path, ?array $data = null, array $hdrs = [] ): array {
		$this->idem  = false;
		$expect_idem = false;

		$headers  = $this->headers;
		$curlopts = [
			CURLOPT_URL               => 'https://api.scanpay.dk' . $path,
			CURLOPT_TCP_KEEPALIVE     => 1,
			CURLOPT_RETURNTRANSFER    => 1,
			CURLOPT_CONNECTTIMEOUT    => 20,
			CURLOPT_TIMEOUT           => 40,
			CURLOPT_DNS_CACHE_TIMEOUT => 180,
			CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_1_1,
			CURLOPT_NOSIGNAL          => 1,
		];
		if ( null !== $data ) {
			$headers[]                      = 'Content-Type: application/json';
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
			throw new \RuntimeException( $detail ? "$err: $detail" : $err );
		}

		$status_code = (int) curl_getinfo( $this->ch, CURLINFO_RESPONSE_CODE );
		if ( 200 !== $status_code ) {
			$body = (string) $result;
			if ( substr_count( $body, "\n" ) !== 1 || strlen( $body ) > 512 ) {
				$body = 'server error';
			}
			throw new \RuntimeException( $status_code . ' ' . $body );
		}
		if ( $expect_idem && ! $this->idem ) {
			throw new \RuntimeException( 'Missing Idempotency-Status header' );
		}
		if ( $this->idem && 'ok' !== $this->idemstatus ) {
			throw new \RuntimeException( 'Server failed to provide idempotency: ' . (string) $result );
		}
		$json = json_decode( (string) $result, true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Invalid JSON response from server' );
		}
		return $json;
	}

	// new_url: Create a new payment link
	public function new_url( array $data ): string {
		$hdr = [ 'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '' ];
		$res = $this->request( '/v1/new', $data, $hdr );
		if ( isset( $res['url'] ) && filter_var( $res['url'], FILTER_VALIDATE_URL ) ) {
			return $res['url'];
		}
		throw new \RuntimeException( 'Invalid response from server' );
	}

	// seq: Get array of changes since the requested sequence number
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

	public function capture( int $trnid, array $data ): array {
		return $this->request( "/v1/transactions/$trnid/capture", $data );
	}

	public function charge( int $subid, array $data, string $idemkey ): array {
		$hdr = [ 'Idempotency-Key' => $idemkey ];
		$res = $this->request( "/v1/subscribers/$subid/charge", $data, $hdr );
		if ( ( $res['type'] ?? null ) === 'charge' && is_int( $res['id'] ?? null ) ) {
			return $res;
		}
		throw new \RuntimeException( 'Invalid response from server' );
	}

	public function renew( int $subid, array $data ): string {
		$hdr = [ 'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '' ];
		$res = $this->request( "/v1/subscribers/$subid/renew", $data, $hdr );
		if ( isset( $res['url'] ) && filter_var( $res['url'], FILTER_VALIDATE_URL ) ) {
			return $res['url'];
		}
		throw new \RuntimeException( 'Invalid response from server' );
	}
}
