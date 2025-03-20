<?php

defined( 'ABSPATH' ) || exit();

class WC_Scanpay_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                   = 'scanpay';
		$this->method_title         = 'Scanpay'; // In settings
		$this->method_description   = __( 'Accept payment cards through Scanpay.', 'scanpay-for-woocommerce' );
		$this->view_transaction_url = WC_SCANPAY_DASHBOARD . '%s';
		$this->supports             = [
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

		add_filter( 'woocommerce_settings_api_form_fields_scanpay', function () {
			$settings = require WC_SCANPAY_DIR . '/includes/form-fields.php';
			$pages    = get_pages();
			foreach ( $pages as $page ) {
				$settings['wcs_terms']['options'][ $page->ID ] = $page->post_title . ' (' . $page->ID . ')';
			}
			return $settings;
		}, 1, 0 );
		$this->init_settings();
		$this->title       = $this->settings['title'];
		$this->description = $this->settings['description'];

		add_action( 'woocommerce_update_options_payment_gateways_scanpay', [ $this, 'process_admin_options' ] );

		// Check if plugin needs to be upgraded (todo: merge wc_scanpay_version with settings)
		if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION && ! get_transient( 'wc_scanpay_updating' ) ) {
			set_transient( 'wc_scanpay_updating', true, 5 * 60 );  // Set a transient for 5 minutes
			require WC_SCANPAY_DIR . '/upgrade.php';
			delete_transient( 'wc_scanpay_updating' );
		}

		if ( 'yes' === $this->settings['stylesheet'] ) {
			add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', function () {
				wp_enqueue_style( 'wcsp-blocks', WC_SCANPAY_URL . '/public/css/checkout.css', null, WC_SCANPAY_VERSION );
			} );
		}

		/*
			WC auto-completes downloadable orders, but not virtual orders. This filter
			will set virtual products to not need processing, so they are auto-completed.
		*/
		if ( 'yes' === $this->settings['wc_complete_virtual'] ) {
			add_filter( 'woocommerce_order_item_needs_processing', function ( $needs_processing, $product ) {
				if ( $needs_processing && true === $product->get_virtual( 'edit' ) ) {
					return false; // Product is virtual, but not downloadable.
				}
				return $needs_processing;
			}, 10, 2 );
		}
	}

	public function get_icon(): string {
		$array = $this->settings['card_icons'];
		if ( $array ) {
			if ( 'yes' === $this->settings['stylesheet'] ) {
				// TODO: find a better way to load this stylesheet or use prefetch
				wp_enqueue_style( 'wcsp-pay', WC_SCANPAY_URL . '/public/css/checkout.css', null, WC_SCANPAY_VERSION );
			}
			$icons = '<span class="wcsp-methods wcsp-cards">';
			foreach ( $array as $key => $card ) {
				$icons .= '<img src="' . WC_SCANPAY_URL . '/public/images/cards/' . $card .
					'.svg" class="wcsp-' . $card . '" alt="' . $card . '" title="' . $card . '">';
			}
			return $icons . '</span>';
		}
		return '';
	}

	/**
	 * Get the title of the payment method, e.g. "Pay by card"
	 *
	 * @return string
	 */
	public function get_title(): string {
		// In the admin, we want to show "scanpay"
		if ( defined( 'WP_ADMIN' ) && isset( $_GET['page'] ) && ( 'wc-orders' === $_GET['page'] || 'wc-orders--shop_subscription' === $_GET['page'] ) ) {
			return 'Scanpay';
		}
		return $this->settings['title'];
	}

	public function get_transaction_url( $wc_order ): string {
		return WC_SCANPAY_DASHBOARD . $wc_order->get_meta( WC_SCANPAY_URI_SHOPID, true ) . '/' .
			$wc_order->get_transaction_id( 'edit' );
	}

	public function process_payment( $order_id ): array {
		require WC_SCANPAY_DIR . '/includes/payment-link.php';
		return wc_scanpay_process_payment( $order_id, $this->settings );
	}

	public function admin_options(): void {
		require WC_SCANPAY_DIR . '/includes/admin-options.php';
	}

	public function process_admin_options(): void {
		global $wpdb;
		$old_shopid = (int) explode( ':', $this->settings['apikey'] ?? '' )[0];
		parent::process_admin_options();
		if ( (int) explode( ':', $this->settings['apikey'] ?? '' )[0] !== $old_shopid ) {
			scanpay_log( 'info', 'API key changed, re-installing SQL tables' );
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_seq" );
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_meta" );
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_subs" );
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}scanpay_queue" );
			require WC_SCANPAY_DIR . '/install.php';
		}
	}

	public function can_refund_order( $order ) {
		return false;
	}
}
