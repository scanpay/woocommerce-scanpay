<?php

defined('ABSPATH') || exit();

class WC_Scanpay_Gateway_Scanpay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'scanpay';
        $this->method_title = 'Scanpay';
        $this->method_description = __('Accept payment cards through Scanpay.', 'scanpay-for-woocommerce');
        $this->init_form_fields();
        $this->init_settings(); // Load the settings into $this->settings
        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];
        $this->supports = [
            'products',
            'pre-orders'
        ];
        add_action('woocommerce_update_options_payment_gateways_scanpay', [$this, 'process_admin_options']);
    }

    // WC_Payment_Gateway:: get_icon()
    public function get_icon(): string
    {
        $array = $this->settings['card_icons'];
        if (!empty($array)) {
            $icons = '<span class="scanpay-methods scanpay-cards">';
            foreach ($array as $key => $card) {
                $icons .= '<img style="max-height: 22px" src="' . WC_SCANPAY_URL . '/public/images/cards/' . $card .
                    '.svg" class="scanpay-' . $card . '" alt="' . $card . '" title="' . $card . '">';
            }
            $icons .= '</span>';
        }
        return $icons;
    }

    // WC_Payment_Gateway:: process_payment()
    public function process_payment($order_id): array
    {
        require WC_SCANPAY_DIR . '/includes/payment-link.php';
        return [
            'result' => 'success',
            'redirect' => wc_scanpay_payment_link($order_id),
        ];
    }

    // WC_Payment_Gateway:: admin_options()
    public function admin_options(): void
    {
        require WC_SCANPAY_DIR . '/includes/admin_options.php';
    }

    // WC_Payment_Gateway:: can_refund_order()
    public function can_refund_order($order) {
        return false;
    }

    // WC_Settings_API:: init_form_fields()
    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable', 'scanpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Scanpay in the checkout.', 'scanpay-for-woocommerce'),
                'default' => 'no',
            ],
            'apikey' => [
                'title' => __('API key', 'scanpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('You can find your API key in the Scanpay dashboard.', 'scanpay-for-woocommerce'),
                'default' => '',
                'placeholder' => 'Required',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                ],
                'desc_tip'    => true,
            ],
            'title' => [
                'title' => __('Title', 'scanpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('A title for the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce'),
                'default' => __('Pay by card.', 'scanpay-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title' => __('Description', 'scanpay-for-woocommerce'),
                'type' => 'text',
                'description' => __('A description of the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce'),
                'default' => __('Pay with card through Scanpay.', 'scanpay-for-woocommerce'),
                'desc_tip'    => true,
            ],
            'card_icons' => [
                'title' => __('Card icons', 'scanpay-for-woocommerce'),
                'type' => 'multiselect',
                'description' => __('Choose which card icons to display on the checkout page.', 'scanpay-for-woocommerce'),
                'options' => [
                    'dankort' => 'Dankort',
                    'visa' => 'Visa',
                    'mastercard' => 'Mastercard',
                    'maestro' => 'Maestro',
                    'amex' => 'American Express',
                    'diners' => 'Diners',
                    'discover' => 'Discover',
                    'unionpay' => 'UnionPay',
                    'jcb' => 'JCB',
                ],
                'default' => ['visa', 'mastercard', 'maestro'],
                'class' => 'wc-enhanced-select',
                'desc_tip'    => true,
            ],
            'subscriptions_enabled' => [
                'title' => __('Subscriptions', 'scanpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable support for WooCommerce Subscriptions.', 'scanpay-for-woocommerce'),
                'description' => __('...', 'scanpay-for-woocommerce'),
                'default' => 'no',
                'desc_tip'    => true,
            ],
            'capture_on_complete' => [
                'title' => __('Auto-Capture', 'scanpay-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Capture payment when order status is set to "completed".', 'scanpay-for-woocommerce'),
                'description' => __('Automatically capture the payment when the order status changes to "completed". Errors will not block the status change. Please read our guide.', 'scanpay-for-woocommerce'),
                'default' => 'yes',
                'desc_tip'    => true,
            ],
            'autocomplete_all' => [
                'title' => '&#10240;',
                'type' => 'checkbox',
                'label' => __('Auto-complete all new orders.', 'scanpay-for-woocommerce'),
                'description' => __('Automatically mark all new orders as "completed".', 'scanpay-for-woocommerce'),
                'default' => 'no',
                'desc_tip'    => true,
            ],
            'autocomplete_renewalorders' => [
                'title' => '&#10240;',
                'type' => 'checkbox',
                'label' => __('Auto-complete renewal orders ', 'scanpay-for-woocommerce') .
                    '<i>(' . __('Subscriptions only', 'scanpay-for-woocommerce') . ')</i>.',
                'description' => __('Automatically mark all new renewal orders as "completed".', 'scanpay-for-woocommerce'),
                'default' => 'no',
                'desc_tip'    => true,
            ],
        ];
    }
}
