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

    public function return_from_gateway() {
        
        try{
            
            if ( !isset($_GET['wc_order']) || empty($_GET['wc_order']) ) {
                // Remove cart
                WC()->cart->empty_cart();
                $order->update_status('failed', 'Invalid payment UID.');
                wc_add_notice('Order ID is missing or invalid.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
            
            if (!isset($_GET['token']) || empty($_GET['token'])) {
                // Remove cart
                WC()->cart->empty_cart();
                $order->update_status('failed', 'Invalid payment UID.');
                wc_add_notice('Token is missing or invalid.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
            
            
            if (!isset($_GET['payment_uid']) || empty($_GET['payment_uid'])) {
                // Remove cart
                WC()->cart->empty_cart();
                $order->update_status('failed', 'Invalid payment UID.');
                wc_add_notice('Payment UID is missing or invalid.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
            
            $order_id = sanitize_text_field($_GET['wc_order']);
            $token = sanitize_text_field($_GET['token']);
            $payment_uid = sanitize_text_field($_GET['payment_uid']);

            $order = wc_get_order($order_id);
            $order_status = $order->get_status();
            $this->gateway->logger->debug('token' . $token, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('payment_uid' . $payment_uid, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('order_status' . $order_status, ['source' => 'moneyro-log']);

            

            if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
                // Remove cart
                WC()->cart->empty_cart();
                wc_add_notice('Order not found or invalid.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }

           

           $invoice_response = wp_remote_post(
                "{$this->gateway->gateway_api}/purchase_via_rial/invoices/{$payment_uid}",
                array(
                    'method'  => 'GET',
                    'headers' => array(
                        'Authorization' => "Bearer {$token}",
                        'Content-Type'  => 'application/json',
                    ),
                )
            );

            

            if (is_wp_error($invoice_response)) {
                $order->update_status('cancelled', 'Payment failed or canceled.');
                wc_add_notice('Failed to get a response from payment server.', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }

            $invoice_status_code = wp_remote_retrieve_response_code($invoice_response);
            $invoice_detail = wp_remote_retrieve_body( $invoice_response );

            if ($invoice_status_code !== 200) {

                $invoice_detail = json_decode($invoice_detail, true);
            
                $error_message = isset($invoice_detail['detail']) ? esc_html($invoice_detail['detail']) : 'Unknown error';
                $error_type = isset($invoice_detail['type']) ? esc_html($invoice_detail['type']) : 'Error type not specified';
            
                $this->gateway->logger->debug("Error: $error_message, Type: $error_type", ['source' => 'moneyro-log']);
                $order->update_status('cancelled', 'Payment failed or canceled.');
                wc_add_notice("Error: $error_message (Type: $error_type)", 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }


            if ($invoice_status_code === 200) {
                // Decode the JSON response to extract the fields
                $invoice_detail = json_decode($invoice_detail, true);
                $this->gateway->logger->debug('invoice_status_code 200' . json_encode($invoice_detail), ['source' => 'moneyro-log']);

                if ($invoice_detail['filled_at'] === null || $invoice_detail['filled_by'] === null) {
                    $order->update_status('cancelled', 'Payment failed or canceled.');
                    wc_add_notice('Payment was canceled.', 'notice');
                    wp_redirect(wc_get_checkout_url());
                    
                    exit;
                }

                // Extract filled_at and filled_by
                $filled_at = $invoice_detail['filled_at'];
                $filled_by = $invoice_detail['filled_by'];
                
                // Log the extracted values
                $this->gateway->logger->debug("Filled At: $filled_at, Filled By: $filled_by", ['source' => 'moneyro-log']);
            
                wc_reduce_stock_levels( $order_id );
                // Remove cart
                WC()->cart->empty_cart();
                
                $order->payment_complete($transaction_id);
                $order->add_order_note('Payment completed via MoneyRo. Transaction ID: ' . $transaction_id);
                wc_add_notice('Payment successful!', 'success');
                wp_redirect($this->gateway->get_return_url($order));
                exit;
            }
        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
        
    } 
}
?>