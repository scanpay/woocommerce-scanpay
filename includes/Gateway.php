<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scanpay extends WC_Payment_Gateway
{
    const API_PING_URL = 'wc_scanpay';
    const DASHBOARD_URL = 'https://dashboard.scanpay.dk';
    protected $shopid;
    protected $apikey;
    protected $client;
    protected $orderUpdater;
    protected $shopseqdb;
    public $queuedchargedb;

    public function __construct($extended = false, $support_subscriptions = true)
    {
        /* Set WC_Payment_Gateway parameters */
        $this->id = 'scanpay';
        //$this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Scanpay';
        $this->method_description = 'Scanpay is a Nordic based payment gateway offering card and mobile based payment.';
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->apikey = $this->get_option('apikey');
        $this->pingurl = WC()->api_request_url(self::API_PING_URL);
        $this->autocapture = $this->get_option('autocapture') === 'yes';
        $this->autocomplete_virtual = $this->get_option('autocomplete_virtual') === 'yes';
        $this->autocomplete_renewalorders = $this->get_option('autocomplete_renewalorders') === 'yes';
        $this->capture_on_complete = $this->get_option('capture_on_complete') === 'yes';
        $this->language = $this->get_option('language');
        $shopid = explode(':', $this->apikey)[0];
        if (ctype_digit($shopid)) {
            $this->shopid = (int)$shopid;
        } else {
            $this->shopid = null;
        }
        /* Subclasses */
        $this->client = new Scanpay\Scanpay($this->apikey, [
            'headers' => [
                'X-Shop-Plugin' => 'woocommerce/' . WC_VERSION . '/' . WC_SCANPAY_PLUGIN_VERSION,
            ],
        ]);
        $this->orderupdater = new Scanpay\OrderUpdater($this->shopid, $this);
        $this->shopseqdb = new Scanpay\ShopSeqDB();
        $this->queuedchargedb = new Scanpay\QueuedChargeDB();

        $this->supports = ['products'];
        if ($support_subscriptions && $this->get_option('subscriptions_enabled') === 'yes') {
            $this->supports = array_merge($this->supports, [
                'subscriptions',
                'subscription_cancellation',
                'subscription_reactivation',
                'subscription_suspension',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'subscription_payment_method_change_admin',
                'subscription_payment_method_change_customer',
                'multiple_subscriptions',
                'pre-orders',
            ]);
            /* Subscription hooks */
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id,
                       [$this, 'scheduled_subscription_payment'], 10, 2);
            add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->id,
                       [$this, 'update_failing_payment_method'], 10, 2 );
            add_filter('woocommerce_subscription_payment_meta', [$this, 'add_subscription_payment_meta'], 10, 2);
            add_filter('woocommerce_subscription_validate_payment_meta', [$this, 'validate_subscription_payment_meta'], 10, 2);
            add_action('woocommerce_before_thankyou', [$this, 'after_subscribe']);
        }
        if (!$extended) {
            if (is_admin()) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_scanpay_info'));
            }
            /* Support for legacy ping url format */
            add_action('woocommerce_api_' . 'scanpay/ping', [$this, 'handle_pings']);
            /* New ping url format */
            add_action('woocommerce_api_' . self::API_PING_URL, [$this, 'handle_pings']);
        }
        add_action('woocommerce_order_status_completed', [$this, 'woocommerce_order_status_completed']);

        /*
         * Fix that WC_Subscriptions_Change_Payment_Gateway::can_subscription_be_updated_to_new_payment_method
         * wont fail, because totals is set to 0 ($subscription->get_total() == 0 will cause it to fail
         */
        if (class_exists('WC_Subscriptions_Change_Payment_Gateway') && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment) {
            remove_filter( 'woocommerce_subscription_get_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11 );
        }
        $this->init_form_fields();
        $this->init_settings();
    }

    private function get_subscription($order) {
        if (!class_exists('WC_Subscriptions')) {
            throw new \Exception("Woocommerce Subscriptions not installed");
        }
        $subscriber = null;
        $nsub = 0;
        foreach (wcs_get_subscriptions_for_order($order, ['order_type' => ['parent', 'switch', 'renewal']]) as $sub) {
            $subscriber = $sub;
            $nsub++;
        }
        if ($nsub < 1) { throw new \Exception("order with subscription contains 0 parent orders"); }
        return $subscriber;
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
        $cur = $order->get_currency();
        $has_nonvirtual = false;

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
                'total' => $itemtotal,
                'sku' => strval($wooitem['product_id']),
            ];
            if (!$wooitem->get_product()->is_virtual()) {
                $has_nonvirtual = true;
            }
        }

        /* Determine if order should be auto-captured */
        if ($has_nonvirtual) {
            $data['autocapture'] = $this->autocapture;
        } else {
            $data['autocapture'] = $this->autocapture || $this->autocomplete_virtual;
        }

        /* Add fees */
        foreach ($order->get_items('fee') as $wooitem) {
            $itemtotal = $wooitem->get_total() + $wooitem->get_total_tax();
            if ($itemtotal < 0) {
                scanpay_log('Cannot handle negative price for fee');
                throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
            }
            $data['items'][] = [
                'name' => $wooitem->get_name(),
                'quantity' => 1,
                'total' => $itemtotal,
            ];
        }

        /* Add shipping costs */
        $shippingcost = $order->get_shipping_total() + $order->get_shipping_tax();
        if ($shippingcost > 0) {
            $method = $order->get_shipping_method();
            $data['items'][] = [
                'name' => isset($method) ? $method : __('Shipping', 'woocommerce-scanpay'),
                'quantity' => 1,
                'total' => $shippingcost,
            ];
        }

        /* Compensate if total hook is used which makes total differ from sum of items */
        $itemtotal = 0;
        foreach ($data['items'] as $i => $item) {
            $itemtotal += $item['total'];
            $data['items'][$i]['total'] .= " $cur"; /* add currency to item totals */
        }
        if ($itemtotal != $order->get_total()) {
            unset($data['items']);
            if ($itemtotal > $order->get_total()) {
                $data['items'][] = [
                    'name' => 'Discounted cart',
                    'total' => $order->get_total() . ' ' . $cur,
                ];
            } else {
                $data['items'][] = [
                    'name' => 'Cart with increased price',
                    'total' => $order->get_total() . ' ' . $cur,
                ];
            }
        }

        $opts = [
            'headers' => [
                'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'],
            ],
        ];

        /* Handle subscriptions */
        if (class_exists('WC_Subscriptions')) {
            delete_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_INITIALPAYMENT_NTRIES);
            /* Handle resubscriptions (change of payment) */
            if (wcs_is_subscription($order)) {
                $shopid = (int)get_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, true);
                if ($shopid !== $this->shopid ) {
                    scanpay_log("subscription #$orderid scanpay shopid ($shopid) does not match shopid from apikey ({$this->shopid})");
                    throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
                }
                $subid = (int)get_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID, true);
                if (empty($subid)) {
                    scanpay_log("subscription #$orderid has empty transactionid");
                    throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
                }
                $allowed = ['language', 'successurl'];
                $data = array_intersect_key($data, array_flip($allowed));
                try {
                    $renewurl = $this->client->renew($subid, array_filter($data), $opts);
                } catch (\Exception $e) {
                    scanpay_log('scanpay client exception: ' . $e->getMessage());
                    throw new \Exception(__('Internal server error', 'woocommerce-scanpay'));
                }
                update_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, $this->shopid);
                return [
                    'result' => 'success',
                    'redirect' => $renewurl,
                ];
            /* Handle new subscriptions */
            } else if (wcs_order_contains_subscription($order)) {
                $data['subscriber'] = [
                    'ref' => strval($orderid),
                ];
                unset($data['items']);
                $sub = $this->get_subscription($order);
                update_post_meta($sub->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SHOPID, $this->shopid);
            } else if (wcs_order_contains_renewal($order)) {
                $data['subscriber'] = [
                    'ref' => strval($orderid),
                ];
                unset($data['items']);
            }
        }
        $data = apply_filters('woocommerce_scanpay_newurl_data', $data);

        try {
            $paymenturl = $this->client->newURL(array_filter($data), $opts);
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

    public function woocommerce_order_status_completed($orderid) {
        $order = wc_get_order($orderid);
        if (!$order) { return; }
        /* Verify that capture on complete is enabled */
        if (!$this->capture_on_complete) {
            return;
        }
        /* Return if order is a subscription */
        if (class_exists('WC_Subscriptions') && wcs_is_subscription($order)) {
            return;
        }
        /* Return if order has no Scanpay shopid */
        $shopid = (int)get_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, true) ;
        if (empty($shopid)) {
            return;
        }
        if ($shopid !== $this->shopid) {
            $order->add_order_note(__('Capture failed: Order shopid does not match apikey shopid', 'woocommerce-scanpay'));
            return;
        }
        /* Return if order is already captured */
        $captured = (int)get_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_CAPTURED, true);
        if ($captured) {
            $order->add_order_note(__('Capture failed: A capture has already been performed on this order', 'woocommerce-scanpay'));
            return;
        }
        $trnid = $order->get_transaction_id();
        if (!$trnid) {
            $trnid = get_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_TRANSACTION_ID, true);
            if (!$trnid) {
                $order->add_order_note(__('Capture failed: Order not authorized', 'woocommerce-scanpay'));
                return;
            }
        }
        $data = [
            'total' => "{$order->get_total()} {$order->get_currency()}",
            'index' => (int)get_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_NACTS, true),
        ];
        try {
            $this->client->capture($trnid, $data);
        } catch (\Exception $e) {
            $order->add_order_note(sprintf(__('Capture failed: %s', 'woocommerce-scanpay'), $e->getMessage()));
        }
    }

    public function seq($local_seq, $seqtypes=false) {
        if (is_null($local_seq)) {
            $local_seqobj = $this->shopseqdb->load($this->shopid);
            if (!$local_seqobj) {
                $this->shopseqdb->insert($this->shopid);
                $local_seqobj = $this->shopseqdb->load($this->shopid);
                if (!$local_seqobj) {
                    return 'unable to load scanpay sequence number';
                }
            }
            $local_seq = $local_seqobj['seq'];
        }

        while (1) {
            try {
                $resobj = $this->client->seq($local_seq);
            } catch (\Exception $e) {
                return 'scanpay client exception: ' . $e->getMessage();
            }
            if (count($resobj['changes']) == 0) {
                break;
            }
            if (!is_null($errmsg = $this->orderupdater->update_all($resobj['changes'], $seqtypes))) {
                return $errmsg;
            }
            if (empty($seqtypes)) {
                $r = $this->shopseqdb->save($this->shopid, $resobj['seq']);
                if (!$r) {
                    if ($r == false) {
                        return 'error saving Scanpay changes';
                    }
                    break;
                }
            }
            $local_seq = $resobj['seq'];
        }
        return null;
    }

    public function handle_pings()
    {
        if (!isset($_SERVER['HTTP_X_SIGNATURE'])) {
            wp_send_json(['error' => 'invalid signature'], 403);
            return;
        }
        $body = file_get_contents('php://input');
        $localsig = base64_encode(hash_hmac('sha256', $body, $this->apikey, true));
        if (!hash_equals($localsig, $_SERVER['HTTP_X_SIGNATURE'])) {
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
        $remote_seq = $jsonreq['seq'];
        $local_seqobj = $this->shopseqdb->load($this->shopid);
        if (!$local_seqobj) {
            $this->shopseqdb->insert($this->shopid);
            $local_seqobj = $this->shopseqdb->load($this->shopid);
            if (!$local_seqobj) {
                scanpay_log('unable to load scanpay sequence number');
                wp_send_json(['error' => 'unable to load scanpay sequence number'], 500);
                return;
            }
        }

        $local_seq = $local_seqobj['seq'];
        if ($local_seq < $remote_seq) {
            $errmsg = $this->seq($local_seq);
            if (!is_null($errmsg)) {
                scanpay_log($errmsg);
                wp_send_json(['error' => $errmsg], 500);
                return;
            }
        } else {
            $this->shopseqdb->updateMtime($this->shopid);
        }
        $queuedcharges = $this->queuedchargedb->loadall();
        foreach ($queuedcharges as $orderid) {
            $order = wc_get_order($orderid);
            $sub = null;
            try {
                $sub = $this->get_subscription($order);
            } catch (\Exception $e) {
                scanpay_log('failed to get subscription from order: ' . $e);
                continue;
            }
            if (update_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_INITIALPAYMENT_NTRIES, '1', '')) {
                $this->scheduled_subscription_payment($sub->get_total_initial_payment(), $order);
            }
            $this->queuedchargedb->delete($orderid);
        }
        wp_send_json_success();
    }

    /* This function is called before __construct(), and thus cannot use the definitions from there */
    public function init_form_fields()
    {
        $local_seqobj;
        $shopid = explode(':', $this->apikey)[0];
        if (ctype_digit($shopid)) {
            $shopseqdb = new Scanpay\ShopSeqDB();
            $local_seqobj = $shopseqdb->load($shopid);
            if (!$local_seqobj) { $local_seqobj = [ 'mtime' => 0 ]; }
        } else {
            $local_seqobj = [ 'mtime' => 0 ];
        }
        $block = [
            'pingurl' => WC()->api_request_url(self::API_PING_URL),
            'lastpingtime'  => $local_seqobj['mtime'],
        ];
        $this->form_fields = buildSettings($block);
    }

    // display the extra data in the order admin panel
    public function display_scanpay_info($order)
    {
        $shopid = get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SHOPID, true);
        if ($shopid === '') {
            return;
        }
        $trnid = get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_TRANSACTION_ID, true);
        $subid = get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID, true);
        $cur = $order->get_currency();
        $auth = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_AUTHORIZED, true), ['currency' => $cur]);
        $captured = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_CAPTURED, true), ['currency' => $cur]);
        $refunded = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_REFUNDED, true), ['currency' => $cur]);
        $trnURL = self::DASHBOARD_URL . '/' . $shopid . '/' . $trnid;
        include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/OrderInfo.phtml');
    }

    public function after_subscribe($orderid)
    {
        /* Attempt to charge initial payments from subscriptions */
        if (!class_exists('WC_Subscriptions')) {
            return;
        }
        $order = wc_get_order($orderid);
        if (!$order) {
            return;
        }
        if (substr_compare($order->get_payment_method(), $this->id, 0, strlen('scanpay')) != 0) {
            return;
        }
        if (!wcs_order_contains_subscription($order)) {
            return;
        }
        if (!$order->needs_payment() || $order->get_total() == 0) {
            return;
        }
        for ($i = 0; $i < 5; $i++) {
            $subid = get_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID, true);
            if (!empty($subid)) { break; }
            if ($i != 5) { usleep(500000); }
        }
        if (empty($subid)) { return; }
        if (update_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_INITIALPAYMENT_NTRIES, '1', '')) {
            $this->scheduled_subscription_payment($order->get_total(), $order);
        } else {
            if (!wc_get_order($orderid)->needs_payment()) {
                return;
            }
            sleep(1);
            if (!wc_get_order($orderid)->needs_payment()) {
                return;
            }
            $this->seq(null, ['subscriber']);
        }
    }

    private static function suberr($renewal_order, $err, $isidempotent=true)
    {
        /* reload order */
        $renewal_order = wc_get_order($renewal_order->get_id());
        if (!$renewal_order->needs_payment()) { return; }
        /* Attempt to weed out races, if the response is not idempotent */
        if (!$isidempotent) {
            sleep(5);
            /* reload order */
            $renewal_order = wc_get_order($renewal_order->get_id());
            if (!$renewal_order->needs_payment()) { return; }
        }
        $msg = sprintf(__('Scanpay transaction failed: %s', 'woocommerce-scanpay'), $err);
        $renewal_order->update_status('failed', $msg);
        scanpay_log('subscriber err: ' . $err, debug_backtrace(FALSE, 1)[0]);
    }

    public function scheduled_subscription_payment($amount = 0.0, $renewal_order)
    {
        if (!$renewal_order->needs_payment()) { return; }

        /* meta data is automatically copied from the shop_subscription to the $renewal order */
        $renewal_orderid = $renewal_order->get_id();
        $subid = get_post_meta($renewal_orderid, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID, true);
        if (empty($subid)) {
            self::suberr($renewal_order, 'Failed to retrieve Scanpay subscriber id from order');
            return;
        }
        $shopid = (int)get_post_meta($renewal_orderid, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, true);
        if (empty($shopid) || $shopid !== $this->shopid) {
            self::suberr($renewal_order, 'API-key shopid does not match stored subscriber shopid');
            return;
        }
        $data = [
            'orderid'  => $renewal_orderid,
            'items'    => [
                ['total' => $amount . ' ' . $renewal_order->get_currency()],
            ],
            'billing'  => array_filter([
                'name'    => $renewal_order->get_billing_first_name() . ' ' . $renewal_order->get_billing_last_name(),
                'email'   => $renewal_order->get_billing_email(),
                'phone'   => preg_replace('/\s+/', '', $renewal_order->get_billing_phone()),
                'address' => array_filter([$renewal_order->get_billing_address_1(), $renewal_order->get_billing_address_2()]),
                'city'    => $renewal_order->get_billing_city(),
                'zip'     => $renewal_order->get_billing_postcode(),
                'country' => $renewal_order->get_billing_country(),
                'state'   => $renewal_order->get_billing_state(),
                'company' => $renewal_order->get_billing_company(),
                'vatin'   => '',
                'gln'     => '',
            ]),
            'shipping' => array_filter([
                'name'    => $renewal_order->get_shipping_first_name() . ' ' . $renewal_order->get_shipping_last_name(),
                'address' => array_filter([$renewal_order->get_shipping_address_1(), $renewal_order->get_shipping_address_2()]),
                'city'    => $renewal_order->get_shipping_city(),
                'zip'     => $renewal_order->get_shipping_postcode(),
                'country' => $renewal_order->get_shipping_country(),
                'state'   => $renewal_order->get_shipping_state(),
                'company' => $renewal_order->get_shipping_company(),
            ]),
        ];
        $maxretries = 3;
        $lasterr = '';
        for ($i = 0; $i < $maxretries; $i++) {

            /* Attempt to load idempotency 10 times */
            for ($j = 0; $j < 10; $j++) {
                $idem = get_post_meta($renewal_order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_CHARGE_IDEM, true);
                if (empty($idem)) {
                    if ($idem === false) {
                        self::suberr($renewal_order, 'Unexpected metadata error loading idempotency key');
                        return;
                    }
                    /* order did not contain an idempotency key, generate one*/
                    $idemtime = time();
                    $idemkey = $this->client->generateIdempotencyKey();
                    $idem = $idemtime . '-' . $idemkey;
                    $r = update_post_meta($renewal_order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_CHARGE_IDEM, $idem, '');
                    if ($r === false) {
                        continue;
                    }
                } else {
                    /* idempotency key did exist on order */
                    $idemarr = explode('-', $idem, 2);
                    $idemtime = $idemarr[0];
                    if (count($idemarr) != 2 || !($idemtime = filter_var($idemtime, FILTER_VALIDATE_INT))) {
                        self::suberr($renewal_order, 'Invalid idempotency key stored in order');
                        return;
                    }
                    $idemkey = $idemarr[1];

                    if (time() > $idemtime + 23 * 60 * 60) {
                        /* idempotency key expired, attempt to seq (only charges) */
                        $r = $this->seq(null, ['charge']);
                        if (!empty($r)) {
                            self::suberr($renewal_order, $r);
                            return;
                        }
                        /* surely we are now up to date, check if order is paid. Reload order: */
                        $renewal_order = wc_get_order($renewal_order->get_id());
                        if (!$renewal_order->needs_payment()) {
                            return;
                        }
                        /* regenerate idempotency key */
                        $idemtime = time();
                        $idemkey = $this->client->generateIdempotencyKey();
                        $oldidem = $idem;
                        $idem = $idemtime . '-' . $idemkey;
                        $r = update_post_meta($renewal_order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_CHARGE_IDEM, $idem, $oldidem);
                        if ($r === false) {
                            continue;
                        }
                    }
                }
                break;
            }
            if ($j === 10) {
                self::suberr($renewal_order, 'Failed to load idempotency key 10 times');
                return;
            }
            $errisidem = false;
            try {
                $chargeResponse = $this->client->charge($subid, $data, ['headers' => ['Idempotency-Key' => $idemkey]]);
                break;
            } catch (Scanpay\IdempotentResponseException $e) {
                $lasterr = $e->getMessage() . ' (idempotent)';
                /* Reset stored idempotency data */
                update_post_meta($renewal_order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_CHARGE_IDEM, '', $idem);
                $errisidem = true;
            } catch (\Exception $e) {
                $lasterr = $e->getMessage();
            }
            if ($i !== $maxretries - 1) { sleep($i * 2 + 1); }
        }
        if ($i === $maxretries) {
            self::suberr($renewal_order, "Encountered scanpay error upon charging sub #$subid: " . $lasterr, $errisidem);
            return;
        }
        $renewal_order->payment_complete($chargeResponse['id']);
    }

    function update_failing_payment_method($oldOrder, $newOrder)
    {
        $oldOrderId = $oldOrder->get_id();
        $newOrderId = $newOrder->get_id();
        update_post_meta($oldOrderId, Scanpay\OrderUpdater::ORDER_DATA_SHOPID,
                         get_post_meta($newOrderId, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, true));
        update_post_meta($oldOrderId, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID,
                         get_post_meta($newOrderId, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID, true));
    }
    function add_subscription_payment_meta($meta, $subscription)
    {
        $subId = $subscription->get_id();
        $meta[$this->id] = [
            'post_meta' => [
                Scanpay\OrderUpdater::ORDER_DATA_SHOPID => [
                    'value' => get_post_meta($subId, Scanpay\OrderUpdater::ORDER_DATA_SHOPID, true),
                    'label' => 'Scanpay shop id',
                ],
                Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID => [
                    'value' => get_post_meta($subId, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID, true),
                    'label' => 'Scanpay subscriber id',
                ],
            ],
        ];
        return $meta;
    }

    public function validate_subscription_payment_meta($methodId, $meta)
    {
        if ($this->id === $methodId) {
            if (!isset($meta['post_meta'][Scanpay\OrderUpdater::ORDER_DATA_SHOPID]) ||
                empty($meta['post_meta'][Scanpay\OrderUpdater::ORDER_DATA_SHOPID])) {
                throw new Exception(__('A Scanpay shopid is required.', 'woocommerce-scanpay'));
            }
            if (!isset($meta['post_meta'][Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID]) ||
                empty($meta['post_meta'][Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID])) {
                throw new Exception(__('A Scanpay subscriberid is required.', 'woocommerce-scanpay'));
            }
        }
    }

}
