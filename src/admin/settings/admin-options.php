<?php

/**
 * Custom admin options for Scanpay gateways.
 *
 * Overrides the default WC_Payment_Gateway::admin_options() layout.
 *
 * @var WC_Gateway_Scanpay_Base $gateway The gateway whose screen is being rendered.
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();


/**
 * Display an admin notice.
 *
 * @param string $msg  Pre-escaped HTML message.
 * @param string $type Notice type: 'info', 'warning', 'error' or 'success'.
 */
function wc_scanpay_admin_notice( string $msg, string $type = 'info' ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $msg is trusted, pre-escaped HTML assembled by the callers below.
	echo '<div class="notice notice-' . esc_attr( $type ) . ' wcsp-notice"><p>' . $msg . '</p></div>';
}

$settings = get_option( WC_SCANPAY_URI_SETTINGS, [] );
$shopid   = (int) strstr( (string) ( $settings['apikey'] ?? '' ), ':', true );

// The synchronization (ping) URL the merchant must register in the Scanpay dashboard.
$ping_url = WC()->api_request_url( 'wc_scanpay' );

// One-click deep link to the dashboard that pre-fills the module and ping URL.
$callback_url = WC_SCANPAY_DASHBOARD . $shopid . '/settings/api/setup?module=woocommerce&url='
	. rawurlencode( $ping_url );

if ( ! $shopid ) {
	$guide_link = sprintf(
		'<a target="_blank" href="%s">%s</a>',
		esc_url( 'https://wordpress.org/plugins/scanpay-for-woocommerce/#installation' ),
		esc_html__( 'installation guide', 'scanpay-for-woocommerce' )
	);
	$setup_text = sprintf(
		/* translators: %s is a link to the installation guide. */
		__( 'To get started, please complete the setup using our %s.', 'scanpay-for-woocommerce' ),
		$guide_link
	);
	wc_scanpay_admin_notice(
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
	// Nothing syncs until the merchant registers this URL, so keep prompting for it.
	wc_scanpay_admin_notice(
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

// One tab per gateway; the ids double as the WC settings section.
$nav_tabs = [
	'scanpay'           => __( 'General', 'scanpay-for-woocommerce' ),
	'scanpay_mobilepay' => 'MobilePay',
	'scanpay_applepay'  => 'Apple Pay',
];
?>

<div class="wcsp-nav wcsp-nav-<?php echo esc_attr( $gateway->id ); ?>" aria-label="Scanpay menu">
	<?php foreach ( $nav_tabs as $section_id => $label ) : ?>
		<?php
		$url = add_query_arg(
			[
				'page'    => 'wc-settings',
				'tab'     => 'checkout',
				'section' => $section_id,
			],
			admin_url( 'admin.php' )
		);

		$classes = 'wcsp-nav-tab';
		if ( $gateway->id === $section_id ) {
			$classes .= ' wcsp-nav-tab-active';
		}
		?>
		<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $url ); ?>">
			<?php echo esc_html( $label ); ?>
		</a>
	<?php endforeach; ?>
	<a class="wcsp-nav-logs" href="<?php echo esc_url( $logs_url ); ?>"><?php esc_html_e( 'Logs', 'scanpay-for-woocommerce' ); ?></a>
	<span id="wcsp-set-nav-mtime" class="wcsp-set-nav-mtime"></span>
</div>

<?php
// Anchor for settings.ts: it reads the polling secret + shop id from these data
// attributes, writes the "Synchronized N seconds ago" string into
// #wcsp-set-nav-mtime, and appends sync / out-of-date warnings here. The secret and
// shop id come from the primary (card) settings option on every gateway screen.
?>
<div id="wcsp-set-alert"
	data-secret="<?php echo esc_attr( (string) ( $settings['secret'] ?? '' ) ); ?>"
	data-shopid="<?php echo esc_attr( (string) $shopid ); ?>"></div>

<table class="form-table wcsp-set-<?php echo esc_attr( $gateway->id ); ?>">
	<?php
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$gateway->generate_settings_html( $gateway->get_form_fields(), true );
	?>
</table>
