<?php
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
				[ 'wc-blocks-registry', 'wc-blocks-checkout', 'wc-settings', 'wp-data', 'wp-element' ],
				WC_SCANPAY_VERSION,
				true
			);
			$this->registered = true;
		}
		return [ 'wcsp-blocks' ];
	}

	/**
	 * The payload checkout.ts renders from. Checkout only, so per-request cost is fine.
	 *
	 * Settings are read straight from the option, deliberately, not through the classic
	 * gateways' get_title()/get_description()/get_icon(). Those apply
	 * woocommerce_gateway_title, _description and _icon, whose callbacks may return HTML,
	 * while checkout.ts hands the payload to React as text -- markup would render
	 * literally. The JSON encoder plus React's text rendering are also what keep raw
	 * settings from becoming executable markup here. If WooCommerce ever exposes a Blocks
	 * filter contract, add it separately; do not invent one.
	 */
	public function get_payment_method_data(): array {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		$data     = [
			'url'     => WC_SCANPAY_URL . '/public/assets/images/',
			'methods' => [],
		];
		// Subscription terms checkbox. woocommerce_after_checkout_validation (classic) does not
		// fire for the Store API checkout, so checkout.ts renders the checkbox as a forced
		// checkout block and wcs_scanpay_blocks_validate_terms() enforces it.
		//
		// Kept outside $data['methods'] and outside the 'enabled' gate on purpose: the consent
		// belongs to the subscription in the cart, so it must cover every gateway the customer
		// can pick and stay active while our own gateways are disabled. This runs regardless of
		// gateway status because AbstractPaymentMethodType::is_active() defaults to true and the
		// registry collects script data for all registered types.
		if ( class_exists( 'WC_Subscriptions_Cart', false ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			$terms_url = wcs_scanpay_terms_url();
			if ( '' !== $terms_url ) {
				$data['terms'] = [
					'url'   => esc_url_raw( $terms_url ),
					// Split on %s in checkout.ts to build the link. Shared verbatim with the
					// classic renderer so translators localize one sentence, punctuation included.
					/* translators: %s is a link to the subscription terms page. */
					'label' => __( 'I accept the %s.', 'scanpay-for-woocommerce' ),
					'link'  => __( 'subscription terms', 'scanpay-for-woocommerce' ),
					'error' => __( 'You must accept the subscription terms to complete your purchase.', 'scanpay-for-woocommerce' ),
				];
			}
		}
		if ( is_array( $settings ) && ( 'yes' === ( $settings['enabled'] ?? 'no' ) ) ) {
			$data['methods']['scanpay'] = [
				// WC_Gateway_Scanpay_Card::default_title() is the source of this string; the
				// two must stay in step, or the same store renders a different label in the
				// two checkouts. Copied rather than called: instantiating the gateway would
				// drag its settings and lazy form fields into a payload built at checkout.
				'title'       => (string) ( $settings['title'] ?? 'Pay by card' ),
				'description' => (string) ( $settings['description'] ?? '' ),
				// WC's validate_multiselect_field() stores '' (not []) when nothing is
				// selected, and (array) '' is [ '' ] -- a non-empty array holding an
				// empty string, which renders one broken <img> on the Blocks checkout.
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
