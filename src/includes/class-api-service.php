<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class API_Service {

    protected $gateway;

    public function __construct($gateway) {
        $this->gateway = $gateway;
    }
    
    /**
     * Handle the callback from the payment gateway.
     */

    //  http://localhost/digi/wc-api/moneyro_payment_gateway?wc_order=4129&status=success&payment_hash=751e1f03ddb1d4fddae87747a849611bced1f219f61287fd4d40a2922383c2e0
    //  
    public function return_from_gateway() {
        $this->gateway->logger->debug('return_from_gateway_trigered' , ['source' => 'moneyro-log']);
        try{
            
            if (isset($_GET['wc_order']) && !empty($_GET['wc_order'])) {
                $order_id = sanitize_text_field($_GET['wc_order']);
                $order = wc_get_order($order_id);
                $payment_uid = $order->get_meta('_payment_uid');
                $transaction_id = $order->get_meta('_order_key');
                $order_key = sanitize_text_field($_GET['key']);

                if ($order) {
                    $payment_status = sanitize_text_field($_GET['status']); // Payment status from gateway
                    $payment_hash = sanitize_text_field($_GET['payment_hash']); // Transaction ID from gateway
                    $national_id = get_post_meta($order_id, '_billing_national_id', true);
                    $hash_value = hash_hmac('sha256', $payment_uid, $this->hmac_secret_key);

                    if (!isset($_GET['payment_hash']) || empty($_GET['payment_hash']))
                    {
                        $order->update_status('failed', 'Payment failed or canceled.');
                        wc_add_notice('Payment failed. Please try again.', 'error');
                    }

                    if ($payment_status === 'success' && $payment_hash === $hash_value) {

                        // Reduce stock levels
                        wc_reduce_stock_levels( $order_id );

                        $order->payment_complete($transaction_id);
                        $order->add_order_note('Payment completed via MoneyRo. Transaction ID: ' . $transaction_id);
                        wc_add_notice('Payment successful!', 'success');

                    } else {
                        $order->update_status('failed', 'Payment failed or canceled.');
                        wc_add_notice('Payment failed. Please try again.', 'error');
                    }
                    wc_clear_notices();
                    // Redirect to order confirmation page
                    wp_redirect($this->gateway->get_return_url($order));
                    exit;
                }
            }
        
            wc_add_notice('Order not found or invalid.', 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
        
    } 
}
?>