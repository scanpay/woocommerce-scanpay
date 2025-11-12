<?php

defined( 'ABSPATH' ) || exit();

return [
	'enabled'     => [
		'title'   => __( 'Enable', 'scanpay-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable MobilePay in the checkout.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],
	'title'       => [
		'title'       => __( 'Title', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'A title for the payment method on the checkout page.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'MobilePay',
	],
	'description' => [
		'title'       => __( 'Description', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		'description' => __( 'A description of the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Betal med MobilePay',
	],
];
