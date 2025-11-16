<?php

/*
 *   admin-options.php
 *   Override WC_Payment_Gateway/WC_Settings_API:: admin_options()
 */

defined( 'ABSPATH' ) || exit();

$settings     = (array) get_option( WC_SCANPAY_URI_SETTINGS, [] );
$shopid       = (int) explode( ':', $settings['apikey'] ?? '' )[0];
$ping_url     = rawurlencode( WC()->api_request_url( 'wc_scanpay' ) );
$sendping_url = WC_SCANPAY_DASHBOARD . $shopid . '/settings/api/setup?module=woocommerce&url=' . $ping_url;
$log_handler  = new WC_Log_Handler_File();
$log_file     = basename( $log_handler->get_log_file_path( 'woo-scanpay' ) );

$class_name = 'form-table wcsp-set-' . $this->id;
if ( isset( $this->settings['subscriptions_enabled'] ) && 'no' === $this->settings['subscriptions_enabled'] ) {
	$class_name = 'form-table wcsp-set-no-subs';
}

$url_debug = add_query_arg(
	[
		'page'     => 'wc-status',
		'tab'      => 'logs',
		'log_file' => $log_file,
		'source'   => 'woo-scanpay',
	],
	admin_url( 'admin.php' )
);
?>

<div class="wcsp-set-nav">
	<a class="button" target="_blank" href="<?php echo esc_url( 'https://github.com/scanpay/woocommerce-scanpay' ); ?>">
		<img
			width="16"
			height="16"
			src="<?php echo esc_url( WC_SCANPAY_URL . '/admin/assets/images/github.svg' ); ?>"
			class="wcsp-set-nav-img-git"
		>
		<?php esc_html_e( 'Guide', 'scanpay-for-woocommerce' ); ?>
	</a>
	<a id="wcsp-set-ping" class="button" target="_blank" href="<?php echo esc_url( $sendping_url ); ?>">
		<img
			width="21"
			height="16"
			src="<?php echo esc_url( WC_SCANPAY_URL . '/admin/assets/images/ping.svg' ); ?>"
			class="wcsp-set-nav-img-ping"
		>
		<?php esc_html_e( 'Send ping', 'scanpay-for-woocommerce' ); ?>
	</a>
	<a class="button" href="<?php echo esc_url( $url_debug ); ?>">
		<?php esc_html_e( 'Debug logs', 'scanpay-for-woocommerce' ); ?>
	</a>
	<span id="wcsp-set-nav-mtime"></span>
</div>

<div
	id="wcsp-set-alert"
	data-shopid="<?php echo esc_attr( (string) $shopid ); ?>"
	<?php // Only expose secret if absolutely necessary; otherwise drop this attribute. ?>
>
	<?php if ( ! $shopid ) : ?>
		<div class="wcsp-set-alert wcsp-set-alert--show">
			<div class="wcsp-set-alert-title">
				<?php esc_html_e( 'Welcome to Scanpay for WooCommerce!', 'scanpay-for-woocommerce' ); ?>
			</div>
			<?php
			$splink = sprintf(
				'<a target="_blank" href="%s">%s</a>',
				esc_url( 'https://wordpress.org/plugins/scanpay-for-woocommerce/#installation' ),
				esc_html__( 'installation guide', 'scanpay-for-woocommerce' )
			);
			// translators: %s is a link to the installation guide.
			printf(
				wp_kses_post( __( 'Please follow the instructions in the %s.', 'scanpay-for-woocommerce' ) ),
				$splink
			);
			?>
		</div>
	<?php endif; ?>
</div>

<table class="<?php echo esc_attr( $class_name ); ?>">
	<?php echo $this->generate_settings_html( $this->get_form_fields(), false ); ?>
</table>
