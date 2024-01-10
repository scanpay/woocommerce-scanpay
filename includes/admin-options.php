<?php

/*
*   admin-options.php
*   Override WC_Payment_Gateway/WC_Settings_API:: admin_options()
*/

defined( 'ABSPATH' ) || exit();

$settings     = get_option( WC_SCANPAY_URI_SETTINGS );
$shopid       = (int) explode( ':', $settings['apikey'] ?? '' )[0];
$ping_url     = rawurlencode( WC()->api_request_url( 'scanpay_ping' ) );
$sendping_url = WC_SCANPAY_DASHBOARD . $shopid . '/settings/api/setup?module=woocommerce&url=' . $ping_url;

echo '<h2>' . esc_html( $this->get_method_title() );
// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
wc_back_link( __( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
echo '</h2>';
echo wp_kses_post( wpautop( $this->get_method_description() ) );
?>

<div class="scanpay--admin--nav">
	<a class="button" target="_blank" href="https://github.com/scanpay/woocommerce-scanpay">
		<img width="16" height="16" src="<?php echo WC_SCANPAY_URL; ?>/public/images/admin/github.svg" class="scanpay--admin--nav--img-git">
		<?php echo __( 'Guide', 'scanpay-for-woocommerce' ); ?>
	</a>

	<?php if ( $shopid ) : ?>
	<a id="scanpay--admin--ping" class="button" target="_blank" href="<?php echo $sendping_url; ?>">
		<img width="21" height="16" src="<?php echo WC_SCANPAY_URL; ?>/public/images/admin/ping.svg" class="scanpay--admin--nav--img-ping">
		<?php echo __( 'Send ping', 'scanpay-for-woocommerce' ); ?>
	</a>
	<?php endif; ?>

	<a class="button" href="?page=wc-status&tab=logs&log_file=<?php echo basename( wc_get_log_file_path( 'woo-scanpay' ) ); ?>&source=woo-scanpay">
		<?php echo __( 'Debug logs', 'scanpay-for-woocommerce' ); ?>
	</a>
	<span id="scanpay-mtime"></span>
</div>

<div id="scanpay--admin--alert" data-shopid="<?php echo $shopid; ?>">
	<!-- No API-key -->
	<?php if ( ! $shopid ) : ?>
		<div class="scanpay--admin--alert scanpay--admin--alert--show">
			<div class="scanpay--admin--alert--title">
				<?php echo __( 'Welcome to Scanpay for WooCommerce!', 'scanpay-for-woocommerce' ); ?>
			</div>
			Please follow the instructions in the
			<a href="https://github.com/scanpay/woocommerce-scanpay/blob/master/docs/installation.md">installation guide</a>.
		</div>
	<?php endif; ?>
</div>

<?php

$class_name = 'form-table';
if ( isset( $this->settings['subscriptions_enabled'] ) && 'no' === $this->settings['subscriptions_enabled'] ) {
	$class_name = 'form-table scanpay--admin--no-subs';
}

/*
	The following is copied from WC_Settings_API::admin_options()
	Last verified on 2023-10
*/
echo '<table class="' . $class_name . '">' .
	$this->generate_settings_html( $this->get_form_fields(), false ) .
'</table>';
