<?php
namespace Scanpay;

if (!defined('ABSPATH')) {
    exit;
}

class OrderUpdater
{
    const ORDER_DATA_SHOPID = '_scanpay_shopid';
    const ORDER_DATA_TRANSACTION_ID = '_scanpay_transaction_id';
    const ORDER_DATA_REV = '_scanpay_rev';
    const ORDER_DATA_NACTS = '_scanpay_nacts';
    const ORDER_DATA_PAYID = '_scanpay_payid';
    const ORDER_DATA_AUTHORIZED = '_scanpay_authorized';
    const ORDER_DATA_CAPTURED = '_scanpay_captured';
    const ORDER_DATA_REFUNDED = '_scanpay_refunded';
    const ORDER_DATA_SUBSCRIBER_TIME = '_scanpay_subscriber_time';
    const ORDER_DATA_SUBSCRIBER_ID = '_scanpay_subscriber_id';
    const ORDER_DATA_SUBSCRIBER_CHARGE_IDEM = '_scanpay_subscriber_charge_idem';
    const ORDER_DATA_SUBSCRIBER_INITIALPAYMENT_NTRIES = '_scanpay_subscriber_initialpayment_ntries';

    private $shopid;
    private $scanpay;
    private $queuedchargedb;

    function __construct($shopid, $scanpayinstance)
    {
        $this->shopid = $shopid;
        $this->scanpay = $scanpayinstance;
    }

    private function autocomplete($order)
    {
        $isprocessing = $order->get_status() === 'processing';
        if ($isprocessing && $this->scanpay->autocomplete_virtual) {
            $has_nonvirtual = false;
            foreach ($order->get_items('line_item') as $item) {
                if (!$item->get_product()->is_virtual()) {
                    $has_nonvirtual = true;
                }
            }
            if (!$has_nonvirtual) {
                $order->update_status('completed', __('Automatically completed virtual order.', 'woocommerce-scanpay'), false);
                $isprocessing = false;
            }
        }
        if ($isprocessing && $this->scanpay->autocomplete_renewalorders && class_exists('WC_Subscriptions') && wcs_order_contains_renewal($order)) {
            $order->update_status('completed', __('Automatically completed renewal order order.', 'woocommerce-scanpay'), false);
        }
    }

    private function updatetrn($d)
    {
        if (!isset($d['id']) || !is_int($d['id'])) {
            return 'Missing "id" in change';
        }
        /* Ignore errornous transactions */
        if (isset($d['error'])) {
            scanpay_log("Received error transaction[id=$d[id]] in order updater: $d[error]");
            return null;
        }
        if (!isset($d['rev']) || !is_int($d['rev'])) {
            return 'Missing "rev" in change';
        }
        if (!isset($d['totals']) || !is_array($d['totals']) ||
            !isset($d['totals']['authorized'])) {
            return 'Missing "totals.authorized" in change';
        }
        if (!isset($d['acts']) || !is_array($d['acts'])) {
            return 'Missing "acts" in change';
        }
        if (!isset($d['orderid'])) {
            scanpay_log('Received ' . $d['type'] . ' #' . $d['id'] . ' without orderid');
            return null;
        }
        $orderid = $d['orderid'];
        $order = wc_get_order($orderid);
        if (!$order) {
            scanpay_log('Order #' . $orderid . ' not in system');
            return null;
        }

        /* Skip the order, if it's registered with a different shop */
        $orderShopId = (int)get_post_meta($orderid, self::ORDER_DATA_SHOPID, true);
        $oldrev = (int)get_post_meta($orderid, self::ORDER_DATA_REV, true);
        if ($this->shopid !== $orderShopId) {
            scanpay_log('Order #' . $orderid . ' shopid (' .
                $orderShopId . ') does not match current shopid (' .
                $this->shopid . ')');
            return null;
        }
        /* Skip the order, if it's not a new revision */
        if ($d['rev'] <= $oldrev) {
            return null;
        }

        $nacts = (int)get_post_meta($orderid, self::ORDER_DATA_NACTS, true);
        for ($i = $nacts; $i < count($d['acts']); $i++) {
            $act = $d['acts'][$i];
            switch ($act['act']) {
            case 'capture':
                if (isset($act['total']) && is_string($act['total'])) {
                    $order->add_order_note(sprintf(__('Captured %s.', 'woocommerce-scanpay'), $act['total']));
                }
                break;
            case 'refund':
                wc_create_refund($actArgs);
                if (isset($act['total']) && is_string($act['total'])) {
                    $order->add_order_note(sprintf(__('Refunded %s.', 'woocommerce-scanpay'), $act['total']));
                }
                break;
            case 'void':
                if (isset($act['total']) && is_string($act['total'])) {
                    $order->add_order_note(sprintf(__('Voided %s.', 'woocommerce-scanpay'), $act['total']));
                }
                break;
            }
        }
        update_post_meta($orderid, self::ORDER_DATA_NACTS, count($d['acts']));
        if (isset($d['totals']['captured'])) {
            $captured = explode(' ', $d['totals']['captured'])[0];
            update_post_meta($orderid, self::ORDER_DATA_CAPTURED, $captured);
        }

        if (isset($d['totals']['refunded'])) {
            $refunded = explode(' ', $d['totals']['refunded'])[0];
            update_post_meta($orderid, self::ORDER_DATA_REFUNDED, $refunded);
        }
        if ($order->needs_payment()) {
            $order->payment_complete($d['id']);
        }
        if (empty(get_post_meta($orderid, self::ORDER_DATA_TRANSACTION_ID))) {
            update_post_meta($orderid, self::ORDER_DATA_TRANSACTION_ID, $d['id']);
            update_post_meta($orderid, self::ORDER_DATA_AUTHORIZED, explode(' ', $d['totals']['authorized'])[0]);
            $order->add_order_note(sprintf(__('The authorized amount is %s', 'woocommerce-scanpay' ),
                                   $d['totals']['authorized']));
        }
        $this->autocomplete($order);
        update_post_meta($orderid, self::ORDER_DATA_REV, $d['rev']);
        return null;
    }

