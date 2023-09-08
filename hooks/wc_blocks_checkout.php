<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Scanpay_Blocks_Support extends AbstractPaymentMethodType {
    protected $name = 'scanpay';

    public function initialize() {
        $this->settings = get_option(WC_SCANPAY_URI_SETTINGS);
    }

    public function get_payment_method_script_handles() {
        $script_asset = require WC_SCANPAY_DIR . '/public/min/blocks.asset.php';
        wp_register_script('wc-scanpay-blocks', WC_SCANPAY_URL . '/public/min/blocks.js',
            $script_asset['dependencies'], $script_asset['version'], true
        );
        return ['wc-scanpay-blocks'];
    }

    public function get_payment_method_data() {
        return [
            'title' => esc_html($this->settings['title']),
            'description' => esc_html($this->settings['description']),
            'url' => esc_url(WC_SCANPAY_URL) . '/public/images/cards/'
        ];
    }
}

add_action('woocommerce_blocks_payment_method_type_registration',
    function(Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
        $payment_method_registry->register(new WC_Scanpay_Blocks_Support);
    }
);
