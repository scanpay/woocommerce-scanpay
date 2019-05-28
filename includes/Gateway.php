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

        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->apikey = $this->get_option('apikey');
        $this->pingurl = WC()->api_request_url(self::API_PING_URL);
        $this->autocomplete_virtual = $this->get_option('autocomplete_virtual') === 'yes';

        $shopid = explode(':', $this->apikey)[0];
        if (ctype_digit($shopid)) {
            $this->shopid = (int)$shopid;
        } else {
            $this->shopid = null;
        }
        /* Subclasses */
        $this->client = new Scanpay\Scanpay($this->apikey);
        $this->orderupdater = new Scanpay\OrderUpdater($this->shopid, $this);
        $this->shopseqdb = new Scanpay\ShopSeqDB();
        $this->queuedchargedb = new Scanpay\QueuedChargeDB();

        $this->supports = ['products'];
        if ($support_subscriptions) {
            $this->supports = array_merge($this->supports, [
                'subscriptions',
                'subscription_cancellation',
                'subscription_reactivation',
                'subscription_suspension',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change_admin',
                'subscription_payment_method_change_customer',
                'multiple_subscriptions',
                'pre-orders',
            ]);
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
            /* Subscription charge hook */
            add_action('woocommerce_scheduled_subscription_payment_' . $this->id,
                       [$this, 'scheduled_subscription_payment'], 10, 2);
        }

        /*
         * Fix that WC_Subscriptions_Change_Payment_Gateway::can_subscription_be_updated_to_new_payment_method
         * wont fail, because totals is set to 0 ($subscription->get_total() == 0 will cause it to fail
         */
        if (class_exists('WC_Subscriptions_Change_Payment_Gateway') && WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment) {
            remove_filter( 'woocommerce_subscription_get_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11 );
        }

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
            'language'    => $this->get_option('language'),
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

        $cur = version_compare(WC_VERSION, '3.0.0', '<') ? $order->get_order_currency() : $order->get_currency();
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
            $data['autocapture'] = $this->get_option('autocapture') === 'yes';
        } else {
            $data['autocapture'] = $this->get_option('autocapture') === 'yes' || $this->get_option('autocapture_virtual') === 'yes';
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
        $shippingcost = $order->get_total_shipping() + $order->get_shipping_tax();
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
                'cardholderIP' => $_SERVER['REMOTE_ADDR'],
            ],
        ];

        /* Handle subscriptions */
        if (class_exists('WC_Subscriptions')) {
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
                return [
                    'result' => 'success',
                    'redirect' => $renewurl,
                ];
            /* Handle new subscriptions */
            } else if (wcs_order_contains_subscription($orderid)) {
                $data['subscriber'] = [
                    'ref' => $orderid,
                ];
                unset($data['items']);
                $nsub = 0;
                foreach (wcs_get_subscriptions_for_order($orderid, ['order_type' => ['parent']]) as $subscription) {
                    update_post_meta($subscription->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SHOPID, $this->shopid);
                    $nsub++;
                }
                if ($nsub !== 1) { throw new \Exception("order with subscription contains $n parent orders"); }
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

        $opts = [
            'autocomplete_virtual' => $this->autocomplete_virtual,
        ];

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
            $queuedcharges = $this->queuedchargedb->loadall();
            foreach ($queuedcharges as $orderid) {
                $order = wc_get_order($orderid);
                if (update_post_meta($orderid, Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_INITIALPAYMENT_NTRIES, '1', '')) {
                    $this->scheduled_subscription_payment($order->get_total(), $order);
                }
                $this->queuedchargedb->delete($orderid);
            }
        } else {
            $this->shopseqdb->updateMtime($this->shopid);
        }
        wp_send_json_success();
    }

    /* This function is called before __construct(), and thus cannot use the definitions from there */
    public function init_form_fields()
    {
        $local_seqobj;
        $apikey = $this->get_option('apikey');
        $shopid = explode(':', $apikey)[0];
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
        $trnid = $order->get_transaction_id();
        $subid = get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_ID, true);
        $cur = version_compare(WC_VERSION, '3.0.0', '<') ? $order->get_order_currency() : $order->get_currency();
        $auth = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_AUTHORIZED, true), ['currency' => $cur]);
        $captured = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_CAPTURED, true), ['currency' => $cur]);
        $refunded = wc_price(get_post_meta($order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_REFUNDED, true), ['currency' => $cur]);
        $trnURL = self::DASHBOARD_URL . '/' . $shopid . '/' . $trnid;
	    include_once(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/OrderInfo.phtml');
    }

    private static function suberr($renewal_order, $err, $isidempotent=true)
    {
        $renewal_order->load();
        if (!$renewal_order->needs_payment()) { return; }
        /* Attempt to weed out races, if the response is not idempotent */
        if (!$isidempotent) {
            sleep(5);
            $renewal_order->load();
            if (!$renewal_order->needs_payment()) { return; }
        }
        WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
        $renewal_order->add_order_note($err);
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
                ['total' => $amount . ' ' . $renewal_order->get_order_currency()],
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
        $maxretries = 5;
        $lasterr = '';
        for ($i = 0; $i < $maxretries; $i++) {

            /* Attempt to load idempotency 10 times */
            for ($j = 0; $j < 10; $j++) {
                $idem = get_post_meta($renewal_order->get_id(), Scanpay\OrderUpdater::ORDER_DATA_SUBSCRIBER_CHARGE_IDEM, true);
                if (empty($idem)) {
                    if ($idem != '') {
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

                    if ($idemtime + 23 * 60 * 60 > time()) {
                        /* idempotency key expired, attempt to seq (only charges) */
                        $r = $this->seq(null, ['charge']);
                        if (!empty($r)) {
                            self::suberr($renewal_order, $r);
                            return;
                        }
                        /* surely we are now up to date, check if order is paid */
                        $renewal_order->load();
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
                $lasterr = $e->getMessage() . ' (idem)';
                $idemkey = '';
                $errisidem = true;
            } catch (\Exception $e) {
                $lasterr = $e->getMessage();
            }
            sleep($i + 1);
        }
        if ($i === $maxretries) {
            self::suberr($renewal_order, "Encountered scanpay error upon charging sub #$subid: " . $lasterr, $errisidem);
            return;
        }
        $renewal_order->payment_complete($chargeResponse['id']);
    }
}
