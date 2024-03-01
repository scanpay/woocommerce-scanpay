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
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		];
		add_action( 'woocommerce_update_options_payment_gateways_scanpay', [ $this, 'process_admin_options' ] );

		// Check if plugin needs to be upgraded (todo: merge wc_scanpay_version with settings)
		if ( get_option( 'wc_scanpay_version' ) !== WC_SCANPAY_VERSION && ! get_transient( 'wc_scanpay_updating' ) ) {
			set_transient( 'wc_scanpay_updating', true, 5 * 60 );  // Set a transient for 5 minutes
			require WC_SCANPAY_DIR . '/includes/upgrade.php';
			delete_transient( 'wc_scanpay_updating' );
		}

		if ( 'yes' === $this->settings['stylesheet'] ) {
			add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', function () {
				wp_enqueue_style( 'wcsp-blocks', WC_SCANPAY_URL . '/public/css/blocks.css', null, WC_SCANPAY_VERSION );
			} );
		}
	}

	public function get_icon(): string {
		$array = $this->settings['card_icons'];
		if ( $array ) {
			if ( 'yes' === $this->settings['stylesheet'] ) {
				// TODO: find a better way to load this stylesheet or use prefetch
				wp_enqueue_style( 'wcsp-pay', WC_SCANPAY_URL . '/public/css/pay.css', null, WC_SCANPAY_VERSION );
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

	public function get_title(): string {
		if ( defined( 'WP_ADMIN' ) && isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
			return 'Scanpay';
		}
		return $this->settings['title'];
	}

	public function get_transaction_url( $wc_order ): string {
		return WC_SCANPAY_DASHBOARD . $wc_order->get_meta( WC_SCANPAY_URI_SHOPID, true ) . '/' .
			$wc_order->get_transaction_id();
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
			require WC_SCANPAY_DIR . '/includes/install.php';
		}
	}

	public function can_refund_order( $order ) {
		return false;
	}

	// Inherited from WC_Settings_API class
	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled'              => [
				'title' => 'Enable',
				'type'  => 'checkbox',
				'label' => 'Enable Scanpay in the checkout.',
			],
			'apikey'               => [
				'title'             => 'API key',
				'type'              => 'text',
				'custom_attributes' => [ 'autocomplete' => 'off' ],
			],
			'title'                => [
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'A title for the payment method. This is displayed on the checkout page.',
				'desc_tip'    => true,
			],
			'description'          => [
				'title'       => 'Description',
				'type'        => 'text',
				'description' => 'A description of the payment method. This is displayed on the checkout page.',
				'desc_tip'    => true,
			],
			'card_icons'           => [
				'title'       => 'Card icons',
				'type'        => 'multiselect',
				'description' => 'Choose which card icons to display on the checkout page.',
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
				'class'       => 'wc-enhanced-select',
				'desc_tip'    => true,
			],
			'stylesheet'           => [
				'title' => 'Stylesheet',
				'type'  => 'checkbox',
				'label' => 'Use default checkout stylesheet (CSS).',
			],
			'capture_on_complete'  => [
				'title'       => 'Auto-Capture',
				'type'        => 'checkbox',
				'label'       => 'Capture when order status is changed to "completed".',
				'description' => 'Automatically capture the payment when the order status changes to "completed".',
				'desc_tip'    => true,
			],
			'wc_complete_virtual'  => [
				'title' => 'Auto-Complete',
				'type'  => 'checkbox',
				'label' => 'Auto-complete virtual orders.',
			],
			'wcs_complete_initial' => [
				'title' => '&#10240;',
				'type'  => 'checkbox',
				'label' => 'Auto-complete new subscribers <i>(Subscriptions only)</i>.',
			],
			'wcs_complete_renewal' => [
				'title' => '&#10240;',
				'type'  => 'checkbox',
				'label' => 'Auto-complete renewal orders <i>(Subscriptions only)</i>.',
			],
		];
	}
}
