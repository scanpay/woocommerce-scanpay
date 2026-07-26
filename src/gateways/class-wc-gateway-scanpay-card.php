<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

require_once WC_SCANPAY_DIR . '/library/functions.php';

final class WC_Gateway_Scanpay_Card extends WC_Gateway_Scanpay_Base {
	public function __construct() {
		$this->id                 = 'scanpay';
		$this->method_title       = 'Scanpay';
		$this->method_description = __( 'Accept payment cards through Scanpay.', 'scanpay-for-woocommerce' );
		$this->icon               = WC_SCANPAY_URL . '/admin/assets/images/icons/scanpay.svg';
		parent::__construct();

		$this->supports = [
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
		];
		if ( 'yes' === $this->get_option( 'stylesheet' ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_styles' ] );
		}
		if ( 'yes' === $this->get_option( 'wc_complete_virtual' ) ) {
			add_filter( 'woocommerce_order_item_needs_processing', 'wc_scanpay_item_needs_processing', 10, 3 );
		}
	}

	/**
	 * The card icons rendered at checkout, per the 'card_icons' setting.
	 *
	 * This override does not make $this->icon dead: WooCommerce reads the raw
	 * property for the admin Payments list.
	 */
	public function get_icon(): string {
		$cards = (array) $this->get_option( 'card_icons', [] );
		if ( ! $cards ) {
			return '';
		}
		$html = '<span class="wcsp-methods wcsp-cards">';
		foreach ( $cards as $card ) {
			$card  = (string) $card;
			$html .= '<img src="' . esc_url( WC_SCANPAY_URL . '/public/assets/images/' . $card . '.svg' ) .
				'" class="wcsp-' . esc_attr( $card ) . '" alt="' . esc_attr( $card ) . '" title="' . esc_attr( $card ) . '">';
		}
		return $html . '</span>';
	}

	/**
	 * Process the payment.
	 *
	 * @return array<string, mixed> WooCommerce result/redirect pair.
	 */
	public function process_payment( $order_id ): array {
		require_once WC_SCANPAY_DIR . '/public/generate-payment-link.php';
		return wc_scanpay_process_payment( (int) $order_id, $this->settings );
	}

	/** Process and save admin options, seeding the SQL tables on a first API key. */
	public function process_admin_options(): void {
		$old = (int) explode( ':', (string) $this->get_option( 'apikey', '' ) )[0];
		parent::process_admin_options();
		$new = (int) explode( ':', (string) $this->get_option( 'apikey', '' ) )[0];
		/*
		 * A stored key can never be replaced here (validate_apikey_field() refuses),
		 * so this only fires when a key is first set -- on a fresh install or after a
		 * reset. install.php is idempotent: it creates the tables if missing and
		 * seeds this shop's seq row at 0, which the ping handler requires before it
		 * will sync (it responds "shop not configured" without one).
		 *
		 * Nothing is dropped here. Deleting data is the reset button's job alone
		 * (admin/hooks/wp-ajax-wc-scanpay-reset.php), never a side effect of a save.
		 */
		if ( $new && $new !== $old ) {
			require WC_SCANPAY_DIR . '/install.php';
		}
	}

	/** Action: wp_enqueue_scripts (only hooked when the 'stylesheet' setting is on). */
	public function enqueue_checkout_styles(): void {
		if ( is_checkout() ) {
			wp_enqueue_style( 'wcsp-pay', WC_SCANPAY_URL . '/public/assets/css/checkout.css', [], WC_SCANPAY_VERSION );
		}
	}
}
