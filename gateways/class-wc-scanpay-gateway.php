<?php

defined( 'ABSPATH' ) || exit();

class WC_Scanpay_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = 'scanpay';
		$this->method_title       = 'Scanpay'; // In settings
		$this->method_description = 'Accept payment cards through Scanpay.';
		$this->init_form_fields(); // Set the admin form_fields and default values (WC_settings_api)
		$this->init_settings();    // Load the settings from DB or use defaults in from_fields
		$this->description          = $this->settings['description'];
		$this->view_transaction_url = WC_SCANPAY_DASHBOARD . '%s';
		$this->supports             = [
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		];
		add_action( 'woocommerce_update_options_payment_gateways_scanpay', [ $this, 'process_admin_options' ] );
	}

	public function get_icon(): string {
		$array = $this->settings['card_icons'];
		if ( ! empty( $array ) ) {
			$icons = '<span class="wcsp-methods wcsp-cards">';
			foreach ( $array as $key => $card ) {
				$icons .= '<img src="' . WC_SCANPAY_URL . '/public/images/cards/' . $card .
					'.svg" class="wcsp-' . $card . '" alt="' . $card . '" title="' . $card . '">';
			}
			$icons .= '</span>';
		}
		return $icons;
	}

	public function get_title(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( defined( 'WP_ADMIN' ) && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
			return 'Scanpay';
		}
		return $this->settings['title'];
	}

	public function get_transaction_url( $wc_order ) {
		return WC_SCANPAY_DASHBOARD . $wc_order->get_meta( WC_SCANPAY_URI_SHOPID, true ) . '/' .
			$wc_order->get_transaction_id();
	}

	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/includes/payment-link.php';
		return [
			'result'   => 'success',
			'redirect' => wc_scanpay_payment_link( $order_id ),
		];
	}

	public function admin_options(): void {
		require WC_SCANPAY_DIR . '/includes/admin-options.php';
	}

	public function process_admin_options(): void {
		parent::process_admin_options();
		require WC_SCANPAY_DIR . '/includes/install.php';
	}

	public function can_refund_order( $order ) {
		return false;
	}

	// Inherited from WC_Settings_API class
	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'              => [
				'title' => __( 'Enable', 'scanpay-for-woocommerce' ),
				'type'  => 'checkbox',
				'label' => __( 'Enable Scanpay in the checkout.', 'scanpay-for-woocommerce' ),
			],
			'apikey'               => [
				'title'             => __( 'API key', 'scanpay-for-woocommerce' ),
				'type'              => 'text',
				'custom_attributes' => [
					'autocomplete' => 'off',
				],
			],
			'title'                => [
				'title'       => __( 'Title', 'scanpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'A title for the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce' ),
				'default'     => __( 'Pay by card.', 'scanpay-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'description'          => [
				'title'       => __( 'Description', 'scanpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'A description of the payment method. This is displayed on the checkout page.', 'scanpay-for-woocommerce' ),
				'default'     => __( 'Pay with card through Scanpay.', 'scanpay-for-woocommerce' ),
				'desc_tip'    => true,
			],
			'card_icons'           => [
				'title'       => __( 'Card icons', 'scanpay-for-woocommerce' ),
				'type'        => 'multiselect',
				'description' => __( 'Choose which card icons to display on the checkout page.', 'scanpay-for-woocommerce' ),
				'options'     => [
					'dankort'    => 'Dankort',
					'visa'       => 'Visa',
					'mastercard' => 'Mastercard',
					'maestro'    => 'Maestro',
					'amex'       => 'American Express',
					'diners'     => 'Diners',
					'discover'   => 'Discover',
					'unionpay'   => 'UnionPay',
					'jcb'        => 'JCB',
				],
				'default'     => [ 'visa', 'mastercard', 'maestro' ],
				'class'       => 'wc-enhanced-select',
				'desc_tip'    => true,
			],
			'capture_on_complete'  => [
				'title'       => __( 'Auto-Capture', 'scanpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Capture when order status is changed to "completed".', 'scanpay-for-woocommerce' ),
				'description' => __( 'Automatically capture the payment when the order status changes to "completed".', 'scanpay-for-woocommerce' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			],
			'wcs_complete_initial' => [
				'title' => __( 'Auto-Complete', 'scanpay-for-woocommerce' ),
				'type'  => 'checkbox',
				'label' => __( 'Auto-complete new subscribers', 'scanpay-for-woocommerce' ) .
					' <i>(' . __( 'Subscriptions only', 'scanpay-for-woocommerce' ) . ')</i>.',
			],
			'wcs_complete_renewal' => [
				'title' => '&#10240;',
				'type'  => 'checkbox',
				'label' => __( 'Auto-complete renewal orders', 'scanpay-for-woocommerce' ) .
					' <i>(' . __( 'Subscriptions only', 'scanpay-for-woocommerce' ) . ')</i>.',
			],
		];
	}
}
