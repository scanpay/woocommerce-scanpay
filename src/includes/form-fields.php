<?php

defined( 'ABSPATH' ) || exit();

return [
	'enabled'              => [
		'title'   => __( 'Enable', 'scanpay-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable Scanpay in the checkout.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],
	'apikey'               => [
		'title'             => __( 'API key', 'scanpay-for-woocommerce' ),
		'type'              => 'text',
		'custom_attributes' => [ 'autocomplete' => 'off' ],
		'default'           => '',
	],
	'title'                => [
		'title'       => __( 'Title', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'A title for the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Betal med kort',
	],
	'description'          => [
		'title'       => __( 'Description', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'A description of the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Betal med betalingskort via Scanpay.',
	],
	'card_icons'           => [
		'title'       => __( 'Card icons', 'scanpay-for-woocommerce' ),
		'type'        => 'multiselect',
		'description' => __( 'Choose which card icons to display on the checkout page.', 'scanpay-for-woocommerce' ),
		'options'     => [
			'dankort'            => 'Dankort',
			'visa'               => 'Visa',
			'mastercard'         => 'Mastercard',
			'maestro'            => 'Maestro',
			'amex'               => 'American Express',
			'diners'             => 'Diners',
			'discover'           => 'Discover',
			'unionpay'           => 'UnionPay',
			'jcb'                => 'JCB',
			'forbrugsforeningen' => 'Forbrugsforeningen',
		],
		'class'       => 'wc-enhanced-select',
		'desc_tip'    => true,
		'default'     => [ 'visa', 'mastercard' ],
	],
	'stylesheet'           => [
		'title'   => 'Stylesheet',
		'type'    => 'checkbox',
		'label'   => __( 'Use default checkout stylesheet (CSS).', 'scanpay-for-woocommerce' ),
		'default' => 'yes',
	],
	'wc_autocapture'       => [
		'title'   => 'Auto-Capture',
		'type'    => 'select',
		'default' => 'completed',
		'options' => [
			'off'       => __( 'Disable', 'scanpay-for-woocommerce' ),
			'completed' => __( 'On order completion (recommended)', 'scanpay-for-woocommerce' ),
			'on'        => __( 'Immediately', 'scanpay-for-woocommerce' ),
		],
	],
	'wc_complete_virtual'  => [
		'title'   => 'Auto-Complete',
		'type'    => 'checkbox',
		'label'   => __( 'Auto-complete virtual orders.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],
	'wcs_complete_initial' => [
		'title'   => '&#10240;',
		'type'    => 'checkbox',
		'label'   => __( 'Auto-complete new subscribers <i>(Subscriptions only)</i>.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],
	'wcs_complete_renewal' => [
		'title'   => '&#10240;',
		'type'    => 'checkbox',
		'label'   => __( 'Auto-complete renewal orders <i>(Subscriptions only)</i>.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],
	'wcs_terms'            => [
		'title'       => __( 'Subscription Terms', 'scanpay-for-woocommerce' ),
		'type'        => 'select',
		'description' => __( 'Add a checkbox for Subscription Terms and Conditions.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => '0',
		'options'     => [ '0' => __( 'Hide checkbox', 'scanpay-for-woocommerce' ) ],
	],
];
