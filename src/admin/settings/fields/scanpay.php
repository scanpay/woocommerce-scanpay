<?php

defined( 'ABSPATH' ) || exit();

// Build the "Subscription terms" page picker: "Hide checkbox" plus every published page.
// This file is required lazily by get_form_fields() (settings screen / unconfigured store),
// so the get_pages() cost is not incurred on normal front-end or checkout requests.
$wcs_terms_options = [ '0' => __( 'Hide checkbox', 'scanpay-for-woocommerce' ) ];
foreach ( (array) get_pages() as $wcs_terms_page ) {
	$wcs_terms_options[ (string) $wcs_terms_page->ID ] = $wcs_terms_page->post_title;
}

return [
	'enabled'              => [
		'title'   => __( 'Enable', 'scanpay-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable Scanpay at checkout.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],

	// Custom type: rendered by WC_Gateway_Scanpay_Base::generate_apikey_html() and
	// gated by ::validate_apikey_field(). Never rendered back to the browser once
	// stored, and only replaceable via the reset button.
	'apikey'               => [
		'title'       => __( 'API key', 'scanpay-for-woocommerce' ),
		'type'        => 'apikey',
		'description' => __( 'Enter the Scanpay API key from your Scanpay dashboard.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => '',
	],

	'title'                => [
		'title'       => __( 'Title', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		/* translators: Payment method title displayed at checkout. */
		'description' => __( 'The payment method title displayed at checkout.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Pay by card',
	],

	'description'          => [
		'title'       => __( 'Description', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		/* translators: Payment method description displayed at checkout. */
		'description' => __( 'The payment method description displayed at checkout.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Pay with a payment card via Scanpay.',
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
		'options'     => $wcs_terms_options,
	],
];
