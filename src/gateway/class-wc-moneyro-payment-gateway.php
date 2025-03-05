<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Moneyro_Payment_Gateway extends WC_Payment_Gateway {

    protected $ui_service;
    protected $order_uid_service;
    protected $admin_field_init_service;
    protected $payment_service;
    protected $api_service;

    public function __construct($container) {
        // Inject Services
        $this->logger = $container->get('logger');
        $this->order_uid_service = $container->get('order_uid_service');
        $this->admin_field_init_service = $container->get('admin_field_init_service');

        $this->id                 = MONEYRO_PAYMENT_GATEWAY_ID;
        $this->method_title       = __('Moneyro Payment Gateway', 'woocommerce');
        $this->method_description = __('Moneyro payment gateway for WooCommerce using the MoneyRo API.', 'woocommerce');
        $this->has_fields         = false;

        // Load the settings.
        $this->form_fields = call_user_func([$this->admin_field_init_service, 'init_form_fields']);
        $this->init_settings();

        // Define user settings variables.
        $this->enabled                  = $this->get_option('enabled');
        $this->title                    = $this->get_option('title');
        $this->description              = $this->get_option('description');
        $this->hmac_secret_key          = $this->get_option('hmac_secret_key');
        $this->api_key                  = $this->get_option('api_key');
        $this->api_secret               = $this->get_option('api_secret');
        $this->merchant_uid             = $this->get_option('merchant_uid');
        $this->gateway_baseUrl          = $this->get_option('gateway_baseUrl');
        $this->gateway_api              = $this->get_option('gateway_api');
        $this->getrate_api              = $this->get_option('getrate_api');
        $this->shipment_margin_rate     = $this->get_option('shipment_margin_rate');
        $this->gateway_margin_rate      = $this->get_option('gateway_margin_rate');
        $this->moneyro_settings_api     = $this->get_option('moneyro_settings_api');

        // Initialize Payment Service
        $this->payment_service = new Payment_Service($this);
        $this->ui_service = new UIService($this);
        $this->api_service = new API_Service($this);

        // Save settings in admin
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        // API
        add_action('woocommerce_api_' . strtolower($this->id), [$this->api_service, 'return_from_gateway']);

        add_action('woocommerce_api_moneyro_update_shipping_cost', [$this->api_service,'moneyro_update_shipping_cost_handler']);

        // filters
        add_filter('woocommerce_checkout_fields', [$this->ui_service, 'add_national_id_field']);        
        
        add_action('woocommerce_admin_order_data_after_shipping_address', [$this->ui_service, 'add_custom_order_data']);

        // other actions
        add_action('woocommerce_checkout_update_order_meta', [$this->ui_service, 'save_billing_national_id'], 10, 2);
        
        add_action('woocommerce_after_checkout_validation', [$this->ui_service, 'validate_national_id_field'], 10, 2);
        
        add_action('woocommerce_checkout_create_order', [$this->order_uid_service, 'save_order_uid_before_validation'], 10, 2);
        
        add_action('woocommerce_thankyou', [$this->ui_service, 'display_payment_id_on_thank_you_page'], 10, 1);
        
        add_action('woocommerce_order_details_after_order_table', [$this->order_uid_service, 'display_order_uid_on_account_page'], 10, 1);
        
        add_action('woocommerce_before_order_pay', [$this->order_uid_service, 'check_and_renew_payment_uid'], 10, 1);
     
        add_action('wp_footer', [$this->ui_service, 'enqueue_script'], 10, 2);
        
    }
    
    public function process_payment($order_id) {
        return $this->payment_service->process_payment($order_id);
    }

    public function return_from_gateway() {
        return $this->api_service->return_from_gateway();
    }
}