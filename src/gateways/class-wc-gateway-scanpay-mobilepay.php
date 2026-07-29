<?php

/**
 * The MobilePay Online gateway. Settings live in woocommerce_scanpay_mobilepay_settings,
 * but the API key it pays with is the card gateway's.
 */

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

	protected function default_title(): string {
		return 'MobilePay';
	}

	protected function default_description(): string {
		return 'Pay with MobilePay.';
	}

	/** The checkout icon. $this->icon stays live for the admin Payments list. */
	public function get_icon(): string {
		// esc_url() because WooCommerce's payment-method template echoes get_icon() raw, and
		// WC_SCANPAY_URL is plugins_url()-derived, so a third-party filter shapes it. PHPCS
		// misses it because the value is returned rather than echoed.
		$html = '<span class="wcsp-methods"><img width="92" height="23" class="wcsp-mobilepay" src="' .
			esc_url( WC_SCANPAY_URL . '/public/assets/images/mobilepay.svg' ) . '" alt="MobilePay" title="MobilePay"></span>';
		// Filtered after the markup is built, as WC_Payment_Gateway does; cast because a
		// filter callback can return anything.
		return (string) apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
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
