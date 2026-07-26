<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

final class WC_Gateway_Scanpay_Mobilepay extends WC_Gateway_Scanpay_Base {
	public function __construct() {
		$this->id                 = 'scanpay_mobilepay';
		$this->method_title       = 'MobilePay';
		$this->method_description = __( 'MobilePay Online through Scanpay.', 'scanpay-for-woocommerce' );
		$this->icon               = WC_SCANPAY_URL . '/admin/assets/images/icons/mobilepay.svg';
		parent::__construct();
	}

	/** The checkout icon. $this->icon stays live for the admin Payments list. */
	public function get_icon(): string {
		return '<span class="wcsp-methods"><img width="92" height="23" class="wcsp-mobilepay" src="' .
			WC_SCANPAY_URL . '/public/assets/images/mobilepay.svg" alt="MobilePay" title="MobilePay"></span>';
	}

	/**
	 * Process the payment. ?go= preselects the method in the payment window.
	 *
	 * @return array<string, mixed> WooCommerce result/redirect pair.
	 */
	public function process_payment( $order_id ): array {
		require_once WC_SCANPAY_DIR . '/public/generate-payment-link.php';
		$arr             = wc_scanpay_process_payment( (int) $order_id, get_option( WC_SCANPAY_URI_SETTINGS, [] ) );
		$arr['redirect'] = add_query_arg( 'go', 'mobilepay', $arr['redirect'] );
		return $arr;
	}
}
