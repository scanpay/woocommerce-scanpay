<?php
namespace Scanpay;

if (!defined('ABSPATH')) {
    exit;
}

class OrderUpdater
{
    const ORDER_DATA_SHOPID = '_scanpay_shopid';
    const ORDER_DATA_REV = '_scanpay_rev';
    const ORDER_DATA_NACTS = '_scanpay_nacts';
    const ORDER_DATA_CAPTURED = '_scanpay_captured';
    const ORDER_DATA_REFUNDED = '_scanpay_refunded';

    public function dataIsValid($data)
    {
        return isset($data['id']) && is_int($data['id']) &&
            isset($data['totals']) && is_array($data['totals']) &&
            isset($data['totals']['authorized']) &&
            isset($data['rev']) && is_int($data['rev']);
    }

    public function update($shopId, $data)
    {
        /* Ignore errornous transactions */
        if (isset($data['error'])) {
            scanpay_log('Received error entry in order updater: ' . $data['error']);
            return true;
        }

        if (!$this->dataIsValid($data)) {
            scanpay_log('Received invalid order data from Scanpay');
            return false;
        }

        $trnId = $data['id'];
        /* Ignore transactions without order ids */
        if (!isset($data['orderid']) || $data['orderid'] === "") {
            scanpay_log('Received transaction #' . $trnId . ' without orderid');
            return true;
        }

        $orderid = $data['orderid'];
        $order = wc_get_order($orderid);
        if (!$order) {
            scanpay_log('Order #' . $orderid . ' not in system');
            return true;
        }

        $newRev = $data['rev'];
        $orderShopId = (int)get_post_meta($orderid, self::ORDER_DATA_SHOPID, true );
        $oldRev = (int)get_post_meta($orderid, self::ORDER_DATA_REV, true );

        if ($shopId !== $orderShopId) {
            scanpay_log('Order #' . $orderid . ' shopid (' .
                $orderShopId . ') does not match current shopid (' .
                $shopId . '()');
            return true;
        }

        if ($newRev <= $oldRev) {
            return true;
        }

        $auth = $data['totals']['authorized'];

        /* Check if the transaciton is already registered */
        if ($order->get_status() === 'pending') {
            $order->payment_complete($trnId);
            $order->add_order_note(sprintf(__('The authorized amount is %s.', 'woocommerce' ), $auth));
        }

        if (isset($data['acts']) && is_array($data['acts'])) {
            $nacts = (int)get_post_meta($orderid, self::ORDER_DATA_NACTS, true);
            for ($i = $nacts; $i < count($data['acts']); $i++) {
                $act = $data['acts'][$i];
            	$actArgs = array(
            		'amount'     => $act['total'],
            		'reason'     => null,
            		'order_id'   => $orderid,
            	);
                switch ($act['act']) {
                case 'capture':
                    if (isset($act['total']) && is_string($act['total'])) {
                        $order->add_order_note(sprintf(__('The captured amount is %s.', 'woocommerce' ), $act['total']));
                    }
                    break;

                case 'refund':
                    wc_create_refund($actArgs);
                    if (isset($act['total']) && is_string($act['total'])) {
                        $order->add_order_note(sprintf(__('The refunded amount is %s.', 'woocommerce' ), $act['total']));
                    }
                    break;
                }
            }
            update_post_meta($orderid, self::ORDER_DATA_NACTS, count($data['acts']));

            if (isset($data['totals']['captured'])) {
                $captured = explode(' ', $data['totals']['captured'])[0];
                update_post_meta($orderid, self::ORDER_DATA_CAPTURED, $captured);
            }

            if (isset($data['totals']['refunded'])) {
				$refunded = explode(' ', $data['totals']['refunded'])[0];
                update_post_meta($orderid, self::ORDER_DATA_REFUNDED, $refunded);
            }
        }

        update_post_meta($orderid, self::ORDER_DATA_REV, $data['rev']);
        return true;
    }

    public function updateAll($shopId, $changes)
    {
        foreach ($changes as $trn) {
            if (!$this->update($shopId, $trn)) {
                return false;
            }
        }
        return true;
    }
}
