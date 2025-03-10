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
            
            if ( !isset($_GET['wc_order']) || empty($_GET['wc_order']) ) {
                // Handle the case where wc_order is not set or is empty
                wc_add_notice('Order ID is missing or invalid.', 'error');
                exit;
            }
            
            if (!isset($_GET['token']) || empty($_GET['token'])) {
                $order->update_status('failed', 'Invalid payment UID.');
                wc_add_notice('Token is missing or invalid.', 'error');
                exit;
            }
            
            
            if (!isset($_GET['payment_uid']) || empty($_GET['payment_uid'])) {
                $order->update_status('failed', 'Invalid payment UID.');
                wc_add_notice('Payment UID is missing or invalid.', 'error');
                //wp_redirect(wc_get_checkout_url());
                exit;
            }
            
            $order_key = sanitize_text_field($_GET['key']);
            $order_id = sanitize_text_field($_GET['wc_order']);
            $token = sanitize_text_field($_GET['token']);
            $payment_uid = sanitize_text_field($_GET['payment_uid']);

            $order = wc_get_order($order_id);
            $order_status = $order->get_status();
            $this->gateway->logger->debug('token' . $token, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('payment_uid' . $payment_uid, ['source' => 'moneyro-log']);

            if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
                wc_add_notice('Order not found or invalid.', 'error');
                exit;
            }

           // Ceck the payment status with moneyro end-point

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
                $this->gateway->logger->debug('Failed to get a response from payment server. ' . $error_messages, ['source' => 'moneyro-log']);
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
                
                exit;
            }


            if ($invoice_status_code === 200) {
                // Decode the JSON response to extract the fields
                $invoice_detail = json_decode($invoice_detail, true);
            

                if ($invoice_detail['filled_at'] === null || $invoice_detail['filled_by'] === null) {
                    $order->update_status('cancelled', 'Payment failed or canceled.');
                    wc_add_notice('Payment was canceled.', 'notice');
                    exit;
                }

                // Extract filled_at and filled_by
                $filled_at = $invoice_detail['filled_at'];
                $filled_by = $invoice_detail['filled_by'];
            
                // Log the extracted values
                $this->gateway->logger->debug("Filled At: $filled_at, Filled By: $filled_by", ['source' => 'moneyro-log']);
            

                wc_reduce_stock_levels( $order_id );

                $order->payment_complete($transaction_id);
                $order->add_order_note('Payment completed via MoneyRo. Transaction ID: ' . $transaction_id);
                wc_add_notice('Payment successful!', 'success');
            }
        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
        
    } 
}
?>