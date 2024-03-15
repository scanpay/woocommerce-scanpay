<?php

/*
    Scanpay module client lib
    Version 2.2.2 (2024-03-06)
    - remove data[] from renew() and newURL() params
    + Add parsePing and shopid
*/

class WC_Scanpay_Client
{
    private $ch; // CurlHandle class is added PHP 8.0
    private array $headers;
    private string $idemstatus;
    private string $apikey;
    public int $shopid;

    public function __construct(string $apikey)
    {
        $this->apikey = $apikey;
        $this->shopid = (int) strstr( $apikey, ':', true );
        $this->ch = curl_init();
        $this->headers = [
            'authorization' => 'Authorization: Basic ' . base64_encode($apikey),
            'x-shop-plugin' => 'X-Shop-Plugin: WC-' . WC_SCANPAY_VERSION . '/' . WC_VERSION . '; PHP-' . PHP_VERSION,
            'content-type' => 'Content-Type: application/json',
            'expect' => 'Expect: ',
        ];
        /* The 'Expect' header will disable libcurl's expect-logic,
            which will save us a HTTP roundtrip on POSTs >1024b. */
    }

    private function headerCallback($handle, string $header)
    {
        // Note: $handle is the cURL resource. The type is resource in PHP 7, but object in PHP 8+
        $arr = explode(':', $header, 2);
        if (isset($arr[1]) && strtolower(trim($arr[0])) === 'idempotency-status') {
            $this->idemstatus = strtolower(trim($arr[1]));
        }
        return strlen($header);
    }

    private function request(string $path, ?array $opts, ?array $data): array
    {
        $this->idemstatus = '';
        $curlopts = [
            CURLOPT_URL => 'https://api.scanpay.dk' . $path,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_DNS_CACHE_TIMEOUT => 180,
            //CURLOPT_DNS_SHUFFLE_ADDRESSES => 1,
        ];

        $headers = $this->headers;
        if (isset($opts, $opts['headers'])) {
            foreach ($opts['headers'] as $key => &$val) {
                $headers[strtolower($key)] = $key . ': ' . $val;
            }
            if (isset($headers['idempotency-key'])) {
                $curlopts[CURLOPT_HEADERFUNCTION] = [$this, 'headerCallback'];
            }
        }
        $curlopts[CURLOPT_HTTPHEADER] = array_values($headers);

        if (isset($data)) {
            $curlopts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($curlopts[CURLOPT_POSTFIELDS] === false) {
                throw new \Exception('Failed to JSON encode request to Scanpay: ' . json_last_error_msg());
            }
        }

        curl_reset($this->ch);
        curl_setopt_array($this->ch, $curlopts);
        $result = curl_exec($this->ch);
        if ($result === false) {
            throw new \Exception(curl_strerror(curl_errno($this->ch)));
        }

        $statusCode = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        if ($statusCode !== 200) {
            if (substr_count($result, "\n") > 1) {
                throw new \Exception("internal server error (code: $statusCode)");
            }
            throw new \Exception($result);
        }

        if (isset($opts, $headers['idempotency-key']) && $this->idemstatus !== 'ok') {
            throw new \Exception("Server failed to provide idempotency. Scanpay returned $statusCode - "
                . explode("\n", $result)[0]);
        }

        $json = json_decode($result, true);
        if (!is_array($json)) {
            throw new \Exception('Invalid JSON response from server');
        }
        return $json;
    }

    // parsePing: json_decode and verify pings
    public function parsePing(): ?array
    {
        if (!isset($_SERVER['HTTP_X_SIGNATURE'])) {
            return null;
        }
		$body = file_get_contents('php://input', false, null, 0, 512);
		if (!hash_equals(base64_encode(hash_hmac('sha256', $body, $this->apikey, true)), $_SERVER['HTTP_X_SIGNATURE']) ) {
            return null;
		}
		$ping = json_decode($body, true);
		if ( ! isset( $ping, $ping['seq'], $ping['shopid'] ) || ! is_int( $ping['seq'] ) || $this->shopid !== $ping['shopid'] ) {
            scanpay_log('error', 'Invalid ping from server');
			return null;
		}
        return $ping;
    }

    // newURL: Create a new payment link
    public function newURL(array $data): string
    {
        $opts = [ 'headers' => [ 'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '' ] ];
        $o = $this->request('/v1/new', $opts, $data);
        if (isset($o['url']) && filter_var($o['url'], FILTER_VALIDATE_URL)) {
            return $o['url'];
        }
        throw new \Exception('Invalid response from server');
    }

    // seq: Get array of changes since the reqested sequence number
    public function seq(int $num): array
    {
        $o = $this->request('/v1/seq/' . $num, null, null);
        if (isset($o['seq'], $o['changes']) && is_int($o['seq']) && is_array($o['changes'])) {
            $empty = empty($o['changes']);
            if (($empty && $o['seq'] <= $num) || (!$empty && $o['seq'] > $num)) {
                return $o;
            }
        }
        throw new \Exception('Invalid seq from server');
    }

    public function capture(int $trnid, array $data): array
    {
        return $this->request("/v1/transactions/$trnid/capture", null, $data);
    }

    public function charge(int $subid, array $data, array $opts = []): array
    {
        $o = $this->request("/v1/subscribers/$subid/charge", $opts, $data);
        if (
            isset($o['type']) && $o['type'] === 'charge' &&
            isset($o['id']) && is_int($o['id'])
        ) {
            return $o;
        }
        throw new \Exception('Invalid response from server');
    }

    public function renew(int $subid, array $data): string
    {
        $opts = [ 'headers' => [ 'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'] ?? '' ] ];
        $o = $this->request("/v1/subscribers/$subid/renew", $opts, $data);
        if (isset($o['url']) && filter_var($o['url'], FILTER_VALIDATE_URL)) {
            return $o['url'];
        }
        throw new \Exception('Invalid response from server');
    }
}
