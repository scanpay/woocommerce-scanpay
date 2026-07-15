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

// The synchronization (ping) URL the merchant must register in the Scanpay dashboard.
$ping_url = WC()->api_request_url( 'wc_scanpay' );

// One-click deep link to the dashboard that pre-fills the module and ping URL.
$callback_url = WC_SCANPAY_DASHBOARD . $shopid . '/settings/api/setup?module=woocommerce&url='
	. rawurlencode( $ping_url );

if ( ! $shopid ) {
	// No API key yet: welcome the merchant and point to the installation guide.
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
} else {
	// API key is set: surface the ping URL to register in the dashboard (core onboarding step).
	scanpay_admin_notice(
		'<strong>' .
			esc_html__( 'Finish your Scanpay setup', 'scanpay-for-woocommerce' ) .
		'</strong><br>' .
		esc_html__(
			'Add this synchronization URL in your Scanpay dashboard so we can keep your orders in sync:',
			'scanpay-for-woocommerce'
		) .
		'<br><input type="text" class="wcsp-setup-url" style="width:100%;max-width:34em;margin:6px 0;"' .
			' value="' . esc_url( $ping_url ) . '" readonly onclick="this.select();">' .
		'<br><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url( $callback_url ) . '">' .
			esc_html__( 'Add URL in the Scanpay dashboard', 'scanpay-for-woocommerce' ) .
		'</a>'
	);
}

// Construct the Scanpay logs URL.
$logs_url = add_query_arg(
	[
		'page'     => 'wc-status',
		'tab'      => 'logs',
		'log_file' => basename( WC_Log_Handler_File::get_log_file_path( 'wc-scanpay' ) ),
		'source'   => 'wc-scanpay',
	],
	admin_url( 'admin.php' )
);

?>

<h2 class="wc-admin-header">
	<small>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>">
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
		$url = add_query_arg(
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
		<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $url ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
	<?php endforeach; ?>
	<a class="wcsp-nav-logs" href="<?php echo esc_url( $logs_url ); ?>">Logs</a>
</div>

<table class="form-table wcsp-set-<?php echo esc_attr( $gateway->id ); ?>">
	<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$gateway->generate_settings_html( $gateway->get_form_fields(), true );
	?>
</table>
