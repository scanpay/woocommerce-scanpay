<?php

defined('ABSPATH') || exit();

class WC_Scanpay_Gateway_ApplePay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'scanpay_applepay';
        $this->method_title = 'Apple Pay (Scanpay)';
        $this->method_description = __('Apple Pay through Scanpay.', 'scanpay-for-woocommerce');

        // Load the settings into $this->settings
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    /* parent::get_icon() */
    public function get_icon(): string
    {
        if ($this->settings['card_icon'] === 'yes') {
            return '<span class="scanpay-methods"><img width="88" height="22" class="scanpay-mobilepay" src="' .
                WC_SCANPAY_URL . '/public/images/cards/applepay.svg" alt="Apple Pay" title="Apple Pay"></span>';
        }
        return '';
    }

    /* parent::process_payment() */
    public function process_payment($order_id): array
    {
        require_once WC_SCANPAY_DIR . '/includes/payment-link.php';
        return [
            'result' => 'success',
            'redirect' => wc_scanpay_payment_link($order_id) . '?go=applepay',
        ];
    }

    /* parent::init_form_fields() */
    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'scanpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Apple Pay in the checkout.', 'scanpay-for-woocommerce'),
                'default' => 'no'
            ],
            'title' => [
                'title' => __('Title', 'scanpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('A title for the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce'),
                'default' => 'Apple Pay',
                'desc_tip'    => true,
            ],
            'description' => [
                'title' => __('Description', 'scanpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('A description of the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce'),
                'default' => __('Pay with Apple Pay.', 'scanpay-for-woocommerce'),
                'desc_tip'    => true,
            ]
        ];
    }
}

