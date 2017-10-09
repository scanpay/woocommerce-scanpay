<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_Scanpay_Mobilepay extends WC_Scanpay_Parent
{
    public function __construct()
    {
        parent::__construct();
        $this->id = 'scanpay_mobilepay';
        $this->setup();
        $this->method_title = 'Scanpay - MobilePay';
    }

    public function process_payment($orderid)
    {
        $obj = parent::process_payment($orderid);
        return [
            'result' => 'success',
            'redirect' => $paymenturl . '?go=mobilepay',
        ];
    }

    function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Enable/Disable', 'woocommerce-scanpay' ),
                'type'    => 'checkbox',
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
        ];
    }


}
