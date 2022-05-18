<?php

if (!defined('ABSPATH')) {
    exit;
}

function buildSettings($block)
{
    ob_start();
    include(WC_SCANPAY_DIR . '/includes/PingUrl.phtml');
    $pingUrlContent = ob_get_contents();
    ob_end_clean();

    $form_fields = [
        'pingurl' => [
            'type' => 'title',
            'description' => $pingUrlContent,
        ],
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
            'css' => 'width: 100%;',
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
            'default' => [ 'renewalorders' ],
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
    return $form_fields;
}
