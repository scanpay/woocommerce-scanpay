<?php

defined( 'ABSPATH' ) || exit();

final class WC_Gateway_Scanpay_Card extends WC_Gateway_Scanpay_Base {
	public function __construct() {
		$this->id                 = 'scanpay';
		$this->method_title       = 'Scanpay';
		$this->method_description = __( 'Accept payment cards through Scanpay.', 'scanpay-for-woocommerce' );
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
			add_filter( 'woocommerce_order_item_needs_processing', [ $this, 'item_needs_processing' ], 10, 2 );
		}
	}

	/**
	 * Get the icon HTML for display on checkout page
	 *
	 * @return string
	 */
	public function get_icon(): string {
		$cards = (array) $this->get_option( 'card_icons', [] );
		if ( ! $cards ) {
			return '';
		}
		$html = '<span class="wcsp-methods wcsp-cards">';
		foreach ( $cards as $card ) {
			// Keep simple sanitation; only allow a–z, 0–9, dash, underscore
			$slug = strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $card ) );
			if ( $slug === '' ) {
				continue;
			}
			$html .= '<img src="' . WC_SCANPAY_URL . '/public/assets/images/cards/' . $slug .
				'.svg" class="wcsp-' . $slug . '" alt="' . $slug . '" title="' . $slug . '">';
		}
		return $html . '</span>';
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id The order ID.
	 * @return array<string, mixed>
	 */
	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/gateways/includes/payment-link.php';
		return wc_scanpay_process_payment( $order_id, $this->settings );
	}

	/**
	 * Process and save admin options.
	 * If the API key has changed, re-install the SQL tables.
	 *
	 * @return void
	 */
	public function process_admin_options(): void {
		global $wpdb;
		$old = (int) explode( ':', (string) $this->get_option( 'apikey', '' ) )[0];

		parent::process_admin_options();

		$new = (int) explode( ':', (string) $this->get_option( 'apikey', '' ) )[0];
		if ( $new !== $old ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_seq" );
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_meta" );
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_subs" );
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_queue" );
			require WC_SCANPAY_DIR . '/install.php';
		}
	}

	/**
	 * Enqueue checkout page styles if enabled in settings.
	 */
	public function enqueue_checkout_styles(): void {
		if ( is_checkout() ) {
			wp_enqueue_style( 'wcsp-pay', WC_SCANPAY_URL . '/public/assets/css/checkout.css', [], WC_SCANPAY_VERSION );
		}
	}

	/**
	 * Filter whether an order item requires processing.
	 *
	 * Called from WC_Order::needs_processing() during checkout; the result is cached
	 * per order. WooCommerce normally skips processing only if a product is both
	 * virtual and downloadable. This override skips processing for all virtual
	 * products, even when they are not downloadable.
	 *
	 * @param bool       $needs_processing Whether the item needs processing.
	 * @param WC_Product $product          The product object.
	 * @return bool
	 */

	public function item_needs_processing( bool $needs_processing, WC_Product $product ): bool {
		if ( $needs_processing && $product->get_virtual( 'edit' ) ) {
			return false;
		}
		return $needs_processing;
	}
}
