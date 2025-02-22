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
                wc_clear_notices();
                return;
            }
            
            
            // Step 1.1: Obtain the token
            $uid =  get_post_meta($order_id, '_payment_uid', true);
            $payment_hash =  get_post_meta($order_id, '_payment_hash', true);

            if(empty($uid)){
                $this->gateway->logger->debug('Order UID not found!!! ', ['source' => 'moneyro-log']);
                wc_add_notice('Order UID not found.', 'error');
                wc_clear_notices();
                return;
            }



            $get_rates = wp_remote_get(
                $this->gateway->getrate_api
            );

            if ( is_wp_error( $get_rates ) ) {
                wc_add_notice('Failed to get current rates from payment server.', 'error');
                wc_clear_notices();
                return;
            }

            $this->update_order_shipping($order, $get_rates);
            return; 

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
                wc_clear_notices();
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
                wc_clear_notices();
                return;
            }

            $auth_data = json_decode( wp_remote_retrieve_body( $auth_response ), true );

            $token = $auth_data['token'];
        

            // Step 1.2: Create purchase invoice
            $user_pay_amount = intval($order->get_total()); // Total order value

            $this->gateway->logger->debug('uid ' . $uid, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('token ' . $token, ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('billing phone ' . $order->get_billing_phone(), ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('total amount ' . intval($order->get_total() * $selling_rate), ['source' => 'moneyro-log']);
            $this->gateway->logger->debug('callback_url ' . get_site_url() . "/wc-api/" . MONEYRO_PAYMENT_GATEWAY_ID . "?wc_order={$order_id}&status=success&payment_hash={$payment_hash}", ['source' => 'moneyro-log']);


            $user_data = array(
                'uid'                      => $uid,
                'merchant_uid'             => $this->gateway->merchant_uid,
                'currency_symbol'          => 'TRY',
                'currency_received_amount' => 1,
                'user_national_code'       => $order_national_id,
                'user_pay_amount'          => intval($order->get_total() * $selling_rate),
                'user_mobile'              => $order->get_billing_phone(),
                'callback_url'             => get_site_url() . "/wc-api/" . MONEYRO_PAYMENT_GATEWAY_ID . "?wc_order={$order_id}&status=success&payment_hash={$payment_hash}"
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
                wc_clear_notices();
                return;
            }

            $invoice_status_code = wp_remote_retrieve_response_code($invoice_response);
            //$decoded_response = json_decode($invoice_response, true);
            
            if ($invoice_status_code !== 200) {
                //$this->gateway->logger->error('API error: ' . $decoded_response, ['source' => 'moneyro-log']);
                wc_add_notice('Failed to get a response from payment server. Status code: ' . $invoice_status_code, 'error');
                wc_clear_notices();
                return;
            }

            $invoice_detail = json_decode( wp_remote_retrieve_body( $invoice_response ), true );
            $invoice_detail = $invoice_detail['detail'];
            
            if ($auth_status_code === 400) {
                wc_add_notice( $invoice_detail . ' Status code: ' . $invoice_status_code, 'error' );
                wc_clear_notices();
                return;
            }

            $invoice_data = json_decode( wp_remote_retrieve_body( $invoice_response ), true );

            // Step 2: Redirect to payment gateway
            $payment_url = "{$this->gateway->gateway_baseUrl}/invoice-preview/{$uid}/";

            // Mark order as pending payment
            $order->update_status( 'pending', __( 'Awaiting payment.', 'woocommerce' ) );

            // Reduce stock levels
            wc_reduce_stock_levels( $order_id );

            // Remove cart
            WC()->cart->empty_cart();


            unset($invoice_detail);
            unset($invoice_response);
            unset($invoice_status_code);
            unset($user_data);
            unset($auth_data);
            unset($auth_detail);
            unset($auth_response);
            unset($payment_hash);
            unset($token);
            unset($order);

            // Return thank you page redirect
            return array(
                'result'   => 'success',
                'redirect' => $payment_url,
            );  
        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
    }

    private function update_order_shipping($order, $get_rates) {
        $rates_detail = json_decode(wp_remote_retrieve_body($get_rates), true);
        $selling_rate = $rates_detail['AED']['when_selling_currency_to_user']['change_in_rial'];
    
        $this->gateway->logger->debug('rates ' . $selling_rate, ['source' => 'moneyro-log']);
    
        $total = $order->get_total();
        $shipping_total = $order->get_shipping_total();
    
        $this->gateway->logger->debug('total ' . $total, ['source' => 'moneyro-log']);
        $this->gateway->logger->debug('shipping_total ' . $shipping_total, ['source' => 'moneyro-log']);
    
        $new_shipping_total = $total * 0.1; 
        $new_total = $total + $new_shipping_total; 
    
        $order->set_shipping_total($new_shipping_total);
        $order->set_total($new_total);
        $order->save();
    }  
}