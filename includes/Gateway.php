<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
use Scanpay\Money as Money;

class ScanpayGateway extends WC_Payment_Gateway
{
    const API_PING_URL = 'scanpay/ping';

    protected $apikey;
    protected $client;
    protected $sequencer;

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
        global $wpdb;
        $this->sequencer = new Scanpay\GlobalSequencer($wpdb);
        $this->client = new Scanpay\Client(['apikey' => $this->apikey]);
        $shopId = explode(':', $this->apikey)[0];
        if (ctype_digit($shopId)) {
            $this->shopid = (int)$shopId;
        } else {
            $this->shopid = null;
        }

        error_log($this->pingurl);
        add_action('woocommerce_api_scanpay/ping', array($this, 'handle_pings'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function process_payment($orderid)
    {
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
                error_log('Cannot handle negative price for item');
                throw new \Exception(__('Internal server error', 'woocommerce'));
            }

            $data['items'][] = [
                'name' => $wooitem['name'],
                'quantity' => intval($wooitem['qty']),
                'price' => (new Money($itemprice, $order->get_order_currency()))->print(),
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
                'price' => (new Money($shippingcost, $order->get_order_currency()))->print(),
            ];
        }

        try {
            $paymenturl = $this->client->GetPaymentURL(array_filter($data), ['cardholderIP' => $_SERVER['REMOTE_ADDR']]);
        } catch (\Exception $e) {
            error_log('scanpay client exception: ' . $e->getMessage());
            throw new \Exception(__('Internal server error', 'woocommerce'));
        }

        /* Update order */
    	$order->update_status('wc-pending');

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

    /*
    
update_post_meta( $order->id, 'custom_key', 'custom_value' );

when you need to add a custom data, and:

$my_value = get_post_meta( $order->id, 'custom_key', true );

    */

    public function handle_pings()
    {
        if (!isset($_SERVER['X-Signature'])) {
            wp_send_json(['error' => 'invalid signature']);
            return;
        }

        $localSig = base64_encode(hash_hmac('sha256', $HTTP_RAW_POST_DATA, $this->apikey, true));
        if ($localSig !== $_SERVER['X-Signature']) { 
            wp_send_json(['error' => 'invalid signature from Scanpay server']);
            return;
        }
        if ($this->shopid === null) {
            wp_send_json(['error' => 'invalid Scanpay API-key']);
            return;
        }
        /* Attempt to decode the json response */
        $jsonres = @json_decode($HTTP_RAW_POST_DATA, true);
        if ($jsonres === null) {
            wp_send_json(['error' => 'invalid json from Scanpay server']);
            return;
        }
        $remoteSeq = $jsonreq['seq'];
        if (!isset($remoteSeq) || !is_int($remoteSeq)) { return; }

        $localSeqObj = $this->sequencer->load($shopId);
        if (!$localSeqObj) {
            $this->sequencer->insert($shopId);
            $localSeqObj = $this->sequencer->load($shopId);
            if (!$localSeqObj) {
                error_log('unable to load scanpay sequence number');
                return;
            }
        }

        $localSeq = $localSeqObj['seq'];
        if ($localSeq === $remoteSeq) {
            $this->sequencer->updateMtime($shopId);
        }

        while ($localSeq < $remoteSeq) {
            try {
                $resobj = $client->getUpdatedTransactions($localSeq);
            } catch (\Exception $e) {
                wp_send_json('scanpay client exception: ' . $e->getMessage());
                return;
            }

            $localSeq = $resobj['seq'];
            if (!$this->orderUpdater->updateAll($shopId, $resobj['changes'])) {
                wp_send_json('error updating orders with Scanpay changes');
                return;
            }

            if (!$this->sequencer->save($shopId, $localSeq)) {
                return;
            }
        }
    }

	public function init_form_fields()
    {
        $block = [
            'pingurl' => WC()->api_request_url(self::API_PING_URL),
            'lastpingtime'  => time(),
        ];
        $this->form_fields = buildSettings($block);
	}

}
