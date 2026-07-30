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
	 * WooCommerce builds the gateway collection for Blocks' sort order immediately before it
	 * asks integrations for this payload, so resolving the singleton below reuses those
	 * instances. Read their normalized public properties, not get_title()/get_description():
	 * those apply filters whose callbacks may return HTML, and checkout.ts renders the values
	 * as text.
	 */
	public function get_payment_method_data(): array {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$data     = [
			'url'     => WC_SCANPAY_URL . '/public/assets/images/',
			'methods' => [],
		];
		// Subscription terms checkbox, rendered by checkout.ts as a forced checkout block and
		// enforced by wcs_scanpay_blocks_validate_terms().
		//
		// Outside $data['methods'] and outside the per-gateway availability gate on purpose:
		// the consent belongs to the subscription in the cart, so it covers every gateway the
		// customer can pick and stays active while our own are unavailable. It reaches the
		// payload because AbstractPaymentMethodType::is_active() defaults to true.
		//
		// WC_Subscriptions first, and not just the cart class: the validator and the Store API
		// namespace both live in public/subscriptions.php, which the router loads behind that
		// guard alone. subscriptions-core ships WC_Subscriptions_Cart without WC_Subscriptions,
		// so gating on the cart class by itself renders a checkbox nothing enforces -- and the
		// posted extensions.scanpay.terms is then dropped by an unregistered namespace rather
		// than refused, so it fails silently in the direction that lets the order through.
		if (
			class_exists( 'WC_Subscriptions', false )
			&& class_exists( 'WC_Subscriptions_Cart', false )
			&& WC_Subscriptions_Cart::cart_contains_subscription()
		) {
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
		foreach ( [ 'scanpay', 'scanpay_mobilepay', 'scanpay_applepay' ] as $id ) {
			$gateway = $gateways[ $id ] ?? null;
			if ( ! $gateway instanceof WC_Gateway_Scanpay_Base || ! $gateway->is_available() ) {
				continue;
			}
			// WC's validate_multiselect_field() stores '' rather than [] when nothing is
			// selected, and (array) '' is [ '' ], which would render one broken card icon.
			// array_values() keeps every branch a JSON array rather than an object.
			$icons = match ( $id ) {
				'scanpay'           => array_values( array_filter( (array) ( $gateway->settings['card_icons'] ?? [ 'visa', 'mastercard' ] ) ) ),
				'scanpay_mobilepay' => [ 'mobilepay' ],
				'scanpay_applepay'  => [ 'applepay' ],
			};
			$data['methods'][ $id ] = [
				'title'       => (string) $gateway->title,
				'description' => (string) $gateway->description,
				'icons'       => $icons,
				'supports'    => array_values( (array) $gateway->supports ),
			];
		}
		return $data;
	}
}
