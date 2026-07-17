<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

return [
	'enabled'     => [
		'title'   => __( 'Enable', 'scanpay-for-woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable MobilePay at checkout.', 'scanpay-for-woocommerce' ),
		'default' => 'no',
	],

	'title'       => [
		'title'       => __( 'Title', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		/* translators: Payment method title shown at checkout. */
		'description' => __( 'The payment method title shown at checkout.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'MobilePay',
	],

	'description' => [
		'title'       => __( 'Description', 'scanpay-for-woocommerce' ),
		'type'        => 'text',
		/* translators: Payment method description shown at checkout. */
		'description' => __( 'The payment method description shown at checkout.', 'scanpay-for-woocommerce' ),
		'desc_tip'    => true,
		'default'     => 'Pay with MobilePay.',
	],
];
