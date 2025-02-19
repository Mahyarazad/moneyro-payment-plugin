<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


   class WC_Moneyro_Payment_Gateway extends WC_Payment_Gateway {

        protected $payment_gateway_service;
        protected $ui_service;
        protected $order_uid_service;
        protected $admin_field_init_service;
        protected $hmac_secret_key;
        protected $api_key;        
        protected $api_secret;     
        protected $merchant_uid;    
        protected $gateway_baseUrl; 
        protected $gateway_api;   
        
        public function __construct() {
            global $container;
            // Inject Services
            $this->payment_gateway_service = $container->get('payment_gateway_service');
            $this->ui_service = $container->get('ui_service');
            $this->order_uid_service = $container->get('order_uid_service');
            $this->admin_field_init_service = $container->get('admin_field_init_service');


            $this->id                 = 'moneyro_payment_gateway';
            $this->method_title       = __( 'Moneyro Payment Gateway', 'woocommerce' );
            $this->method_description = __( 'Moneyro payment gateway for WooCommerce using the MoneyRo API.', 'woocommerce' );
            $this->has_fields         = false;
                
            // Load the settings.
            $this->form_fields = call_user_func([$this->admin_field_init_service, 'init_form_fields']);
            $this->init_settings();

            // Define user settings variables.
            $this->enabled         = $this->get_option( 'enabled' );
            $this->title           = $this->get_option( 'title' );
            $this->description     = $this->get_option( 'description' );
            $this->hmac_secret_key = $this->get_option( 'hmac_secret_key' );
            $this->api_key         = $this->get_option( 'api_key' );
            $this->api_secret      = $this->get_option( 'api_secret' );
            $this->merchant_uid    = $this->get_option( 'merchant_uid' );
            $this->gateway_baseUrl = $this->get_option( 'gateway_baseUrl' );
            $this->gateway_api     = $this->get_option( 'gateway_api' );
           
            // Save settings in admin
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

             // Register hooks with the services
            add_action('woocommerce_api_' . strtolower($this->id), [$this->payment_service, 'return_from_gateway']);
        
            add_filter('woocommerce_checkout_fields', [$this->national_id_service, 'add_national_id_field']);

            add_action('woocommerce_checkout_update_order_meta', [$this->national_id_service, 'save_billing_national_id'], 10, 2);
        
            add_action('woocommerce_after_checkout_validation', [$this->national_id_service, 'validate_national_id_field'], 10, 2);
        
            add_action('woocommerce_checkout_create_order', [$this->order_uid_service, 'save_order_uid_before_validation'], 10, 2);
        
            add_action('woocommerce_thankyou', [$this->order_uid_service, 'display_payment_id_on_thank_you_page'], 20, 1);
        
            add_action('woocommerce_order_details_after_order_table', [$this->order_uid_service, 'display_order_uid_on_account_page'], 10, 1);
        
            add_action('woocommerce_before_order_pay', [$this->order_uid_service, 'check_and_renew_payment_uid'], 10, 1);
        
            add_action('wp_footer', [$this->national_id_service, 'enqueue_script'], 10, 2);

        }     

        public function process_payment( $order_id ) {
            $payment_params = array(
                'hmac_secret_key'  => $this->hmac_secret_key,
                'api_key'          => $this->api_key,
                'api_secret'       => $this->api_secret,
                'merchant_uid'     => $this->merchant_uid,
                'gateway_baseUrl'  => $this->gateway_baseUrl,
                'gateway_api'      => $this->gateway_api,
                'return_url'       => $this->get_return_url($order),
                'order'            => $this->wc_get_order( $order_id ),
                'order_id'         => $this->wc_get_order( $order_id )
            );
            $this->payment_gateway_service->process_payment($payment_params);
        }

        public function return_from_gateway() {
            $this->payment_gateway_service->return_from_gateway();
        }
        
    }
?>
