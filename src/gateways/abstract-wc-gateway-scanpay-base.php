<?php

declare(strict_types=1);

defined( 'ABSPATH' ) || exit();

abstract class WC_Gateway_Scanpay_Base extends WC_Payment_Gateway {
	public function __construct() {
		$this->supports   = [ 'products' ];
		$this->has_fields = false;
		$this->init_settings();
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * Deliberately empty. WooCommerce builds the form fields on every request; that is
	 * pure overhead off the settings screen, so get_form_fields() loads them lazily.
	 */
	public function init_form_fields(): void {}

	/**
	 * The settings schema and its defaults, required from admin/settings/fields/<id>.php.
	 * Only reached in the admin, or when a setting has no stored value yet.
	 */
	public function get_form_fields(): array {
		if ( empty( $this->form_fields ) ) {
			$this->form_fields = require WC_SCANPAY_DIR . '/admin/settings/fields/' . $this->id . '.php';
		}
		return $this->form_fields;
	}

	/**
	 * The checkout display title, e.g. "Pay by card".
	 *
	 * 'Scanpay' under is_admin() is deliberate branding: sync collapses all three
	 * gateways into the 'scanpay' payment method and owns the real
	 * payment_method_title, so the admin should name the account, not the button.
	 */
	public function get_title(): string {
		// Cast: get_option() returns whatever is stored, and strict_types turns a
		// non-string (a hand-edited option, a filter) into a TypeError on a method WC
		// calls while rendering checkout.
		return is_admin() ? 'Scanpay' : (string) $this->get_option( 'title', 'Scanpay' );
	}

	/** The checkout description, e.g. "Pay with a payment card via Scanpay." */
	public function get_description(): string {
		return (string) $this->get_option( 'description', '' ); // Cast: see get_title().
	}

	/**
	 * The Scanpay dashboard URL for this order's transaction.
	 *
	 * @param WC_Order $wco Untyped in the signature to match WC_Payment_Gateway.
	 */
	public function get_transaction_url( $wco ): string {
		$shop = (string) $wco->get_meta( WC_SCANPAY_URI_SHOPID, true );
		$tx   = (string) $wco->get_transaction_id( 'edit' );
		return esc_url( WC_SCANPAY_DASHBOARD . rawurlencode( $shop ) . '/' . rawurlencode( $tx ) );
	}

	/** Render the settings screen. $gateway is the required file's handle on $this. */
	public function admin_options(): void {
		$gateway = $this;
		require WC_SCANPAY_DIR . '/admin/settings/admin-options.php';
	}

	/**
	 * Process and save admin options. The required file runs in this scope, with $this live.
	 *
	 * @return bool Whether anything was saved.
	 */
	public function process_admin_options() {
		return require WC_SCANPAY_DIR . '/admin/settings/process-admin-options.php';
	}

	/**
	 * Render the API key field.
	 *
	 * WC_Settings_API::generate_settings_html() dispatches generate_{$type}_html
	 * when the method exists, so the 'apikey' field type lands here.
	 *
	 * The stored key is never emitted. WC's own password field is
	 * generate_text_html() with type=password, which renders
	 * value="<the live key>" into the DOM -- readable by anyone with devtools, a
	 * password manager, or an XSS on this page, for the credential that both
	 * charges cards and signs the ping HMAC. Once a key is stored we render a
	 * masked form and no input at all: replacing it goes through the reset
	 * button, so the tables are never silently orphaned onto a different shop.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field definition from admin/settings/fields/scanpay.php.
	 */
	public function generate_apikey_html( $key, $data ): string {
		$field_key = $this->get_field_key( $key );
		$stored    = (string) $this->get_option( $key, '' );
		$data      = wp_parse_args(
			$data,
			[
				'title'       => '',
				'description' => '',
				'desc_tip'    => false,
			]
		);
		// Show which shop is configured without revealing the key itself.
		$shopid = (int) strstr( $stored, ':', true );
		$masked = $shopid . ':' . str_repeat( '*', 24 );
		// Pre-escaped: wp_kses_post() + WC-generated tooltip markup.
		$title_html = wp_kses_post( $data['title'] ) . $this->get_tooltip_html( $data );

		ob_start();
		?>
		<?php // Row class so settings.scss can target this row: once a key is stored, there is no input to key off. ?>
		<tr valign="top" class="wcsp-set-row-apikey">
			<th scope="row" class="titledesc">
				<?php // Only label the input when there is one; a dangling for= resolves to nothing. ?>
				<?php if ( '' === $stored ) : ?>
					<label for="<?php echo esc_attr( $field_key ); ?>">
						<?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above. ?>
					</label>
				<?php else : ?>
					<span><?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above. ?></span>
				<?php endif; ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<?php if ( '' === $stored ) : ?>
						<input class="input-text regular-input" type="password" autocomplete="off"
							name="<?php echo esc_attr( $field_key ); ?>"
							id="<?php echo esc_attr( $field_key ); ?>" value=""
							placeholder="<?php esc_attr_e( 'Paste your Scanpay API key', 'scanpay-for-woocommerce' ); ?>">
						<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WC-generated markup. ?>
					<?php else : ?>
						<code class="wcsp-set-apikey"><?php echo esc_html( $masked ); ?></code>
						<p class="description">
							<?php esc_html_e( 'To use a different Scanpay account, delete the local Scanpay data first. Nothing is lost for good: it is re-synced from Scanpay once you add a key.', 'scanpay-for-woocommerce' ); ?>
						</p>
						<button type="button" class="button wcsp-set-reset"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'wc-scanpay-reset' ) ); ?>">
							<?php esc_html_e( 'Delete data and change API key', 'scanpay-for-woocommerce' ); ?>
						</button>
						<span class="wcsp-set-reset-msg"></span>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Whether a string has the shape of a Scanpay API key: "<shopid>:<base64 secret>".
	 *
	 * A shape check only -- it says nothing about whether the key works. Both the
	 * standard and URL-safe base64 alphabets are accepted, so this stays permissive
	 * about the secret while still rejecting the pasted-wrong-thing cases (no
	 * separator, a non-numeric shop id, embedded whitespace, stray quotes).
	 *
	 * @param string $key Candidate key.
	 */
	private function is_apikey( string $key ): bool {
		$colon = strpos( $key, ':' );
		if ( false === $colon || 0 === $colon ) {
			return false; // No separator, or an empty shop id.
		}
		if ( ! ctype_digit( substr( $key, 0, $colon ) ) ) {
			return false;
		}
		$secret = rtrim( substr( $key, $colon + 1 ), '=' );
		return '' !== $secret && strlen( $secret ) === strspn(
			$secret,
			'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/-_'
		);
	}

