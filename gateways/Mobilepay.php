<?php

defined('ABSPATH') || exit();

class WC_Scanpay_Gateway_Mobilepay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'scanpay_mobilepay';
        $this->has_fields = false;

        // Load the settings into $this->settings
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];
        $this->supports = ['products'];
        $this->method_title = 'MobilePay (Scanpay)';
        $this->method_description = 'MobilePay Online through Scanpay.';

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    /* parent::get_icon() */
    public function get_icon()
    {
        if ($this->settings['card_icon'] === 'yes') {
            $dirurl = WC_HTTPS::force_https_url(plugins_url('/public/images/', __DIR__));
            return '<span class="scanpay-methods"><img width="88" height="22" src="' . $dirurl .
                'mobilepay.svg" class="scanpay-mobilepay" alt="MobilePay" title="MobilePay"></span>';
        }
        return '';
    }

    /* parent::process_payment() */
    public function process_payment($order_id)
    {
        require WC_SCANPAY_DIR . '/includes/payment-link.php';
        return [
            'result' => 'success',
            'redirect' => wc_scanpay_payment_link($order_id) . '?go=mobilepay',
        ];
    }

    /* parent::init_form_fields() */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enable',
                'type' => 'checkbox',
                'label' => 'Enable MobilePay in the checkout',
                'default' => 'no'
            ],
            'title' => [
                'title' => 'Checkout title',
                'type' => 'text',
                'default' => 'MobilePay'
            ],
            'description' => [
                'title' => 'Checkout description',
                'type' => 'textarea',
                'default' => 'Pay with MobilePay'
            ],
            'card_icon' => [
                'title' => 'Checkout icon',
                'type' => 'checkbox',
                'label' => 'Show MobilePay logo on the checkout page.',
                'default' => 'yes'
            ]
        ];
    }
}

