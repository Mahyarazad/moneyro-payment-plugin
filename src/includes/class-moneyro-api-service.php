<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MoneyroAPIService{

    protected $getrate_api;
    protected $moneyro_settings_api;

    public function __construct($getrate_api, $moneyro_settings_api) {

        $this->getrate_api = $getrate_api;
        $this->moneyro_settings_api = $moneyro_settings_api;
    }

    public function fetch_currency_rates() {

        $response = wp_remote_get($this->getrate_api);

        if (is_wp_error($response)) {
            wp_send_json_error(array("error" => "Failed to fetch data."));
        }
    
        $body = wp_remote_retrieve_body($response);

        $currency_data = json_decode($body, true);

        if (isset($currency_data['AED'])) {
            return $currency_data['AED']['when_selling_currency_to_user']['change_in_rial'];        
        } 

        return "";
    }

    public function fetch_purchase_via_rial_initial_fee() {

        $response = wp_remote_get($this->moneyro_settings_api);
    
        if (is_wp_error($response)) {
            wp_send_json_error(array("error" => "Failed to fetch data."));
        }
    
        $body = wp_remote_retrieve_body($response);
    
        $settings_data = json_decode($body, true);
    
        foreach ($settings_data['results'] as $setting) {
            if ($setting['setting_key'] === 'purchase_via_rial_initial_fee') {
                return $setting['setting_value'];
            }
        }

        return "";
    }
}

?>