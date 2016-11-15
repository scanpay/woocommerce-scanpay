<?php
if (!defined('ABSPATH')) {
    exit;
}


function buildSettings($block)
{
    global $woocommerce_for_scanpay_logfile;
    global $woocommerce_for_scanpay_dir;

    ob_start();
    include($woocommerce_for_scanpay_dir . '/includes/PingUrl.phtml');
    $pingUrlContent = ob_get_contents();
    ob_end_clean();

    $form_fields = [
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
        'pingurl' => [
        	'title'             => __( 'Ping URL', 'woocommerce-scanpay' ),
        	'description'       => $pingUrlContent . __( 'This is the URL Scanpay can use to notify Magento of changes in transaction status.', 'woocommerce-scanpay' ),
        	'default'           => 'sdsd',
            'custom_attributes' => [
                'disabled' => '',
            ],
            'css' => 'display: none;',
        ],
        'debug' => [
        	'title'   => __( 'Debug', 'woocommerce-scanpay' ),
        	'type'    => 'checkbox',
        	'label'   => __( 'Enable error logging', 'woocommerce-scanpay' ) . "(<code>$woocommerce_for_scanpay_logfile</code>)",
        	'default' => 'yes',
            'custom_attributes' => [
                'disabled' => '',
            ],
        ]
    ];
    return $form_fields;
}
