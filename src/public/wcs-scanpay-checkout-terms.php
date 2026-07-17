<?php

defined( 'ABSPATH' ) || exit();

if ( class_exists( 'WC_Subscriptions_Cart', false ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
	$settings = get_option( WC_SCANPAY_URI_SETTINGS );
	// 'publish' gate: the stored id goes stale if the page is trashed or deleted.
	// get_page_link() dereferences the post unguarded (warning on a deleted page),
	// and a trashed page would link the customer to a 404. See the same guard in
	// gateways/blocks/class-wc-scanpay-blocks-support.php.
	if (
		$settings && isset( $settings['wcs_terms'] ) && '0' !== $settings['wcs_terms']
		&& 'publish' === get_post_status( (int) $settings['wcs_terms'] )
	) {
		$url = esc_url( get_page_link( (int) $settings['wcs_terms'] ) );
		$txt = sprintf(
			/* translators: %s is a link to the subscription terms page. */
			__( 'I accept the %s.', 'scanpay-for-woocommerce' ),
			'<a href="' . $url . '">' . esc_html__( 'subscription terms', 'scanpay-for-woocommerce' ) . '</a>'
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
