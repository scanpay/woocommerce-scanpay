<?php
namespace Scanpay;
if (!defined('ABSPATH')) {
    exit;
}

class Client
{
    protected $apikey;
    protected $host;
    public function __construct($arg)
    {
        $this->apikey = $arg['apikey'];
        $this->host = $arg['host'];
    }

    public function req($url, $data, $opts = [])
    {
        /* Create a curl request towards the api endpoint */
        $ch = curl_init('https://' . $this->{'host'} . '/v1/new');
        if ($data != null) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        global $woocommerce_for_scanpay_plugin_version;
        $headers = [
            'Authorization: Basic ' . base64_encode($this->apikey),
            'X-Shop-System: Magento 2',
            'X-Extension-Version: ' . $woocommerce_for_scanpay_plugin_version, //$plugin_data['Version'],
        ];

        if (!isset($opts['cardholderIP'])) {
            $headers = array_merge($headers, [ 'X-Cardholder-Ip: ' . $opts['cardholderIP'] ]);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        if (!$result) {
            $errstr = 'unknown error';
            if ($errno = curl_errno($ch)) {
                $errstr = curl_strerror($errno);
            }

            curl_close($ch);
            throw new \Exception('curl_exec - ' . $errstr);
        }

        /* Retrieve the http status code */
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            if ($code === 403) {
                throw new \Exception('Invalid API-key');
            }
            throw new \Exception('Unexpected http response code: ' . $code);
        }

        /* Attempt to decode the json response */
        $resobj = @json_decode($result, true);
        if ($resobj === null) {
            throw new \Exception('unable to json-decode response');
        }

        /* Check if error field is present */
        if (isset($resobj['error'])) {
            throw new \Exception('server returned error: ' . $resobj['error']);
        }
        return $resobj;
    }

    public function GetPaymentURL($data, $opts = [])
    {
        $resobj = $this->req('/v1/new', $data, $opts);

        /* Check the existence of the server and the payid field */
        if (!isset($resobj['url'])) {
            throw new \Exception('missing json fields in server response');
        }

        if (!filter_var($resobj['url'], FILTER_VALIDATE_URL)) {
            throw new \Exception('invalid url in server response');
        }

        /* Generate the payment URL link from the server and payid */
        return $resobj['url'];
    }
    
    public function getUpdatedTransactions($seq) {
        $resobj = $this->req('/v1/seq/' . $seq, null, null);
        if (!isset($resobj['seq']) || !isset($resobj['changes'])) {
            throw new LocalizedException(__('missing json fields in server response'));
        }
        return $resobj;
    }

}
