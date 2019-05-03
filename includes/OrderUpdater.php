<?php
namespace Scanpay;

if (!defined('ABSPATH')) {
    exit;
}

abstract class EntUpdater
{
    const ORDER_DATA_SHOPID = '_scanpay_shopid';
    const ORDER_DATA_REV = '_scanpay_rev';
    const ORDER_DATA_NACTS = '_scanpay_nacts';
    const ORDER_DATA_AUTHORIZED = '_scanpay_authorized';
    const ORDER_DATA_CAPTURED = '_scanpay_captured';
    const ORDER_DATA_REFUNDED = '_scanpay_refunded';
    const ORDER_DATA_SUBSCRIBER_TIME = '_scanpay_subscriber_time';
    const ORDER_DATA_SUBSCRIBER_ID = '_scanpay_subscriber_id';
    const ORDER_DATA_SUBSCRIBER_CHARGE_IDEMKEY = '_scanpay_subscriber_charge_idemkey';
    const ORDER_DATA_SUBSCRIBER_CHARGE_IDEMTIME = '_scanpay_subscriber_charge_idemtime';
    protected $orderid;
    protected $order;
    protected $shopid;
    protected $data;
    abstract protected function type();
    abstract protected function isvalid();
    abstract protected function getorderid();
    abstract protected function before_acts();
    abstract protected function handle_act($act);
    abstract protected function after_acts();

    function __construct($shopid, $data)
    {
        $this->shopid = $shopid;
        $this->data = $data;
        $this->orderid = $this->getorderid();
    }

    public function update()
    {
        $d = $this->data;
        /* Ignore errornous transactions */
        if (isset($d['error'])) {
            scanpay_log('Received error entry in order updater: ' . $d['error']);
            return true;
        }
        if (!$this->isvalid($d)) {
            scanpay_log('Received invalid transaction data from Scanpay');
            return false;
        }
        if (!isset($d['id']) || !is_int($d['id'])) {
            scanpay_log('Missing "id" in change');
            return false;
        }
        if (!isset($d['rev']) || !is_int($d['rev'])) {
            scanpay_log('Missing "rev" in change');
            return false;
        }
        if ($this->orderid === NULL) {
            scanpay_log('Received ' . $this->type() . ' #' . $d['id'] . ' without orderid');
            return true;
        }

        $this->order = wc_get_order($this->orderid);
        if (!$this->order) {
            scanpay_log('Order #' . $this->orderid . ' not in system');
            return true;
        }

        $newRev = $d['rev'];
        $orderShopId = (int)get_post_meta($this->orderid, self::ORDER_DATA_SHOPID, true);
        $oldRev = (int)get_post_meta($this->orderid, self::ORDER_DATA_REV, true);
        if ($this->shopid !== $orderShopId) {
            scanpay_log('Order #' . $this->orderid . ' shopid (' .
                $orderShopId . ') does not match current shopid (' .
                $this->shopid . '()');
            return true;
        }

        if ($newRev <= $oldRev) {
            return true;
        }

        $this->before_acts();
        if (isset($d['acts']) && is_array($d['acts'])) {
            $nacts = (int)get_post_meta($this->orderid, self::ORDER_DATA_NACTS, true);
            for ($i = $nacts; $i < count($d['acts']); $i++) {
                $this->handle_act($d['acts'][$i]);
            }
            update_post_meta($this->orderid, self::ORDER_DATA_NACTS, count($d['acts']));
        }
        $this->after_acts();
        update_post_meta($this->orderid, self::ORDER_DATA_REV, $d['rev']);
        return true;
    }
}

class TrnUpdater extends EntUpdater
{
    protected function type() {
        return 'transaction';
    }

    protected function isvalid()
    {
        return isset($this->data['totals']) && is_array($this->data['totals']) &&
            isset($this->data['totals']['authorized']);
    }

    protected function getorderid()
    {
        if (!isset($this->data['orderid'])) { return NULL; }
        return $this->data['orderid'];
    }

    protected function before_acts()
    {
        $auth = $d['totals']['authorized'];
        $status = $this->order->get_status();
        if ($status === 'pending' || $status === 'cancelled') {
            $this->order->payment_complete($this->data['id']);
            $this->order->add_order_note(sprintf(__('The authorized amount is %s.', 'woocommerce' ), $auth));
            update_post_meta($this->orderid, self::ORDER_DATA_AUTHORIZED, explode(' ', $auth)[0]);
        }
    }

