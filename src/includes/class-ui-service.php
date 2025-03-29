<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class UIService {

    protected $gateway;
    protected $moneyro_api_service;

    public function __construct($gateway) {
        $this->gateway = $gateway;
        $this->moneyro_api_service = new MoneyroAPIService($gateway->getrate_api,$gateway->moneyro_settings_api);
    }

    public function enqueue_script() {


        if (is_checkout()) {
            $gateway_id = esc_js($this->id);
            
            $default_shipment_cost = $this->gateway->get_available_shipping_methods()['cost'];
            // Get necessary data from the API and WooCommerce
            $selling_rate = round($this->moneyro_api_service->fetch_currency_rates(), 0);
            $initial_fee = $this->moneyro_api_service->fetch_purchase_via_rial_initial_fee();
            $total_including_tax = WC()->cart->get_subtotal() + WC()->cart->get_taxes_total();
            $new_total_with_shipment_cost = $total_including_tax * (($this->gateway->shipment_margin_rate + $this->gateway->gateway_margin_rate) / 100);
            $new_total_with_shipment_cost_irr = $new_total_with_shipment_cost * $selling_rate;
            $new_total = round($this->calculate__aed_total($total_including_tax, $selling_rate, $initial_fee), 1);
    
            ?>
            <script type="text/javascript">
                var moneyro_vars = {
                    gateway_id: "<?php echo $this->gateway->id; ?>",
                    total_including_tax: <?php echo $total_including_tax; ?>,
                    new__total_with_shipment_cost: <?php echo $new_total_with_shipment_cost; ?>,
                    new__total_with_shipment_cost_irr: <?php echo $new_total_with_shipment_cost_irr; ?>,
                    default_shipment_cost: <?php echo $default_shipment_cost; ?>,
                    new_total: <?php echo $new_total; ?>,
                };
    
                jQuery(function($) {
                    function roundToOneDecimal(num) {
                        return parseFloat(num.toFixed(1));
                    }
    
                    function formatCurrency(value) {
                        return parseFloat(value).toLocaleString('en-US');
                    }
    
                    function toggleNationalIDField() {
                        if ($('input[name="payment_method"]:checked').val() === moneyro_vars.gateway_id) {
                            $('#billing_national_id_field').show();
                        } else {
                            $('#billing_national_id_field').hide();
                        }
                    }
    
                    function updateShippingCost() {
                        var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
                        var shippingLabel = $(".woocommerce-shipping-totals td .woocommerce-Price-amount.amount bdi");
                        var totalLabel = $(".order-total td .woocommerce-Price-amount.amount bdi");
    
                        if (selectedPaymentMethod === moneyro_vars.gateway_id) {
                            if (shippingLabel.length) {
                                shippingLabel.html(`${roundToOneDecimal(moneyro_vars.new__total_with_shipment_cost)}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                            }
                            if (totalLabel.length) {
                                totalLabel.html(`${moneyro_vars.new_total}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                            }
    
                            if ($('.dgland-shipping-info').length === 0) {
                                var newRow = `
                                    <tr class="dgland-shipping-info">
                                        <th>Shipping Fee in IRR</th>
                                        <td><span class="woocommerce-Price-amount amount"><bdi>${formatCurrency(moneyro_vars.new__total_with_shipment_cost_irr)}&nbsp;<span class="woocommerce-Price-currencySymbol">IRR</span></bdi></span></td>
                                    </tr>`;
                                $('.woocommerce-shipping-totals.shipping').after(newRow);
                            }
                        } else {
                            if ($('.dgland-shipping-info').length) {
                                $('.dgland-shipping-info').remove();
                            }

                            shippingLabel.html(`${moneyro_vars.default_shipment_cost}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                            
                            totalLabel.html(`${moneyro_vars.total_including_tax + moneyro_vars.default_shipment_cost}&nbsp;<span class="woocommerce-Price-currencySymbol">AED</span>`);
                        }
                    }
    
                    $('form.checkout').on('change', 'input[name="payment_method"]', function() {
                        toggleNationalIDField();
                        updateShippingCost();
                    });
    
                    toggleNationalIDField();
                    updateShippingCost();
    
                    // Do not remove these
                    $(document).ajaxComplete(function(event, xhr, settings) {
                        if (settings.url.indexOf('wc-ajax=update_order_review') !== -1) {
                            toggleNationalIDField();
                            updateShippingCost();
                        }
                    });
                });
            </script>
            <?php
        }
    }
    
    
    public function validate_national_id_field($data, $errors) {
        if (MONEYRO_PAYMENT_GATEWAY_ID === WC()->session->get('chosen_payment_method')) {
    
            $national_id = $_POST['billing_national_id'];
            $billing_phone = $_POST['billing_phone'];
    
            // Check if the National ID is empty
            if (empty($national_id)) {
                $errors->add('billing_national_id_error', sprintf(
                    __('<a href="#billing_national_id"><strong>National ID</strong> is required.</a>', 'woocommerce')
                ));
            }
    
            // Check if the National ID contains only digits
            if (!empty($national_id) && !preg_match('/^\d+$/', $national_id)) {
                $errors->add('billing_national_id_invalid', sprintf(
                    __('<a href="#billing_national_id"><strong>National ID</strong> should only contain numbers.</a>', 'woocommerce')
                ));
            }
    
            // Check if the National ID is exactly 10 digits
            if (!empty($national_id) && !preg_match('/^\d{10}$/', $national_id)) {
                $errors->add('billing_national_id_invalid', sprintf(
                    __('<a href="#billing_national_id"><strong>National ID</strong> must be exactly 10 digits.</a>', 'woocommerce')
                ));
            }
    
            // Check if the billing phone is empty
            if (empty($billing_phone)) {
                wc_add_notice(
                    __('<a href="#billing_phone"><strong>Billing Phone</strong> is a required field.</a>', 'woocommerce'),
                    'error'
                );
            } elseif (strlen($billing_phone) !== 13 || !preg_match('/^\+989/', $billing_phone)) {
                wc_add_notice(
                    __('<a href="#billing_phone"><strong>Billing Phone</strong> must start with +989 and be exactly 13 characters long.</a>', 'woocommerce'),
                    'error'
                );
            }
        }
    }
    

    public function save_billing_national_id($order_id, $data) {
        try{
            if (!empty($_POST['billing_national_id'])) {
                update_post_meta($order_id, '_billing_national_id', sanitize_text_field($_POST['billing_national_id']));
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
            $order = wc_get_order($order_id);
            $order_status = $order->get_status();
            $this->gateway->logger->debug('order_status => ' . $order_status, ['source' => 'moneyro-log']);
            WC()->cart->empty_cart();
            if (MONEYRO_PAYMENT_GATEWAY_ID === $order->get_payment_method()) {

               if($order_status === 'pending' || $order_status === 'cancelled'){
                   
                   echo '<script>
                    document.addEventListener("DOMContentLoaded", function () {
                        const successMessage = document.querySelector(".woocommerce-notice--success.woocommerce-thankyou-order-received");
                        const woocommerceButtons = document.querySelectorAll(".woocommerce-button");
                        
                        if (successMessage) {
                            // Change the text content
                            successMessage.textContent = "Payment failed or canceled";

                            // Change the color (customize as needed)
                            successMessage.style.backgroundColor = "#f8d7da"; // Light red background
                            successMessage.style.color = "#721c24"; // Dark red text
                            successMessage.style.borderColor = "#721c24";
                        }

                        woocommerceButtons.forEach(button => {
                            button.remove();
                        });

                    });
                    </script>';


                }else{
                    // Get the payment UID from the order meta
                    $uid = get_post_meta($order_id, '_payment_uid', true);
                    if(strlen($uid) !== 0){
                        echo '<script>
                                document.addEventListener("DOMContentLoaded", function () {
                                    const orderDetailsList = document.querySelector(".woocommerce-order-overview.order_details");
                                    if (orderDetailsList) {
                                        const nationalIdItem = document.createElement("li");
                                        nationalIdItem.classList.add("woocommerce-order-overview__national-id");
                                        nationalIdItem.innerHTML = `Payment Invoice: 
                                            <strong>
                                                <a href="' . esc_url( $this->gateway->gateway_baseUrl . '/invoice-preview/' . $payment_uid ) . '">' . __( 'View Invoice', 'woocommerce' ) . '</a>
                                            </strong>
                                        `;
                                        orderDetailsList.appendChild(nationalIdItem);
                                    }
                                });
                            </script>';
                    }
                }  
           }
        }catch (Exception $e){
            $this->gateway->logger->error('Exception: ' . $e->getMessage(), ['source' => 'moneyro-log']);
        }  
                
    }

    function add_custom_order_data($order) {

        $payment_uid = $order->get_meta('_payment_uid');
        echo '<p><strong>' . __( 'Payment Method:', 'woocommerce' ) . '</strong> ' . esc_html( $order->get_meta( '_payment_method') ) . '</p>';
        echo '<p><strong>' . __( 'Payment UID:', 'woocommerce' ) . '</strong> ' . esc_html( $payment_uid ) . '</p>';
        echo '<p><strong>' . __( 'Payment Invoice:', 'woocommerce' ) . '</strong> <a href="' . esc_url( $this->gateway->gateway_baseUrl . '/invoice-preview/' . $payment_uid ) . '">' . __( 'View Invoice', 'woocommerce' ) . '</a></p>';
       
    }

    
    public function display_order_uid_on_account_page($order) {
        
        $payment_uid = $order->get_meta('_payment_uid');
                
        if ($payment_uid) {
            echo '<script>
                    document.addEventListener("DOMContentLoaded", function() {
                        var tableFooter = document.querySelector(".woocommerce-table tfoot");
                        if (tableFooter) {
                            var paymentUidRow = document.createElement("tr");
                            paymentUidRow.innerHTML = `
                            <th scope=\"row\">Payment Invoice:</th><td>
                                <strong>
                                    <a href="' . esc_url( $this->gateway->gateway_baseUrl . '/invoice-preview/' . $payment_uid ) . '">' . __( 'View Invoice', 'woocommerce' ) . '</a>
                                </strong>
                            </td>`;
                            
                            var totalRow = tableFooter.querySelector("tr:last-child");
                            if (totalRow) {
                                tableFooter.insertBefore(paymentUidRow, totalRow);
                            }
                        }
                    });
                </script>';
        }
    }

    private function calculate__aed_total($total_including_tax, $selling_rate, $initial_fee){

        $new__total_with_shipment_cost = $total_including_tax * ((100 + $this->gateway->shipment_margin_rate + $this->gateway->gateway_margin_rate) / 100); 

        return  ceil($new__total_with_shipment_cost);
    }  
}
?>
