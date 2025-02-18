<?php

namespace MoneyroPaymentPlugin\gateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Use continer to get services
use DI\Container;

class WC_Moneyro_Payment_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $container = require plugin_dir_path(__FILE__) . 'includes/container.php';
        $vehicle = $container->get(IVehicle::class);

        $this->id                 = 'moneyro_payment_gateway';
        $this->method_title       = __( 'Moneyro Payment Gateway', 'woocommerce' );
        $this->method_description = __( 'Moneyro payment gateway for WooCommerce using the MoneyRo API.', 'woocommerce' );
        $this->has_fields         = false;

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user settings variables
        $this->enabled         = $this->get_option( 'enabled' );
        $this->title           = $this->get_option( 'title' );
        $this->description     = $this->get_option( 'description' );
        $this->api_key         = $this->get_option( 'api_key' );
        $this->api_secret      = $this->get_option( 'api_secret' );
        $this->merchant_uid    = $this->get_option( 'merchant_uid' );
        $this->gateway_baseUrl = $this->get_option( 'gateway_baseUrl' );
        $this->gateway_api     = $this->get_option( 'gateway_api' );

        // Save settings in admin
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Payment listener/API hook
        add_action( 'woocommerce_api_' . strtolower( $this->id ), array( $this, 'return_from_gateway' ) );

        // Hooks for National ID Field
        add_filter('woocommerce_checkout_fields', array('WC_Moneyro_National_ID', 'add_national_id_field'));
        add_action('woocommerce_checkout_update_order_meta', array('WC_Moneyro_National_ID', 'save_billing_national_id'), 10, 2);
        add_action('woocommerce_after_checkout_validation', array('WC_Moneyro_National_ID', 'validate_national_id_field'), 10, 2);
        add_action('woocommerce_checkout_create_order', array('WC_Moneyro_UID', 'save_order_uid_before_validation'), 10, 2);
        add_action('woocommerce_thankyou', array('WC_Moneyro_National_ID', 'display_national_id_on_thank_you_page'), 20, 1);
        add_action('woocommerce_order_details_after_order_table', array('WC_Moneyro_UID', 'display_order_uid_on_account_page'), 10, 1);
        add_action('woocommerce_before_order_pay', array('WC_Moneyro_UID','check_and_renew_payment_uid'), 10, 1);
        add_action('wp_footer', array('WC_Moneyro_National_ID','enqueue_script'), 10, 2);
    }

    // Function signatures for all other methods
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable Moneyro Payment Gateway', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Title displayed to customers during checkout.', 'woocommerce' ),
                'default'     => __( 'Moneyro Payment', 'woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Description displayed to customers during checkout.', 'woocommerce' ),
                'default'     => __( 'Pay securely using our custom payment gateway.', 'woocommerce' ),
            ),
            'api_key' => array(
                'title'       => __( 'API Key', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your API Key from MoneyRo.', 'woocommerce' ),
                'default'     => '',
            ),
            'api_secret' => array(
                'title'       => __( 'API Secret', 'woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your API Secret from MoneyRo.', 'woocommerce' ),
                'default'     => '',
            ),
            'merchant_uid' => array(
                'title'       => __( 'Merchant UID', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your Merchant UID from MoneyRo.', 'woocommerce' ),
                'default'     => '',
            ),
            'gateway_baseUrl' => array(
                'title'       => __( 'Gateway Base URL', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Base URL for the Payment URL.', 'woocommerce' ),
                'default'     => '',
            ),
            'gateway_api' => array(
                'title'       => __( 'Gateway API URL', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Base URL for the MoneyRo API.', 'woocommerce' ),
                'default'     => '',
            ),
        );
     }
    public function process_admin_options() { 
        
    }
    public function process_payment($order_id) {
        return $this->payment_handler->process_payment($order_id);
     }
    public function return_from_gateway() { }
}
?>
