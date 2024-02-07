<?php

defined( 'ABSPATH' ) || exit();

class WC_Scanpay_Gateway_Mobilepay extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'scanpay_mobilepay';
		$this->method_title       = 'MobilePay (Scanpay)';
		$this->method_description = 'MobilePay Online through Scanpay.';
		$this->form_fields        = [
			'enabled'     => [
				'title'   => 'Enable',
				'type'    => 'checkbox',
				'label'   => 'Enable MobilePay in the checkout.',
				'default' => 'no',
			],
			'title'       => [
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'A title for the payment method on the checkout page.',
				'desc_tip'    => true,
				'default'     => 'MobilePay',
			],
			'description' => [
				'title'       => 'Description',
				'type'        => 'text',
				'description' => 'A description of the payment method. This is displayed on the checkout page.',
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
		$arr             = wc_scanpay_process_payment( $order_id );
		$arr['redirect'] = $arr['redirect'] . '?go=mobilepay';
		return $arr;
	}

	public function admin_options(): void {
		require WC_SCANPAY_DIR . '/includes/admin-options.php';
	}
}
