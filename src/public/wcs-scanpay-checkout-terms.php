<?php

defined( 'ABSPATH' ) || exit();

if ( class_exists( 'WC_Subscriptions_Cart', false ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	if ( $settings && isset( $settings['wcs_terms'] ) && '0' !== $settings['wcs_terms'] ) {
		$url = get_page_link( $settings['wcs_terms'] );
		$txt = 'Jeg accepterer <a href="' . $url . ' ">abonnementsbetingelserne</a>.';

		woocommerce_form_field(
			'wcssp-terms-field',
			[
				'type'  => 'hidden',
				'value' => '1',
			]
		);
		woocommerce_form_field(
			'wcssp-terms',
			[
				'type'        => 'checkbox',
				'class'       => [ 'form-row wcssp-terms' ],
				'label_class' => [ 'woocommerce-form__label woocommerce-form__label-for-checkbox checkbox' ],
				'input_class' => [ 'woocommerce-form__input woocommerce-form__input-checkbox input-checkbox' ],
				'required'    => true,
				'label'       => $txt,
			]
		);
	}
}
