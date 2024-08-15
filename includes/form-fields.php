<?php

defined( 'ABSPATH' ) || exit();

return [
	'enabled'              => [
		'title'   => 'Enable',
		'type'    => 'checkbox',
		'label'   => 'Enable Scanpay in the checkout.',
		'default' => 'no',
	],
	'apikey'               => [
		'title'             => 'API key',
		'type'              => 'text',
		'custom_attributes' => [ 'autocomplete' => 'off' ],
		'default'           => '',
	],
	'title'                => [
		'title'       => 'Title',
		'type'        => 'text',
		'description' => 'A title for the payment method. This is displayed on the checkout page.',
		'desc_tip'    => true,
		'default'     => 'Betal med kort',
	],
	'description'          => [
		'title'       => 'Description',
		'type'        => 'text',
		'description' => 'A description of the payment method. This is displayed on the checkout page.',
		'desc_tip'    => true,
		'default'     => 'Betal med betalingskort via Scanpay.',
	],
	'card_icons'           => [
		'title'       => 'Card icons',
		'type'        => 'multiselect',
		'description' => 'Choose which card icons to display on the checkout page.',
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
		'label'   => 'Use default checkout stylesheet (CSS).',
		'default' => 'yes',
	],
	'wc_autocapture'       => [
		'title'   => 'Auto-Capture',
		'type'    => 'select',
		'default' => 'completed',
		'options' => [
			'off'       => 'Disable',
			'completed' => 'On order completion (recommended)',
			'on'        => 'Immediately',
		],
	],
	'wc_complete_virtual'  => [
		'title'   => 'Auto-Complete',
		'type'    => 'checkbox',
		'label'   => 'Auto-complete virtual orders.',
		'default' => 'no',
	],
	'wcs_complete_initial' => [
		'title'   => '&#10240;',
		'type'    => 'checkbox',
		'label'   => 'Auto-complete new subscribers <i>(Subscriptions only)</i>.',
		'default' => 'no',
	],
	'wcs_complete_renewal' => [
		'title'   => '&#10240;',
		'type'    => 'checkbox',
		'label'   => 'Auto-complete renewal orders <i>(Subscriptions only)</i>.',
		'default' => 'no',
	],
	'wcs_terms'            => [
		'title'       => 'Subscription Terms',
		'type'        => 'select',
		'description' => 'Add a checkbox for Subscription Terms and Conditions.',
		'desc_tip'    => true,
		'default'     => '0',
		'options'     => [ '0' => 'Hide checkbox' ],
	],
];
