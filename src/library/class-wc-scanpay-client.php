<?php

/*
	Scanpay module client lib
	Version 2.2.3 (2024-03-17)
	- remove data[] from renew() and new_url() params
	+ Add parse_ping and shopid
	+ Optimizations
*/

class WC_Scanpay_Client {

	private $ch; // CurlHandle class is added PHP 8.0
	private array $headers;
	private string $idemstatus;
	private string $apikey;
	public int $shopid;

	public function __construct( string $apikey ) {
		$this->apikey  = $apikey;
		$this->shopid  = (int) strstr( $apikey, ':', true );
		$this->ch      = curl_init();
		$this->headers = [
			'Authorization: Basic ' . base64_encode( $apikey ),
			'X-Shop-Plugin: WC-' . WC_SCANPAY_VERSION . '/' . WC()->version . '; PHP-' . PHP_VERSION,
			'Content-Type: application/json',
			'Expect: ',
		];
		/* The 'Expect' header will disable libcurl's expect-logic,
			which will save us a HTTP roundtrip on POSTs >1024b. */
	}

	// header_callback: find idempotency-status header and store it in $this->idemstatus
	private function header_callback( $handle, string $header ) {
		// Note: $handle is the cURL resource. The type is resource in PHP 7, but object in PHP 8+
		$len = strlen( $header );
		if ( $len < 19 || $len > 26 ) {
			return $len; // Not a header we are interested in
		}
		$arr = explode( ':', $header );
		if ( isset( $arr[1] ) && strtolower( trim( $arr[0] ) ) === 'idempotency-status' ) {
			$this->idemstatus = strtolower( trim( $arr[1] ) );
		}
		return $len;
	}

	private function request( string $path, ?array $opts, ?array $data ): array {
		$this->idemstatus = '';
		$curlopts         = [
			CURLOPT_URL               => 'https://api.scanpay.dk' . $path,
			CURLOPT_TCP_KEEPALIVE     => 1,
			CURLOPT_RETURNTRANSFER    => 1,
			CURLOPT_CONNECTTIMEOUT    => 20,
			CURLOPT_TIMEOUT           => 40,
			CURLOPT_DNS_CACHE_TIMEOUT => 180,
			//CURLOPT_DNS_SHUFFLE_ADDRESSES => 1,
		];

		$headers = $this->headers;
		if ( isset( $opts['headers'] ) ) {
			foreach ( $opts['headers'] as $key => $val ) {
				$headers[] = $key . ': ' . $val;
			}
			if ( isset( $opts['headers']['Idempotency-Key'] ) ) {
				$curlopts[ CURLOPT_HEADERFUNCTION ] = [ $this, 'header_callback' ];
			}
		}
		$curlopts[ CURLOPT_HTTPHEADER ] = $headers;

		if ( isset( $data ) ) {
			$curlopts[ CURLOPT_POSTFIELDS ] = json_encode( $data, JSON_UNESCAPED_SLASHES );
			if ( false === $curlopts[ CURLOPT_POSTFIELDS ] ) {
				throw new \Exception( 'Failed to JSON encode request to Scanpay: ' . json_last_error_msg() );
			}
		}

		curl_reset( $this->ch );
		curl_setopt_array( $this->ch, $curlopts );
		$result = curl_exec( $this->ch );
		if ( false === $result ) {
			throw new \Exception( curl_strerror( curl_errno( $this->ch ) ) );
		}

		$status_code = (int) curl_getinfo( $this->ch, CURLINFO_RESPONSE_CODE );
		if ( 200 !== $status_code ) {
			if ( substr_count( $result, "\n" ) !== 1 || strlen( $result ) > 512 ) {
				$result = 'server error';
			}
			throw new \Exception( $status_code . ' ' . $result );
		}

		if ( isset( $opts['headers']['Idempotency-Key'] ) && 'ok' !== $this->idemstatus ) {
			throw new \Exception( 'Server failed to provide idempotency: ' . $result );
		}

		$json = json_decode( $result, true );
		if ( ! is_array( $json ) ) {
			throw new \Exception( 'Invalid JSON response from server' );
		}
		return $json;
	}

	// parse_ping: json_decode and verify pings
	public function parse_ping(): ?array {
		if ( ! isset( $_SERVER['HTTP_X_SIGNATURE'] ) ) {
			return null;
		}
		$body = file_get_contents( 'php://input', false, null, 0, 512 );
		if ( ! hash_equals( base64_encode( hash_hmac( 'sha256', $body, $this->apikey, true ) ), $_SERVER['HTTP_X_SIGNATURE'] ) ) {
			return null;
		}
		$ping = json_decode( $body, true );
		if ( ! isset( $ping, $ping['seq'], $ping['shopid'] ) || ! is_int( $ping['seq'] ) || $this->shopid !== $ping['shopid'] ) {
			scanpay_log( 'error', 'Invalid ping from server' );
			return null;
		}
		return $ping;
	}

	// new_url: Create a new payment link
	public function new_url( array $data ): string {
		$opts = [ 'headers' => [ 'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '' ] ];
		$o    = $this->request( '/v1/new', $opts, $data );
		if ( isset( $o['url'] ) && filter_var( $o['url'], FILTER_VALIDATE_URL ) ) {
			return $o['url'];
		}
		throw new \Exception( 'Invalid response from server' );
	}

	// seq: Get array of changes since the reqested sequence number
	public function seq( int $num ): array {
		$o = $this->request( '/v1/seq/' . $num, null, null );
		if ( isset( $o['seq'], $o['changes'] ) && is_int( $o['seq'] ) && is_array( $o['changes'] ) ) {
			$empty = empty( $o['changes'] );
			if ( ( $empty && $o['seq'] <= $num ) || ( ! $empty && $o['seq'] > $num ) ) {
				return $o;
			}
		}
		throw new \Exception( 'Invalid seq from server' );
	}

	public function capture( int $trnid, array $data ): array {
		return $this->request( "/v1/transactions/$trnid/capture", null, $data );
	}

	public function charge( int $subid, array $data, array $opts = [] ): array {
		$o = $this->request( "/v1/subscribers/$subid/charge", $opts, $data );
		if (
			isset( $o['type'] ) && 'charge' === $o['type'] &&
			isset( $o['id'] ) && is_int( $o['id'] )
		) {
			return $o;
		}
		throw new \Exception( 'Invalid response from server' );
	}

	public function renew( int $subid, array $data ): string {
		$opts = [ 'headers' => [ 'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '' ] ];
		$o    = $this->request( "/v1/subscribers/$subid/renew", $opts, $data );
		if ( isset( $o['url'] ) && filter_var( $o['url'], FILTER_VALIDATE_URL ) ) {
			return $o['url'];
		}
		throw new \Exception( 'Invalid response from server' );
	}
}
