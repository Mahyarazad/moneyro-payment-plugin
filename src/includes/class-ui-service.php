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
        $total_cart = WC()->cart->total;

        // Enqueue the script if on the checkout page
        
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
                'cart' => $total_cart
            ));
    
            // Inline script to handle UI changes
            ?>
                <script type="text/javascript">

                    jQuery(function($) {
                        // Hide the National ID field initially
                        var total_cart = parseFloat(moneyro_vars.cart);

                        function formatCurrency(value) {
                            // Split the value into the number and currency parts
                            const parts = String(value).split(' ');
                            const numberPart = parts[0];
                            const currencyPart = parts[1];

                            // Convert the number to a float, then format it with commas
                            const formattedNumber = parseFloat(numberPart).toLocaleString('en-US');

                            // Return the formatted string with currency
                            return `${formattedNumber}`;
                        }



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
                            var shippingLabel = $(".woocommerce-shipping-totals td .woocommerce-Price-amount.amount bdi");
                            var totalLabel = $(".order-total td .woocommerce-Price-amount.amount bdi");
                            
                            const perecent = 10;
                            
                            if (selectedPaymentMethod === moneyro_vars.gateway_id) {
                                var settings = {
                                    "url": moneyro_vars.getrate_api,
                                    "method": "GET",   
                                };



                                $.ajax(settings).done(function (response) 
                                {
                                    var selling_rate = parseInt(response.AED.when_selling_currency_to_user.change_in_rial);
                                    var new_shipping_cost = total_cart * selling_rate * 0.1;
                                    console.log(total_cart);
                                    console.log(selling_rate);


                                    if (shippingLabel.length) {
                                        shippingLabel.html(`${total_cart * 0.1}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                                    }
                                    if (totalLabel.length) {
                                        totalLabel.html(`${total_cart * 1.1 - 11}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                                    }

                                    if ($('.moneyro-shipping-info').length === 0) {
                                        var newRow = `
                                            <tr class="moneyro-shipping-info">
                                                <th>Shipping Fee in IRR</th>
                                                <td><span class="woocommerce-Price-amount amount"><bdi>${formatCurrency(new_shipping_cost)}&nbsp;<span class="woocommerce-Price-currencySymbol">IRR</span></bdi></span></td>
                                            </tr>`;
                                        $('.woocommerce-shipping-totals.shipping').after(newRow);
                                    }
                                    
                                }).fail(function (jqXHR, textStatus, errorThrown) {
                                    // Handle any errors here
                                    console.error('AJAX error:', textStatus, errorThrown);
                                });;
                                                                

                                
                            }else{
                                if ($('.moneyro-shipping-info').length) {
                                    $('.moneyro-shipping-info').remove();
                                }

                                shippingLabel.html(`11&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                                totalLabel.html(`${total_cart}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                               
                            }
                        }
                        
                        // Trigger the toggle function on payment method change
                        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
                            toggleNationalIDField();
                            updateShippingCost();
                        });
                        
                        
                        toggleNationalIDField();
                        updateShippingCost();
                    });
                </script>
            <?php
        
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
