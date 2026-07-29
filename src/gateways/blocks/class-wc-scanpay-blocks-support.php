<?php

/**
 * The WooCommerce Blocks registration for all three gateways: one script handle and one
 * payload, from which checkout.ts renders the payment methods and the subscription terms
 * checkbox. The class exists because the Blocks integration contract demands one.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Scanpay_Blocks_Support extends AbstractPaymentMethodType {
	protected $name          = 'scanpay';
	private bool $registered = false;

	/**
	 * Deliberately empty. WooCommerce Blocks registers the payment method on most admin
	 * and frontend pages, so anything done here is paid for site-wide.
	 */
	public function initialize(): void {}

	/** Registered once: WooCommerce calls this repeatedly while building the checkout. */
	public function get_payment_method_script_handles(): array {
		if ( ! $this->registered ) {
			wp_register_script(
				'wcsp-blocks',
				WC_SCANPAY_URL . '/public/assets/js/checkout.js',
				// wc-blocks-checkout provides registerCheckoutBlock() and wp-data the
				// validation/checkout stores, both used by the subscription terms block.
				//
				// No 'wp-i18n' and no wp_set_script_translations(): checkout.ts calls __()
				// nowhere, because every string it renders is translated on this side and
				// travels in the payload. A __() added to that bundle needs both, or it
				// silently renders English.
				[ 'wc-blocks-registry', 'wc-blocks-checkout', 'wc-settings', 'wp-data', 'wp-element' ],
				WC_SCANPAY_VERSION,
				true
			);
			$this->registered = true;
		}
		return [ 'wcsp-blocks' ];
	}

	/**
	 * The payload checkout.ts renders from. Built on three render paths, not one: Blocks'
	 * Payments\Api hooks its script data to both woocommerce_blocks_checkout_enqueue_data and
	 * woocommerce_blocks_cart_enqueue_data, and the Cart and Mini Cart blocks both fire the
	 * latter -- so a block theme with a header mini-cart builds this on every page. Do not gate
	 * it on is_checkout(); the Cart block needs the same bundle.
	 *
	 * Settings are read straight from the option, not through the classic gateways'
	 * get_title()/get_description()/get_icon(): those apply filters whose callbacks may return
	 * HTML, and checkout.ts hands the payload to React as text, so markup would render
	 * literally. If WooCommerce ever exposes a Blocks filter contract, add it separately; do
	 * not invent one.
	 */
	public function get_payment_method_data(): array {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		$data     = [
			'url'     => WC_SCANPAY_URL . '/public/assets/images/',
			'methods' => [],
		];
		// Subscription terms checkbox, rendered by checkout.ts as a forced checkout block and
		// enforced by wcs_scanpay_blocks_validate_terms().
		//
		// Outside $data['methods'] and outside the 'enabled' gate on purpose: the consent
		// belongs to the subscription in the cart, so it covers every gateway the customer
		// can pick and stays active while our own are disabled. It reaches the payload
		// because AbstractPaymentMethodType::is_active() defaults to true.
		if ( class_exists( 'WC_Subscriptions_Cart', false ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			$terms_url = wcs_scanpay_terms_url();
			if ( '' !== $terms_url ) {
				$data['terms'] = [
					'url'   => esc_url_raw( $terms_url ),
					// Fed to createInterpolateElement() in checkout.ts, which swaps the <a> tag for
					// a real element. Verbatim from the classic renderer, which rewrites the same
					// tag in PHP, so translators localize one sentence for both checkouts.
					/* translators: keep the <a> tags around the link text; they become the link to the subscription terms page. */
					'label' => __( 'I accept the <a>subscription terms</a>.', 'scanpay-for-woocommerce' ),
					'error' => __( 'You must accept the subscription terms to complete your purchase.', 'scanpay-for-woocommerce' ),
				];
			}
		}
		if ( is_array( $settings ) && ( 'yes' === ( $settings['enabled'] ?? 'no' ) ) ) {
			$data['methods']['scanpay'] = [
				// WC_Gateway_Scanpay_Card::default_title() is the source; the two must stay in
				// step, or a store renders a different label in each checkout. Copied rather
				// than called, because instantiating the gateway would drag its lazy form
				// fields into a payload built on every page of the store.
				'title'       => (string) ( $settings['title'] ?? 'Pay by card' ),
				'description' => (string) ( $settings['description'] ?? '' ),
				// WC's validate_multiselect_field() stores '' rather than [] when nothing is
				// selected, and (array) '' is [ '' ], which renders one broken <img>.
				// array_values() keeps this a JSON array rather than an object.
				'icons'       => array_values( array_filter( (array) ( $settings['card_icons'] ?? [] ) ) ),
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
