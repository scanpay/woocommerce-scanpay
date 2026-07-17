<?php
declare(strict_types=1);

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Scanpay_Blocks_Support extends AbstractPaymentMethodType {
	protected $name          = 'scanpay';
	private bool $registered = false;

	/**
	 * Called whenever the payment method is registered by WooCommerce Blocks.
	 * This runs on most admin and frontend pages, so leave it empty to avoid overhead.
	 */
	public function initialize(): void {}

	/*
	 *  get_payment_method_script_handles() is called multiple times in the checkout
	 *  to enqueue scripts needed for the payment method.
	 */
	public function get_payment_method_script_handles(): array {
		if ( ! $this->registered ) {
			wp_register_script(
				'wcsp-blocks',
				WC_SCANPAY_URL . '/public/assets/js/checkout.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
				WC_SCANPAY_VERSION,
				true
			);
			$this->registered = true;
		}
		return [ 'wcsp-blocks' ];
	}

	/*
	 *  get_payment_method_data() is only called in the checkout
	 *  The data returned here will be used to render the payment method in the frontend.
	 */
	public function get_payment_method_data(): array {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		$data     = [
			'url'     => WC_SCANPAY_URL . '/public/assets/images/',
			'methods' => [],
		];
		if ( is_array( $settings ) && ( 'yes' === ( $settings['enabled'] ?? 'no' ) ) ) {
			$data['methods']['scanpay'] = [
				'title'       => (string) ( $settings['title'] ?? 'Scanpay' ),
				'description' => (string) ( $settings['description'] ?? '' ),
				'icons'       => (array) ( $settings['card_icons'] ?? [] ),
				'supports'    => [
					'products',
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'subscription_amount_changes',
					'subscription_date_changes',
					'subscription_payment_method_change_customer',
					'subscription_payment_method_change_admin',
					'multiple_subscriptions',
				],
			];
			// Subscription terms checkbox. woocommerce_after_checkout_validation (classic) does
			// not fire for the Store API checkout, so the checkbox is rendered inside this
			// method's content (checkout.ts) and enforced in wcs_scanpay_blocks_validate_terms().
			// The page picker only offers published pages, but the stored id goes
			// stale if that page is later trashed or deleted. get_page_link()
			// dereferences the post unguarded, so a deleted page warns straight
			// into this Store API JSON response, and a trashed one would link the
			// customer to a 404. Anything but a published page: terms disabled.
			if (
				class_exists( 'WC_Subscriptions_Cart', false )
				&& WC_Subscriptions_Cart::cart_contains_subscription()
				&& '0' !== ( $settings['wcs_terms'] ?? '0' )
				&& 'publish' === get_post_status( (int) $settings['wcs_terms'] )
			) {
				$data['methods']['scanpay']['terms'] = [
					'url'    => esc_url_raw( (string) get_page_link( (int) $settings['wcs_terms'] ) ),
					'before' => __( 'I accept the ', 'scanpay-for-woocommerce' ),
					'link'   => __( 'subscription terms', 'scanpay-for-woocommerce' ),
					'after'  => '.',
					'error'  => __( 'You must accept the subscription terms to complete your purchase.', 'scanpay-for-woocommerce' ),
				];
			}
		}
		$mobilepay = get_option( 'woocommerce_scanpay_mobilepay_settings' );
		if ( is_array( $mobilepay ) && ( 'yes' === ( $mobilepay['enabled'] ?? 'no' ) ) ) {
			$data['methods']['scanpay_mobilepay'] = [
				'title'       => (string) ( $mobilepay['title'] ?? 'MobilePay' ),
				'description' => (string) ( $mobilepay['description'] ?? '' ),
				'icons'       => [ 'mobilepay' ],
				'supports'    => [
					'products',
				],
			];
		}
		$applepay = get_option( 'woocommerce_scanpay_applepay_settings' );
		if ( is_array( $applepay ) && ( 'yes' === ( $applepay['enabled'] ?? 'no' ) ) ) {
			$data['methods']['scanpay_applepay'] = [
				'title'       => (string) ( $applepay['title'] ?? 'Apple Pay' ),
				'description' => (string) ( $applepay['description'] ?? '' ),
				'icons'       => [ 'applepay' ],
				'supports'    => [
					'products',
				],
			];
		}
		return $data;
	}
}
