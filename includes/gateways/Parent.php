<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

abstract class WC_Scanpay_Parent extends WC_Scanpay
{
    public function __construct()
    {
        parent::__construct(true);
    }

    public function setup()
    {
        $this->init_form_fields();
        $this->init_settings();
        if (is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->main_settings = $this->settings;
    }

}
