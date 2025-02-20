<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class UIService {

    protected $gateway;

    public function __construct($gateway) {
        $this->gateway = $gateway;
    }


    public function update_shipping_cost_handler() {
        // Log the request for debugging
        error_log('AJAX request received.');
    
        // Verify the nonce
        if (!check_ajax_referer('ajax_nonce', '_ajax_nonce', false)) {
            error_log('Nonce verification failed.');
            wp_send_json_error('Nonce verification failed.');
            wp_die();
        }
    
        // // Get the custom shipping amount from the AJAX request
        // $custom_shipping_amount = isset($_POST['custom_shipping_amount']) ? floatval($_POST['custom_shipping_amount']) : 0;
    
        // // Log the received data
        // error_log('Received custom shipping amount: ' . $custom_shipping_amount);
    
        // // Check if the shipping amount is valid
        // if ($custom_shipping_amount > 0) {
        //     // You can add logic to update the shipping cost here
        //     wp_send_json_success(array('message' => 'Shipping cost updated to: ' . $custom_shipping_amount));
        // } else {
        //     wp_send_json_error(array('message' => 'Invalid shipping amount.'));
        // }
    
        // Always exit to avoid extra output
        wp_die();
    } 
    public function enqueue_custom_ajax_script() {
        wp_enqueue_script(
            'custom-ajax-script',
            plugins_url('assets/js/custom-ajax.js', __DIR__), // Correct way to reference plugin files
            array('jquery'),
            '1.0.0',
            true
        );
    
        // Pass AJAX URL to the script
        wp_localize_script('moneyro-js', 'moneyro_vars', array(
            'gateway_id'  => esc_js($gateway_id),
            'getrate_api' => esc_url($getrate_api),
            'nonce'    => wp_create_nonce('ajax_nonce'), // Security nonce
            'ajax_url' => admin_url('admin-ajax.php'),
            'cart' => WC()->cart->get_cart(),
        ));
    }

    public function enqueue_script() {
        $gateway_id = MONEYRO_PAYMENT_GATEWAY_ID;
        $getrate_api = $this->gateway->getrate_api;
    
        // Enqueue the script if on the checkout page
        if (is_checkout()) {
            wp_enqueue_script(
                'moneyro-js',
                plugins_url('assets/js/custom-ajax.js', __DIR__), // Correct way to reference plugin files
                array('jquery'),
                '1.0.0',
                true
            );
            

            // Pass PHP variables to JavaScript
            wp_localize_script('moneyro-js', 'moneyro_vars', array(
                'gateway_id'  => esc_js($gateway_id),
                'getrate_api' => esc_url($getrate_api),
                'nonce'    => wp_create_nonce('ajax_nonce'), // Security nonce
                'ajax_url' => admin_url('admin-ajax.php'),
                'cart' => WC()->cart->get_cart(),
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
                        
                        $.ajax({
                            type: 'POST',
                            url: moneyro_vars.ajax_url,
                            data: {
                                _ajax_nonce: moneyro_vars.nonce, // nonce
                                action: "update_shipping_cost", // action
                            },
                            success: function(response) {
                                if (response.success) {
                                    console.log('Shipping cost updated:', response.data);
                                    // Optionally refresh the page or update UI here
                                    location.reload(); // Reload to see updated prices
                                } else {
                                    console.error('Error:', response.data.message);
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('AJAX error:', status);
                            }
                        });
                        
                        if (selectedPaymentMethod === moneyro_vars.gateway_id) {
                            var settings = {
                                "url": moneyro_vars.getrate_api,
                                "method": "GET",
                                
                            };
                            
                            $.ajax(settings).done(function (response) {

                                var changeInRial = response.AED.when_selling_currency_to_user.change_in_rial;
                                var shippingLabel = $(".woocommerce-shipping-totals td .woocommerce-Price-amount.amount bdi");
                                console.log(moneyro_vars.cart);
                                if (shippingLabel.length) {
                                    shippingLabel.html(`${changeInRial}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                                }

                                if ($('.moneyro-shipping-info').length === 0) {
                                    var newRow = `
                                        <tr class="moneyro-shipping-info">
                                            <th>Shipping Fee in IRR</th>
                                            <td><span class="woocommerce-Price-amount amount"><bdi>${changeInRial}&nbsp;<span class="woocommerce-Price-currencySymbol">IRR</span></bdi></span></td>
                                        </tr>
                                    `;
    
                                    // Append after the existing shipping row
                                    $('.woocommerce-shipping-totals.shipping').after(newRow);
                                }

                            });
                        }else{
                            $('.moneyro-shipping-info').remove();
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
                $this->gateway->logger->debug('National Id updated in database' . $national_id, ['source' => 'moneyro-log']);
            }

        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
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
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
                
    }

    public function custom_override_shipping_cost() {
        // Check if we are on the checkout page
        if (is_checkout()) {
            // Set your custom shipping amount
            $custom_shipping_amount = 5.00; // Change this to your desired amount
    
            // Get current shipping rates
            $shipping_methods = WC()->cart;
    
            $this->gateway->logger->debug('Shipping methods: ' . json_encode($shipping_methods), ['source' => 'moneyro-log']);
            // Loop through each shipping method and set the cost
            // foreach ($shipping_packages as $package_key => $package) {
            //     foreach ($package['rates'] as $method_id => $method) {
            //         // Check if it's the right shipping method
            //         if ($method_id === 'flat_rate:4') {
            //             // Instead of directly modifying, use this:
            //             $method->cost = $custom_shipping_amount;
            //             // Also, you might need to set the tax class if necessary
            //             $method->taxes = []; // Or set the appropriate tax array if needed
            //         }
            //     }
            // }
        }
    }   

    function woocommerce_package_rates($rates ) {
        
        $discount_amount = 30; // 30%

        foreach($rates as $key => $rate ) {
            $rates[$key]->cost = $rates[$key]->cost - ( $rates[$key]->cost * ( $discount_amount/100 ) );
        }

        return $rates;
    }
}
?>
