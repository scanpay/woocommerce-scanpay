<?php

/**
 * The card gateway, and the only one of the three that supports subscriptions. Its
 * settings option is the primary one: it holds the shared API key and every cross-gateway
 * setting.
 */

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
		// Both read $this->settings directly, for the reason init_gateway_props() documents:
		// get_option() would force-load the lazy form fields, and this gateway's fields file
		// opens with an unbounded get_pages(). The fallbacks are the field defaults.
		//
		// The stylesheet setting is the only gate, deliberately: checkout.css is not the card
		// gateway's -- all three wrap their icons in the same <span class="wcsp-methods"> --
		// so adding 'yes' === $this->enabled would strip it from a MobilePay-only shop.
		// WC_Payment_Gateways::init() constructs every registered class with no enabled test,
		// which is what makes this constructor the right place for a shop-wide hook.
		if ( 'yes' === ( $this->settings['stylesheet'] ?? 'yes' ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_styles' ] );
		}
		if ( 'yes' === ( $this->settings['wc_complete_virtual'] ?? 'no' ) ) {
			add_filter( 'woocommerce_order_item_needs_processing', 'wc_scanpay_item_needs_processing', 10, 3 );
		}
	}

	protected function default_title(): string {
		return 'Pay by card';
	}

	protected function default_description(): string {
		return 'Pay with a payment card via Scanpay.';
	}

	/**
	 * The card icons rendered at checkout, per the 'card_icons' setting. Does not make
	 * $this->icon dead: WooCommerce reads the raw property for the admin Payments list.
	 */
	public function get_icon(): string {
		// array_filter mirrors the normalization the Blocks payload applies. Consistency, not
		// a fix: get_option()'s $empty_value already coerces a stored '' to [].
		$cards = array_values( array_filter( (array) $this->get_option( 'card_icons', [] ) ) );
		$html  = '';
		if ( $cards ) {
			$html = '<span class="wcsp-methods wcsp-cards">';
			foreach ( $cards as $card ) {
				$card  = (string) $card;
				$html .= '<img src="' . esc_url( WC_SCANPAY_URL . '/public/assets/images/' . $card . '.svg' ) .
					'" class="wcsp-' . esc_attr( $card ) . '" alt="' . esc_attr( $card ) . '" title="' . esc_attr( $card ) . '">';
			}
			$html .= '</span>';
		}
		// Filtered after our markup is built and escaped, as WC_Payment_Gateway does. Cast
		// because a filter callback is bound by no contract.
		return (string) apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
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
	public function process_admin_options(): bool {
		$old   = (int) explode( ':', (string) $this->get_option( 'apikey', '' ) )[0];
		$saved = parent::process_admin_options();
		$new   = (int) explode( ':', (string) $this->get_option( 'apikey', '' ) )[0];
		/*
		 * validate_apikey_field() refuses to replace a stored key, so this only fires when
		 * one is first set: a fresh install or a post-reset save. install.php is idempotent
		 * -- it creates the tables if missing and seeds this shop's seq row at 0, without
		 * which the ping handler answers "shop not configured" and never syncs.
		 *
		 * Nothing is dropped here; deleting data is the reset button's job alone.
		 */
		if ( $new && $new !== $old ) {
			require WC_SCANPAY_DIR . '/install.php';
		}
		return $saved;
	}

	/** Action: wp_enqueue_scripts (only hooked when the 'stylesheet' setting is on). */
	public function enqueue_checkout_styles(): void {
		if ( is_checkout() ) {
			wp_enqueue_style( 'wcsp-pay', WC_SCANPAY_URL . '/public/assets/css/checkout.css', [], WC_SCANPAY_VERSION );
		}
	}
}
