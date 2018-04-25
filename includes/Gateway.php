<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scanpay extends WC_Payment_Gateway
{
    const API_PING_URL = 'scanpay/ping';
    const DASHBOARD_URL = 'https://dashboard.scanpay.dk';
    protected $apikey;
    protected $orderUpdater;
    protected $sequencer;
    protected $client;

    public function __construct($extended = false)
    {
        /* Set WC_Payment_Gateway parameters */
        $this->id = 'scanpay';
        //$this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Scanpay';
        $this->method_description = 'Scanpay is a Nordic based payment gateway offering card and mobile based payment.';


        $this->init_form_fields();
        $this->init_settings();
        /* Call the required WC_Payment_Gateway functions */
        if (!$extended && is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_scanpay_info'));
        }

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->language = $this->get_option('language');
        $this->apikey = $this->get_option('apikey');
        $this->pingurl = WC()->api_request_url(self::API_PING_URL);
        $this->autocapture = $this->get_option('autocapture') === 'yes';

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
    }

    public function process_payment($orderid)
    {
        if ($this->shopid === null) {
            scanpay_log('invalid api key format');
            throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
        }
        $order = wc_get_order($orderid);
        $data = [
            'orderid'     => strval($orderid),
            'language'    => $this->language,
            'successurl'  => $this->get_return_url($order),
            'autocapture' => (bool)$this->autocapture,
            'billing'     => array_filter([
                'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email'   => $order->get_billing_email(),
                'phone'   => preg_replace('/\s+/', '', $order->get_billing_phone()),
                'address' => array_filter([$order->get_billing_address_1(), $order->get_billing_address_2()]),
                'city'    => $order->get_billing_city(),
                'zip'     => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'state'   => $order->get_billing_state(),
                'company' => $order->get_billing_company(),
                'vatin'   => '',
                'gln'     => '',
            ]),
            'shipping'    => array_filter([
                'name'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'address' => array_filter([$order->get_shipping_address_1(), $order->get_shipping_address_2()]),
                'city'    => $order->get_shipping_city(),
                'zip'     => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
                'state'   => $order->get_shipping_state(),
                'company' => $order->get_shipping_company(),
            ]),
        ];

        $cur = version_compare(WC_VERSION, '3.0.0', '<') ? $order->get_order_currency() : $order->get_currency();

        /* Add the requested items to the request data */
        foreach ($order->get_items('line_item') as $wooitem) {
            $itemtotal = $order->get_line_total($wooitem, true);
            if ($itemtotal < 0) {
                scanpay_log('Cannot handle negative price for item');
                throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
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
                'name' => $wooitem->get_name(),
                'quantity' => intval($wooitem['qty']),
                'total' => $itemtotal . ' ' . $cur,
                'sku' => strval($wooitem['product_id']),
            ];
        }

        /* Add fees */
        foreach ($order->get_items('fee') as $wooitem) {
            $itemtotal = $wooitem->get_total();
            if ($itemtotal < 0) {
                scanpay_log('Cannot handle negative price for fee');
                throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
            }
            $data['items'][] = [
                'name' => $wooitem->get_name(),
                'quantity' => 1,
                'total' => $itemtotal . ' ' . $cur,
            ];
        }

        /* Add shipping costs */
        $shippingcost = $order->get_total_shipping() + $order->get_shipping_tax();
        if ($shippingcost > 0) {
            $method = $order->get_shipping_method();
            $data['items'][] = [
                'name' => isset($method) ? $method : __('Shipping', 'woocommerce-scanpay'),
                'quantity' => 1,
                'total' => $shippingcost . ' ' . $cur,
            ];
        }
        $data = apply_filters('woocommerce_scanpay_newurl_data', $data);
        try {
            $paymenturl = $this->client->getPaymentURL(array_filter($data), ['cardholderIP' => $_SERVER['REMOTE_ADDR']]);
        } catch (\Exception $e) {
            scanpay_log('scanpay client exception: ' . $e->getMessage());
            throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
        }

        /* Update order */
        $order->update_status('wc-pending');
        update_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, $this->shopid);

        return [
            'result' => 'success',
            'redirect' => $paymenturl,
        ];
    }

    public function handle_pings()
    {
        if (!isset($_SERVER['HTTP_X_SIGNATURE'])) {
            wp_send_json(['error' => 'invalid signature'], 403);
            return;
        }

        $body = file_get_contents('php://input');
        $localSig = base64_encode(hash_hmac('sha256', $body, $this->apikey, true));
        if (!hash_equals($localSig, $_SERVER['HTTP_X_SIGNATURE'])) {
            wp_send_json(['error' => 'invalid signature'], 403);
            return;
        }
        if ($this->shopid === null) {
            wp_send_json(['error' => 'invalid Scanpay API-key']);
            return;
        }

        /* Attempt to decode the json response */
        $jsonreq = @json_decode($body, true);
        if ($jsonreq === null) {
            wp_send_json(['error' => 'invalid json from Scanpay server'], 400);
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
                wp_send_json(['error' => 'unable to load scanpay sequence number'], 500);
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
                wp_send_json(['error' => 'scanpay client exception: ' . $e->getMessage()], 500);
                return;
            }
            if (!$this->orderUpdater->updateAll($this->shopid, $resobj['changes'])) {
                wp_send_json(['error' => 'error updating orders with Scanpay changes'], 500);
                return;
            }

            if (!$this->sequencer->save($this->shopid, $resobj['seq'])) {
                if ($resobj['seq']!== $localSeq) {
                    wp_send_json(['error' => 'error saving Scanpay changes'], 500);
                    return;
                }
                break;
            }
            $localSeq = $resobj['seq'];
        }
        wp_send_json_success();
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

    // display the extra data in the order admin panel
    public function display_scanpay_info($order)
    {
        $shopId = get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SHOPID, true);
        if ($shopId === '') {
            return;
        }
        $trnId = $order->get_transaction_id();
        $cur = version_compare(WC_VERSION, '3.0.0', '<') ? $order->get_order_currency() : $order->get_currency();
        $auth = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_AUTHORIZED, true), array( 'currency' => $cur));
        $captured = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_CAPTURED, true), array( 'currency' => $cur));
        $refunded = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_REFUNDED, true), array( 'currency' => $cur));
        $trnURL = self::DASHBOARD_URL . '/' . addslashes($shopId) . '/' . addslashes($trnId);
        ?>
        </div>
        <div class="order_data_column">
            <h3><?php echo __('Scanpay Details', 'woocommerce-scanpay'); ?></h3>
            <p>
                <strong><?php echo __('Transaction ID', 'woocommerce-scanpay') ?>:</strong>
                <?php echo '<a style="text-decoration:none; float:right" href="' . $trnURL . '" target="_blank">' . htmlspecialchars($trnId) ?>
                <span class="dashicons dashicons-arrow-right-alt"></span></a>
            </p>
            <p>
                <strong><?php echo __('Authorized', 'woocommerce-scanpay')?>:</strong>
                <span style="float: right">
                    <?php echo $auth ?>
                    <span class="dashicons"></span>
                </span>
            </p>
            <p>
                <strong><?php echo __('Captured', 'woocommerce-scanpay')?>:</strong>
                <?php echo '<a style="text-decoration:none; float:right" href="' . $trnURL . '/capture" target="_blank">' . $captured ?>
                <span class="dashicons dashicons-plus-alt"></span></a>
            <p>
                <strong><?php echo __('Refunded', 'woocommerce-scanpay')?>:</strong>
                <?php echo '<a style="text-decoration:none; float:right" href="' . $trnURL . '/refund" target="_blank">' . $refunded ?>
                <span class="dashicons dashicons-dismiss"></span></a>
            </p>
        </div><div style="display: none">
        <?php
    }

}
