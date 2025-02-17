<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Moneyro_UID {

    public function __construct() {
        $this->logger = wc_get_logger();
    }

    public static function check_and_renew_payment_uid($order) {
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
    }

    public function save_order_uid_before_validation($order, $data) {
        // Generate UUID for this session
        $uid = wp_generate_uuid4(); // Generate UUID for the session
                
        // Save UUID in the order meta
        $order->update_meta_data('_payment_uid', sanitize_text_field($uid));

        // Save _payment_uid_creation_timestamp in the order meta
        $order->update_meta_data('_payment_uid_creation_timestamp', sanitize_text_field(time()));

        // Save _payment_uid_expiration_timestamp in the order meta
        $order->update_meta_data('_payment_uid_expiration_timestamp', sanitize_text_field((time() + (15 * 60))));
        
        // Save the order with the new metadata 
        $order->save(); 

        $this->logger->info('Updating _payment_uid from save_order_uid_before_validation' . $uid, ['source' => 'moneyro-log']);
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
