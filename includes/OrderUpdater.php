<?php

defined('ABSPATH') || exit();

class WC_Scanpay_OrderUpdater
{
    private $shopid;
    private $settings;
    private $seqdb;

    public function __construct($settings)
    {
        require_once WC_SCANPAY_DIR . '/includes/SeqDB.php';
        $this->settings = $settings;
        $this->shopid = (int) explode(':', $settings['apikey'])[0];
        $this->seqdb = new WC_Scanpay_SeqDB($this->shopid);
    }

    public function handlePing($ping)
    {
        ignore_user_abort(true);
        $db = $this->seqdb->get_seq();
        if ($ping['shopid'] !== $db['shopid']) {
            throw new Exception('Ping shopid does not match shopid in Woo');
        }

        if ($ping['seq'] === $db['seq']) {
            $this->seqdb->update_mtime();
        } elseif ($ping['seq'] < $db['seq']) {
            throw new Exception(sprintf(
                'Ping seq (%u) is lower than the local seq (%u)',
                $ping['seq'],
                $db['seq']
            ));
        } else {
            set_time_limit(120); // [default: 30s]
            require_once WC_SCANPAY_DIR . '/includes/ScanpayClient.php';
            $client = new WC_Scanpay_API_Client($this->settings['apikey']);

            $seq = $db['seq'];
            while (1) {
                $res = $client->seq($seq);
                if (count($res['changes']) === 0) {
                    break; // done
                }

                foreach ($res['changes'] as $change) {
                    switch ($change['type']) {
                        case 'transaction':
                        case 'charge':
                            $this->updateTransaction($change);
                            break;
                        case 'subscriber':
                            // $this->updateSubscriber($change);
                            break;
                        default:
                            throw new Exception('Unknown change type: ' . $change['type']);
                    }
                }
                $seq = $res['seq'];
                $this->seqdb->set_seq($seq);
            }
        }
    }

    private function updateTransaction($d)
    {
        // Skip changes with 'error' (ERR)
        if (isset($d['error'])) {
            return scanpay_log('error', "Transaction[id=$d[id]] error: $d[error]");
        }

        // Skip changes without 'orderid'
        if (!isset($d['orderid'])) {
            return scanpay_log('error', "Transaction does not have an orderID");
        }

        // Throw if {id,rev,acts,totals} is missing in change (ERR)
        if (!isset($d['id']) || !is_int($d['id'])) {
            throw new Exception('Missing "id" in change');
        }
        if (!isset($d['rev']) || !is_int($d['rev'])) {
            throw new Exception('Missing "rev" in change');
        }
        if (!isset($d['acts']) || !is_array($d['acts'])) {
            throw new Exception('Missing "acts" in change');
        }
        if (
            !isset($d['totals']) || !is_array($d['totals']) ||
            !isset($d['totals']['authorized'])
        ) {
            throw new Exception('Missing "totals.authorized" in change');
        }

        $orderid = $d['orderid'];
        $order = wc_get_order($orderid);
        if (!$order) {
            scanpay_log('warning', "Order #$orderid not found in WooCommerce");
            return; // Skip this change
        }

        $orderShopId = (int) $order->get_meta(WC_SCANPAY_URI_SHOPID);
        $oldrev = (int) $order->get_meta(WC_SCANPAY_URI_REV);
        if ($orderShopId !== $this->shopid) {
            return scanpay_log(
                'warning',
                "Order #$orderid with shopID: $orderShopId " .
                "does not match current shopID ($this->shopid)"
            );
        }

        // TODO: Needed??
        $order->set_payment_method('scanpay');
        // $order->set_payment_method_title('Scanpay');

        // Skip the order, if it's not a new revision
        if ($d['rev'] <= $oldrev) {
            return;
        }

        // Revive order if it was cancelled
        if ($order->get_status() === 'cancelled') {
            $order->update_status('processing');
        }

        $nacts = (int) $order->get_meta(WC_SCANPAY_URI_NACTS);

        for ($i = $nacts; $i < count($d['acts']); $i++) {
            $act = $d['acts'][$i];
            switch ($act['act']) {
                case 'capture':
                    if (isset($act['total']) && is_string($act['total'])) {
                        $order->add_order_note(sprintf(
                            'Captured %s',
                            $act['total']
                        ));
                    }
                    break;
                case 'refund':
                    //wc_create_refund($actArgs);
                    if (isset($act['total']) && is_string($act['total'])) {
                        $order->add_order_note(sprintf('Refunded %s.', $act['total']));
                    }
                    break;
                case 'void':
                    $order->add_meta_data(WC_SCANPAY_URI_VOIDED, 1, true);
                    if (isset($act['total']) && is_string($act['total'])) {
                        $order->add_order_note(sprintf('Voided %s.', $act['total']));
                    }
                    break;
            }
        }

        $order->add_meta_data(WC_SCANPAY_URI_NACTS, count($d['acts']), true);

        if (isset($d['totals']['captured'])) {
            $captured = explode(' ', $d['totals']['captured'])[0];
            $order->add_meta_data(WC_SCANPAY_URI_CAPTURED, $captured, true);
        }

        if (isset($d['totals']['refunded'])) {
            $refunded = explode(' ', $d['totals']['refunded'])[0];
            $order->add_meta_data(WC_SCANPAY_URI_REFUNDED, $refunded, true);
        }

        if ($order->needs_payment()) {
            $order->payment_complete($d['id']);
        }

        if (empty($order->get_meta(WC_SCANPAY_URI_TRNID))) {
            $order->add_meta_data(WC_SCANPAY_URI_TRNID, $d['id']);
            $order->add_meta_data(WC_SCANPAY_URI_AUTHORIZED, explode(' ', $d['totals']['authorized'])[0]);
        }

        // Autocomplete virtual orders
        if ($this->settings['autocomplete_virtual'] && $order->get_status() === 'processing') {
            $this->autocompleteVirtual($order);
        }

        $order->add_meta_data(WC_SCANPAY_URI_REV, $d['rev'], true);
        $order->save();
    }

    private function autocompleteVirtual($order)
    {
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if ($product && !$product->is_virtual()) {
                return false;
            }
        }
        $order->update_status('completed', 'Virtual order set to completed by Scanpay.');
    }
}