	/**
	 * Validate the API key field.
	 *
	 * WC_Settings_API::get_field_value() dispatches validate_{$key}_field ahead of
	 * validate_{$type}_field, so this intercepts every save and is the real gate --
	 * generate_apikey_html() only hides the input.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 */
	public function validate_apikey_field( $key, $value ): string {
		$stored = (string) $this->get_option( $key, '' );
		// stripslashes to match the field contract this method hooks: $value comes from
		// WC_Settings_API::get_post_data() -> raw $_POST, slash-escaped by
		// wp_magic_quotes(). Every WC counterpart strips (validate_password_field() is
		// trim( stripslashes( $value ) )). Inert for the current key alphabet.
		$value = trim( stripslashes( (string) $value ) );

		/*
		 * Required, not defensive: once a key is stored the field renders no input,
		 * so nothing is posted. WC's validate_password_field() is a bare
		 * trim( stripslashes() ), which would save the blank and wipe the key.
		 */
		if ( '' === $value ) {
			return $stored;
		}
		if ( '' !== $stored ) {
			/*
			 * Replacing a key points the store at a different Scanpay shop, which the
			 * local tables do not belong to. Deleting them is the merchant's explicit
			 * call via the reset button, never a side effect of saving a form.
			 */
			WC_Admin_Settings::add_error(
				__( 'Error: The Scanpay API key cannot be replaced. Delete the local Scanpay data first.', 'scanpay-for-woocommerce' )
			);
			return $stored;
		}
		/*
		 * Reject a malformed key instead of storing it. Storing first is what makes a
		 * typo expensive: the field then renders masked with no input, so "try again"
		 * is impossible short of the reset button, whose copy warns about deleting
		 * data. process-admin-options.php also proves the key against the API, even
		 * while the gateway is disabled, but that is a network call: this shape check
		 * is the cheap, offline first pass.
		 */
		if ( ! $this->is_apikey( $value ) ) {
			WC_Admin_Settings::add_error(
				__( 'Error: The Scanpay API key is malformed. It should look like "1234:xxxxxxxx".', 'scanpay-for-woocommerce' )
			);
			return $stored; // Empty: the input stays editable.
		}
		return $value;
	}

	/**
	 * Never offer a refund button: refunds are issued in the Scanpay dashboard and the
	 * plugin only reflects them read-only, via sync.
	 */
	public function can_refund_order( $order ): bool {
		return false;
	}

	/** Whether WC admin should show the "setup required" notice on this gateway. */
	public function needs_setup(): bool {
		$settings = get_option( WC_SCANPAY_URI_SETTINGS, [] );
		return '' === (string) ( $settings['apikey'] ?? '' );
	}
}
