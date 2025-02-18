<?php
/**
 * Plugin Name: Moneyro Payment Gateway for WooCommerce
 * Description: Moneyro payment gateway for WooCommerce using the MoneyRo API.
 * Version: 2.0.2
 * Author: Maahyar Azad
 * License: GPL2
 */



// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// Load the Bootstrap file
require_once __DIR__ . '/src/includes/bootstrap.php';

// Initialize the moneyro payment gateway after plugins are loaded.
add_action( 'plugins_loaded', 'moneyro_payment_gateway_init', 11 );

// Add the moneyro payment gateway to WooCommerce.
function moneyro_payment_gateway_init() {
    // Check if WooCommerce is active
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'moneyro_payment_gateway_woocommerce_missing' );
        return;
    }

    // Load the payment gateway class
    require_once plugin_dir_path( __FILE__ ) . 'src/gateway/class-wc-moneyro-payment-gateway.php';

    // Add the payment gateway to WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'add_moneyro_payment_gateway' );
}

function moneyro_payment_gateway_woocommerce_missing() {
    echo '<div class="error"><p><strong>Moneyro Payment Gateway</strong> requires WooCommerce to be installed and activated.</p></div>';
}

function add_moneyro_payment_gateway( $methods ) {
    $methods[] = 'WC_Moneyro_Payment_Gateway';
    return $methods;
}
?>
