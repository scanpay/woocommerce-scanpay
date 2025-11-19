<?php

/**
 * Custom admin options for Scanpay gateways.
 *
 * Overrides the default WC_Payment_Gateway::admin_options() layout.
 *
 * @var WC_Payment_Gateway $gateway Current gateway instance.
 */

defined( 'ABSPATH' ) || exit();


/**
 * Display an admin notice.
 *
 * @param string $msg The message to display.
 * @param string $type    The type of notice: 'info', 'warning', 'error', 'success'.
 */
function scanpay_admin_notice( string $msg, string $type = 'info' ): void {
	echo '<div class="notice notice-' . esc_attr( $type ) . ' wcsp-notice"><p>' . $msg . '</p></div>';
}

// Get the shopID from the API key (first part before the colon).
$settings = get_option( WC_SCANPAY_URI_SETTINGS, [] );
$shopid   = (int) strtok( $settings['apikey'] ?? '', ':' );

// Create the ping URL that we send to the dashboard.
$callback_url = WC_SCANPAY_DASHBOARD . $shopid . '/settings/api/setup?module=woocommerce&url='
	. rawurlencode( WC()->api_request_url( 'wc_scanpay' ) );

// Add welcome notice if shopid is not set.
if ( ! $shopid ) {
	$link = sprintf(
		'<a target="_blank" href="%s">%s</a>',
		esc_url( 'https://wordpress.org/plugins/scanpay-for-woocommerce/#installation' ),
		esc_html__( 'installation guide', 'scanpay-for-woocommerce' )
	);
	/* translators: %s is a link to the installation guide. */
	$setup_text = sprintf(
		__( 'To get started, please complete the setup using our %s.', 'scanpay-for-woocommerce' ),
		$link
	);
	scanpay_admin_notice(
		'<strong>' .
			esc_html__( 'Thank you for choosing Scanpay!', 'scanpay-for-woocommerce' ) .
		'</strong><br>' .
		esc_html__(
			'This plugin is built and maintained by Scanpay, and we aim to keep it efficient, stable, and simple to use. We hope it serves you well.',
			'scanpay-for-woocommerce'
		) . '<br>' .
		$setup_text
	);
}

// Check for unread scanpay logs
$files = WC_Log_Handler_File::get_log_files();
$scanpay_logs = array_filter(
    array_keys( $files ),
    fn ( $file ) => str_starts_with( $file, 'wc-scanpay' )
);
if ( count ( $scanpay_logs ) > 0 ) {
	// Construct the log file name.
	$logs_url    = add_query_arg(
		[
			'page'     => 'wc-status',
			'tab'      => 'logs',
			'log_file' => basename( WC_Log_Handler_File::get_log_file_path( 'wc-scanpay' ) ),
			'source'   => 'wc-scanpay',
		],
		admin_url( 'admin.php' )
	);
	scanpay_admin_notice( "You have unread logs", "warning" );
}




?>

<h2 class="wc-admin-header">
	<small>
		<a href="/wp-admin/admin.php?page=wc-settings&amp;tab=checkout">
			<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
		</a>
	</small>
	Scanpay
</h2>

<?php

// Navigation tabs.
$tabs = [
	'scanpay'           => 'Generelt',
	'scanpay_mobilepay' => 'MobilePay',
	'scanpay_applepay'  => 'Apple Pay',
];
?>
<div class="wcsp-nav wcsp-nav-<?php echo $gateway->id; ?>" aria-label="Scanpay menu">
	<?php foreach ( $tabs as $id => $label ) : ?>
		<?php
		$url     = add_query_arg(
			[
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => $id,
			],
			admin_url( 'admin.php' )
		);
		$classes = 'wcsp-nav-tab';
		if ( $gateway->id === $id ) {
			$classes .= ' wcsp-nav-tab-active';
		}
		?>
		<a class="<?php echo esc_attr( $classes ); ?>"
			href="<?php echo esc_url( $url ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
	<?php endforeach; ?>
</div>

<table class="form-table wcsp-set-<?php echo esc_attr( $gateway->id ); ?>">
	<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$gateway->generate_settings_html( $gateway->get_form_fields(), true );
	?>
</table>
