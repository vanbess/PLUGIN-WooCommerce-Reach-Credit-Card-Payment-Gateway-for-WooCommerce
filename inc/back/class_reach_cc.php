<?php

defined('ABSPATH') ?: exit();

if (!class_exists('Reach_CC')) :

    class Reach_CC extends WC_Payment_Gateway_CC {

        private static $instance;

        protected $test_environment;
        protected $merchant_id;
        protected $merchant_secret_key;
        protected $fraud_service;
        protected $delayed_capture;
        protected $woocommerce_default_currency;

        /**
         * Returns the *Singleton* instance of this class.
         *
         * @return Singleton The *Singleton* instance.
         */
        public static function getInstance() {
            // Check is $instance has been set
            if (null === self::$instance) {
                // Creates sets object to instance
                self::$instance = new self();
            }
            // Returns the instance
            return self::$instance;
        }


        /**
         * Constructor for payment gateway properties et al
         */
        public function __construct() {

            // setup basics
            $this->id                 = 'reach_cc';
            $this->has_fields         = true;
            $this->method_title       = 'Reach Credit Card';
            $this->method_description = 'Pay with your credit/debit card via Reach';
            $this->supports           = ['products', 'refunds'];

            // init options fields 
            $this->init_form_fields();

            // load settings   
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description         = $this->get_option('description');
            $this->enabled             = $this->get_option('enabled');
            $this->test_environment    = $this->get_option('test_environment');
            $this->merchant_id         = $this->test_environment ? $this->get_option('test_merchant_id') : $this->get_option('live_merchant_id');
            $this->merchant_secret_key = $this->test_environment ? $this->get_option('test_merchant_secret_key') : $this->get_option('live_merchant_secret_key');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // We need custom JavaScript to obtain a token
            add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);

            // Add Reach Fingerprint script after checkout form
            // add_action('wp_head', [$this, 'displayFingerprintScript']);
            add_action('wp_footer', [$this, 'displayFingerprintScript']);
        }

        /**
         * Enqueue device fingerprint script
         */
        // public function enq_dfp_script() {
        //     wp_enqueue_script('reach-dfp-script', [$this, 'displayFingerprintScript'], array('jquery'), '2.18', true);
        // }

        /**
         * Get Gateway Settings and protected vars
         * @return array
         */
        public function getGatewaySettings() {
            $gatewaySettings = [
                'test_environment'             => $this->test_environment,
                'merchant_secret_key'          => $this->merchant_secret_key,
                'merchant_id'                  => $this->merchant_id,
                'fraud_service'                => $this->fraud_service,
                'delayed_capture'              => $this->delayed_capture,
                'woocommerce_default_currency' => $this->woocommerce_default_currency,
            ];
            return $gatewaySettings;
        }

        /**
         * Init form fields
         *
         * @return void
         */
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Reach Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Credit Card via Reach',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with your credit/debit card via Reach',
                ),
                'test_environment' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'test_merchant_id' => array(
                    'title' => 'Test MerchantId',
                    'type'  => 'text'
                ),
                'test_merchant_secret_key' => array(
                    'title' => 'Test Secret Key',
                    'type'  => 'text',
                ),
                'live_merchant_id' => array(
                    'title' => 'Live MerchantId',
                    'type'  => 'text'
                ),
                'live_merchant_secret_key' => array(
                    'title' => 'Live Secret Key',
                    'type'  => 'text'
                ),
            );
        }

        /**
         * Build credit card form/checkout fields
         *
         * @return void
         */
        public function form() {

            // do initial setup
            $currency                 = function_exists('alg_get_current_currency_code') ? alg_get_current_currency_code() : get_woocommerce_currency();
            $gatewaySettings          = $this->getGatewaySettings();
            $countryCode              = WC()->customer->get_shipping_country();

            // send payment methods request
            $getPaymentMethodsRequest = Reach_API::getPaymentMethodsAPICall($gatewaySettings, $countryCode, $currency);

            // if request successful
            if ($getPaymentMethodsRequest['success']) :

                // retrieve payment methods
                $methods = $getPaymentMethodsRequest['data'];

                // if no payment methods, display error
                if (!$methods) : ?>
                    <p class="form-row form-row-wide"><?php _e('There are no payment methods available for this country and currency. Please choose a different payment method.') ?></p>
            <?php endif;

                // retrieve and return all card methods
                $cardMethods = array_filter($methods, function ($method) {
                    return $method['Class'] = 'Card';
                });

            // if request not successful
            // @todo Add error logging (maybe)
            else :

            endif;

            // enque wc credit card form script 
            wp_enqueue_script('wc-credit-card-form'); ?>

            <!-- CC form fields -->
            <?php if (!empty($cardMethods)) : ?>

                <fieldset id="wc-<?php echo esc_attr($this->id); ?>-cc-form" class='wc-credit-card-form wc-payment-form reach_payment_box_wrapper'>

                    <?php do_action('woocommerce_credit_card_form_start', $this->id); ?>

                    <!-- card number -->
                    <p class="form-row form-row-wide">
                        <label for="reach-card-number"><?php esc_html_e('Card number', 'woocommerce'); ?> <span class="required">*</span></label>
                        <input id="reach-card-number" name="reach-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" />
                    </p>

                    <!-- card expiry -->
                    <p class="form-row form-row-first">
                        <label for="reach-card-expiry"><?php esc_html_e('Expiration (MM/YY)', 'woocommerce'); ?> <span class="required">*</span></label>
                        <input id="reach-card-expiry" name="reach-card-expiry" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" autocomplete="cc-exp" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="<?php esc_html_e('Expiry (MM/YY)', 'woocommerce'); ?>" />
                    </p>
                    
                    <!-- CVC field -->
                    <?php if (!$this->supports('credit_card_form_cvc_on_saved_method')) : ?>
                        <p class="form-row form-row-last">
                            <label for="reach-card-cvc"><?php esc_html_e('Card Security Code', 'woocommerce'); ?> <span class="required">*</span></label>
                            <input id="reach-card-cvc" name="reach-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="<?php esc_html_e('CVV', 'woocommerce'); ?>" />
                        </p>
                    <?php endif; ?>

                    <!-- device fingerprint -->
                    <input type="hidden" id="reach-device-fingerprint" name="reach-device-fingerprint" value="" />

                    <!-- payment name -->
                    <input type="hidden" id="reach-payment-name" name="reach-payment-name" value="Card" />

                    <!-- payment class -->
                    <input type="hidden" id="reach-payment-class" name="reach-payment-class" value="Card" />


                    <!-- extract device fingerprint and add to hidden input -->
                    <script id="extract_fp">
                        window.addEventListener('load', function() {

                            var iframe = document.querySelector('#gip_fingerprint');

                            var regex = /\/([a-z0-9-]+)\.htm/;
                            var match = iframe.src.match(regex);

                            if (match) {
                                console.log('match found');
                                var fingerprint = match[1];
                                document.querySelector('#reach-device-fingerprint').value = fingerprint;
                            } 

                        });
                    </script>


                </fieldset>

                <?php do_action('woocommerce_credit_card_form_end', $this->id); ?>
                <?php
                // retrieve and display Reach badge (assuming this refers to the Reach logo)
                $consumer_ip     = Reach_Helpers::getUserIP();
                $gatewaySettings = $this->getGatewaySettings();
                $getBadgeRequest = Reach_API::getBadgeAPICall($gatewaySettings, $currency, $consumer_ip);

                if ($getBadgeRequest['success']) : ?>
                    <p class="form-row form-row-first reach-badge"><?php echo $getBadgeRequest['data']['Html']; ?></p>
                <?php endif; ?>
            <?php endif; ?>

<?php }

        /**
         * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
         */
        public function payment_scripts() {
            // add custom vars to be able to call ajax calls like /fingerprint in reach_scripts.js
            wp_localize_script(
                'reach_scripts',
                'reach_vars',
                array(
                    'fingerprint_url' => ($this->test_environment ? Reach_API::API_TEST_URL : Reach_API::API_LIVE_URL) . '/fingerprint?MerchantId=' . $this->merchant_id,
                    'cardinfo_url'    => ($this->test_environment ? Reach_API::API_TEST_URL : Reach_API::API_LIVE_URL) . '/getCardInfo?MerchantId=' . $this->merchant_id,
                )
            );
        }

        /**
         * The payment processing is done via process_payment($order_id ).
         * It is important to point out that it gets the current order passed to it so we can get the values we need.
         * The credit card fields can be obtained from $_POST.
         * @param $order_id
         * @return null
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public function process_payment($order_id) {

            // Get this Order's information
            $customerOrder = new WC_Order($order_id);
            $formParams    = $_POST;
            $openContract  = false;

            Reach_Log::log('====================== START ORDER ===============================', 'info');

            Reach_Log::log(json_encode($formParams), 'debug', 'CHECKOUT POST DATA: ');

            $customerOrder->add_order_note(__('Checking out with payment method: ' . $formParams['reach-payment-name'], 'sbwc-reach-cc'));

            // Override the payment method title
            try {
                $customerOrder->set_payment_method_title($formParams['reach-payment-name']);
            } catch (\WC_Data_Exception $e) {
            }

            // Create Reach /checkout API call
            $gatewaySettings = $this->getGatewaySettings();
            $checkoutCall    = Reach_API::checkoutAPICall($customerOrder, $formParams, $gatewaySettings, $openContract);

            Reach_Log::logOrder($customerOrder, 'info');

            // If API call is not successful throw an error and exit
            if (!$checkoutCall['success']) {

                Reach_Log::log($checkoutCall['err_msg'], 'error', 'CHECKOUT API CALL ERROR: ');
                Reach_Log::log(json_encode($checkoutCall['data']), 'error', 'CHECKOUT API CALL DATA: ');

                $filteredErrorMsg = Reach_Helpers::filterErrorMessage($checkoutCall['err_msg']);

                // Show Error Notice on Woocommerce Checkout
                wc_add_notice(__('Payment error: ', 'sbwc-reach-cc') . $filteredErrorMsg, 'error');

                // Delete this order as it wasn't successful
                $customerOrder->delete();
                return null;
            }

            // For online/offline payments, there is a redirect that needs to happen
            if (array_key_exists('Action', $checkoutCall['data'])) {

                if (array_key_exists('Redirect', $checkoutCall['data']['Action'])) {
                    // Return redirect
                    return array(
                        'result'   => 'success',
                        'redirect' => $checkoutCall['data']['Action']['Redirect']
                    );
                } elseif (array_key_exists('Display', $checkoutCall['data']['Action'])) {
                    Reach_Log::log(json_encode($checkoutCall['data']['Action']['Display']), 'debug', 'Action - Display');
                    die();
                }
            }

            $orderTotalString = Reach_Helpers::getOrderTotalString($customerOrder);

            // Handle Card types responses
            if (array_key_exists('Captured', $checkoutCall['data']) && $checkoutCall['data']['Captured'] == true) {
                $note = sprintf(__('Payment authorized for %s and captured.', 'sbwc-reach-cc'), $orderTotalString);
                $customerOrder->add_order_note($note);
                $customerOrder->payment_complete();
                Reach_Log::log($note, 'info', 'PAYMENT COMPLETED');
            } else {

                if (array_key_exists('Authorized', $checkoutCall['data']) && $checkoutCall['data']['Authorized'] == true) {
                    $note = sprintf(__('Payment authorized for %s , awaiting capture...', 'sbwc-reach-cc'), $orderTotalString);
                } else {
                    $note = sprintf(__('Payment authorizing for %s', 'sbwc-reach-cc'), $orderTotalString);
                }

                $customerOrder->add_order_note(__($note, 'sbwc-reach-cc'));
                Reach_Log::log($note, 'info');

                // Add note for fraud review
                if (array_key_exists('UnderReview', $checkoutCall['data']) && $checkoutCall['data']['UnderReview'] == true) {
                    $noteFraud = __('Payment is under review. Fulfillment should not continue until the payment has been approved.', 'sbwc-reach-cc');
                    $customerOrder->add_order_note($noteFraud);
                    Reach_Log::log($note, 'info');
                }
            }

            // Empty cart
            WC()->cart->empty_cart();

            // Return redirect
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($customerOrder)
            );
        }

        /**
         * For all transactions placed by the customer, a device fingerprint is required. The fingerprint can be collected with the /fingerprint API request.
         * The information we gather with /fingerprint allows us to form a complete picture of the consumer, and helps us to
         * distinguish fraudulent behavior from normal customer behavior.
         * Unlike the rest of the Reach API Requests, /fingerprint needs to be loaded on the page in <script> tags so that a
         * pixel can be present on the frontend for our fraud system to hit and gather information about the customer's system.
         * https://docs.withreach.com/docs/fingerprint
         */
        public function displayFingerprintScript() {
            // ORIGINAL SCRIPT SRC
            // $script_scr = $this->test_environment ? 'https://checkout-sandbox.gointerpay.net/v2.18/fingerprint?MerchantId=' . $this->merchant_id
            //     : 'https://checkout.gointerpay.net/v2.18/fingerprint?MerchantId=' . $this->merchant_id;

            if(is_checkout()):
                $script_scr = $this->test_environment ? 'https://checkout.rch.how/v2.21/fingerprint?MerchantId=' . $this->merchant_id
                    : 'https://checkout.rch.io/v2.21/fingerprint?MerchantId=' . $this->merchant_id;
                echo '<script id="reach-fp" async="" src="' . $script_scr . '"></script>';

                wp_enqueue_style('reach_cc', SBWC_REACH_URI.'assets/styles/checkout.css');

            endif;

        }

        /**
         * Process refund
         *
         * @param int $order_id
         * @param float $amount
         * @param string $reason
         * @return bool|WP_Error
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public function process_refund($order_id, $amount = null, $reason = '') {

            $order           = wc_get_order($order_id);
            $gatewaySettings = $this->getGatewaySettings();
            $refundAPICall   = Reach_API::refundAPICall($order, $gatewaySettings, $amount);

            if (!$refundAPICall['success']) {
                $error_msg = 'Reach Refund Error: ' . $refundAPICall['err_msg'];
                Reach_Log::log($error_msg . ' JSON: ' . json_encode($refundAPICall['data']), 'error');
                $order->add_order_note(__($error_msg, 'sbwc-reach-cc'));
                return new WP_Error(422, $error_msg);
            }

            $orderRefundString = Reach_Helpers::getOrderRefundString($order, $amount, $refundAPICall['finalAmount']);

            $order->add_order_note(__('Refund initiated for amount: ' . $orderRefundString . '.', 'sbwc-reach-cc'));
            return true;
        }

        /**
         * Process normal payment order (not subscriptions)
         *
         * @param $customerOrder
         * @param $formParams $_POST // get cc form inputs and hidden inputs like fingerprint
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public function processNormalPayment($customerOrder, $formParams) {

            Reach_Log::log('====================== START ORDER ===============================', 'info');
            Reach_Log::log(json_encode($formParams), 'debug', 'CHECKOUT POST DATA: ');

            $customerOrder->add_order_note(__('Checking out with payment method: ' . $formParams['reach-payment-name'], 'sbwc-reach-cc'));

            // Rates::updatePostMeta($customerOrder->get_id(), 'reach_payment_method', $formParams['reach-payment-method']);
            // Rates::updatePostMeta($customerOrder->get_id(), 'reach_payment_name', $formParams['reach-payment-name']);

            // Override the payment method title
            try {
                $customerOrder->set_payment_method_title($formParams['reach-payment-name']);
            } catch (\WC_Data_Exception $e) {
            }

            // Create Reach /checkout API call
            $gatewaySettings = $this->getGatewaySettings();
            $checkoutCall    = Reach_API::checkoutAPICall($customerOrder, $formParams, $gatewaySettings);

            Reach_Log::logOrder($customerOrder, 'info');

            // If API call is not successful throw an error and exit
            if (!$checkoutCall['success']) {

                Reach_Log::log($checkoutCall['err_msg'], 'error', 'CHECKOUT API CALL ERROR: ');
                Reach_Log::log(json_encode($checkoutCall['data']), 'error', 'CHECKOUT API CALL DATA: ');

                $filteredErrorMsg = Reach_Helpers::filterErrorMessage($checkoutCall['err_msg']);

                // Show Error Notice on Woocommerce Checkout
                wc_add_notice(__('Payment error: ', 'sbwc-reach-cc') . $filteredErrorMsg, 'error');

                // Delete this order as it wasn't successful
                $customerOrder->delete();
                return null;
            }

            // For online/offline payments, there is a redirect that needs to happen
            if (array_key_exists('Action', $checkoutCall['data'])) {
                if (array_key_exists('Redirect', $checkoutCall['data']['Action'])) {
                    // Return redirect
                    return array(
                        'result' => 'success',
                        'redirect' => $checkoutCall['data']['Action']['Redirect']
                    );
                } elseif (array_key_exists('Display', $checkoutCall['data']['Action'])) {
                    Reach_Log::log(json_encode($checkoutCall['data']['Action']['Display']), 'debug', 'Action - Display');
                    die();
                }
            }

            $orderTotalString = Reach_Helpers::getOrderTotalString($customerOrder);

            // Handle Card types responses
            if (array_key_exists('Captured', $checkoutCall['data']) && $checkoutCall['data']['Captured'] == true) {
                $note = sprintf(__('Payment authorized for %s and captured.', 'sbwc-reach-cc'), $orderTotalString);
                $customerOrder->add_order_note($note);
                $customerOrder->payment_complete();
                Reach_Log::log($note, 'info', 'PAYMENT COMPLETED');
            } else {

                if (array_key_exists('Authorized', $checkoutCall['data']) && $checkoutCall['data']['Authorized'] == true) {
                    $note = sprintf(__('Payment authorized for %s , awaiting capture...', 'sbwc-reach-cc'), $orderTotalString);
                } else {
                    $note = sprintf(__('Payment authorizing for %s', 'sbwc-reach-cc'), $orderTotalString);
                }

                $customerOrder->add_order_note(__($note, 'sbwc-reach-cc'));
                Reach_Log::log($note, 'info');

                // Add note for fraud review
                if (array_key_exists('UnderReview', $checkoutCall['data']) && $checkoutCall['data']['UnderReview'] == true) {
                    $noteFraud = __('Payment is under review. Fulfillment should not continue until the payment has been approved.', 'sbwc-reach-cc');
                    $customerOrder->add_order_note($noteFraud);
                    Reach_Log::log($note, 'info');
                }
            }

            // Empty cart
            WC()->cart->empty_cart();

            // Return redirect
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($customerOrder)
            );
        }
    }

endif;
