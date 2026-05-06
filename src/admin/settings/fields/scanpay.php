<?php

defined( 'ABSPATH' ) || exit();

return [
	'enabled'              => [
		'title'   => __( 'Enable', 'scanpay-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable Scanpay at checkout.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],

	'apikey'               => [
		'title'             => __( 'API key', 'scanpay-for-woocommerce' ),
		'type'              => 'password',
		'description'       => __( 'Enter the Scanpay API key from your Scanpay dashboard.', 'scanpay-for-woocommerce' ),
		'desc_tip'          => true,
		'custom_attributes' => [
			'autocomplete' => 'off',
		],
		'default'           => '',
	],

	'title'                => [
		'title'       => __( 'Title', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		/* translators: Payment method title displayed at checkout. */
		'description' => __( 'The payment method title displayed at checkout.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Betal med kort',
	],

	'description'          => [
		'title'       => __( 'Description', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		/* translators: Payment method description displayed at checkout. */
		'description' => __( 'The payment method description displayed at checkout.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Betal med betalingskort via Scanpay.',
	],

	'card_icons'           => [
		'title'       => __( 'Card icons', 'scanpay-for-woocommerce' ),
		'type'        => 'multiselect',
		'description' => __( 'Choose which card icons to display at checkout.', 'scanpay-for-woocommerce' ),
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
		'label'   => __( 'Use the default Scanpay checkout stylesheet (CSS).', 'scanpay-for-woocommerce' ),
		'default' => 'yes',
	],

	'wc_autocapture'       => [
		'title'   => __( 'Auto-capture', 'scanpay-for-woocommerce' ),
		'type'    => 'select',
		'default' => 'completed',
		'options' => [
			'off'       => __( 'Disable', 'scanpay-for-woocommerce' ),
			'completed' => __( 'On order completion (recommended)', 'scanpay-for-woocommerce' ),
			'on'        => __( 'Immediately', 'scanpay-for-woocommerce' ),
		],
	],

	'wc_complete_virtual'  => [
		'title'   => __( 'Auto-complete', 'scanpay-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Automatically mark virtual orders as completed.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],

	'wcs_complete_initial' => [
		'type'     => 'checkbox',
		'label'    => __( 'Auto-complete new subscription orders (Subscriptions only).', 'scanpay-for-woocommerce' ),
		'desc_tip' => true,
		'default'  => 'no',
	],

	'wcs_complete_renewal' => [
		'type'     => 'checkbox',
		'label'    => __( 'Auto-complete renewal orders (Subscriptions only).', 'scanpay-for-woocommerce' ),
		'desc_tip' => true,
		'default'  => 'no',
	],

	'wcs_terms'            => [
		'title'       => __( 'Subscription terms', 'scanpay-for-woocommerce' ),
		'type'        => 'select',
		'description' => __( 'Add a checkbox for subscription terms and conditions at checkout.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => '0',
		'options'     => [
			'0' => __( 'Hide checkbox', 'scanpay-for-woocommerce' ),
			// Additional pages/options can be injected dynamically.
		],
	],
];
