<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class ScanpayGateway extends WC_Payment_Gateway
{
    const API_PING_URL = 'scanpay/ping';
    protected $apikey;
    protected $orderUpdater;
    protected $sequencer;
    protected $client;

    public function __construct()
    {
        /* Set WC_Payment_Gateway parameters */
        $this->id = 'scanpay';
        //$this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Scanpay';
        $this->method_description = 'Scanpay is a Nordic based payment gateway offering card and mobile based payment.';
        /* Call the required WC_Payment_Gateway functions */
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->language = $this->get_option('language');
        $this->apikey = $this->get_option('apikey');
        $this->pingurl = WC()->api_request_url(self::API_PING_URL);

        /* Subclasses */
        $this->orderUpdater = new Scanpay\OrderUpdater();
        $this->sequencer = new Scanpay\GlobalSequencer();
        $this->client = new Scanpay\Client(['apikey' => $this->apikey]);
        $shopId = explode(':', $this->apikey)[0];
        if (ctype_digit($shopId)) {
            $this->shopid = (int)$shopId;
        } else {
            $this->shopid = null;
        }

        add_action('woocommerce_api_' . self::API_PING_URL, array($this, 'handle_pings'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function process_payment($orderid)
    {
        if ($this->shopid === null) {
            scanpay_log('invalid api key format');
            throw new \Exception(__('Internal server error', 'woocommerce'));
        }

    	$order = wc_get_order($orderid);
        $data = [
            'orderid'    => strval($orderid),
            'language'   => $this->language,
            'successurl' => $this->get_return_url($order),
            'billing'    => array_filter([
                'name'    => $order->billing_first_name . ' ' . $order->billing_last_name,
                'email'   => $order->billing_email,
                'phone'   => preg_replace('/\s+/', '', $order->billing_phone),
                'address' => array_filter([$order->billing_address_1, $order->billing_address_2]),
                'city'    => $order->billing_city,
                'zip'     => $order->billing_postcode,
                'country' => $order->billing_country,
                'state'   => $order->billing_state,
                'company' => $order->billing_company,
                'vatin'   => '',
                'gln'     => '',
            ]),
            'shipping'   => array_filter([
                'name'    => $order->shipping_first_name . ' ' . $order->shipping_last_name,
                'address' => array_filter([$order->shipping_address_1, $order->shipping_address_2]),
                'city'    => $order->shipping_city,
                'zip'     => $order->shipping_postcode,
                'country' => $order->shipping_country,
                'state'   => $order->shipping_state,
                'company' => $order->shipping_company,
            ]),
        ];

        /* Add the requested items to the request data */
        foreach ($order->get_items('line_item') as $wooitem) {
            $itemprice = $order->get_item_total($wooitem, true);
            if ($itemprice < 0) {
                scanpay_log('Cannot handle negative price for item');
                throw new \Exception(__('Internal server error', 'woocommerce'));
            }

            /*
             * Variation handling will be added at a later date
             *if (isset($wooitem['variation_id'])) {
             *   $product = $order->get_product_from_item($wooitem);
             *   $variation = $product->get_child($wooitem['variation_id']);
             *   if ($variation !== false) {
             *       scanpay_log('fmtattr: ' . $variation->get_formatted_variation_attributes(true));
             *   }
             *}
             */

            $data['items'][] = [
                'name' => $wooitem['name'],
                'quantity' => intval($wooitem['qty']),
                'price' => $itemprice . ' ' . $order->get_order_currency(),
                'sku' => $wooitem['product_id'],
            ];
        }

        /* Add shipping costs */
        $shippingcost = $order->get_total_shipping() + $order->get_shipping_tax();
        if ($shippingcost > 0) {
            $method = $order->get_shipping_method();
            $data['items'][] = [
                'name' => isset($method) ? $method : __('Shipping', 'woocommerce'),
                'quantity' => 1,
                'price' => $shippingcost . ' ' . $order->get_order_currency(),
            ];
        }

        try {
            $paymenturl = $this->client->getPaymentURL(array_filter($data), ['cardholderIP' => $_SERVER['REMOTE_ADDR']]);
        } catch (\Exception $e) {
            scanpay_log('scanpay client exception: ' . $e->getMessage());
            throw new \Exception(__('Internal server error', 'woocommerce'));
        }

        /* Update order */
    	$order->update_status('wc-pending');
        update_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, $this->shopid);

    	/* Reduce stock levels */
    	$order->reduce_order_stock();

    	/* Remove cart */
    	global $woocommerce;
    	$woocommerce->cart->empty_cart();
    	return [
    		'result' => 'success',
    		'redirect' => $paymenturl,
        ];
    }

    public function handle_pings()
    {
        if (!isset($_SERVER['HTTP_X_SIGNATURE'])) {
            wp_send_json(['error' => 'invalid signature']);
            return;
        }

        $body = file_get_contents('php://input');
        $localSig = base64_encode(hash_hmac('sha256', $body, $this->apikey, true));
        if (!hash_equals($localSig, $_SERVER['HTTP_X_SIGNATURE'])) { 
            wp_send_json(['error' => 'invalid signature']);
            return;
        }
        if ($this->shopid === null) {
            wp_send_json(['error' => 'invalid Scanpay API-key']);
            return;
        }

        /* Attempt to decode the json response */
        $jsonreq = @json_decode($body, true);
        if ($jsonreq === null) {
            wp_send_json(['error' => 'invalid json from Scanpay server']);
            return;
        }

        if (!isset($jsonreq['seq']) || !is_int($jsonreq['seq'])) { return; }
        $remoteSeq = $jsonreq['seq'];

        $localSeqObj = $this->sequencer->load($this->shopid);
        if (!$localSeqObj) {
            $this->sequencer->insert($this->shopid);
            $localSeqObj = $this->sequencer->load($this->shopid);
            if (!$localSeqObj) {
                scanpay_log('unable to load scanpay sequence number');
                return;
            }
        }

        $localSeq = $localSeqObj['seq'];
        if ($localSeq === $remoteSeq) {
            $this->sequencer->updateMtime($this->shopid);
        }
        while ($localSeq < $remoteSeq) {
            try {
                $resobj = $this->client->getUpdatedTransactions($localSeq);
            } catch (\Exception $e) {
                scanpay_log('scanpay client exception: ' . $e->getMessage());
                return;
            }
            $localSeq = $resobj['seq'];
            if (!$this->orderUpdater->updateAll($this->shopid, $resobj['changes'])) {
                wp_send_json('error updating orders with Scanpay changes');
                return;
            }

            if (!$this->sequencer->save($this->shopid, $localSeq)) {
                return;
            }
        }
    }

    /* This function is called before __construct(), and thus cannot use the definitions from there */
	public function init_form_fields()
    {
        $localSeqObj;
        $apikey = $this->get_option('apikey');
        $shopId = explode(':', $apikey)[0];
        if (ctype_digit($shopId)) {
            $sequencer = new Scanpay\GlobalSequencer();
            $localSeqObj = $sequencer->load($shopId);
            if (!$localSeqObj) { $localSeqObj = [ 'mtime' => 0 ]; }
        } else {
            $localSeqObj = [ 'mtime' => 0 ];
        }
        $block = [
            'pingurl' => WC()->api_request_url(self::API_PING_URL),
            'lastpingtime'  => $localSeqObj['mtime'],
        ];
        $this->form_fields = buildSettings($block);
	}

}
