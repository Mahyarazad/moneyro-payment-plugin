<?php
namespace MoneyroPaymentPlugin;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
interface IWC_Moneyro_Payment {
    public function process_payment($order_id);
    public function return_from_gateway();
}
class WC_Moneyro_Payment {

    public function __construct($gateway_api) {
        $this->logger = wc_get_logger();
        $this->gateway_api = $gateway_api;
    }

    public function process_payment($order_id) {
        $order = wc_get_order( $order_id );
                $current_time = time();
                $expiration_timestamp = $order->get_meta('_payment_uid_expiration_timestamp');
                $uid = null;

                if ($current_time > $expiration_timestamp) {

                    $this->logger->info('creating new uid payment '.$uid , ['source' => 'moneyro-log']);
                    // Save UUID in the order meta
                    $order->update_meta_data('_payment_uid', sanitize_text_field(wp_generate_uuid4()));
                    // Save _payment_uid_creation_timestamp in the order meta
                    $order->update_meta_data('_payment_uid_creation_timestamp', sanitize_text_field(time()));
                    // Save _payment_uid_expiration_timestamp in the order meta
                    $order->update_meta_data('_payment_uid_expiration_timestamp', sanitize_text_field((time() + (15 * 60))));

                    $order->save(); // Save the order with the new metadata
                }
                
                
                $order_national_id = get_post_meta($order_id, '_billing_national_id', true);
                
                if(empty($order_national_id)){
                    $this->logger->info('National Id not found!!! ', ['source' => 'moneyro-log']);
                    wc_add_notice('National Id not found.', 'error');
                    return;
                }
                
                
                // Step 1.1: Obtain the token
                $uid =  get_post_meta($order_id, '_payment_uid', true);

                if(empty($uid)){
                    $this->logger->info('Order UID not found!!! ', ['source' => 'moneyro-log']);
                    wc_add_notice('Order UID not found.', 'error');
                    return;
                }


                $auth_response = wp_remote_post(
                    "{$this->gateway_api}/login_with_password/robot_user/",
                    array(
                        'method'  => 'POST',
                        'body'    => json_encode(
                            array(
                                'api_key'    => $this->api_key,
                                'api_secret' => $this->api_secret,
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

                $auth_detail = json_decode( wp_remote_retrieve_body( $auth_response ), true );
                $auth_detail = $auth_detail['detail'];

                if ($auth_status_code === 400) {
                    wc_add_notice( $auth_detail . ' Status code: ' . $auth_status_code, 'error' );
                    return;
                }

                $auth_data = json_decode( wp_remote_retrieve_body( $auth_response ), true );

                $token = $auth_data['token'];
            

                // Step 1.2: Create purchase invoice
                $user_pay_amount = intval($order->get_total()); // Total order value

                $this->logger->info('uid ' . $uid, ['source' => 'moneyro-log']);
                $this->logger->info('token ' . $token, ['source' => 'moneyro-log']);
                $this->logger->info('billing phone ' . $order->get_billing_phone(), ['source' => 'moneyro-log']);
                $this->logger->info('total amount ' . intval($user_pay_amount * 245000), ['source' => 'moneyro-log']);


                $user_data = array(
                    'uid'                      => $uid,
                    'merchant_uid'             => $this->merchant_uid,
                    'currency_symbol'          => 'TRY',
                    'currency_received_amount' => 1,
                    'user_national_code'       => $order_national_id,
                    'user_pay_amount'          => intval($user_pay_amount * 245000),
                    'user_mobile'              => $order->get_billing_phone(),
                );

                $invoice_response = wp_remote_post(
                    "{$this->gateway_api}/purchase_via_rial/invoices/",
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
                    return;
                }

                $invoice_status_code = wp_remote_retrieve_response_code($invoice_response);
                //$decoded_response = json_decode($invoice_response, true);
                
                if ($invoice_status_code !== 200) {
                    //$this->logger->error('API error: ' . $decoded_response, ['source' => 'moneyro-log']);
                    wc_add_notice('Failed to get a response from payment server. Status code: ' . $invoice_status_code, 'error');
                    return;
                }

                $invoice_detail = json_decode( wp_remote_retrieve_body( $invoice_response ), true );
                $invoice_detail = $invoice_detail['detail'];
                
                if ($auth_status_code === 400) {
                    wc_add_notice( $invoice_detail . ' Status code: ' . $invoice_status_code, 'error' );
                    return;
                }

                $invoice_data = json_decode( wp_remote_retrieve_body( $invoice_response ), true );

                // Step 2: Redirect to payment gateway
                $payment_url = "{$this->gateway_baseUrl}/invoice-preview/{$uid}/";

                // Mark order as pending payment
                $order->update_status( 'pending', __( 'Awaiting payment.', 'woocommerce' ) );

                // Reduce stock levels
                wc_reduce_stock_levels( $order_id );

                // Remove cart
                WC()->cart->empty_cart();

                // Return thank you page redirect
                return array(
                    'result'   => 'success',
                    'redirect' => $payment_url,
                );  
    }

    public function return_from_gateway() {
        if (isset($_GET['wc_order']) && !empty($_GET['wc_order'])) {
            $order_id = sanitize_text_field($_GET['wc_order']);
            $order = wc_get_order($order_id);
    
            if ($order) {
                $payment_status = sanitize_text_field($_GET['status']); // Payment status from gateway
                $transaction_id = sanitize_text_field($_GET['transaction_id']); // Transaction ID from gateway
                $national_id = get_post_meta($order_id, '_billing_national_id', true);

                if ($payment_status === 'success') {
                    $order->payment_complete($transaction_id);
                    $order->add_order_note('Payment completed via MoneyRo. Transaction ID: ' . $transaction_id);
                    wc_add_notice('Payment successful!', 'success');

                } else {
                    $order->update_status('failed', 'Payment failed or canceled.');
                    wc_add_notice('Payment failed. Please try again.', 'error');
                }
    
                // Redirect to order confirmation page
                wp_redirect($this->get_return_url($order));
                exit;
            }
        }
    
        wc_add_notice('Order not found or invalid.', 'error');
        wp_redirect(wc_get_checkout_url());
        exit;
    }
}
?>
