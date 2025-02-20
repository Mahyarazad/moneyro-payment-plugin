<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UIDService {

    protected $logger;

    public function __construct($logger) {
        $this->logger = $logger;
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
                $payment_hash = hash_hmac('sha256', $uid, $this->hmac_secret_key);

                // Save UUID in the order meta
                $order->update_meta_data('_payment_uid', sanitize_text_field($uid));
                // Save UUID in the order meta
                $order->update_meta_data('_payment_hash', sanitize_text_field($payment_hash));
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

    public function display_order_uid_on_account_page($order) {
        // Get the Order UID from the order meta
        $order_uid = get_post_meta($order->get_id(), '_payment_uid', true);
                
        // Check if the UID exists and display it
        if ($order_uid) {
            echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var tableFooter = document.querySelector(".woocommerce-table tfoot");
                        if (tableFooter) {
                            var paymentUidRow = document.createElement("tr");
                            paymentUidRow.innerHTML = `<th scope=\"row\">Payment UID:</th><td>' . esc_html($order_uid) . '</td>`;
                            
                            var totalRow = tableFooter.querySelector("tr:last-child");
                            if (totalRow) {
                                tableFooter.insertBefore(paymentUidRow, totalRow);
                            }
                        }
                    });
                </script>';
        }
    }
}
?>
