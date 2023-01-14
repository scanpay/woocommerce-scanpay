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
        'autocapture' => in_array('all', (array) $settings['autocapture']),
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

    $virtualOrder = in_array('virtual', (array) $settings['autocapture']);
    $types = array('line_item', 'fee', 'shipping', 'coupon');
    $sum = 0;

    foreach ($order->get_items($types) as $id => $item) {
        $lineTotal = $order->get_line_total($item, true, true); // incl_taxes and rounded
        $data['items'][] = [
            'name' => $item->get_name(),
            'sku' => $item->is_type('line_item') ? strval($item->get_product_id()) : null,
            'quantity' => intval($item->get_quantity()),
            'total' => $lineTotal . ' ' . $currency_code
        ];
        if ($virtualOrder && $item->is_type('line_item')) {
            $product = $item->get_product();
            if (!empty($product) && !$product->is_virtual()) {
                $virtualOrder = false;
            }
        }
        if ($lineTotal < 0) {
            scanpay_log('notice', 'Negative line total in #' . $orderid . '.');
            continue;
        }
        $sum = wc_scanpay_addmoney($sum, $lineTotal);
    }

    if ($virtualOrder) {
        $data['autocapture'] = true;
    }

    // Check if sum of items matches the order total
    if (wc_scanpay_cmpmoney($sum, $order->get_total())) {
        unset($data['items']);
        $data['items'][] = [
            'name' => 'Total',
            'total' => $order->get_total() . ' ' . $currency_code,
        ];
        $errmsg = sprintf(
            'Warning: The sum of all items (%s) does not match the order total (%s).' .
            'The item list will not be available in the scanpay dashboard.',
            $sum,
            $order->get_total()
        );
        $order->add_order_note($errmsg);
        scanpay_log('warning', $errmsg);
    }

    // Let 3rd party plugins manipulate $data
    $data = apply_filters('woocommerce_scanpay_newurl_data', $data);

    // Use the scanpay client lib to create a payment link
    require_once WC_SCANPAY_DIR . '/includes/ScanpayClient.php';
    $client = new Scanpay\Scanpay($settings['apikey'], [
        'headers' => [
            'X-Shop-Plugin' => 'woocommerce/' . WC_VERSION . '/' . WC_SCANPAY_VERSION,
            'X-Cardholder-IP' => $_SERVER['REMOTE_ADDR'],
        ],
    ]);

    try {
        $paymentLink = $client->newURL(array_filter($data));
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
