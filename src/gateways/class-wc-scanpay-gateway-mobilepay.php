<?php

defined( 'ABSPATH' ) || exit();

class WC_Scanpay_Gateway_Mobilepay extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'scanpay_mobilepay';
		$this->method_title       = 'MobilePay (Scanpay)';
		$this->method_description = __( 'MobilePay Online through Scanpay.', 'scanpay-for-woocommerce' );
		$this->form_fields        = [
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
		$this->init_settings(); // Load the settings into $this->settings
		$this->title       = $this->settings['title'];
		$this->description = $this->settings['description'];
		$this->supports    = [ 'products' ];

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function get_icon(): string {
		return '<span class="wcsp-methods"><img width="92" class="wcsp-mobilepay" src="' .
			WC_SCANPAY_URL . '/public/images/cards/mobilepay.svg" alt="MobilePay" title="MobilePay"></span>';
	}

	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/includes/payment-link.php';
		$arr             = wc_scanpay_process_payment( $order_id, get_option( WC_SCANPAY_URI_SETTINGS ) );
		$arr['redirect'] = $arr['redirect'] . '?go=mobilepay';
		return $arr;
	}

	public function admin_options(): void {
		require WC_SCANPAY_DIR . '/includes/admin-options.php';
	}
}
