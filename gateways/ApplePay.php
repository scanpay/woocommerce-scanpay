<?php

defined('ABSPATH') || exit();

class WC_Scanpay_Gateway_ApplePay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'scanpay_applepay';
        $this->method_title = 'Apple Pay (Scanpay)';
        $this->method_description = __('Apple Pay through Scanpay.', 'scanpay-for-woocommerce');
        $this->init_settings(); // Load the settings into $this->settings
        $this->title = 'Apple Pay';
        $this->description = 'Betal med Apple Pay';
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'scanpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Apple Pay in the checkout.', 'scanpay-for-woocommerce'),
                'default' => 'no'
            ]
        ];
        $this->supports = ['products'];
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    // WC_Payment_Gateway:: get_icon()
    public function get_icon(): string
    {
        return '<span class="scanpay-methods"><img width="45" height="20" class="scanpay-mobilepay" src="' .
            WC_SCANPAY_URL . '/public/images/cards/applepay.svg" alt="Apple Pay" title="Apple Pay"></span>';
    }

    // WC_Payment_Gateway:: process_payment()
    public function process_payment($order_id): array
    {
        require WC_SCANPAY_DIR . '/includes/payment-link.php';
        return [
            'result' => 'success',
            'redirect' => wc_scanpay_payment_link($order_id) . '?go=applepay',
        ];
    }

    // WC_Payment_Gateway:: admin_options()
    public function admin_options(): void
    {
        require WC_SCANPAY_DIR . '/includes/admin_options.php';
    }
}
