<?php

/**
 * The subscription terms checkbox on the classic checkout. A template, not a module: the
 * require in wcs_scanpay_checkout_terms() is the render call.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

// Not on the order-pay endpoint. WooCommerce's pay template includes checkout/terms.php
// too, so this hook also fires while paying for an existing order -- where
// woocommerce_after_checkout_validation never runs and the cart is unrelated to what is
// being paid for. Rendering there would show a checkbox nothing enforces.
if (
	! is_checkout_pay_page()
	&& class_exists( 'WC_Subscriptions_Cart', false )
	&& WC_Subscriptions_Cart::cart_contains_subscription()
) {
	// The shared render/validate predicate: '' unless a positive wcs_terms id points at a
	// still-published page.
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
		/*
		 * The marker that says this checkbox was rendered, copied from WooCommerce's own
		 * terms field. Everything here runs from
		 * woocommerce_checkout_after_terms_and_conditions, which checkout/terms.php fires
		 * inside its woocommerce_checkout_show_terms block -- so a store filtering that to
		 * false, or a theme override without the hook, renders nothing while
		 * woocommerce_after_checkout_validation still fires. The marker is how
		 * wcs_scanpay_validate_terms() tells that apart from an unticked box.
		 */
		echo '<input type="hidden" name="wcssp-terms-field" value="1" />';
	}
}
