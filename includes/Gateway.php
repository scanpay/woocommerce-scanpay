<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
use Scanpay\Money as Money;
use Scanpay\Client as Client;

class ScanpayGateway extends WC_Payment_Gateway
{
    public function __construct()
    {
        /* Set WC_Payment_Gateway parameters */
        $this->id = 'scanpay';
        //$this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'Scanpay';
        $this->method_description = 'Scanpay is a Nordic based payment gateway offering card and mobile based payment.';
        /* Call the required WC_Payment_Gateway functions */
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->language = $this->get_option('language');
        $this->client = new Client([
            'host' => 'api.scanpay.dk',
            'apikey' => $this->get_option('apikey'),
        ]);
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function process_payment($orderid)
    {
    	global $woocommerce;
    	$order = new WC_Order($orderid);
        $data = [
            'orderid'    => strval($orderid),
            'language'   => $this->language,
            'successurl' => $this->get_return_url($order),
            'billing'    => array_filter([
                'name'    => $order->billing_first_name . ' ' . $order->billing_last_name,
                'email'   => $order->billing_email,
                'phone'   => preg_replace('/\s+/', '', $order->billing_phone),
                'address' => array_filter([$order->billing_address_1, $order->billing_address_2]),
                'city'    => $order->billing_city,
                'zip'     => $order->billing_postcode,
                'country' => $order->billing_country,
                'state'   => $order->billing_state,
                'company' => $order->billing_company,
                'vatin'   => '',
                'gln'     => '',
            ]),
            'shipping'   => array_filter([
                'name'    => $order->shipping_first_name . ' ' . $order->shipping_last_name,
                'address' => array_filter([$order->shipping_address_1, $order->shipping_address_2]),
                'city'    => $order->shipping_city,
                'zip'     => $order->shipping_postcode,
                'country' => $order->shipping_country,
                'state'   => $order->shipping_state,
                'company' => $order->shipping_company,
            ]),
        ];
        /* Add the requested items to the request data */
        foreach ($order->get_items('line_item') as $wooitem) {
            $itemprice = $order->get_item_total($wooitem, true);
            if ($itemprice < 0) {
                error_log('Cannot handle negative price for item');
                throw new \Exception(__('Internal serve error'));
            }
            $data['items'][] = [
                'name' => $wooitem['name'],
                'quantity' => intval($wooitem['qty']),
                'price' => (new Money($itemprice, $order->get_order_currency()))->print(),
                'sku' => $wooitem['product_id'],
            ];
        }
        /* Add shipping costs */
        $shippingcost = $order->get_total_shipping() + $order->get_shipping_tax();
        if ($shippingcost > 0) {
            $method = $order->get_shipping_method();
            $data['items'][] = [
                'name' => isset($method) ? $method : __('Shipping'),
                'quantity' => 1,
                'price' => (new Money($shippingcost, $order->get_order_currency()))->print(),
            ];
        }

        try {
            $paymenturl = $this->client->GetPaymentURL(array_filter($data), ['cardholderIP' => $_SERVER['REMOTE_ADDR']]);
        } catch (\Exception $e) {
            error_log('scanpay client exception: ' . $e->getMessage());
            throw new \Exception(__('Internal serve error'));
        }

        /* Update order */
    	$order->update_status('on-hold', __( 'Awaiting payment', 'woocommerce' ));

    	/* Reduce stock levels */
    	$order->reduce_order_stock();

    	/* Remove cart */
    	$woocommerce->cart->empty_cart();
    	return [
    		'result' => 'success',
    		'redirect' => $paymenturl,
        ];
    }

	public function init_form_fields()
    {

		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'woocommerce-scanpay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Scanpay', 'woocommerce-scanpay' ),
				'default' => 'yes',
            ],
			'title' => [
				'title'       => __( 'Title', 'woocommerce-scanpay' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-scanpay' ),
				'default'     => 'Scanpay',
            ],
			'description' => [
				'title'       => __( 'Description', 'woocommerce-scanpay' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-scanpay' ),
				'default'     => __( "Pay via Scanpay using credit card or mobile payment.", 'woocommerce-scanpay' )
            ],
			'language' => [
				'title'    => __( 'Language', 'woocommerce-scanpay' ),
				'type'     => 'select',
				'options'  => [
                    '' => __( 'Automatic', 'woocommerce-scanpay' ),
					'en'   => 'English',
					'da'   => 'Danish',
                ],
				'description' => __( 'Set the payment window language. \'Automatic\' allows Scanpay to choose a language based on the browser of the customer.', 'woocommerce-scanpay' ),
				'default'     => 'auto',
            ],
			'apikey' => [
				'title'             => __( 'API key', 'woocommerce-scanpay' ),
				'type'              => 'text',
				'description'       => __( 'Copy your API key from the <a href="https://dashboard.scanpay.dk/settings/api">dashboard API settings</a>.', 'woocommerce-scanpay' ),
				'default'           => '',
				'placeholder'       => __( 'Required', 'woocommerce-scanpay' ),
                'custom_attributes' => [
                    'autocomplete' => 'off',
                ],
            ],
			'debug' => [
				'title'   => __( 'Debug', 'woocommerce-scanpay' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable error logging (<code>woocommerce/logs/scanpay.txt</code>)', 'woocommerce-scanpay' ),
				'default' => 'yes',
            ]
        ];
	}

}
