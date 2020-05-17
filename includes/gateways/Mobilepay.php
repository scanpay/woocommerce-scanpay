<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scanpay_Mobilepay extends WC_Scanpay_Parent
{
    public function __construct()
    {
        parent::__construct(false);
        $this->id = 'scanpay_mobilepay';
        $this->setup();
        $this->method_title = 'MobilePay (Scanpay)';
        $this->method_description = 'MobilePay Online through Scanpay.';
    }

    public function process_payment($orderid)
    {
        $obj = parent::process_payment($orderid);
        $obj['redirect'] .= '?go=mobilepay';
        return $obj;
    }

    public function add_card_icons($icons, $id)
    {
        if ($id == $this->id && $this->get_option('card_icon') === 'yes') {
            $icons = '<span class="scanpay-cards">';
            $icon_url = WC_HTTPS::force_https_url($this->plugin_dir_url . 'assets/images/mobilepay.svg');
            $icons .= '<img height="21" src="' . $icon_url . '" class="scanpay-mobilepay style="margin: 3px 0 0 5px">';
            $icons .= '</span>';
        }
        return $icons;
    }

    function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable', 'woocommerce-scanpay' ),
                'type'    => 'checkbox',
                'description' => __( 'This controls whether MobilePay is shown in checkout. You MUST enable MobilePay in the Scanpay dashboard for this to work.' ),
                'label'   => __( 'Enable MobilePay', 'woocommerce-scanpay' ),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __( 'Title', 'woocommerce-scanpay' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-scanpay' ),
                'default'     => 'MobilePay',
            ],
            'description' => [
                'title'       => __( 'Description', 'woocommerce-scanpay' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-scanpay' ),
            ],
            'card_icon' => [
                'title' => 'MobilePay Icon',
                'type' => 'checkbox',
                'description' => 'Show MobilePay icon on the checkout page.',
                'default' => 'yes',
            ],
        ];
    }


}
