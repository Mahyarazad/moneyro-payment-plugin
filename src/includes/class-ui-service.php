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
        if (is_checkout()) {
            $gateway_id  = esc_js($this->id);
            $getrate_api = esc_url($this->getrate_api);
            $nonce       = wp_create_nonce('woocommerce-update-order-review');
            $ajax_url    = esc_url(admin_url('admin-ajax.php'));
            $total = WC()->cart->total;
            $subtotal = WC()->cart->get_subtotal();

            // Get the total tax amount
            $taxes = WC()->cart->get_taxes_total();
            
            // Calculate the total including tax
            $total_including_tax = $subtotal + $taxes;
            ?>
            <script type="text/javascript">
                var moneyro_vars = {
                    shipment_margin_rate: "<?php echo $this->gateway->shipment_margin_rate; ?>",
                    gateway_margin_rate: "<?php echo $this->gateway->gateway_margin_rate; ?>",
                    gateway_id: "<?php echo $this->gateway->id; ?>",
                    getrate_api: "<?php echo $this->gateway->getrate_api; ?>",
                    moneyro_settings_api: "<?php echo $this->gateway->moneyro_settings_api; ?>",
                    nonce: "<?php echo $nonce; ?>",
                    ajax_url: "<?php echo $ajax_url; ?>",
                    total_including_tax: <?php echo $total_including_tax; ?> ,
                    total: <?php echo $total; ?> 
                };
    
                jQuery(function($) {
                        // Hide the National ID field initially
                        var total_including_tax = parseFloat(moneyro_vars.total_including_tax);
                        function roundToOneDecimal(num) {
                            return parseFloat(num.toFixed(1));
                        }

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
                            
                            var purchase_via_rial_initial_fee = 0;

                            if (selectedPaymentMethod === moneyro_vars.gateway_id) {
                                var rate_settings = {
                                    "url": moneyro_vars.getrate_api,
                                    "method": "GET",   
                                };

                                var gateway_settings = {
                                    "url": moneyro_vars.moneyro_settings_api,
                                    "method": "GET",   
                                };

                                var purchase_via_rial_initial_fee = 20000;
                                var settings = {
                                    "url": moneyro_vars.moneyro_settings_api,
                                    "method": "GET",
                                    "dataType": "json",
                                    "crossDomain": true,  // This ensures the request is treated as cross-origin
                                    "xhrFields": {
                                        "withCredentials": true // If you need cookies or credentials for the request
                                    }
                                };

                                $.ajax(settings).done(function(response) {
                                    console.log(response);
                                    var settings = response.results.filter(item => item.setting_key === "purchase_via_rial_initial_fee");
                                    purchase_via_rial_initial_fee = parseInt(settings[0].setting_value);
                                    console.log('purchase_via_rial_initial_fee', purchase_via_rial_initial_fee);

                                }).fail(function(jqXHR, textStatus, errorThrown) {
                                    console.error("Error: " + textStatus, errorThrown);
                                });


                                $.ajax(rate_settings).done(function (response) 
                                {
                                    var selling_rate = parseInt(response.AED.when_selling_currency_to_user.change_in_rial);
                                    var new_shipping_cost = total_including_tax * ((parseInt(moneyro_vars.shipment_margin_rate) + parseInt(moneyro_vars.gateway_margin_rate)) / 100);
                                    var new_shipping_cost_irr = new_shipping_cost * selling_rate;
                                    var user_pay_amount =  Math.ceil(total_including_tax * ((parseInt(moneyro_vars.shipment_margin_rate) + parseInt(moneyro_vars.gateway_margin_rate) + 100) / 100) * selling_rate) + purchase_via_rial_initial_fee;

                                    console.log('new_shipping_cost', new_shipping_cost);
                                    console.log('total_including_tax', total_including_tax);
                                    console.log('selling_rate', selling_rate);
                                    console.log('user_pay_amount', user_pay_amount);

                                    var user_pay_amount_for_ui = roundToOneDecimal(user_pay_amount / selling_rate);

                                    if (shippingLabel.length) {
                                        shippingLabel.html(`${roundToOneDecimal(new_shipping_cost)}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                                    }
                                    if (totalLabel.length) {
                                        totalLabel.html(`${user_pay_amount_for_ui}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                                    }

                                    if ($('.dgland-shipping-info').length === 0) {
                                        var newRow = `
                                            <tr class="dgland-shipping-info">
                                                <th>Shipping Fee in IRR</th>
                                                <td><span class="woocommerce-Price-amount amount"><bdi>${formatCurrency(new_shipping_cost_irr)}&nbsp;<span class="woocommerce-Price-currencySymbol">IRR</span></bdi></span></td>
                                            </tr>`;
                                        $('.woocommerce-shipping-totals.shipping').after(newRow);
                                    }

                                    // if ($('.dgland-shipping-info-aed').length === 0) {
                                    //     var include = `<div class='dgland'></br><small class="includes_tax">(includes <span class="woocommerce-Price-amount amount">
                                    //         ${new_shipping_cost}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span></span> DGLand Fee)
                                    //         </small></div>`;

                                    //     $('.includes_tax').after(include);
                                    // }
                                    
                                }).fail(function (jqXHR, textStatus, errorThrown) {
                                    // Handle any errors here
                                    window.alert('AJAX error:', textStatus, errorThrown);
                                });;
  
                            }else{
                                if ($('.moneyro-shipping-info').length) {
                                    $('.moneyro-shipping-info').remove();
                                }
                                if ($('.dgland-margin-info').length) {
                                    $('.dgland-margin-info').remove();
                                }
                                shippingLabel.html(`11&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                                totalLabel.html(`${moneyro_vars.total}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                               
                            }
                        }
                        
                        // Trigger the toggle function on payment method change
                        $('form.checkout').on('change', 'input[name="payment_method"]', function() {
                            toggleNationalIDField();
                            updateShippingCost();
                        });
                        
                        
                        toggleNationalIDField();
                        updateShippingCost();

                        $(document).ajaxComplete(function(event, xhr, settings) {
                            // Check if the request was for updating the order review
                            if (settings.url.indexOf('wc-ajax=update_order_review') !== -1) {
                                toggleNationalIDField();
                                updateShippingCost();
                            }
                        });

                    });
                </script>
            <?php

            unset($gateway_id, $getrate_api, $nonce, $ajax_url, $subtotal, $taxes, $total);
        }
    }
    
    public function validate_national_id_field($data, $errors) {
        if (MONEYRO_PAYMENT_GATEWAY_ID === WC()->session->get('chosen_payment_method')) {

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

            $billing_phone = $_POST['billing_phone'];
            // Check if the billing phone is empty
            if (empty($billing_phone)) {
                wc_add_notice(__('Billing Phone is a required field.', 'woocommerce'), 'error');
            } elseif (strlen($billing_phone) !== 13 || !preg_match('/^\+989/', $billing_phone)) {
                wc_add_notice(__('Billing Phone must start with +989 and be exactly 13 characters long.', 'woocommerce'), 'error');
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

    function add_custom_order_data($order) {
        // $this->gateway->logger->debug('Adding custom order data' . $order, ['source' => 'moneyro-log']);
        // ;
        $payment_uid = $order->get_meta('_payment_uid');
        echo '<p><strong>' . __( 'Payment Method:', 'woocommerce' ) . '</strong> ' . esc_html( $order->get_meta( '_payment_method') ) . '</p>';
        echo '<p><strong>' . __( 'Payment UID:', 'woocommerce' ) . '</strong> ' . esc_html( $payment_uid ) . '</p>';
        echo '<p><strong>' . __( 'Payment Invoice:', 'woocommerce' ) . '</strong> <a href="' . esc_url( $this->gateway->gateway_baseUrl . '/invoice-preview/' . $payment_uid ) . '">' . __( 'View Invoice', 'woocommerce' ) . '</a></p>';
       
    }

}
?>
