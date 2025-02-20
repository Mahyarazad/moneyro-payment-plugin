<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class UIService {

    protected $gateway;

    public function __construct($gateway) {
        $this->gateway = $gateway;
    }

    public function enqueue_script() {
        $gateway_id = MONEYRO_PAYMENT_GATEWAY_ID;
        $getrate_api = $this->gateway->getrate_api;
    
        // Enqueue the script if on the checkout page
        if (is_checkout()) {
            wp_enqueue_script(
                'moneyro-js',
                plugins_url('src/assets/js/moneyro.js', __FILE__), // Ensure this path is correct
                array('jquery'),
                null,
                true
            );
    
            // Pass PHP variables to JavaScript
            wp_localize_script('moneyro-js', 'moneyro_vars', array(
                'gateway_id'  => esc_js($gateway_id),
                'getrate_api' => esc_url($getrate_api),
            ));
    
            // Inline script to handle UI changes
            ?>
            <script type="text/javascript">
                jQuery(function($) {
                    // Hide the National ID field initially
                    function toggleNationalIDField() {
                        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
    
                        // Check if the Moneyro payment method is selected
                        if (selectedPaymentMethod === moneyro_vars.gateway_id) {
                            $('#billing_national_id_field').show(); // Show National ID field
                        } else {
                            $('#billing_national_id_field').hide(); // Hide National ID field
                        }
                    }
    
                    function updateShippingCost() {
                        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
                        var shippingElement = $('.woocommerce-Price-amount bdi');
                        if (selectedPaymentMethod === moneyro_vars.gateway_id) {
                            if (shippingElement.length) {
                                var originalShipping = shippingElement.text();
                                console.log(originalShipping);// Add your logic to update the shipping cost here
                            }
                        }
                    }
    
                    // Trigger the toggle function on payment method change
                    $('form.checkout').on('change', 'input[name="payment_method"]', function() {
                        toggleNationalIDField();
                        updateShippingCost();
                    });
    
                    // Trigger the toggle function when the page loads to check the selected payment method
                    toggleNationalIDField();
                    updateShippingCost();
                });
            </script>
            <?php
        }
    }
    
    public function validate_national_id_field($data, $errors) {
        if (MONEYRO_PAYMENT_GATEWAY_ID === WC()->session->get('chosen_payment_method')) {

            // $national_id = $data['billing_national_id']; // Correctly reference the input field here
            $national_id = $_POST['billing_national_id'];
            // Check if the National ID is empty
            if (empty($national_id)) {
                $errors->add('billing_national_id_error', __('National ID is required.', 'woocommerce'));
            }
        
            // Check if the National ID contains only digits
            if (!empty($national_id) && !preg_match('/^\d+$/', $national_id)) {
                $errors->add('billing_national_id_invalid', __('National ID should only contain numbers.', 'woocommerce'));
            }
        
            // Check if the National ID is exactly 10 digits
            if (!empty($national_id) && !preg_match('/^\d{10}$/', $national_id)) {
                $errors->add('billing_national_id_invalid', __('National ID must be exactly 10 digits.', 'woocommerce'));
            }
        }
    }

    public function save_billing_national_id($order_id, $data) {
        try{
            if (isset($_POST['billing_national_id'])) {
                $national_id = sanitize_text_field($_POST['billing_national_id']);
                update_post_meta($order_id, '_billing_national_id', $national_id);
                $this->gateway->logger->info('National Id updated in database' . $national_id, ['source' => 'moneyro-log']);
            }

        }catch (Exception $e){
            $this->gateway->logger->info('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }
    }

    public function add_national_id_field($fields) {
        $fields['billing']['billing_national_id'] = [
            'type'        => 'text',
            'label'       => __('National ID', 'woocommerce'),
            'required'    => true,
            'class'       => ['form-row-wide'],
            'validate'    => ['required'],
        ];
        return $fields;
    }

    public function display_payment_id_on_thank_you_page($order_id) {
       try{
           if (MONEYRO_PAYMENT_GATEWAY_ID === WC()->session->get('chosen_payment_method')) {
                // Get the payment UID from the order meta
                $uid = get_post_meta($order_id, '_payment_uid', true);
                if(strlen($uid) !== 0){
                    echo '<script>
                            document.addEventListener("DOMContentLoaded", function () {
                                const orderDetailsList = document.querySelector(".woocommerce-order-overview.order_details");
                                if (orderDetailsList) {
                                    const nationalIdItem = document.createElement("li");
                                    nationalIdItem.classList.add("woocommerce-order-overview__national-id");
                                    nationalIdItem.innerHTML = `Payment UID: <strong>' . esc_html($uid) . '</strong>`;
                                    orderDetailsList.appendChild(nationalIdItem);
                                }
                            });
                        </script>';
                }
           }

        }catch (Exception $e){
            $this->gateway->logger->info('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
                
    }
}
?>