    protected function handle_act($act)
    {
        $actArgs = array(
            'amount'     => $act['total'],
            'reason'     => null,
            'order_id'   => $this->orderid,
        );
        switch ($act['act']) {
        case 'capture':
            if (isset($act['total']) && is_string($act['total'])) {
                $this->order->add_order_note(sprintf(__('Captured %s.', 'woocommerce' ), $act['total']));
            }
            break;
        case 'refund':
            wc_create_refund($actArgs);
            if (isset($act['total']) && is_string($act['total'])) {
                $this->order->add_order_note(sprintf(__('Refunded %s.', 'woocommerce' ), $act['total']));
            }
            break;
        case 'void':
            if (isset($act['total']) && is_string($act['total'])) {
                $this->order->add_order_note(sprintf(__('Voided %s.', 'woocommerce' ), $act['total']));
            }
            break;
        }
    }

    protected function after_acts()
    {
        if (isset($this->data['totals']['captured'])) {
            $captured = explode(' ', $this->data['totals']['captured'])[0];
            update_post_meta($this->orderid, self::ORDER_DATA_CAPTURED, $captured);
        }

        if (isset($this->data['totals']['refunded'])) {
            $refunded = explode(' ', $this->data['totals']['refunded'])[0];
            update_post_meta($this->orderid, self::ORDER_DATA_REFUNDED, $refunded);
        }
    }
}

class ChargeUpdater extends TrnUpdater
{
    protected function type() {
        return 'charge';
    }

    protected function isvalid() {
        return parent::isvalid() && isset($data['subscriber']) &&
            is_array($data['subscriber']) && isset($data['subscriber']['id']);
    }

    protected function before_acts()
    {
        $auth = $d['totals']['authorized'];
        if ($this->order->needs_payment()) {
            WC_Subscriptions_Manager::process_subscription_payments_on_order($this->order);
            update_post_meta($this->orderid, self::ORDER_DATA_AUTHORIZED, explode(' ', $auth)[0]);
        }
    }
}

class SubscriberUpdater extends EntUpdater
{
    protected function type() {
        return 'subscriber';
    }

    protected function isvalid() {
        return isset($data['time']) && is_array($data['time']) &&
            isset($data['time']['created']) && is_int($data['time']['created']);
    }

    protected function getorderid()
    {
        if (!isset($this->data['ref'])) { return NULL; }
        return $this->data['ref'];
    }

    private function savesubscriber($tchanged) {
        $oldSubTime = (int)get_post_meta($this->orderid, self::ORDER_DATA_SUBSCRIBER_TIME, true);
        $oldSubId = get_post_meta($this->orderid, self::ORDER_DATA_SUBSCRIBER_ID, true);
        if (empty($oldSubId) || $tchanged > $oldSubTime){
            update_post_meta($this->orderid, self::ORDER_DATA_SUBSCRIBER_TIME, $tcreated);
            update_post_meta($this->orderid, self::ORDER_DATA_SUBSCRIBER_ID, $this->data['id']);
            if (empty($oldSubId)) {
                $this->order->add_order_note(__('Subscription payment method added', 'woocommerce' ));
            } else {
                $this->order->add_order_note(__('Subscription payment method renewed', 'woocommerce' ));
            }
        }
    }

    protected function before_acts() {
        $this->savesubscriber($this->data['time']['created']);
    }

    protected function handle_act($act)
    {
        switch ($act['act']) {
        case 'renew':
            if (isset($act['time']) && is_int($act['time'])) {
                $this->savesubscriber($act['time']);
            }
            break;
        }
    }

    protected function after_acts() {
        if ($this->order->get_status() === 'pending') {
            $this->order->payment_complete();
        }
    }

}

class OrderUpdater
{
    public function update_all($shopid, $changes)
    {
        foreach ($changes as $change) {
            switch ($change['type']) {
            case 'transaction':
                $updater = new TrnUpdater($shopid, $change);
                break;
            case 'charge':
                $updater = new ChargeUpdater($shopid, $change);
                break;
            case 'subscriber':
                $updater = new SubscriberUpdater($shopid, $change);
                break;
            default:
                scanpay_log('Unknown change type ' . $change['type']);
                continue;
            }
            try {
            if (!$updater->update()) {
                return false;
            }
            } catch (\Exception $e) {
                scanpay_log($e->getMessage());
                return false;
            }
        }
        return true;
    }
}


