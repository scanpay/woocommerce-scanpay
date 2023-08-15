<?php

defined('ABSPATH') || exit();

function wc_scanpay_payment_link($orderid)
{
    require WC_SCANPAY_DIR . '/includes/math.php';

    $settings = get_option(WC_SCANPAY_URI_SETTINGS);
    $shopid = (int) explode(':', $settings['apikey'])[0];
    if (!$shopid) {
        scanpay_log('alert', 'Missing or invalid Scanpay API key');
        throw new \Exception('Error: The Scanpay API key is invalid. Please contact the shop.');
    }

    $order = wc_get_order($orderid);
    $currency_code = $order->get_currency();

    $data = [
        'orderid'     => strval($orderid),
        'language'    => $settings['language'],
        'successurl'  => $order->get_checkout_order_received_url(),
        'billing'     => array_filter([
            'name'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email'   => $order->get_billing_email(),
            'phone'   => preg_replace('/\s+/', '', $order->get_billing_phone()),
            'address' => array_filter([$order->get_billing_address_1(), $order->get_billing_address_2()]),
            'city'    => $order->get_billing_city(),
            'zip'     => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
            'state'   => $order->get_billing_state(),
            'company' => $order->get_billing_company()
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

    $types = array('line_item', 'fee', 'shipping', 'coupon');
    $itemWithNegativePrice;
    $sum = 0;

    foreach ($order->get_items($types) as $id => $item) {
        $lineTotal = $order->get_line_total($item, true, true); // incl_taxes and rounded
        $data['items'][] = [
            'name' => $item->get_name(),
            'sku' => $item->is_type('line_item') ? strval($item->get_product_id()) : null,
            'quantity' => intval($item->get_quantity()),
            'total' => $lineTotal . ' ' . $currency_code
        ];
        if ($lineTotal < 0) {
            $itemWithNegativePrice = true;
        }
        $sum = wc_scanpay_addmoney($sum, $lineTotal);
    }

    if ($itemWithNegativePrice) {
        unset($data['items']);
        $order->add_order_note(
            'The order has an item with a negative price. The item list ' .
            'will not be available in the scanpay dashboard.'
        );
    }

    // Check if sum of items matches the order total
    if (wc_scanpay_cmpmoney($sum, $order->get_total())) {
        unset($data['items']);
        $errmsg = sprintf(
            'The sum of all items (%s) does not match the order total (%s).' .
            'The item list will not be available in the scanpay dashboard.',
            $sum,
            $order->get_total()
        );
        $order->add_order_note($errmsg);
        scanpay_log('warning', "Order #$orderid: $errmsg");
    }

    // Order came without items... or we ignored them
    if (!isset($data['items'])) {
        $data['items'][] = [
            'name' => 'Total',
            'total' => $order->get_total() . ' ' . $currency_code,
        ];
    }

    // Let 3rd party plugins manipulate $data
    $data = apply_filters('woocommerce_scanpay_newurl_data', $data);

    // Use the scanpay client lib to create a payment link
    require WC_SCANPAY_DIR . '/includes/ScanpayClient.php';
    $client = new WC_Scanpay_API_Client($settings['apikey'], [
        'headers' => [
            'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'],
        ],
    ]);

    try {
        $paymentLink = $client->newURL($data);
    } catch (\Exception $e) {
        scanpay_log('error', 'scanpay client exception: ' . $e->getMessage());
        throw new \Exception(
            'Error: We could not create a link to the payment window. ' .
            'Please wait a moment and try again.'
        );
    }
    $order->update_status('wc-pending');
    $order->add_meta_data(WC_SCANPAY_URI_SHOPID, $shopid);
    $order->add_meta_data(WC_SCANPAY_URI_PAYID, basename(parse_url($paymentLink, PHP_URL_PATH)));
    $order->save();
    return $paymentLink;
}
