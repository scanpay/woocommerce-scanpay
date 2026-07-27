<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

final class WC_Gateway_Scanpay_ApplePay extends WC_Gateway_Scanpay_Base {
	public function __construct() {
		$this->id                 = 'scanpay_applepay';
		$this->method_title       = 'Apple Pay';
		$this->method_description = __( 'Apple Pay through Scanpay.', 'scanpay-for-woocommerce' );
		$this->icon               = WC_SCANPAY_URL . '/admin/assets/images/icons/apple-pay.svg';
		parent::__construct();

		// $this->enabled is initialized by the parent constructor from the saved setting,
		// so this hooks nothing on a store that does not offer Apple Pay.
		if ( 'yes' === $this->enabled ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_script' ] );
		}
	}

	/**
	 * Whether this request renders the classic (shortcode) checkout.
	 *
	 * Testing is_checkout() alone would be wrong: it is true on a Blocks checkout page
	 * too, and Blocks already gates Apple Pay through canMakePayment() in checkout.ts.
	 * The pay-for-order page is always classic, even when the configured checkout page
	 * holds the block. WC_Blocks_Utils is guarded for the WooCommerce 3.6 floor, and its
	 * two-argument signature is required -- has_block_in_page() takes the page.
	 */
	private function is_classic_checkout(): bool {
		if ( is_checkout_pay_page() ) {
			return true;
		}
		if ( ! is_checkout() ) {
			return false;
		}
		return ! class_exists( 'WC_Blocks_Utils' )
			|| ! WC_Blocks_Utils::has_block_in_page( wc_get_page_id( 'checkout' ), 'woocommerce/checkout' );
	}

	/**
	 * Enqueue the classic-checkout availability probe.
	 * Action: wp_enqueue_scripts (only hooked while this gateway is enabled)
	 *
	 * Depends on jquery because classic checkout's fragment refresh is a jQuery custom
	 * event, and on wp-i18n because the sole-gateway notice is translated.
	 */
	public function enqueue_checkout_script(): void {
		if ( ! $this->is_classic_checkout() ) {
			return;
		}
		wp_enqueue_script(
			'wc-scanpay-applepay',
			WC_SCANPAY_URL . '/public/assets/js/applepay.js',
			[ 'jquery', 'wp-i18n' ],
			WC_SCANPAY_VERSION,
			[ 'strategy' => 'defer' ]
		);
		wp_set_script_translations( 'wc-scanpay-applepay', 'scanpay-for-woocommerce', WC_SCANPAY_DIR . '/languages' );
	}

	protected function default_title(): string {
		return 'Apple Pay';
	}

	protected function default_description(): string {
		return 'Pay with Apple Pay.';
	}

	/** The checkout icon. $this->icon stays live for the admin Payments list. */
	public function get_icon(): string {
		// esc_url() as the card gateway does on the identical concatenation: WooCommerce
		// echoes get_icon() raw (templates/checkout/payment-method.php:26), and
		// WC_SCANPAY_URL is plugins_url()-derived, which runs a third-party filter -- so it
		// is not a compile-time constant. PHPCS misses it because the value is returned.
		$html = '<span class="wcsp-methods"><img width="45" height="20" class="wcsp-applepay" src="' .
			esc_url( WC_SCANPAY_URL . '/public/assets/images/applepay.svg' ) . '" alt="Apple Pay" title="Apple Pay"></span>';
		// Filtered after the markup is built, as WC_Payment_Gateway does; cast because a
		// filter callback can return anything and this method returns string.
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
		$arr['redirect'] = add_query_arg( 'go', 'applepay', $arr['redirect'] );
		return $arr;
	}
}
