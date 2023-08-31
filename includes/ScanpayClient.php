<?php

class WC_Scanpay_API_Client
{
    private object $ch;
    private array $headers;
    private ?string $idemstatus;
    private array $opts;

    public function __construct(string $apikey, array $opts = [])
    {
        if (!function_exists('curl_init')) {
            throw new \Exception("ERROR: Please enable php-curl\n");
        }
        $this->ch = curl_init(); // reuse handle
        $this->opts = $opts;
        $this->headers = [
            'authorization' => 'Authorization: Basic ' . base64_encode($apikey),
            'x-shop-plugin' => 'woocommerce/' . WC_VERSION . '/' . WC_SCANPAY_VERSION,
            'content-type' => 'Content-Type: application/json',
        ];
        if (isset($opts['headers'])) {
            foreach ($opts['headers'] as $key => &$val) {
                $this->headers[strtolower($key)] = $key . ': ' . $val;
            }
        }
    }

    private function headerCallback($curl, $hdr)
    {
        scanpay_log('info', gettype($curl) . '; hdr: ' . gettype($hdr));
        $header = explode(':', $hdr, 2);
        // Skip invalid headers
        if (count($header) < 2) return strlen($hdr);

        if (strtolower(trim($header[0])) === 'idempotency-status') {
            $this->idemstatus = strtoupper(trim($header[1]));
        }
        return strlen($hdr);
    }

    private function request(string $path, array $reqOpts = [], array $data = []): array
    {
        $this->idemstatus = null;
        $opts = $this->opts;
        $headers = $this->headers;

        if (!empty($reqOpts)) {
            $opts = array_merge($this->opts, $reqOpts);
            if (isset($reqOpts['headers'])) {
                foreach ($reqOpts['headers'] as $key => &$val) {
                    $headers[strtolower($key)] = $key . ': ' . $val;
                }
            }
        }

        $curlopts = [
            CURLOPT_URL => 'https://api.scanpay.dk' . $path,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 40,
            // Debugging
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => fopen('./curl.log', 'w+'),
        ];

        if (!empty($data)) {
            $curlopts[CURLOPT_POST] = 1;
            $curlopts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($curlopts[CURLOPT_POSTFIELDS] === false) {
                throw new \Exception('Failed to JSON encode request to Scanpay: ' . json_last_error_msg());
            }
        }
        if (isset($headers['idempotency-key'])) {
            // this function is called by cURL for each header received
            $curlopts[CURLOPT_HEADERFUNCTION] = [$this, 'headerCallback'];
        }

        // Let the merchant override $curlopts.
        if (isset($opts['curl'])) {
            foreach ($opts['curl'] as $key => &$val) {
                $curlopts[$key] = $val;
            }
        }
        curl_setopt_array($this->ch, $curlopts);

        $result = curl_exec($this->ch);
        if (!$result) {
            throw new \Exception(curl_strerror(curl_errno($this->ch)));
        }
        $statusCode = curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        if ($statusCode !== 200) {
            throw new \Exception(('Scanpay returned "' . explode("\n", $result)[0] . '"'));
        }

        // Validate Idempotency-Status
        if (isset($headers['idempotency-key']) && $this->idemstatus !== 'OK') {
            throw new \Exception(
                "Server failed to provide idempotency. Scanpay returned $statusCode - " . explode("\n", $result)[0]
            );
        }

        $resobj = json_decode($result, true);
        if ($resobj === null) {
            throw new \Exception('Invalid JSON response from server');
        }
        return $resobj;
    }

    // newURL: Create a new payment link
    public function newURL(array $data, array $opts = []): string
    {
        $res = $this->request('/v1/new', $opts, $data);
        if (isset($res['url']) && filter_var($res['url'], FILTER_VALIDATE_URL)) {
            return $res['url'];
        }
        throw new \Exception('Invalid response from server');
    }

    // seq: Get array of changes since the reqested seqnum
    public function seq(int $seqnum): array
    {
        $res = $this->request('/v1/seq/' . $seqnum);
        if (
            isset($res['seq']) && is_int($res['seq']) &&
            isset($res['changes']) && is_array($res['changes'])
        ) {
            return $res;
        }
        throw new \Exception('Invalid response from server');
    }

    public function capture(int $trnid, array $data, array $opts = []): array
    {
        return $this->request("/v1/transactions/$trnid/capture", $opts, $data);
    }

    public function generateIdempotencyKey(): string
    {
        return rtrim(base64_encode(random_bytes(32)), '=');
    }

    public function charge(int $subid, array $data, array $opts = []): array
    {
        $o = $this->request("/v1/subscribers/$subid/charge", $opts, $data);
        if (
            isset($o['type']) && $o['type'] === 'charge' &&
            isset($o['id']) && is_int($o['id']) &&
            isset($o['totals']) && isset($o['totals']['authorized'])
        ) {
            return $o;
        }
        throw new \Exception('Invalid response from server');
    }

    public function renew(int $subid, array $data, array $opts = []): string
    {
        $o = $this->request("/v1/subscribers/$subid/renew", $opts, $data);
        if (isset($o['url']) && filter_var($o['url'], FILTER_VALIDATE_URL)) {
            return $o['url'];
        }
        throw new \Exception('Invalid response from server');
    }
}
