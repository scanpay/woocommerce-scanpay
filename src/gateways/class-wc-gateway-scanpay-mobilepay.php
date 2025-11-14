<?php

defined( 'ABSPATH' ) || exit();

final class WC_Gateway_Scanpay_Mobilepay extends WC_Gateway_Scanpay_Base {
	public function __construct() {
		$this->id                 = 'scanpay_mobilepay';
		$this->method_title       = 'MobilePay';
		$this->method_description = __( 'MobilePay Online through Scanpay.', 'scanpay-for-woocommerce' );
		$this->icon               = WC_SCANPAY_URL . '/admin/assets/images/icons/mobilepay.svg';
		parent::__construct();
	}

	/**
	 * Get the icon HTML for display on checkout page
	 *
	 * @return string
	 */
	public function get_icon(): string {
		return '<span class="wcsp-methods"><img width="92" height="23" class="wcsp-mobilepay" src="' .
			WC_SCANPAY_URL . '/public/assets/images/mobilepay.svg" alt="MobilePay" title="MobilePay"></span>';
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id The order ID.
	 * @return array<string, mixed>
	 */
	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/gateways/includes/payment-link.php';
		$arr             = wc_scanpay_process_payment( $order_id, get_option( WC_SCANPAY_URI_SETTINGS ) );
		$arr['redirect'] = $arr['redirect'] . '?go=mobilepay';
		return $arr;
	}
}
