<?php
namespace Scanpay;
if (!defined('ABSPATH')) { 
    exit;
}
class Client {
    protected $apikey;
    protected $host;
    public function __construct($arg) {
        $this->apikey = $arg['apikey'];
        $this->host = $arg['host'];
    }

    public function GetPaymentURL($data, $opts = []) {
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        error_log(print_R($data, TRUE));
        /* Create a curl request towards the api endpoint */
        $ch = curl_init('https://' . $this->{'host'} . '/v1/new');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_USERPWD, $this->{'apikey'});
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if (isset($opts['cardholderIP'])) { 
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Cardholder-Ip: ' . $opts['cardholderIP']]);
        }

        $result = curl_exec($ch);
        if ($result === FALSE) {
            $errstr = 'unknown error';
            if($errno = curl_errno($ch)) {
                $errstr = curl_strerror($errno);
            }
            curl_close($ch);
            throw new \Exception('curl_exec - ' . $errstr);
        }
        /* Retrieve the http status code */
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode !== 200) {
            if ($httpcode === 403) {
                throw new \Exception('Invalid API-key');
            }
            throw new \Exception('Unexpected http response code: ' . $httpcode);
        }
        /* Attempt to decode the json response */
        $jsonres = @json_decode($result);
        if ($jsonres === null) {
            throw new \Exception('unable to json-decode response');
        }
        /* Check if error field is present */
        if (isset($jsonres->{'error'})) {
            throw new \Exception('server returned error: ' . $jsonres->{'error'});
        }
        /* Check the existence of the server and the payid field */
        if(!isset($jsonres->{'url'})) {     
            throw new \Exception('missing json fields in server response');
        }
        if (filter_var($jsonres->{'url'}, FILTER_VALIDATE_URL) === FALSE) {
            throw new \Exception('invalid url in server response');
        }
        /* Generate the payment URL link from the server and payid */
        return $jsonres->{'url'};
    }
}