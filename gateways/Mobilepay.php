<?php

defined('ABSPATH') || exit();

class WC_Scanpay_Gateway_Mobilepay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'scanpay_mobilepay';
        $this->method_title = 'MobilePay (Scanpay)';
        $this->method_description = __('MobilePay Online through Scanpay.', 'scanpay-for-woocommerce');
        $this->init_settings(); // Load the settings into $this->settings
        $this->title = 'MobilePay';
        $this->description = 'Betal med MobilePay';

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'scanpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable MobilePay in the checkout.', 'scanpay-for-woocommerce'),
                'default' => 'no'
            ]
        ];
        $this->supports = ['products'];
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    // WC_Payment_Gateway:: get_icon()
    public function get_icon(): string
    {
        return '<span class="scanpay-methods"><img width="92" class="scanpay-mobilepay" src="' .
            WC_SCANPAY_URL . '/public/images/cards/mobilepay.svg" alt="MobilePay" title="MobilePay"></span>';
    }

    // WC_Payment_Gateway:: process_payment()
    public function process_payment($order_id): array
    {
        require WC_SCANPAY_DIR . '/includes/payment-link.php';
        return [
            'result' => 'success',
            'redirect' => wc_scanpay_payment_link($order_id) . '?go=mobilepay',
        ];
    }

    // WC_Payment_Gateway:: admin_options()
    public function admin_options(): void
    {
        require WC_SCANPAY_DIR . '/includes/admin_options.php';
    }
}
