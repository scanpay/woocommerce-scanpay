<?php

defined( 'ABSPATH' ) || exit();

class WC_Scanpay_Gateway_ApplePay extends WC_Payment_Gateway {
	public function __construct() {
		$this->id          = 'scanpay_applepay';
		$this->title       = 'Apple Pay';
		$this->description = 'Betal med Apple Pay';
		$this->supports    = [ 'products' ];
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable', 'scanpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Apple Pay in the checkout.', 'scanpay-for-woocommerce' ),
				'default' => 'no',
			],
		];

		$this->method_title       = 'Apple Pay (Scanpay)';
		$this->method_description = __( 'Apple Pay through Scanpay.', 'scanpay-for-woocommerce' );
		$this->init_settings(); // Load the settings into $this->settings
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function get_icon(): string {
		return '<span class="wcsp-methods"><img width="45" height="20" class="wcsp-mobilepay" src="' .
			WC_SCANPAY_URL . '/public/images/cards/applepay.svg" alt="Apple Pay" title="Apple Pay"></span>';
	}

	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/includes/payment-link.php';
		return [
			'result'   => 'success',
			'redirect' => wc_scanpay_payment_link( $order_id ) . '?go=applepay',
		];
	}

	public function admin_options(): void {
		require WC_SCANPAY_DIR . '/includes/admin-options.php';
	}
}
