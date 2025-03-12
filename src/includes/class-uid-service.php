<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UIDService {

    protected $logger;
    protected $transaction_service;

    public function __construct($logger, $transaction_service) {
        $this->logger = $logger;
        $this->transaction_service = $transaction_service;
    }

    public function check_and_renew_payment_uid($order) {
        try{
            $current_time = time();
            $expiration_timestamp = $order->get_meta('_payment_uid_expiration_timestamp');

            if ($current_time > $expiration_timestamp) {
                // Renew UID and update timestamps
                $new_uid = wp_generate_uuid4(); // Generate a new UID
                $order->update_meta_data('_payment_uid', $new_uid);
                $order->update_meta_data('_payment_uid_creation_timestamp', $current_time);
                $order->update_meta_data('_payment_uid_expiration_timestamp', $current_time + (15 * 60));
                $order->save();

                $this->logger->info("Payment UID renewed for order {$order->get_id()}", ['source' => 'moneyro-log']);
            }
        }catch (Exception $e){
            $this->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }    
    }

    public function save_order_uid_before_validation($order, $data) {
        try{
            if (MONEYRO_PAYMENT_GATEWAY_ID === WC()->session->get('chosen_payment_method')) {
                // Generate UUID for this session
                $uid = wp_generate_uuid4();
                // Generate HMAC 
                $order->update_meta_data('_order_key', sanitize_text_field($this->transaction_service->generate_transaction_id()));
                // Save UUID in the order meta
                $order->update_meta_data('_payment_uid', sanitize_text_field($uid));
                // Save _payment_uid_creation_timestamp in the order meta
                $order->update_meta_data('_payment_uid_creation_timestamp', sanitize_text_field(time()));
                // Save _payment_uid_expiration_timestamp in the order meta
                $order->update_meta_data('_payment_uid_expiration_timestamp', sanitize_text_field((time() + (15 * 60))));

                $order->save(); // Save the order with the new metadata

                $this->logger->info('Updating _payment_uid from save_order_uid_before_validation' . $uid, ['source' => 'moneyro-log']);
            }
                    
        }catch (Exception $e){
            $this->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        } 
    }
   
}
?>
