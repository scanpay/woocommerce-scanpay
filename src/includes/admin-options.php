<?php

/*
 *   admin-options.php
 *   Override WC_Payment_Gateway/WC_Settings_API:: admin_options()
 */

defined( 'ABSPATH' ) || exit();

$settings     = get_option( WC_SCANPAY_URI_SETTINGS );
$shopid       = (int) explode( ':', $settings['apikey'] ?? '' )[0];
$secret       = $settings['secret'] ?? '';
$ping_url     = rawurlencode( WC()->api_request_url( 'wc_scanpay' ) );
$sendping_url = WC_SCANPAY_DASHBOARD . $shopid . '/settings/api/setup?module=woocommerce&url=' . $ping_url;
$log_handler  = new WC_Log_Handler_File();
$log_file     = basename( $log_handler->get_log_file_path( 'woo-scanpay' ) );

echo '<h2>' . esc_html( $this->get_method_title() );
// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
wc_back_link( __( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
echo '</h2>';
echo wp_kses_post( wpautop( $this->get_method_description() ) );
?>

<div class="wcsp-set-nav">
	<a class="button" target="_blank" href="https://github.com/scanpay/woocommerce-scanpay">
		<img width="16" height="16" src="<?php echo WC_SCANPAY_URL; ?>/public/images/admin/github.svg" class="wcsp-set-nav-img-git">
		Guide
	</a>

	<?php if ( $shopid ) : ?>
	<a id="wcsp-set-ping" class="button" target="_blank" href="<?php echo $sendping_url; ?>">
		<img width="21" height="16" src="<?php echo WC_SCANPAY_URL; ?>/public/images/admin/ping.svg" class="wcsp-set-nav-img-ping">
		Send ping
	</a>
	<?php endif; ?>

	<a class="button" href="?page=wc-status&tab=logs&log_file=<?php echo $log_file; ?>&source=woo-scanpay">
		Debug logs
	</a>
	<span id="wcsp-set-nav-mtime"></span>
</div>

<div id="wcsp-set-alert" data-shopid="<?php echo $shopid; ?>" data-secret="<?php echo $secret; ?>">
	<!-- No API-key -->
	<?php if ( ! $shopid ) : ?>
		<div class="wcsp-set-alert wcsp-set-alert--show">
			<div class="wcsp-set-alert-title">
				<?php echo __( 'Welcome to Scanpay for WooCommerce!', 'scanpay-for-woocommerce' ); ?>
			</div>
			<?php
				$splink = '<a target=”_blank” href="https://wordpress.org/plugins/scanpay-for-woocommerce/#installation">' . __( 'installation guide', 'scanpay-for-woocommerce' ) . '</a>';
				// translators: %s is a link to the installation guide
				$msg = sprintf( __( 'Please follow the instructions in the %s.', 'scanpay-for-woocommerce' ), $splink );
				echo $msg;
			?>
		</div>
	<?php endif; ?>
</div>

<?php

$class_name = 'form-table wcsp-set-' . $this->id;
if ( isset( $this->settings['subscriptions_enabled'] ) && 'no' === $this->settings['subscriptions_enabled'] ) {
	$class_name = 'form-table wcsp-set-no-subs';
}

/*
 *  The following is copied from WC_Settings_API::admin_options()
 *  Last verified on 2023-10
 */
echo '<table class="' . $class_name . '">' .
	$this->generate_settings_html( $this->get_form_fields(), false ) .
'</table>';
