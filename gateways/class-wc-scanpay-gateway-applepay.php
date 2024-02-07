<?php

defined( 'ABSPATH' ) || exit();

class WC_Scanpay_Gateway_ApplePay extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'scanpay_applepay';
		$this->method_title       = 'Apple Pay (Scanpay)';
		$this->method_description = 'Apple Pay through Scanpay.';
		$this->form_fields        = [
			'enabled'     => [
				'title'   => 'Enable',
				'type'    => 'checkbox',
				'label'   => 'Enable Apple Pay in the checkout.',
				'default' => 'no',
			],
			'title'       => [
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'A title for the payment method on the checkout page.',
				'desc_tip'    => true,
				'default'     => 'Apple Pay',
			],
			'description' => [
				'title'       => 'Description',
				'type'        => 'text',
				'description' => 'A description of the payment method. This is displayed on the checkout page.',
				'desc_tip'    => true,
				'default'     => 'Betal med Apple Pay',
			],
		];
		$this->init_settings(); // Load the settings into $this->settings
		$this->title       = $this->settings['title'];
		$this->description = $this->settings['description'];
		$this->supports    = [ 'products' ];

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function get_icon(): string {
		return '<span class="wcsp-methods"><img width="45" height="20" class="wcsp-mobilepay" src="' .
			WC_SCANPAY_URL . '/public/images/cards/applepay.svg" alt="Apple Pay" title="Apple Pay"></span>';
	}

	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/includes/payment-link.php';
		$arr             = wc_scanpay_process_payment( $order_id );
		$arr['redirect'] = $arr['redirect'] . '?go=applepay';
		return $arr;
	}

	public function admin_options(): void {
		require WC_SCANPAY_DIR . '/includes/admin-options.php';
	}
}
