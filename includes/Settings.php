<?php
if (!defined('ABSPATH')) {
    exit;
}


function buildSettings($block)
{
    ob_start();
    include(WC_SCANPAY_FOR_WOOCOMMERCE_DIR . '/includes/PingUrl.phtml');
    $pingUrlContent = ob_get_contents();
    ob_end_clean();

    $form_fields = [
        'enabled' => [
            'title'   => __( 'Enable/Disable', 'woocommerce-scanpay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable Card Payments', 'woocommerce-scanpay' ),
            'description' => __( 'This controls whether Scanpay debit/credit card payment is shown in checkout.<br>Set up an acquirer in the Scanpay dashboard for this to work.' ),
            'default' => 'yes',
        ],
        'title' => [
            'title'       => __( 'Title', 'woocommerce-scanpay' ),
            'type'        => 'text',
            'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-scanpay' ),
            'default'     => 'Credit Card / Debit Card',
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
        'pingurl' => [
            'title'             => __( 'Ping URL', 'woocommerce-scanpay' ),
            'description'       => $pingUrlContent . __( 'This URL is used to tell Woocommerce about transaction changes.', 'woocommerce-scanpay' ),
            'default'           => 'sdsd',
            'custom_attributes' => [
                'disabled' => '',
            ],
            'css' => 'display: none;',
        ],
        'capture_on_complete' => [
            'title'   => __( 'Capture on Complete', 'woocommerce-scanpay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable capture of orders upon completion', 'woocommerce-scanpay' ),
            'default' => 'no',
        ],
        'autocapture' => [
            'title'   => __( 'Auto-capture', 'woocommerce-scanpay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable auto-capture of all orders', 'woocommerce-scanpay' ),
            'default' => 'no',
            'description' => __( 'Automatically capture all orders upon authorization regardless of product types.', 'woocommerce-scanpay' ),
        ],
        'autocomplete_virtual' => [
            'title'   => __( 'Auto-complete virtual orders', 'woocommerce-scanpay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable automatic completion of virtual orders after payment', 'woocommerce-scanpay' ),
            'default' => 'no',
            'description' => __( 'Automatically capture and set order status to "Completed" for paid orders that only contain virtual products.', 'woocommerce-scanpay' ),
        ],
        'debug' => [
            'title'   => __( 'Debug', 'woocommerce-scanpay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable error logging', 'woocommerce-scanpay' ) . "(<code>" . WC_SCANPAY_FOR_WOOCOMMERCE_LOGFILE ."</code>)",
            'default' => 'yes',
            'custom_attributes' => [
                'disabled' => '',
            ],
        ],
        '_subscriptions' => [
            'type' => 'title',
            'title' => __( 'Subscriptions', 'woocommerce-scanpay' ),
        ],
        'subscriptions_enabled' => [
            'title'   => __( 'Enable/Disable Subscriptions', 'woocommerce-scanpay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable Subscription Payments Support (BETA)', 'woocommerce-scanpay' ),
            'description' => __( 'This option adds support for subscriptions provided by Woocommerce Subscriptions.' ),
            'default' => 'no',
        ],
        'autocomplete_renewalorders' => [
            'title'   => __( 'Auto-complete  renewal orders', 'woocommerce-scanpay' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable automatic completion of subscription renewal orders', 'woocommerce-scanpay' ),
            'default' => 'no',
        ],
    ];
    return $form_fields;
}
