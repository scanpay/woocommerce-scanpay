<?php

defined( 'ABSPATH' ) || exit();

final class WC_Gateway_Scanpay_ApplePay extends WC_Gateway_Scanpay_Base {
	public function __construct() {
		$this->id                 = 'scanpay_applepay';
		$this->method_title       = 'Apple Pay';
		$this->method_description = __( 'Apple Pay through Scanpay.', 'scanpay-for-woocommerce' );
		$this->icon               = WC_SCANPAY_URL . '/admin/assets/images/icons/apple-pay.svg';
		parent::__construct();
	}

	/**
	 * Get the icon HTML for the payment method.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<span class="wcsp-methods"><img width="45" height="20" class="wcsp-mobilepay" src="' .
			WC_SCANPAY_URL . '/public/assets/images/applepay.svg" alt="Apple Pay" title="Apple Pay"></span>';
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id The order ID.
	 * @return array<string, mixed>
	 */
	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/includes/payment-link.php';
		$arr             = wc_scanpay_process_payment( $order_id, get_option( WC_SCANPAY_URI_SETTINGS ) );
		$arr['redirect'] = $arr['redirect'] . '?go=applepay';
		return $arr;
	}
}
