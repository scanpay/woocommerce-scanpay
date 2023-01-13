<?php

defined('ABSPATH') || exit();

class WC_Scanpay_Gateway_Scanpay extends WC_Payment_Gateway
{
    public function __construct()
    {
        $this->id = 'scanpay';
        $this->has_fields = false;

        // Load the settings into $this->settings
        $this->init_form_fields();
        $this->init_settings();
        $this->shopid = (int) explode(':', $this->settings['apikey'])[0];
        $this->view_transaction_url = "https://dashboard.scanpay.dk/$this->shopid/%s";

        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];
        $this->supports = [
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_admin',
            'subscription_payment_method_change_customer',
            'multiple_subscriptions',
            'pre-orders'
        ];
        $this->method_title = 'Scanpay';
        $this->method_description = 'Secure and innovative payment gateway.';

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    /* parent::get_icon() */
    public function get_icon()
    {
        $array = $this->settings['card_icons'];
        if (!empty($array)) {
            $dirurl = WC_HTTPS::force_https_url(plugins_url('/public/images/', __DIR__));
            $icons = '<span class="scanpay-methods scanpay-cards">';
            foreach ($array as $key => $card) {
                $icons .= '<img width="32" height="20" src="' . $dirurl . $card .
                    '.svg" class="scanpay-' . $card . '" alt="' . $card . '" title="' . $card . '">';
            }
            $icons .= '</span>';
        }
        return $icons;
    }

    /* parent::process_payment() */
    public function process_payment($order_id)
    {
        require WC_SCANPAY_DIR . '/includes/PaymentLink.php';
        return [
            'result' => 'success',
            'redirect' => wc_scanpay_payment_link($order_id),
        ];
    }

    /*
    *   parent::admin_options()
    *   Override to add our settings header
    */
    public function admin_options()
    {
        echo '<h2>Scanpay';
        wc_back_link(__('Return to payments', 'woocommerce'), admin_url('admin.php?page=wc-settings&tab=checkout'));
        echo '</h2>';
        echo wp_kses_post(wpautop($this->get_method_description()));
        require_once WC_SCANPAY_DIR . '/includes/settings-header.php';
        echo '<table class="form-table">'
            . $this->generate_settings_html($this->get_form_fields(), false) .
        '</table>';
    }

    /* parent::init_form_fields() */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => 'Enable',
                'type' => 'checkbox',
                'label' => 'Enable Scanpay for WooCommerce.',
                'default' => 'no',
            ],
            'apikey' => [
                'title' => 'API key',
                'type' => 'text',
                'default' => '',
                'placeholder' => 'Required',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                ],
            ],
            'title' => [
                'title' => 'Title',
                'type' => 'text',
                'default' => 'Credit card',
            ],
            'description' => [
                'title' => 'Description',
                'type' => 'text',
                'default' => 'Pay with credit card or debit card through Scanpay.',
            ],
            'card_icons' => [
                'title' => 'Credit card icons',
                'type' => 'multiselect',
                'description' => 'Show card icons on the checkout page.',
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
            ],
            'language' => [
                'title' => 'Language',
                'type' => 'select',
                'options' => [
                    '' => 'Automatic (browser language)',
                    'en' => 'English',
                    'da' => 'Danish',
                    'se' => 'Swedish',
                    'no' => 'Norwegian',
                ],
                'description' => 'Set the payment window language.',
                'default' => 'auto',
            ],
            'capture_on_complete' => [
                'title' => 'Capture on Complete',
                'type' => 'checkbox',
                'label' => 'Enable Capture on Complete',
                'description' => 'Automatically capture orders when they are marked as <i>\'Completed\'</i>.',
                'default' => 'yes',
            ],
            'autocomplete_virtual' => [
                'title' => 'Auto-complete',
                'type' => 'checkbox',
                'label' => 'Auto-complete virtual orders',
                'description' => 'Automatically mark all virtual orders as <i>\'Completed\'</i>.',
                'default' => 'no',
            ],
            'autocomplete_renewalorders' => [
                'title' => '',
                'type' => 'checkbox',
                'label' => 'Auto-complete renewal orders (Subscriptions)',
                'description' => 'Automatically mark renewal orders as <i>\'Completed\'</i> (subscriptions only).',
                'default' => 'no',
            ],
            'autocapture' => [
                'title' => 'Auto-capture',
                'type' => 'multiselect',
                'label' => 'Enable Auto-capture',
                'description' => 'Immediately capture specified order types.',
                'options' => [
                    'virtual' => 'Virtual orders',
                    'all' => 'ALL orders',
                    'renewalorders' => 'Renewal orders (Subscription charges)',
                ],
                'default' => ['renewalorders'],
                'class' => 'wc-enhanced-select',
            ],
            'subscriptions_enabled' => [
                'title' => 'Enable Subscriptions',
                'type' => 'checkbox',
                'label' => 'Enable support for <a target="_blank" href="https://woocommerce.com/' .
                    'products/woocommerce-subscriptions/">WooCommerce Subscriptions</a>.',
                'default' => 'no',
            ],
        ];
    }
}
