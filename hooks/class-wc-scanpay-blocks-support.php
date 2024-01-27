<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Scanpay_Blocks_Support extends AbstractPaymentMethodType {
	protected $name          = 'scanpay';
	private bool $registered = false;

	public function initialize() {
		// This fn is called EVERYWHERE. Let's not do anything here.
	}

	public function get_payment_method_script_handles() {
		// Called EVERYWHERE and 5x per page load. So we cache the registration.
		if ( ! $this->registered ) {
			wp_register_script(
				'wcsp-blocks',
				WC_SCANPAY_URL . '/public/js/blocks.js',
				[ 'wc-blocks-registry', 'wc-settings', 'wp-element' ],
				WC_SCANPAY_VERSION,
				true
			);
			$this->registered = true;
		}
		return [ 'wcsp-blocks' ];
	}

	/*
		get_payment_method_data() is only called in the checkout
		The data returned here will be used to render the payment method in the frontend.
	*/
	public function get_payment_method_data() {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS );
		return [
			'url'     => WC_SCANPAY_URL . '/public/images/cards/',
			'methods' => [
				'scanpay'           => [
					'title'       => $settings['title'],
					'description' => $settings['description'],
					'icons'       => $settings['card_icons'],
					'supports'    => [
						'products',
						'subscriptions',
					],
				],
				'scanpay_mobilepay' => [
					'title'       => 'MobilePay',
					'description' => 'Betal med MobilePay',
					'icons'       => [ 'mobilepay' ],
					'supports'    => [
						'products',
					],
				],
				'scanpay_applepay'  => [
					'title'       => 'Apple Pay',
					'description' => 'Betal med Apple Pay',
					'icons'       => [ 'applepay' ],
					'supports'    => [
						'products',
					],
				],
			],
		];
	}
}
