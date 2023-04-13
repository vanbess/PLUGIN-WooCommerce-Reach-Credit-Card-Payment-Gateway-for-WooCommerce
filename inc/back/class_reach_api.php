<?php

defined('ABSPATH') ?: exit();

if (!class_exists('Reach_API')) :

    class Reach_API {

        const API_LIVE_URL = 'https://checkout.rch.io/v2.21';
        const API_TEST_URL = 'https://checkout.rch.how/v2.21';
        // const API_LIVE_URL = 'https://checkout.gointerpay.net/v2.21';
        // const API_TEST_URL = 'https://checkout-sandbox.gointerpay.net/v2.21';
        //const API_TEST_URL = 'https://checkout.rch.how/v2.18';

        const API_STASH_LIVE_URL = 'https://stash.gointerpay.net';
        const API_STASH_TEST_URL = 'https://stash.rch.how';
        // const API_STASH_LIVE_URL = 'https://stash.gointerpay.net';
        // const API_STASH_TEST_URL = 'https://stash-sandbox.gointerpay.net';
        //const API_STASH_TEST_URL = 'https://stash.rch.how/';

        /**
         * The list of 0 decimal currencies which must be handled differently.
         *
         * @see https://docs.withreach.com/docs/displaying-currencies
         *
         * @var array
         */
        const ZERO_DECIMAL_CURRENCIES = [
            'BIF',
            'BYR',
            'CLF',
            'CLP',
            'CVE',
            'DJF',
            'GNF',
            'ISK',
            'JPY',
            'KMF',
            'KRW',
            'PYG',
            'RWF',
            'UGX',
            'UYI',
            'VND',
            'VUV',
            'XAF',
            'XOF',
            'XPF'
        ];

        /**
         * Retrieve payment methods
         *
         * @param array $gatewaySettings
         * @param string $countryCode
         * @param string $userSelectedCurrency
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function getPaymentMethodsAPICall(array $gatewaySettings, $countryCode = 'US', $userSelectedCurrency = 'USD') {

            $url = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/getPaymentMethods';
            $getParams = '?MerchantId=' . $gatewaySettings['merchant_id'] .
                '&Country=' . $countryCode .
                '&Currency=' . $userSelectedCurrency;

            //Reach_Log::log($url . $getParams, 'debug', '/GET_PAYMENT_METHODS CALL');

            list($parse_success, $err_msg, $data) = Reach_Request::process('get', $url . $getParams);

            $success = false;
            if ($parse_success) {
                if (array_key_exists('PaymentMethods', $data) && is_array($data['PaymentMethods'])) {
                    $data    = $data['PaymentMethods'];
                    $success = true;
                }
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data
            ];

            //Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Perform API /checkout request
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param WC_Order $order
         * @param array $formParams
         * @param array $gatewaySettings
         * @param bool $openContract  // If true, a contract (used for recurring billing, subscriptions, stored payment options, etc.) will be opened if possible.
         * @return array [$success, $err_msg, $data]
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function checkoutAPICall(WC_Order $order, array $formParams, array $gatewaySettings, $openContract = false) {

            $url     = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/checkout';
            $hasCard = ($formParams['reach-payment-class'] == 'Card');

            if ($hasCard) {

                // Stash Card Details and get StashId or throw error
                $card = [
                    'Number' => str_replace(' ', '', $formParams['reach-card-number']),
                    'Name'   => $formParams['billing_first_name'] . ' ' . $formParams['billing_last_name'],
                    'Expiry' => [
                        'Year'  => Reach_Helpers::getYearFromExpiryInput($formParams['reach-card-expiry']),
                        'Month' => Reach_Helpers::getMonthFromExpiryInput($formParams['reach-card-expiry'])
                    ],
                    'VerificationCode' => $formParams['reach-card-cvc']
                ];

                Reach_Log::log(json_encode($card), 'debug', '/STASH CALL PAYLOAD: ');

                $stashRequest = self::stashAPICall($card, $formParams['reach-device-fingerprint'], $gatewaySettings);
                
                // Throw error and show error notices on wrong card details
                if (!$stashRequest['success']) {
                    return [
                        'success' => $stashRequest['success'],
                        'err_msg' => $stashRequest['err_msg'],
                        'data'    => $stashRequest['data']
                    ];
                }

                // Get Card Type - Visa, MC, Diners,...
                $cardDetailsRequest =  self::getCardInfoAPICall(Reach_Helpers::getCardIIN($formParams['reach-card-number']), $gatewaySettings);
                // Throw error and show notices on wrong request or can't get card type for some reason
                if (!$cardDetailsRequest['success'] || !$cardDetailsRequest['data']['PaymentMethod']) {
                    return [
                        'success' => $cardDetailsRequest['success'],
                        'err_msg' => $cardDetailsRequest['err_msg'],
                        'data'    => $cardDetailsRequest['data']
                    ];
                }
            }

            // Check the PaymentMethod (if it's Card we call getCardInfoAPICall to get CC type Visa, MC, else just get PAYPAL, BOLERO, ...)
            $paymentMethod = $hasCard ? $cardDetailsRequest['data']['PaymentMethod'] : get_post_meta($order->get_id(), 'reach_payment_method', true);
            $userSelectedCurrency = get_post_meta($order->get_id(), '_order_currency', true) ? get_post_meta($order->get_id(), '_order_currency', true) : get_woocommerce_currency();

            // Prepare the payload for checkout call
            $payload = array(
                // Credentials and API Info
                "MerchantId"            => $gatewaySettings['merchant_id'], // The 36 character GUID which identifies the merchant to the Reach system
                "ReferenceId"           => 'wc_order_' . $order->get_id() . '_' . $gatewaySettings['merchant_id'], //  // An optional string identifying the order in the merchant’s order management system. If specified, this is returned in the notification of a processed order,...
                "DeviceFingerprint"     => $formParams['reach-device-fingerprint'], // hidden input from the CC form
                "PaymentMethod"         => $paymentMethod, // Card Type - Visa, MC, Diners,...  or Online Type - Paypal, ... or Offline Type - Bolero, ... or iDEAL -> need to specify the Issuer Name
                "ConsumerCurrency"      => $userSelectedCurrency, // The upper-case 3 character ISO 4217 currency code which the shopper has indicated is their preferred currency. (example 'USD', 'BGN', ..)
                "AcceptLiability"       => FALSE, // AcceptLiabilityFlag=TRUE: Merchant using their own Fraud; AcceptLiability=FALSE: Merchant using Reach Fraud
                "Capture"               => true, // If true or the payment method used by the consumer does not support pre-authorization, the payment will be completed.
                "Items"                 => self::getOrderItems($order),
                "Charges"               => self::getOrderCharges($order), // TODO check for extra charge
                "Discounts"             => self::getOrderDiscounts($order, false), // TODO check for extra discount
                "ConsumerTotal"         => self::getReachSummedTotal($order), //Reach_Helpers::getConvertedAmount($order, $order->get_total()), // Order total
                "Consumer"              => self::getOrderConsumer($order, []), // AKA billing contact.
                "Notify"                => site_url('/woocommerce-gateway-reach-notifications'), // An optional URL that will override the default notification URL configured for the merchant. All notifications related to this order, including refunds, will go to this override.
                "StashId"               => null,
                "Return"                => site_url('/woocommerce-gateway-reach-return-url'), // If it requires external auth like sms pin, etc, we need to provide a Return Url that handles Reach notifications (https://docs.withreach.com/docs/states-and-events)
            );

            // The 36 character GUID for a guaranteed foreign exchange rate. If omitted, the rate will be determined at the time of settlement and all prices and breakdowns must be specified in the consumer currency.
            // if ($baseCurrencyCurrencyRateId) {
            //     $payload["RateOfferId"] = $userSelectedCurrencyRateId;
            // }

            // Add Shipping to the payload
            $payload["Shipping"] = [
                "ConsumerPrice" => ($order->get_shipping_total() > 0) ? Reach_Helpers::getConvertedAmount($order, $order->get_shipping_total()) : 0,   // FIX send zero value -> ALWAYS send Shipping and Consignee object
                "ConsumerTaxes" => ($order->get_total_tax() > 0) ? Reach_Helpers::getConvertedAmount($order, $order->get_total_tax()) : 0,
                "ConsumerDuty"  => 0
            ];

            $payload["Consignee"] = self::getOrderConsignee($order); //AKA shipping contact.

            // If it's card payment
            if ($hasCard) {
                $payload["StashId"] = $stashRequest['data']['StashId'];
            }

            if (!$hasCard) {
                $payload["Return"] = site_url('/woocommerce-gateway-reach-return-url');
            }

            Reach_Log::log(json_encode($payload), 'debug', '/CHECKOUT CALL PAYLOAD: ');

            // Get Request Signature
            $signature = Reach_Helpers::getSignature(json_encode($payload), $gatewaySettings['merchant_secret_key']);

            Reach_Log::log($signature, 'debug', '/CHECKOUT CALL SIGNATURE: ');

            // Perform the /Checkout request
            $postBody = [
                'form_params' => [
                    'request'   => json_encode($payload),
                    'signature' => $signature,
                ]
            ];

            $success = false;

            list($parse_success, $err_msg, $data) = Reach_Request::process('post', $url, $gatewaySettings['merchant_secret_key'], $postBody);

            if ($parse_success) {

                if (array_key_exists('Error', $data)) {
                    $err_msg = 'Error processing checkout';
                    if (array_key_exists('Code', $data['Error'])) {
                        $err_msg = $data['Error']['Code'];
                    }
                } elseif (array_key_exists('OrderId', $data) && !empty($data['OrderId'])) {
                    try {
                        $order->set_transaction_id($data['OrderId']); // NOTE: transaction id is set ONLY if there is NOT an error (we can't perform /query request later if checkout call has errors)
                        $order->save();
                        $success = true;
                    } catch (WC_Data_Exception $e) {
                        $err_msg = 'Invalid OrderId';
                    }
                }
            }

            return [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data
            ];
        }


        /**
         * Perform API /capture request
         * Capture an authorized order
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param WC_Order $order
         * @param array $gatewaySettings
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function captureAPICall(WC_Order $order, array $gatewaySettings) {

            $url = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/capture';

            // Prepare the payload for capture call
            $payload = [
                "MerchantId" => $gatewaySettings['merchant_id'],
                "OrderId" => $order->get_transaction_id() // f31c4279-d3c0-4102-8244-4eae0ac70fb9
            ];

            Reach_Log::log(json_encode($payload), 'debug', '/CAPTURE CALL PAYLOAD: ');

            // Get Request Signature
            $signature = Reach_Helpers::getSignature(json_encode($payload), $gatewaySettings['merchant_secret_key']);

            Reach_Log::log($signature, 'debug', '/CAPTURE CALL SIGNATURE: ');

            $postBody = [
                'form_params' => [
                    'request'   => json_encode($payload),
                    'signature' => $signature
                ]
            ];

            $success = false;

            list($parse_success, $err_msg, $data) = Reach_Request::process('post', $url, $gatewaySettings['merchant_secret_key'], $postBody);

            if ($parse_success) {
                if (array_key_exists('Error', $data)) {
                    $err_msg = 'Error processing capture';
                    if (array_key_exists('Code', $data['Error'])) {
                        $err_msg = $data['Error']['Code'];
                    }
                } elseif (array_key_exists('OrderId', $data) && !empty($data['OrderId'])) {
                    $success = true;
                }
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data
            ];

            Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Perform API Stash request
         * The Reach Stash allows the temporary storage of client data for use in subsequent Checkout API requests.
         * @docs https://docs.withreach.com/docs/stash
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param array $card Data to be stashed
         * @param string $deviceFingerprint
         * @param array $gatewaySettings
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        protected static function stashAPICall(array $card, string $deviceFingerprint, array $gatewaySettings) {
            $url = $gatewaySettings['test_environment'] ? self::API_STASH_TEST_URL . '/' . $gatewaySettings['merchant_id'] . '/' . Reach_Helpers::getUUID()
                : self::API_STASH_LIVE_URL . '/' . $gatewaySettings['merchant_id'] . '/' . Reach_Helpers::getUUID();

            $postBody = [
                'form_params' => [
                    'DeviceFingerprint' => $deviceFingerprint,
                    'card' => json_encode($card),
                ]
            ];
            list($parse_success, $err_msg, $data) = Reach_Request::process('post_stash', $url, $gatewaySettings['merchant_secret_key'], $postBody);

            $success = false;
            if ($parse_success) {
                if (array_key_exists('Warnings', $data) && !empty($data['Warnings'])) {
                    $err_msg = 'Error processing stash: ' . implode(', ', $data['Warnings']);
                } elseif (array_key_exists('StashId', $data)) {
                    $stash_id = $data['StashId'];
                    $success = true;
                } else {
                    $err_msg = 'Invalid StashId';
                }
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data
            ];

            Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Perform API /query request
         * Query Order information from the Reach system.
         * If the order is not found, an HTTP 404 response will be
         * returned. If there is a system problem with querying the order, an HTTP 503 response will be returned and the
         * request should be tried again.
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param WC_Order $order
         * @param array $gatewaySettings
         * @param string $orderIdentifier - OrderId or ReferenceId or ContractId (The Reach order identifier returned in the checkout response, or a ReferenceId
         *                           specified by the merchant in the checkout request, or a ContractId returned when a Reach contract has been created.)
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function queryAPICall(WC_Order $order, array $gatewaySettings, $orderIdentifier = 'OrderId') {

            $url = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/query';

            $payload = [
                "MerchantId" => $gatewaySettings['merchant_id'],
            ];

            if ($orderIdentifier == 'OrderId') {
                $payload[$orderIdentifier] = $order->get_transaction_id(); // '65566f9d-6a8b-42c7-88be-ed0c2e6072f0'
            }

            if ($orderIdentifier == 'ReferenceId') {
                $payload[$orderIdentifier] = 'wc_order_' . $order->get_id() . '_' . $gatewaySettings['merchant_id'];
            }

            if ($orderIdentifier == 'ContractId') {
                $payload[$orderIdentifier] = ''; // TODO ContractId returned when Reach contract has been created
            }

            Reach_Log::log(json_encode($payload), 'debug', '/QUERY CALL PAYLOAD: ');

            $signature = Reach_Helpers::getSignature(json_encode($payload), $gatewaySettings['merchant_secret_key']);

            $postBody = [
                'form_params' => [
                    'request'   => json_encode($payload),
                    'signature' => $signature
                ]
            ];

            $success = false;

            list($parse_success, $err_msg, $data) = Reach_Request::process('post', $url, $gatewaySettings['merchant_secret_key'], $postBody);

            if ($parse_success) {
                if (array_key_exists('OrderId', $data) && !empty($data['OrderId'])) {
                    $success = true;
                } else {
                    $err_msg = 'Invalid OrderId';
                }
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data
            ];

            Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Perform API /getCardInfo request
         * Fetches information about a card based on its IIN and return Card Type (VISA, MC, AMEX, DINERS, ...)
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param int $cardIIN The 6-digit IIN (Issuer Identification Number) of the card. Previously known as the BIN,
         *                      this is the first 6 digits of the card for which info should be fetched.
         *                      Only values between 100000 and 999999 are accepted.
         * @param array $gatewaySettings
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        protected static function getCardInfoAPICall(int $cardIIN, array $gatewaySettings) {

            $url       = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/getCardInfo';
            $getParams = '?MerchantId=' . $gatewaySettings['merchant_id'] . '&IIN=' . $cardIIN;

            Reach_Log::log($getParams, 'debug', '/GetCardInfo CALL');

            list($parse_success, $err_msg, $data) = Reach_Request::process('get', $url . $getParams);

            $success = false;

            if ($parse_success && array_key_exists('PaymentMethod', $data) && !empty($data['PaymentMethod'])) {
                $success = true;
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data
            ];

            Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Perform API /badge request
         * Retrieve the badge
         * Reach Logo & Privacy Policy on Checkout
         *
         * @param array $gatewaySettings
         * @param string $currency
         * @param $consumerIP
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function getBadgeAPICall(array $gatewaySettings, $currency = 'USD', $consumerIP) {
            $url = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/badge';
            $getParams = '?MerchantId=' . $gatewaySettings['merchant_id'] .
                '&Currency=' . $currency .
                '&ConsumerIpAddress=' . $consumerIP;

            //Log::log($url . $getParams, 'debug', '/BADGE CALL');

            list($parse_success, $err_msg, $data) = Reach_Request::process('get', $url . $getParams);

            $success = false;
            if ($parse_success) {
                $success = true;
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data
            ];

            //Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Perform API /refund request
         * Submit a full or partial refund for an order that has been captured.
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param WC_Order $order
         * @param array $gatewaySettings
         * @param float $amount
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function refundAPICall(WC_Order $order, array $gatewaySettings, $amount) {

            $finalAmount = null;

            // Get More Info About The Order with /query call
            $queryAPICall = self::queryAPICall($order, $gatewaySettings, 'OrderId');

            if (!$queryAPICall['success']) {
                return $queryAPICall;
            }

            // Calculate local and Reach amounts
            $finalAmount       = $reachRefundAmount = Reach_Helpers::getConvertedAmount($order, $amount);
            $amountAfterRefund = $order->get_remaining_refund_amount();

            if ($amountAfterRefund > 0) {
                // Partial refund.

            } else {

                // Full refund or refunding remaining amount.
                $reachConsumerTotal = $queryAPICall['data']['ConsumerTotal'];
                $reachRefundedAmount = array_reduce($queryAPICall['data']['Refunds'], function ($carry, $item) {
                    return $carry + $item['ConsumerAmount'];
                });
                $reachRemainingRefundAmount = Reach_Helpers::getRoundedAmount($order, $reachConsumerTotal - $reachRefundedAmount);

                $reachAmountAfterRefund = Reach_Helpers::getRoundedAmount($order, $reachRemainingRefundAmount - $reachRefundAmount);

                // Test if reach amount after refund is +/- 5 of the lowest unit.
                // For 0-decimal currencies (JPY/KRW etc.) this is an integer value of 5, otherwise it
                // will be a float value of 0.05 (CAD/USD) to account for cents.
                // If so, we will simply refund the remainder.
                // -1/0 equates to '<='
                if (is_int($reachAmountAfterRefund)) {
                    $delta = 5;
                } else {
                    $delta = 0.05;
                }
                $comp = Reach_Helpers::compareFloats(abs($reachAmountAfterRefund), $delta);
                if ($comp === -1 || $comp === 0) {
                    $finalAmount = $reachRemainingRefundAmount;
                } else {

                    // The amount is greater than the allowed delta.
                    // We will throw an error and stop execution.
                    $success = false;
                    $err_msg = 'Error with refund - amount after refund is greater than allowed delta: ' . $reachAmountAfterRefund;
                    Reach_Log::log($err_msg, 'error');

                    $result = [
                        'success'     => $success,
                        'err_msg'     => $err_msg,
                        'data'        => $queryAPICall['data'],
                        'finalAmount' => $finalAmount
                    ];
                    return $result;
                }
            }

            Reach_Log::log('', 'debug', '/REFUND CALL');

            Reach_Log::logRefund([
                'amount'                     => $amount,
                'amountAfterRefund'          => $amountAfterRefund,
                'reachRemainingRefundAmount' => $reachRemainingRefundAmount,
                'reachRefundAmount'          => $reachRefundAmount,
                'reachAmountAfterRefund'     => $reachAmountAfterRefund,
                'finalAmount'                => $finalAmount,
            ]);

            // Perform API /refund request
            $url = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/refund';

            // Prepare the payload for refund call
            $payload = [
                "MerchantId"  => $gatewaySettings['merchant_id'],
                "OrderId"     => $order->get_transaction_id(),                                                              // f31c4279-d3c0-4102-8244-4eae0ac70fb9
                "ReferenceId" => 'wc_refund_' . $order->get_id() . '_' . $gatewaySettings['merchant_id'] .  '_' . time(),
                "Amount"      => $finalAmount
            ];

            Reach_Log::log(json_encode($payload), 'debug', '/REFUND CALL PAYLOAD: ');

            // Get Request Signature
            $signature = Reach_Helpers::getSignature(json_encode($payload), $gatewaySettings['merchant_secret_key']);

            Reach_Log::log($signature, 'debug', '/REFUND CALL SIGNATURE: ');

            $postBody = [
                'form_params' => [
                    'request'   => json_encode($payload),
                    'signature' => $signature
                ]
            ];

            $success = false;

            list($parse_success, $err_msg, $data) = Reach_Request::process('post', $url, $gatewaySettings['merchant_secret_key'], $postBody);

            if ($parse_success) {
                if (array_key_exists('Error', $data)) {
                    $err_msg = 'Error processing refund';
                    if (array_key_exists('Code', $data['Error'])) {
                        $err_msg = $data['Error']['Code'];
                    }
                } elseif (array_key_exists('RefundId', $data) && !empty($data['RefundId'])) {
                    $success = true;
                }
            }

            $result = [
                'success'     => $success,
                'err_msg'     => $err_msg,
                'data'        => $data,
                'finalAmount' => $finalAmount
            ];

            Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Perform API /cancel request
         * Cancel a pre-authorized order. This requires that payment has not been captured.
         * Used only with a Cancel Order button in the admin (Check Hooks.php)
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param WC_Order $order
         * @param array $gatewaySettings
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function cancelAPICall(WC_Order $order, array $gatewaySettings) {

            $url = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/cancel';

            // Prepare the payload for cancel call
            $payload = [
                "MerchantId" => $gatewaySettings['merchant_id'],
                "OrderId"    => $order->get_transaction_id(),      // f31c4279-d3c0-4102-8244-4eae0ac70fb9
            ];

            Reach_Log::log(json_encode($payload), 'debug', '/CANCEL ORDER CALL PAYLOAD: ');

            // Get Request Signature
            $signature = Reach_Helpers::getSignature(json_encode($payload), $gatewaySettings['merchant_secret_key']);

            Reach_Log::log($signature, 'debug', '/CANCEL ORDER CALL SIGNATURE: ');

            $postBody = [
                'form_params' => [
                    'request'   => json_encode($payload),
                    'signature' => $signature
                ]
            ];

            $success = false;

            list($parse_success, $err_msg, $data) = Reach_Request::process('post', $url, $gatewaySettings['merchant_secret_key'], $postBody);

            if ($parse_success) {
                if (array_key_exists('Error', $data)) {
                    $err_msg = 'Error processing cancel';
                    if (array_key_exists('Code', $data['Error'])) {
                        $err_msg = $data['Error']['Code'] . (!empty($data['Error']['Message']) ? ' - ' . $data['Error']['Message'] : '');
                    }
                } elseif (array_key_exists('OrderId', $data) && !empty($data['OrderId'])) {
                    $success = true;
                }
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data,
            ];

            Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }


        /**
         * Perform API /modify request
         * Modify an order before capture has been initiated. Some fields may only be modified before authorization,
         * as indicated below. After authorization, the total amount of the order may not exceed the authorized amount.
         * Used only with a Modify Order button in the admin (@see Hooks.php)
         * @docs https://withreach.com/docs/GoInterpayCheckoutAPIGuide-v2r020.pdf
         *
         * @param WC_Order $order
         * @param array $gatewaySettings
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function modifyAPICall(WC_Order $order, array $gatewaySettings) {

            $url = ($gatewaySettings['test_environment'] ? self::API_TEST_URL : self::API_LIVE_URL) . '/modify';

            // Find the extra charge or discount
            list($extra_charge, $extra_discount) = self::getReachTotalOffset($order);

            // Prepare the payload for cancel call
            $payload = [
                "MerchantId"    => $gatewaySettings['merchant_id'],                    // The 36 character GUID which identifies the merchant to the Reach system
                "OrderId"       => $order->get_transaction_id(),                       // f31c4279-d3c0-4102-8244-4eae0ac70fb9
                "Items"         => self::getOrderItems($order),
                "Charges"       => self::getOrderCharges($order, $extra_charge),
                "Discounts"     => self::getOrderDiscounts($order, $extra_discount),
                "ConsumerTotal" => self::getReachSummedTotal($order),                  // Reach_Helpers::getConvertedAmount($order, $order->get_total()), // Order total
            ];

            // Add Shipping to the payload
            $payload["Shipping"] = [
                "ConsumerPrice" => ($order->get_shipping_total() > 0) ? Reach_Helpers::getConvertedAmount($order, $order->get_shipping_total()) : 0,   // FIX send zero value -> ALWAYS send Shipping and Consignee object
                "ConsumerTaxes" => ($order->get_total_tax() > 0) ? Reach_Helpers::getConvertedAmount($order, $order->get_total_tax()) : 0,
                "ConsumerDuty"  => 0
            ];
            $payload["Consignee"] = self::getOrderConsignee($order); //AKA shipping contact.

            Reach_Log::log(json_encode($payload), 'debug', '/MODIFY CALL PAYLOAD: ');

            // Get Request Signature
            $signature = Reach_Helpers::getSignature(json_encode($payload), $gatewaySettings['merchant_secret_key']);

            Reach_Log::log($signature, 'debug', '/MODIFY CALL SIGNATURE: ');

            $postBody = [
                'form_params' => [
                    'request'   => json_encode($payload),
                    'signature' => $signature
                ]
            ];

            $success = false;
            list($parse_success, $err_msg, $data) = Reach_Request::process('post', $url, $gatewaySettings['merchant_secret_key'], $postBody);

            if ($parse_success) {
                if (array_key_exists('Error', $data)) {
                    $err_msg = 'Error processing modify';
                    if (array_key_exists('Code', $data['Error'])) {
                        $err_msg = $data['Error']['Code'] . (!empty($data['Error']['Message']) ? ' - ' . $data['Error']['Message'] : '');
                    }
                } elseif (array_key_exists('OrderId', $data) && !empty($data['OrderId'])) {
                    $success = true;
                }
            }

            $result = [
                'success' => $success,
                'err_msg' => $err_msg,
                'data'    => $data,
            ];

            Reach_Log::log(json_encode($result), 'debug', 'RESPONSE DATA');

            return $result;
        }

        /**
         * Format Order Items to match Reach API requirements
         * @param WC_Order $order
         * @return array
         */
        private static function getOrderItems(WC_Order $order) {
            return array_values(array_map(function ($obj) use ($order) {
                /* @var $obj WC_Order_Item_Product */
                return [
                    "Description"   => $obj->get_product()->get_name(),
                    "ConsumerPrice" => Reach_Helpers::getConvertedAmount($order, ($obj->get_subtotal() / $obj->get_quantity())),
                    "Quantity"      => $obj->get_quantity(),
                    "Sku"           => $obj->get_product()->get_id(),
                ];
            }, $order->get_items()));
        }

        /**
         * Format Order Consumer to match Reach API checkout/modify request
         *
         * AKA billing contact.
         *
         * @param WC_Order $order
         * @param array $options
         * @return array
         */
        private static function getOrderConsumer(WC_Order $order, $options = []) {
            $consumer = [
                "Name"       => $order->get_formatted_billing_full_name(),
                "Company"    => $order->get_billing_company(),
                "Email"      => $order->get_billing_email(),
                "Phone"      => $order->get_billing_phone(),
                "Address"    => $order->get_billing_address_1() . (!empty($order->get_billing_address_2()) ? ' ' . $order->get_billing_address_2() : ''),
                "City"       => $order->get_billing_city(),
                "Region"     => $order->get_billing_state(),
                "PostalCode" => $order->get_billing_postcode(),
                "Country"    => $order->get_billing_country(),
                "IpAddress"  => Reach_Helpers::getUserIP()                                                                                                 // Will return 'Unusable address' if 127.0.0.1 is sent
            ];

            if (!empty($options)) {
                $consumer = array_merge($consumer, $options);
            }

            // Convert empty strings to null
            array_walk($consumer, function (&$item) {
                $item = !empty($item) ? $item : null;
            });

            return $consumer;
        }

        /**
         * Format Order Consignee to match Reach API checkout/modify request
         *
         * AKA shipping contact.
         *
         * @param WC_Order $order
         * @param bool $use_billing
         * @return array
         */
        private static function getOrderConsignee(WC_Order $order, $use_billing = false) {

            // Use the billing if true, or if the order is missing shipping information
            if ($use_billing || empty($order->get_shipping_country())) {
                $consignee = [
                    "Name"       => $order->get_formatted_billing_full_name(),
                    "Company"    => $order->get_billing_company(),
                    "Email"      => $order->get_billing_email(),                                                                                                // WC has billing values for these
                    "Phone"      => $order->get_billing_phone(),                                                                                                // WC has billing values for these
                    "Address"    => $order->get_billing_address_1() . (!empty($order->get_billing_address_2()) ? ' ' . $order->get_billing_address_2() : ''),
                    "City"       => $order->get_billing_city(),
                    "Region"     => $order->get_billing_state(),
                    "PostalCode" => $order->get_billing_postcode(),
                    "Country"    => $order->get_billing_country(),
                ];
            } else {
                $consignee = [
                    "Name"       => $order->get_formatted_shipping_full_name(),
                    "Company"    => $order->get_shipping_company(),
                    "Address"    => $order->get_shipping_address_1() . (!empty($order->get_shipping_address_2()) ? ' ' . $order->get_shipping_address_2() : ''),
                    "City"       => $order->get_shipping_city(),
                    "Region"     => $order->get_shipping_state(),
                    "PostalCode" => $order->get_shipping_postcode(),
                    "Country"    => $order->get_shipping_country(),
                ];
            }

            // Convert empty strings to null
            array_walk($consignee, function (&$item) {
                $item = !empty($item) ? $item : null;
            });

            return $consignee;
        }


        /**
         * Format discounts for Reach API checkout/modify request
         *
         * We're adding 1 discount with the names of each coupon used along with the total discount amount
         * This is because we're unable to get the individual discount amounts that each coupon charges
         *
         * @param WC_Order $order
         * @param float|bool $extra_discount Used to offset any differences
         * @return array|null
         */
        private static function getOrderDiscounts(WC_Order $order, $extra_discount = false) {

            $discounts = [];

            if (!empty($order->get_coupon_codes())) {
                $discounts[] = [
                    "Name"          => implode(",", $order->get_coupon_codes()),
                    "ConsumerPrice" => Reach_Helpers::getConvertedAmount($order, $order->get_total_discount()),
                ];
            }

            if ($extra_discount !== false) {
                $discounts[] = [
                    "Name"          => "Balancing Discount",
                    "ConsumerPrice" => Reach_Helpers::getConvertedAmount($order, $extra_discount)
                ];
            }

            return !empty($discounts) ? $discounts : null;
        }

        /**
         * Format charges for Reach checkout/modify request
         *
         * @param WC_Order $order
         * @param float|bool $extra_charge Used to offset any differences
         * @return array|null
         */
        private static function getOrderCharges(WC_Order $order, $extra_charge = false) {

            $charges = array_values(array_map(function ($obj) use ($order) {
                /* @var $obj WC_Order_Item_Fee */
                return [
                    "Name"          => $obj->get_name(),
                    "ConsumerPrice" => (Reach_Helpers::getConvertedAmount($order, $obj->get_amount())) * -1,
                ];
            }, $order->get_fees()));

            if ($extra_charge !== false) {
                $charges[] = [
                    "Name"          => "Balancing Charge",
                    "ConsumerPrice" => Reach_Helpers::getRoundedAmount($order, $extra_charge)
                ];
            }

            return !empty($charges) ? $charges : null;
        }

        /**
         * Find the extra charge or discount needed to balance the summed total to the converted total.
         *
         * Used in modify call
         *
         * @param WC_Order $order
         * @return array
         */
        private static function getReachTotalOffset(WC_Order $order) {

            $summed_total    = self::getReachSummedTotal($order);
            $converted_total = Reach_Helpers::getConvertedAmount($order, $order->get_total());

            // Compare floats using bc function to a precision of 2 decimals
            $compare        = Reach_Helpers::compareFloats($converted_total, $summed_total);
            $difference     = abs(Reach_Helpers::getRoundedAmount($order, $converted_total - $summed_total));
            $extra_charge   = false;
            $extra_discount = false;

            if ($compare === 0) {
                // Do nothing.
            } elseif ($compare === 1) {
                $extra_charge = $difference;
            } else {
                $extra_discount = $difference;
            }

            return [$extra_charge, $extra_discount];
        }

        /**
         * Calculate the total using the sum method
         *
         * The total is calculated by converting order items individually and summing them up.
         * This is the way Reach calculates the total on their end, so we need to
         * get the same total on our end to be able to find any differences, so we can send a
         * correct 'ConsumerTotal'.
         *
         * The differences will be handled by @see self::getReachTotalOffset()
         *
         * Used in modify call
         *
         * @param WC_Order $order
         * @return float|int
         */
        private static function getReachSummedTotal(WC_Order $order) {

            $items     = self::getOrderItems($order);
            $charges   = self::getOrderCharges($order);
            $discounts = self::getOrderDiscounts($order);
            $shipping  = [
                "ConsumerPrice" => Reach_Helpers::getConvertedAmount($order, $order->get_shipping_total()),
                "ConsumerTaxes" => Reach_Helpers::getConvertedAmount($order, $order->get_total_tax()),
                "ConsumerDuty"  => 0
            ];

            $total = 0;
            foreach ($items as $item) {
                $total += $item['Quantity'] * $item['ConsumerPrice'];
            }
            if (is_array($charges)) {
                foreach ($charges as $charge) {
                    $total += $charge['ConsumerPrice'];
                }
            }
            if (is_array($discounts)) {
                foreach ($discounts as $discount) {
                    $total -= $discount['ConsumerPrice'];
                }
            }
            $total += $shipping['ConsumerPrice'] + $shipping['ConsumerTaxes'];

            return Reach_Helpers::getRoundedAmount($order, $total);
        }
    }

endif;
