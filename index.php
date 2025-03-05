<?php
/**
 * Plugin Name: Moneyro Payment Gateway for WooCommerce
 * Description: Moneyro payment gateway for WooCommerce using the MoneyRo API.
 * Version: 2.11.0
 * Author: Maahyar Azad
 * License: GPL2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Show WooCommerce Missing Notice
function moneyro_payment_gateway_woocommerce_missing() {
    echo '<div class="error"><p><strong>Moneyro Payment Gateway</strong> requires WooCommerce to be installed and activated.</p></div>';
}

define('MONEYRO_PAYMENT_GATEWAY_ID', 'moneyro_payment_gateway');

// Initialize the Moneyro Payment Gateway
add_action('plugins_loaded', 'moneyro_payment_gateway_init', 11);

function moneyro_payment_gateway_init() {
    // Check if WooCommerce is active
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'moneyro_payment_gateway_woocommerce_missing');
        return;
    }

    // Include necessary files
    require_once __DIR__ . '/src/includes/class-container.php';
    require_once __DIR__ . '/src/includes/class-admin-field-init.php';
    require_once __DIR__ . '/src/includes/class-payment-service.php';
    require_once __DIR__ . '/src/includes/class-api-service.php';
    require_once __DIR__ . '/src/includes/class-ui-service.php';
    require_once __DIR__ . '/src/includes/class-uid-service.php';
    require_once __DIR__ . '/src/includes/class-transaction-id-service.php';
    require_once __DIR__ . '/src/gateway/class-wc-moneyro-payment-gateway.php';

    // Instantiate the Dependency Injection (DI) container
    global $container;

    $container = new DIContainer();

    // Register services in the container
    $container->set('logger', wc_get_logger());
    $container->set('transaction-service', new TransactionService());
    $container->set('admin_field_init_service', new AdminService());
    // $container->set('ui_service', new UIService($container->get('logger')));
    $container->set('order_uid_service', new UIDService($container->get('logger'), $container->get('transaction-service')));


    // Add the payment gateway to WooCommerce with DI container
    add_filter('woocommerce_payment_gateways', function ($methods) use ($container) {
        $methods[] = new WC_Moneyro_Payment_Gateway($container);
        return $methods;
    });
}
?>
