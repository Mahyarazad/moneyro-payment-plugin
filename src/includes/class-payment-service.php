<?php

if (!defined('ABSPATH')) {
    exit;
}

class Payment_Service {

    protected $gateway;
    protected $moneyro_api_service;

    public function __construct($gateway) {
        $this->gateway = $gateway;
        $this->moneyro_api_service = new MoneyroAPIService($gateway->getrate_api,$gateway->moneyro_settings_api);
    }

    public function process_payment( $order_id ) {
        try{

            $order = wc_get_order( $order_id );
            $current_time = time();
            $expiration_timestamp = $order->get_meta('_payment_uid_expiration_timestamp');
            $uid = null;
            
            if ($current_time > $expiration_timestamp) {

                $this->gateway->logger->debug('creating new uid payment '.$uid , ['source' => 'moneyro-log']);
                // Save UUID in the order meta
                
                $new_uid = wp_generate_uuid4();
                
                $order->update_meta_data('_order_key', sanitize_text_field($this->generate_transaction_id()));
                // Update UID in the order meta
                $order->update_meta_data('_payment_uid', sanitize_text_field($new_uid));
                // Save _payment_uid_creation_timestamp in the order meta
                $order->update_meta_data('_payment_uid_creation_timestamp', sanitize_text_field(time()));
                // Save _payment_uid_expiration_timestamp in the order meta
                $order->update_meta_data('_payment_uid_expiration_timestamp', sanitize_text_field((time() + (15 * 60))));

                $order->save(); // Save the order with the new metadata
            }
            
            
            $order_national_id = get_post_meta($order_id, '_billing_national_id', true);
            
            if(empty($order_national_id)){
                $this->gateway->logger->debug('National Id not found!!! ', ['source' => 'moneyro-log']);
                wc_add_notice('National Id not found.', 'error');
                return;
            }
            
            
            // Step 1.1: Obtain the token
            $uid =  get_post_meta($order_id, '_payment_uid', true);

            if(empty($uid)){
                $this->gateway->logger->debug('Order UID not found!!! ', ['source' => 'moneyro-log']);
                wc_add_notice('Order UID not found.', 'error');
                return;
            }

            $result = $this->update_order_shipping($order_id);


            $auth_response = wp_remote_post(
                "{$this->gateway->gateway_api}/login_with_password/robot_user/",
                array(
                    'method'  => 'POST',
                    'body'    => json_encode(
                        array(
                            'api_key'    => $this->gateway->api_key,
                            'api_secret' => $this->gateway->api_secret,
                            'uid'        => $uid,
                        )
                    ),
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                )
            );


            if (is_wp_error($auth_response)) {
                wc_add_notice('Failed to authenticate with payment server.', 'error');
                return;
            }
            
            $auth_status_code = wp_remote_retrieve_response_code($auth_response);

            if ($auth_status_code !== 200) {
                
                wc_add_notice('Failed to authenticate with payment server. Status code: ' . $auth_status_code, 'error');
                return;
            }

            $auth_data = json_decode( wp_remote_retrieve_body( $auth_response ), true );
            $token = $auth_data['token'];

            // Step 1.2: Create purchase invoice

            $transaction_id = get_post_meta($order_id, '_order_key', true);

            $this->gateway->logger->debug('uid ' . $uid, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('token ' . $token, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('billing phone ' . $order->get_billing_phone(), ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('currency_received_amount' . $result['currency_received_amount'], ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('user_pay_amount' . $result['user_pay_amount'], ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('get_transaction_id' . $transaction_id, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('callback_url ' . get_site_url() . "/wc-api/" . MONEYRO_PAYMENT_GATEWAY_ID . "?wc_order={$order_id}&token={$token}&payment_uid={$uid}&transaction_id={$transaction_id}", ['source' => 'moneyro-log']);

            $user_data = array(
                'uid'                           => $uid,
                'merchant_uid'                  => $this->gateway->merchant_uid,
                'payment_method'                => 'gateway',
                'currency_symbol'               => 'AED',
                'currency_received_amount'      => $result['currency_received_amount'],
                'user_pay_amount'               => $result['user_pay_amount'],
                'merchant_transaction_number'   => $transaction_id,
                'user_national_code'            => $order_national_id,
                'user_mobile'                   => $order->get_billing_phone(),
                'callback_url'                  => get_site_url() . "/wc-api/" . MONEYRO_PAYMENT_GATEWAY_ID . "?wc_order={$order_id}&token={$token}&payment_uid={$uid}&transaction_id={$transaction_id}"
            );

            $invoice_response = wp_remote_post(
                "{$this->gateway->gateway_api}/purchase_via_rial/invoices/",
                array(
                    'method'  => 'POST',
                    'body'    => json_encode( $user_data ),
                    'headers' => array(
                        'Authorization' => "Bearer {$token}",
                        'Content-Type'  => 'application/json',
                    ),
                )
            );

            if (is_wp_error($invoice_response)) {
                
                wc_add_notice('Failed to get a response from payment server.', 'error');
                $this->gateway->logger->debug('Failed to get a response from payment server. ' . $error_messages, ['source' => 'moneyro-log']);

                return;
            }

            $invoice_status_code = wp_remote_retrieve_response_code($invoice_response);
            $invoice_detail = wp_remote_retrieve_body( $invoice_response );

            if ($invoice_status_code !== 200) {

                $invoice_detail = json_decode($invoice_detail, true);

                if (isset($invoice_detail['detail']) && is_array($invoice_detail['detail'])) {
                    foreach ($invoice_detail['detail'] as $error) {
                        if (isset($error['msg']) && isset($error['input'])) {
                            $msg = esc_html($error['msg']);
                            $input = esc_html($error['input']);
                
                            // Log the extracted values
                            $this->gateway->logger->debug("Error Message: $msg, Input: $input", ['source' => 'moneyro-log']);
                
                            // Optionally display the error on the front-end
                            wc_add_notice("Error: $msg (Input: $input)", 'error');
                            return;
                        }
                    }
                }

                wc_add_notice('Failed to get a response from payment server. Status code: ' . $invoice_status_code . json_encode($invoice_detail), 'error');
                return;
            }
            

            // Step 2: Redirect to payment gateway
            $payment_url = "{$this->gateway->gateway_baseUrl}/invoice-preview/{$uid}/";

            // Mark order as pending payment
            $order->update_status( 'pending', __( 'Awaiting payment.', 'woocommerce' ) );

            $order->save();

            // Unset temporary variables here
            unset($order, $current_time, $expiration_timestamp, $uid, $new_uid, $transaction_id);
            unset($order_national_id, $get_rates, $result, $auth_response, $auth_status_code, $auth_data, $token);
            unset($selling_rate, $user_data, $invoice_response, $invoice_status_code, $invoice_detail);

            // Return thank you page redirect
            return array(
                'result'   => 'success',
                'redirect' => $payment_url,
            );  

        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
    }

    private function update_order_shipping($order_id) {

        $selling_rate = round($this->moneyro_api_service->fetch_currency_rates(),0);

        $order = wc_get_order($order_id);
        $shipping_total = $order->get_shipping_total();
        $subtotal = WC()->cart->get_subtotal();
        
        // Get the total tax amount
        $taxes = WC()->cart->get_taxes_total();
        
        // Calculate the total including tax
        $total_including_tax = $subtotal + $taxes;
        
        $new__total_with_shipment_cost = $total_including_tax * ((100 + $this->gateway->shipment_margin_rate) / 100); 

        $new_total = ceil($new__total_with_shipment_cost);
        $new_total_irr = ceil($new_total * ((100 + $this->gateway->gateway_margin_rate) / 100 ) * $selling_rate) + $this->moneyro_api_service->fetch_purchase_via_rial_initial_fee(); 
        
        $this->gateway->logger->debug('taxes ' . $taxes, ['source' => 'moneyro-log']);
        $this->gateway->logger->debug('subtotal ' . $subtotal, ['source' => 'moneyro-log']);
        $this->gateway->logger->debug('new__total_with_shipment_cost ' . $new__total_with_shipment_cost, ['source' => 'moneyro-log']);
        $this->gateway->logger->debug('new_total: ' . $new_total, ['source' => 'moneyro-log']);
        $this->gateway->logger->debug('new_total_irr ' . $new_total_irr, ['source' => 'moneyro-log']);

        $order->set_shipping_total($new_shipping_total);
        $order->set_total($new_total);
        $order->save();


        return [
            'currency_received_amount' => $new_total,
            'user_pay_amount' => $new_total_irr,
            'selling_rate' => $selling_rate,
        ];
    }  

    private function generate_transaction_id() {
        // Generate a random 4-digit number
        $random_number = mt_rand(1000, 9999);
        
        // Get the current timestamp (to ensure uniqueness)
        $timestamp = time();
        
        // Combine the prefix, timestamp, and random number to form a unique ID
        $transaction_id = "DGLand{$timestamp}{$random_number}";

        return $transaction_id;
    }
    
}