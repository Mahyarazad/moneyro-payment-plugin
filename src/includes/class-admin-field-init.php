<?php

class AdminService{

	public function init_form_fields(): array {
        return [
            'enabled' => [
                'title'       => __( 'Enable/Disable', 'woocommerce' ),
                'label'       => __( 'Enable Moneyro Payment Gateway', 'woocommerce' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ],
            'title' => [
                'title'       => __( 'Title', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Title displayed to customers during checkout.', 'woocommerce' ),
                'default'     => __( 'Moneyro Payment', 'woocommerce' ),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __( 'Description', 'woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'Description displayed to customers during checkout.', 'woocommerce' ),
                'default'     => __( 'Pay securely using our custom payment gateway.', 'woocommerce' ),
            ],
            'gateway_margin_rate' => [
                'title'       => __( 'Gateway Margin Rate', 'woocommerce' ),
                'type'        => 'number', 
                'description' => __( 'Enter a margin rate as an integer. This rate will be multiplied by the total cost. Use a whole number, for example, 10 for 10% margin.', 'woocommerce' ),
                'default'     => '1',
                'custom_attributes' => [
                    'min' => '0', 
                ],
            ],
            'shipment_margin_rate' => [
                'title'       => __( 'Shipment Margin Rate', 'woocommerce' ),
                'type'        => 'number', 
                'description' => __( 'Enter a margin rate as an integer. This rate will be used to calculate the shipping cost. Use a whole number, for example, 10 for 10% margin.', 'woocommerce' ),
                'default'     => '',
                'custom_attributes' => [
                    'min' => '0', 
                ],
            ],
            'hmac_secret_key' => [
                'title'       => __( 'HMAC Key', 'woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Enter a secure HMAC key used for hashing and verifying data integrity. This key should be a long, randomly generated string to enhance security. Avoid using simple or guessable values. Recommended length: at least 32 characters.', 'woocommerce' ),
                'default'     => '',
            ],
            'api_key' => [
                'title'       => __( 'API Key', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your API Key from MoneyRo.', 'woocommerce' ),
                'default'     => '',
            ],
            'api_secret' => [
                'title'       => __( 'API Secret', 'woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your API Secret from MoneyRo.', 'woocommerce' ),
                'default'     => '',
            ],
            'merchant_uid' => [
                'title'       => __( 'Merchant UID', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Your Merchant UID from MoneyRo.', 'woocommerce' ),
                'default'     => '',
            ],
            'gateway_baseUrl' => [
                'title'       => __( 'Gateway Base URL', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Base URL for the Payment URL.', 'woocommerce' ),
                'default'     => '',
            ],
            'gateway_api' => [
                'title'       => __( 'Gateway API URL', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Base URL for the MoneyRo API.', 'woocommerce' ),
                'default'     => '',
            ],
            'getrate_api' => [
                'title'       => __( 'Rate API URL', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Base URL for the MoneyRo Rate API.', 'woocommerce' ),
                'default'     => '',
            ],
            'moneyro_settings_api' => [
                'title'       => __( 'Moneyro Settings API URL', 'woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Base URL for the MoneyRo Rate API.', 'woocommerce' ),
                'default'     => '',
            ],
        ];
    }
}