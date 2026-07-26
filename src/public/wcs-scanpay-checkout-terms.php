<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// Not on the order-pay endpoint. Both templates/checkout/payment.php and
// templates/checkout/form-pay.php include checkout/terms.php, so this hook also fires while
// paying for an existing order -- where woocommerce_after_checkout_validation never runs, and
// where the cart has nothing to do with what is being paid for. Rendering there would show a
// checkbox nothing enforces.
if (
	! is_checkout_pay_page()
	&& class_exists( 'WC_Subscriptions_Cart', false )
	&& WC_Subscriptions_Cart::cart_contains_subscription()
) {
	// wcs_scanpay_terms_url() is the shared render/validate predicate: '' unless a positive
	// wcs_terms id points at a still-published page. See its docblock in woocommerce-scanpay.php.
	$url = wcs_scanpay_terms_url();
	if ( '' !== $url ) {
		$txt = sprintf(
			/* translators: %s is a link to the subscription terms page. */
			__( 'I accept the %s.', 'scanpay-for-woocommerce' ),
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'subscription terms', 'scanpay-for-woocommerce' ) . '</a>'
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