    private function updatesub($d)
    {
        if (!isset($d['id']) || !is_int($d['id'])) {
            return 'Missing "id" in change';
        }
        /* Ignore errornous transactions */
        if (isset($d['error'])) {
            scanpay_log("Received error transaction[id=$d[id]] in order updater: $d[error]");
            return null;
        }
        if (!isset($d['rev']) || !is_int($d['rev'])) {
            return 'Missing "rev" in change';
        }
        if (!isset($d['acts']) || !is_array($d['acts'])) {
            return 'Missing "acts" in change';
        }
        if (!isset($d['ref'])) {
            scanpay_log('Received subscriber #' . $d['id'] . ' without ref');
            return null;
        }
        if (!class_exists('WC_Subscriptions')) {
            scanpay_log('Received subscriber #' . $d['id'] . ', but Woocommerce Subscriptions is not enabled');
            return null;
        }
        $orderid = $d['ref'];
        $order = wc_get_order($orderid);
        if (!$order) {
            scanpay_log('Order #' . $orderid . ' not in system');
            return null;
        }

        /* Get the time of the last authorize/renew action */
        $tchanged = $d['time']['authorized'];
        for ($i = 0; $i < count($d['acts']); $i++) {
            $act = $d['acts'][$i];
            switch ($act['act']) {
            case 'renew':
                if (isset($act['time']) && is_int($act['time'])) {
                    $tchanged = $act['time'];
                }
                break;
            }
        }
        if (wcs_is_subscription($order)) {
            update_post_meta($orderid, self::ORDER_DATA_SHOPID, $this->shopid);
            update_post_meta($orderid, self::ORDER_DATA_SUBSCRIBER_TIME, $tchanged);
            update_post_meta($orderid, self::ORDER_DATA_SUBSCRIBER_ID, $d['id']);
        } else {
            foreach (wcs_get_subscriptions_for_order($order, ['order_type' => ['parent', 'switch', 'renewal']]) as $sub) {
                $subid = $sub->get_id();
                $oldSubTime = (int)get_post_meta($subid, self::ORDER_DATA_SUBSCRIBER_TIME, true);
                $oldSubId = get_post_meta($subid, self::ORDER_DATA_SUBSCRIBER_ID, true);
                if ($tchanged > $oldSubTime) {
                    update_post_meta($subid, self::ORDER_DATA_SHOPID, $this->shopid);
                    update_post_meta($subid, self::ORDER_DATA_SUBSCRIBER_TIME, $tchanged);
                    update_post_meta($subid, self::ORDER_DATA_SUBSCRIBER_ID, $d['id']);

                    /*
                     * Set the subscriber info for the parent order (since it will not be copied from subscription
                     * to this order, as the parent order obviously is already created. This is is essential for the
                     * inital payment made below this loop.
                     */
                    update_post_meta($orderid, self::ORDER_DATA_SUBSCRIBER_TIME, $tchanged);
                    update_post_meta($orderid, self::ORDER_DATA_SUBSCRIBER_ID, $d['id']);

                    if (empty($oldSubId)) {
                        $order->add_order_note(__('Subscription payment method added', 'woocommerce-scanpay' ) .
                                                  "(id=$d[id])");
                    } else {
                        $order->add_order_note(__('Subscription payment method renewed', 'woocommerce-scanpay' ) .
                                                  "(id=$d[id])");
                    }
                }
            }
        }

        if ($order->needs_payment()) {
            if ($order->get_total() > 0) {
                $this->scanpay->queuedchargedb->save($orderid);
            } else {
                $order->payment_complete();
            }
        }
        $this->autocomplete($order);
        return null;
    }

    public function update_all($changes, $seqtypes)
    {
        $shopid = $this->shopid;
        foreach ($changes as $change) {
            if (!empty($seqtypes) && !in_array($change['type'], $seqtypes)) {
                continue;
            }
            try {
                switch ($change['type']) {
                case 'transaction':
                case 'charge':
                    $errmsg = $this->updatetrn($change);
                    break;
                case 'subscriber':
                    $errmsg = $this->updatesub($change);
                    break;
                default:
                    scanpay_log('Unknown change type ' . $change['type']);
                    continue 2;
                }
            } catch (\Exception $e) {
                return 'order update exception: ' . $e->getMessage();
            }
            if (!is_null($errmsg)) {
                return 'order update failed: ' . $errmsg;
            }
        }
        return null;
    }
}
