<?php

defined( 'ABSPATH' ) || exit();

return [
	'enabled'     => [
		'title'   => __( 'Enable', 'scanpay-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable Apple Pay in the checkout.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],
	'title'       => [
		'title'       => __( 'Title', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'A title for the payment method on the checkout page.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Apple Pay',
	],
	'description' => [
		'title'       => __( 'Description', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'A description of the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Betal med Apple Pay',
	],
];
