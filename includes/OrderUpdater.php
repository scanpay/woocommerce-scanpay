<?php
namespace Scanpay;

if (!defined('ABSPATH')) {
    exit;
}

use Scanpay\Money as Money;

class OrderUpdater
{
    const ORDER_DATA_SHOPID = '_scanpay_shopid';
    const ORDER_DATA_SEQ = '_scanpay_seq';
    const ORDER_DATA_NACTS = '_scanpay_nacts';
    const ORDER_DATA_CAPTURED = '_scanpay_captured';
    const ORDER_DATA_REFUNDED = '_scanpay_refunded';

    public function dataIsValid($data)
    {
        return isset($data['id']) && is_int($data['id']) &&
            isset($data['totals']) && is_array($data['totals']) &&
            isset($data['totals']['authorized']) &&
            Money::validate($data['totals']['authorized']) &&
            isset($data['seq']) && is_int($data['seq']);
    }

    public function update($shopId, $data)
    {
        /* Ignore errornous transactions */
        if (isset($data['error'])) {
            error_log('Received error entry in seq upater: ' . $data['error']);
            return true;
        }

        if (!$this->dataIsValid($data)) {
            error_log('Received invalid order data from Scanpay');
            return false;
        }

        $trnId = $data['id'];
        /* Ignore transactions without order ids */
        if (!isset($data['orderid']) || $data['orderid'] === "") {
            error_log('Received transaction #' . $trnId . ' without orderid');
            return true;
        }

        $orderid = $data['orderid'];
        $order = wc_get_order($orderid);
        if (!$order) {
            error_log('Order #' . $orderid . ' not in system');
            return true;
        }

        $newSeq = $data['seq'];
        $orderShopId = (int)get_post_meta($orderid, self::ORDER_DATA_SHOPID, true );
        $oldSeq = (int)get_post_meta($orderid, self::ORDER_DATA_SEQ, true );

        if ($shopId !== $orderShopId) {
            error_log('Order #' . $orderid . ' shopid (' .
                $orderShopId . ') does not match current shopid (' .
                $shopId . '()');
            return true;
        }

        if ($newSeq <= $oldSeq) {
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

            if (isset($data['totals']['captured']) && Money::validate($data['totals']['captured'])) {
                $captured = (new Money($data['totals']['captured']))->number();
                update_post_meta($orderid, self::ORDER_DATA_CAPTURED, $captured);
            }

            if (isset($data['totals']['refunded']) && Money::validate($data['totals']['refunded'])) {
                $refunded = (new Money($data['totals']['refunded']))->number();
                update_post_meta($orderid, self::ORDER_DATA_REFUNDED, $refunded);
            }
        }

        update_post_meta($orderid, self::ORDER_DATA_SEQ, $data['seq']);
        return true;
    }

    public function updateAll($shopId, $dataArr)
    {
        foreach ($dataArr as $data) {
            if (!$this->update($shopId, $data)) {
                return false;
            }
        }
        return true;
    }
}
