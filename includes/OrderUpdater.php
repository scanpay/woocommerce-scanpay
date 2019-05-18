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
    const ORDER_DATA_SUBSCRIBER_CHARGE_IDEM = '_scanpay_subscriber_charge_idem';
    protected $orderid;
    protected $order;
    protected $shopid;
    protected $data;
    protected $scanpay;
    abstract protected function type();
    abstract protected function isvalid();
    abstract protected function getorderid();
    abstract protected function before_acts();
    abstract protected function handle_act($act);
    abstract protected function after_acts();

    function __construct($shopid, $data, $scanpayinstance)
    {
        $this->shopid = $shopid;
        $this->data = $data;
        $this->orderid = $this->getorderid();
        $this->scanpay = $scanpayinstance;
    }

    public function update()
    {
        $d = $this->data;
        /* Ignore errornous transactions */
        if (isset($d['error'])) {
            scanpay_log('Received error entry in order updater: ' . $d['error']);
            return null;
        }
        if (!$this->isvalid($d)) {
            return 'Received invalid ' . $this->type() . ' data from Scanpay';
        }
        if (!isset($d['id']) || !is_int($d['id'])) {
            return 'Missing "id" in change';
        }
        if (!isset($d['rev']) || !is_int($d['rev'])) {
            return 'Missing "rev" in change';
        }
        if ($this->orderid === null) {
            scanpay_log('Received ' . $this->type() . ' #' . $d['id'] . ' without orderid');
            return null;
        }

        $this->order = wc_get_order($this->orderid);
        if (!$this->order) {
            scanpay_log('Order #' . $this->orderid . ' not in system');
            return null;
        }

        $newRev = $d['rev'];
        $orderShopId = (int)get_post_meta($this->orderid, self::ORDER_DATA_SHOPID, true);
        $oldRev = (int)get_post_meta($this->orderid, self::ORDER_DATA_REV, true);
        if ($this->shopid !== $orderShopId) {
            scanpay_log('Order #' . $this->orderid . ' shopid (' .
                $orderShopId . ') does not match current shopid (' .
                $this->shopid . '()');
            return null;
        }

        if ($newRev <= $oldRev) {
            return null;
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
        return null;
    }

    protected function autocomplete()
    {
        /*  autocomplete virtual orders */
        if ($this->scanpay->autocomplete_virtual && $this->order->get_status() === 'processing') {
            $has_nonvirtual = false;
            foreach ($this->order->get_items('line_item') as $item) {
                if (!$item->get_product()->is_virtual()) {
                    $has_nonvirtual = true;
                }
            }
            if (!$has_nonvirtual) {
                $this->order->update_status('completed', __('Automatically completed virtual order.', 'woocommerce-scanpay'), false);
            }
        }
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
        if (!isset($this->data['orderid'])) { return null; }
        return $this->data['orderid'];
    }

    protected function before_acts()
    {
        $auth = $this->data['totals']['authorized'];
        if ($this->order->needs_payment()) {
            $this->order->payment_complete($this->data['id']);
            $this->order->add_order_note(sprintf(__('The authorized amount is %s.', 'woocommerce-scanpay' ), $auth));
            update_post_meta($this->orderid, self::ORDER_DATA_AUTHORIZED, explode(' ', $auth)[0]);
        }
        $this->autocomplete();
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
                $this->order->add_order_note(sprintf(__('Captured %s.', 'woocommerce-scanpay'), $act['total']));
            }
            break;
        case 'refund':
            wc_create_refund($actArgs);
            if (isset($act['total']) && is_string($act['total'])) {
                $this->order->add_order_note(sprintf(__('Refunded %s.', 'woocommerce-scanpay'), $act['total']));
            }
            break;
        case 'void':
            if (isset($act['total']) && is_string($act['total'])) {
                $this->order->add_order_note(sprintf(__('Voided %s.', 'woocommerce-scanpay'), $act['total']));
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
}

class SubscriberUpdater extends EntUpdater
{
    protected function type() {
        return 'subscriber';
    }

    protected function isvalid() {
        return isset($this->data['time']) && isset($this->data['time']['authorized']);
    }

    protected function getorderid()
    {
        if (!isset($this->data['ref'])) { return null; }
        return $this->data['ref'];
    }

    private function savesubscriber($tchanged) {
        foreach (wcs_get_subscriptions_for_order($this->orderid) as $subscription) {
            $oldSubTime = (int)get_post_meta($subscription->id, self::ORDER_DATA_SUBSCRIBER_TIME, true);
            $oldSubId = get_post_meta($subscription->id, self::ORDER_DATA_SUBSCRIBER_ID, true);
            if ($tchanged > $oldSubTime){
                update_post_meta($this->orderid, self::ORDER_DATA_SUBSCRIBER_TIME, $tcreated);
                update_post_meta($this->orderid, self::ORDER_DATA_SUBSCRIBER_ID, $this->data['id']);
                update_post_meta($subscription->id, self::ORDER_DATA_SUBSCRIBER_TIME, $tcreated);
                update_post_meta($subscription->id, self::ORDER_DATA_SUBSCRIBER_ID, $this->data['id']);
                if (empty($oldSubId)) {
                    $this->order->add_order_note(__('Subscription payment method added', 'woocommerce-scanpay' ) .
                                                    "(id={$this->data['id']})");
                } else {
                    $this->order->add_order_note(__('Subscription payment method renewed', 'woocommerce-scanpay' ) .
                                                    "(id={$this->data['id']})");
                }
            }
        }
    }

    protected function before_acts() {
        $this->savesubscriber($this->data['time']['authorized']);
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
        if ($this->order->needs_payment()) {
            if ($this->order->get_total() > 0) {
                $this->scanpay->scheduled_subscription_payment($this->order->get_total(), $this->order);
                /* no need to set success/failure, the above method will handle that */
            } else {
                $this->order->payment_complete();
            }
        }
        $this->autocomplete();
    }

}

class OrderUpdater
{
    public static function update_all($shopid, $changes, $scanpayinstance, $seqtypes)
    {
        foreach ($changes as $change) {
            if (!empty($seqtypes) && !in_array($seqtypes, $change['type'])) {
                continue;
            }
            switch ($change['type']) {
            case 'transaction':
                $updater = new TrnUpdater($shopid, $change, $scanpayinstance);
                break;
            case 'charge':
                $updater = new ChargeUpdater($shopid, $change, $scanpayinstance);
                break;
            case 'subscriber':
                $updater = new SubscriberUpdater($shopid, $change, $scanpayinstance);
                break;
            default:
                scanpay_log('Unknown change type ' . $change['type']);
                continue;
            }
            try {
                if (!is_null($errmsg = $updater->update())) {
                    return 'order update failed: ' . $errmsg;
                }
            } catch (\Exception $e) {
                return 'order update exception: ' . $e->getMessage();
            }
        }
        return null;
    }
}


