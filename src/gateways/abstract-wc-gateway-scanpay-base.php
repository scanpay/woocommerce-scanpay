<?php

defined( 'ABSPATH' ) || exit();

abstract class WC_Gateway_Scanpay_Base extends WC_Payment_Gateway {
	public function __construct() {
		$this->supports   = [ 'products' ];
		$this->has_fields = false;
		$this->init_settings();
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * WooCommerce recommends loading form fields on every request.
	 * That’s needless overhead, so this method is left empty and fields
	 * are loaded lazily in get_form_fields() only when needed (e.g. in settings).
	 */
	public function init_form_fields(): void {}

	/**
	 * Get the gateway form fields (settings schema and default values).
	 * Only used in the admin or when a setting is not yet saved in the database.
	 *
	 * @return array
	 */
	public function get_form_fields(): array {
		if ( empty( $this->form_fields ) ) {
			$this->form_fields = require WC_SCANPAY_DIR . '/gateways/fields/' . $this->id . '.php';
		}
		return $this->form_fields;
	}

	/**
	 * Return the display title, e.g. "Pay by Card".
	 *
	 * @return string
	 */
	public function get_title(): string {
		return is_admin() ? 'Scanpay' : $this->get_option( 'title', 'Scanpay' );
	}

	/**
	 * Get the description of the payment method, e.g. "Pay securely using your credit card."
	 *
	 * @return string
	 */
	public function get_description(): string {
		return $this->get_option( 'description', '' );
	}

	/**
	 * Get the URL to view transaction details in Scanpay dashboard
	 *
	 * @param WC_Order $wco
	 * @return string
	 */
	public function get_transaction_url( $wco ): string {
		$shop = (string) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true );
		$tx   = (string) $wco->get_transaction_id( 'edit' );
		return esc_url( WC_SCANPAY_DASHBOARD . rawurlencode( $shop ) . '/' . rawurlencode( $tx ) );
	}

	/**
	 * Output admin options.
	 *
	 * @return void
	 */
	public function admin_options(): void {
		require WC_SCANPAY_DIR . '/gateways/includes/admin-options.php';
	}

	/**
	 * Indicate that refunds are not supported.
	 *
	 * @param WC_Order $order The order to check.
	 * @return bool
	 */
	public function can_refund_order( $order ): bool {
		return false;
	}

	/**
	 * Determine whether the gateway needs setup before it can be enabled.
	 * This is used by WC admin to show a setup notice.
	 *
	 * @return bool
	 */
	public function needs_setup(): bool {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS, [] );
		return '' === (string) ( $settings['apikey'] ?? '' );
	}
}
