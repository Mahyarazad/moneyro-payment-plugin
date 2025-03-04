<?php

if (!defined('ABSPATH')) {
    exit;
}

class Payment_Service {
    protected $gateway;


    public function __construct($gateway) {
        $this->gateway = $gateway;
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
                $new_payment_hash = hash_hmac('sha256', $new_uid, $this->hmac_secret_key);

                
                $order->update_meta_data('_transaction_id', sanitize_text_field($new_payment_hash));
                // Update UID in the order meta
                $order->update_meta_data('_payment_uid', sanitize_text_field($new_uid));
                // Update UID in the order meta
                $order->update_meta_data('_payment_hash', sanitize_text_field($new_payment_hash));
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
                //  wc_clear_notices();
                return;
            }
            
            
            // Step 1.1: Obtain the token
            $uid =  get_post_meta($order_id, '_payment_uid', true);
            $payment_hash =  get_post_meta($order_id, '_payment_hash', true);

            if(empty($uid)){
                $this->gateway->logger->debug('Order UID not found!!! ', ['source' => 'moneyro-log']);
                wc_add_notice('Order UID not found.', 'error');
                //wc_clear_notices();
                return;
            }



            $get_rates = wp_remote_get(
                $this->gateway->getrate_api
            );

            if ( is_wp_error( $get_rates ) ) {
                wc_add_notice('Failed to get current rates from payment server.', 'error');
                //wc_clear_notices();
                return;
            }

            $result = $this->update_order_shipping($order_id, $get_rates);


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
                //wc_clear_notices();
                return;
            }
            
            $auth_status_code = wp_remote_retrieve_response_code($auth_response);

            if ($auth_status_code !== 200) {
                
                wc_add_notice('Failed to authenticate with payment server. Status code: ' . $auth_status_code, 'error');
                wc_clear_notices();
                return;
            }

            $auth_detail = json_decode( wp_remote_retrieve_body( $auth_response ), true );
            $auth_detail = $auth_detail['detail'];

            if ($auth_status_code === 400) {
                wc_add_notice( $auth_detail . ' Status code: ' . $auth_status_code, 'error' );
                //wc_clear_notices();
                return;
            }

            $auth_data = json_decode( wp_remote_retrieve_body( $auth_response ), true );

            $token = $auth_data['token'];
        

            // Step 1.2: Create purchase invoice

            $user_pay_amount = $result['new_total'];
            $selling_rate = $result['selling_rate'];

            $this->gateway->logger->debug('uid ' . $uid, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('token ' . $token, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('billing phone ' . $order->get_billing_phone(), ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('total amount ' . $user_pay_amount, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('total amount IRR ' . intval($user_pay_amount * $selling_rate), ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('get_transaction_id' . $this->generate_transaction_id(), ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('callback_url ' . get_site_url() . "/wc-api/" . MONEYRO_PAYMENT_GATEWAY_ID . "?wc_order={$order_id}&status=success&payment_hash={$payment_hash}", ['source' => 'moneyro-log']);


            $user_data = array(
                'uid'                           => $uid,
                'merchant_uid'                  => $this->gateway->merchant_uid,
                'payment_method'                => 'gateway',
                'currency_symbol'               => 'AED',
                'currency_received_amount'      => round($user_pay_amount, 1),
                'user_pay_amount'               => round($user_pay_amount * $selling_rate, 1),
                'merchant_transaction_number'   => $this->generate_transaction_id(),
                'user_national_code'            => $order_national_id,
                'user_mobile'                   => $order->get_billing_phone(),
                'callback_url'                  => get_site_url() . "/wc-api/" . MONEYRO_PAYMENT_GATEWAY_ID . "?wc_order={$order_id}&status=success&payment_hash={$payment_hash}"
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
                
                //wc_clear_notices();
                return;
            }

            $invoice_status_code = wp_remote_retrieve_response_code($invoice_response);
            $invoice_body = wp_remote_retrieve_body( $invoice_response );
            
            if ($invoice_status_code !== 200) {
                $this->gateway->logger->debug('Failed to get a response from payment server. ' . $invoice_body, ['source' => 'moneyro-log']);
                wc_add_notice('Failed to get a response from payment server. Status code: ' . $invoice_status_code . $invoice_body, 'error');
                //wc_clear_notices();
                return;
            }

            $invoice_detail = json_decode( wp_remote_retrieve_body( $invoice_response ), true );
            $invoice_detail = $invoice_detail['detail'];
            
            if ($auth_status_code === 400) {
                wc_add_notice( $invoice_detail . ' Status code: ' . $invoice_status_code, 'error' );
                //wc_clear_notices();
                return;
            }

            $invoice_data = json_decode( wp_remote_retrieve_body( $invoice_response ), true );

            // Step 2: Redirect to payment gateway
            $payment_url = "{$this->gateway->gateway_baseUrl}/invoice-preview/{$uid}/";
            // $order->update_meta_data( '_payment_method', MONEYRO_PAYMENT_GATEWAY_ID ); // 'moneyro'
            // $order->update_meta_data( '_payment_method_title', MONEYRO_PAYMENT_GATEWAY_ID );
            $order->save();

            // Mark order as pending payment
            $order->update_status( 'pending', __( 'Awaiting payment.', 'woocommerce' ) );



            // Remove cart
            WC()->cart->empty_cart();



            // Unset temporary variables
            unset($invoice_detail, $invoice_response, $invoice_status_code, $user_data, $auth_data, $auth_detail, $auth_response, $payment_hash, $token, $order_national_id);

            // Return thank you page redirect
            return array(
                'result'   => 'success',
                'redirect' => $payment_url,
            );  
        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
    }

    private function update_order_shipping($order_id, $get_rates) {
        $rates_detail = json_decode(wp_remote_retrieve_body($get_rates), true);
        $selling_rate = $rates_detail['AED']['when_selling_currency_to_user']['change_in_rial'];
    

        $order = wc_get_order($order_id);
        $shipping_total = $order->get_shipping_total();
        $subtotal = WC()->cart->get_subtotal();
        
        // Get the total tax amount
        $taxes = WC()->cart->get_taxes_total();
        
        // Calculate the total including tax
        $total_including_tax = $subtotal + $taxes;
        $margin_to_be_added = $this->gateway->shipment_margin_rate + $this->gateway->gateway_margin_rate ;
        $new_shipping_total = $total_including_tax * ($margin_to_be_added / 100); 
        $new_total = ceil($total_including_tax * ((100 + $margin_to_be_added) / 100) * $selling_rate) + 20000; 
        $new_total = ceil($new_total/ $selling_rate);
        
        $this->gateway->logger->debug('new_shipping_total ' . $new_shipping_total, ['source' => 'moneyro-log']);
        $this->gateway->logger->debug('new_total: ' . $new_total, ['source' => 'moneyro-log']);


        
        $order->set_shipping_total($new_shipping_total);
        $order->set_total($new_total);
        $order->save();


        return [
            'new_total' => $new_total,
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