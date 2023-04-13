<?php

defined('ABSPATH') ?: exit();

use WC_Abstract_Order;
use WC_Geolocation;
use WC_Order;
use WC_Payment_Gateway;
use WC_Payment_Gateways;
use WC_Tax;

if (!class_exists('Reach_Helpers')) :
    
    class Reach_Helpers {

        /**
         * Get the signature required for all Reach Reach_API requests/responses
         * @param $body
         * @param $secret_key
         * @return string
         */
        public static function getSignature(string $body, $secret_key) {
            return base64_encode(hash_hmac('sha256', $body, $secret_key, TRUE));
        }

        /**
         * Calculate rounded amount
         * Check if currency is zero decimal
         *
         * @param WC_Abstract_Order $order
         * @param float $amount
         * @return float|int
         */
        public static function getRoundedAmount(WC_Abstract_Order $order, $amount) {
            $userSelectedCurrencyRate = (float)get_post_meta($order->get_id(), 'user_selected_currency_rate', true);

            // Remove thousand separator
            $thousand_sep = get_option('woocommerce_price_thousand_sep') ?: ',';
            if (!empty($thousand_sep)) {
                $amount = str_replace($thousand_sep, '', $amount);
            }

            if (in_array(strtoupper($userSelectedCurrencyRate), Reach_API::ZERO_DECIMAL_CURRENCIES)) {
                $rounded_amount = intval(round($amount));
            } else {
                $rounded_amount = round($amount, 2);
                $rounded_amount = number_format($rounded_amount, 2, '.', '');
            }

            return $rounded_amount;
        }

        /**
         * Calculate converted amount
         * Check user selected currency rate and merchant default currency rate
         * Divide the amount to the base, then multiply to the user selected rate
         * Example: Merchant Default is BGN (1.7186 BGN = 1 USD). User is paying in EUR (0.8495 EUR = 1 USD).
         * So if it's 100 BGN and want to calculate in EUR the price then calculate like this = 100 / 1.7186 * 0.8495
         *
         *
         * @param WC_Abstract_Order $order
         * @param float $amount
         * @return float|int
         */
        public static function getConvertedAmount(WC_Abstract_Order $order, $amount) {
            
            $userSelectedCurrencyRate = (float)get_post_meta($order->get_id(), 'user_selected_currency_rate', true);
            $baseCurrencyRate         = (float)get_post_meta($order->get_id(), 'base_currency_rate', true);

            // Only convert if rate is available, otherwise leave as is.
            if (!empty($userSelectedCurrencyRate) && !empty($baseCurrencyRate)) {
                $amount = $amount / $baseCurrencyRate * $userSelectedCurrencyRate;
                $amount = number_format(round($amount, 2), 2);
            }

            return self::getRoundedAmount($order, $amount);
        }

        /**
         * USED
         * Get consumer IP address, hard code if local 127.0.0.1
         * @return string
         */
        public static function getUserIP() {
            // Handle Cloudflare IP proxy
            if (!empty($_SERVER["HTTP_CF_CONNECTING_IP"])) {
                $consumer_ip = $_SERVER["HTTP_CF_CONNECTING_IP"];
                // TODO log 'Cloudflare detected, using connecting IP instead: $consumer_id
            } else {
                $consumer_ip = $_SERVER['REMOTE_ADDR'];
            }
            // Hard Code a test IP if running locally.
            if (!filter_var($consumer_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE |  FILTER_FLAG_NO_RES_RANGE)) {
                $consumer_ip = '46.249.80.24'; // Grind IP
            }
            return $consumer_ip;
        }


        /**
         * Get Current User Country (Geolocation) – WooCommerce
         * !!! Geolocation must be enabled @ Woo Settings >> General >> Default customer location
         * @see https://www.businessbloomer.com/woocommerce-detecting-current-user-country-geolocation/
         * @return string
         */
        public static function getUserCurrencyBasedOnIp() {
            // Geolocation must be enabled @ Woo Settings
            $location = WC_Geolocation::geolocate_ip(); // bg ip - '46.249.80.24'; usa ip 52.93.134.180

            if (!$location['country']) {
                return false;
            }

            $currency = self::getCurrencyBasedOnCountryCode($location['country']);

            if (!$currency) {
                return false;
            }

            return $currency;
        }


        /**
         * USED
         * Get a string with the order total and conversion info
         *
         * @param WC_Order $order
         * @return string
         */
        public static function getOrderTotalString(WC_Order $order) {
            // Custom order total with conversion info
            $user_selected_currency       = get_post_meta($order->get_id(), 'user_selected_currency', true);
            $user_selected_currency_rate  = get_post_meta($order->get_id(), 'user_selected_currency_rate', true);
            $order_total_from_woocommerce = $order->get_total();
            $order_total_converted        = self::getConvertedAmount($order, $order->get_total());
            $order_total_str              = $order_total_from_woocommerce . ' x ' . $user_selected_currency_rate . ' ≈ ' . $order_total_converted . ' ' . $user_selected_currency;

            return $order_total_str;
        }

        /**
         * USED
         * Get a string with the refund and conversion info
         *
         * @param WC_Order $order
         * @param float $amount
         * @param float|bool $finalAmount Override with the actual amount if provided.
         * @return string
         */
        public static function getOrderRefundString(WC_Order $order, $amount, $finalAmount = false) {
            // Custom order total with conversion info
            $user_selected_currency      = get_post_meta($order->get_id(), 'user_selected_currency', true);
            $user_selected_currency_rate = get_post_meta($order->get_id(), 'user_selected_currency_rate', true);
            $amount_converted            = $finalAmount !== false ? $finalAmount : self::getConvertedAmount($order, $amount);
            $order_refund_str            = $amount . ' x ' . $user_selected_currency_rate . ' ≈ ' . $amount_converted . ' ' . $user_selected_currency;
            return $order_refund_str;
        }

        /**
         * Get the Reach Gateway from WC
         *
         * @return WC_Payment_Gateway
         */
        public static function getReachGateway() {
            $gateway_controller = WC_Payment_Gateways::instance();
            $all_gateways       = $gateway_controller->payment_gateways();
            $gateway            = $all_gateways['reach_cc'];
            return $gateway;
        }

        /**
         * Retrieve the WC order id from a Reach Transactional OrderId
         * Example: '65566f9d-6a8b-42c7-88be-ed0c2e6072f0'
         * Internally in WC, we are saving the Reach Transactional OrderId as the transaction_id of the WC order.
         *
         * @param $transactional_id
         * @return null
         */
        public static function getOrderIdByOrderTransactionalId($transactional_id) {
            // Convert to internal WC order_id (which is a post id)
            global $wpdb;
            $table_postmeta = $wpdb->prefix . "postmeta";
            $post_id_arr = $wpdb->get_col("
                    SELECT post_id
                    FROM $table_postmeta
                    WHERE meta_key = '_transaction_id'
                    AND meta_value = '$transactional_id'
                ");

            if (!empty($post_id_arr)) {
                return $post_id_arr[0];
            } else {
                return null;
            }
        }

        /**
         * Retrieve a note for the order based on an OrderState
         *
         * @param string $order_state The OrderState from GoInterpay
         * @param bool|string $pre_note An optional prepended note
         * @return string
         */
        public static function getNoteFromOrderState($order_state, $pre_note = false) {

            switch ($order_state) {
                case 'Processing':
                    $note = 'A payment attempt requiring external authentication is in progress.';
                    break;
                case 'PaymentAuthorized':
                    $note = 'Payment has been authorized.';
                    break;
                case 'Processed':
                    $note = 'Payment has completed successfully.';
                    break;
                case 'ProcessingFailed':
                    $note = 'The last payment attempt was unsuccessful.';
                    break;
                case 'Cancelled':
                    $note = 'The order has been cancelled.';
                    break;
                case 'Declined':
                    $note = 'The payment attempt has been declined due to a fraud review. Fulfillment should not continue.';
                    break;
                default:
                    $note = 'Invalid OrderState';
            }

            $note = ($pre_note !== false ? $pre_note : '') . $note;

            return $note;
        }

        /**
         * USED
         * Filter out certain error codes to use a generic message
         * @param $msg
         * @return string
         */
        public static function filterErrorMessage($msg) {
            switch ($msg) {
                case 'PaymentAuthorizationFailed':
                    $new_msg = __('Unfortunately authentication failed and is required for this transaction. Please try again.', 'sbwc-reach-cc');
                    break;
                case 'FraudSuspected':
                    $new_msg = __('We are sorry, but your payment cannot be processed at this time.', 'sbwc-reach-cc');
                    break;
                case 'Error processing stash: CardNumberInvalid':
                    $new_msg = __('Oops! Is that the correct number on your card? Please try entering the number again.', 'sbwc-reach-cc');
                    break;
                case 'CardVerificationCodeInvalid':
                    $new_msg = __('The verification code for your card is invalid. Please check you verification code and try again.', 'sbwc-reach-cc');
                    break;
                case 'CurrencyNotFound':
                    $new_msg = __('We are sorry, but the currency used for this transaction is not supported by Reach.', 'sbwc-reach-cc');
                    break;
                case 'EmailAddressInvalid':
                    $new_msg = __('Please provide a valid email address.', 'sbwc-reach-cc');
                    break;
                case 'PaymentMethodUnsupported':
                    $new_msg = __('We are sorry, but the payment method you are using is either not supported by Reach, or not approved by the merchant.', 'sbwc-reach-cc');
                    break;
                case 'CardNameInvalid':
                    $new_msg = __('The card name you provided is invalid, please provide a valid name.', 'sbwc-reach-cc');
                    break;
                case 'CardNumberInvalid':
                    $new_msg = __('The card number you provided is invalid, please provide a valid number.', 'sbwc-reach-cc');
                    break;
                case 'CardYearInvalid':
                    $new_msg = __('The card year you provided is invalid, please provide a valid year.', 'sbwc-reach-cc');
                    break;
                case 'CardMonthInvalid':
                    $new_msg = __('The card month you provided is invalid, please provide a valid month.', 'sbwc-reach-cc');
                    break;
                case 'CardExpired':
                    $new_msg = __('The card you provided has expired, please provide alternative card details.', 'sbwc-reach-cc');
                    break;
                case 'PostalCodeInvalid':
                    $new_msg = __('The postal code you provided is invalid.', 'sbwc-reach-cc');
                    break;
                case 'CountryInvalid':
                    $new_msg = __('The country you provided is invalid.', 'sbwc-reach-cc');
                    break;
                case 'AmountLimitExceeded':
                    $new_msg = __('We are sorry, but the transaction exceeds the maximum allowed amount for your card. Please try using a different card.', 'sbwc-reach-cc');
                    break;
                case 'Blacklisted':
                    $new_msg = __('We are sorry, but your payment cannot be processed at this time.', 'sbwc-reach-cc');
                    break;
                case 'PhoneInvalid':
                    $new_msg = __('Please provide a valid phone number.', 'sbwc-reach-cc');
                    break;
                case 'RegionInvalid':
                    $new_msg = __('Please provide a valid region.', 'sbwc-reach-cc');
                    break;
                case 'IssuerInvalid':
                    $new_msg = __('Your card issuer is not recognized by Reach, or is invalid. Please try using a different card.', 'sbwc-reach-cc');
                    break;
                case 'ConsumerInvalid':
                    $new_msg = __('It looks like the personal details you provided is invalid. Please check these details and try again.', 'sbwc-reach-cc');
                    break;
                case 'ConsigneeInvalid':
                    $new_msg = __('It looks like the personal details you provided is invalid. Please check these details and try again.', 'sbwc-reach-cc');
                    break;
                case 'AuthenticationRequired':
                    $new_msg = __('We are sorry, but this transaction requires in person authentication. Please try using a different card.', 'sbwc-reach-cc');
                    break;
                case 'PaymentAuthenticationCancelled':
                    $new_msg = __('This transaction could not be completed because you cancelled it.', 'sbwc-reach-cc');
                    break;
                case 'AlreadyCancelled':
                    $new_msg = __('We are sorry, but this transaction has been cancelled. Please try using a different card.', 'sbwc-reach-cc');
                    break;
                case 'PaymentFailed':
                    $new_msg = __('We are sorry, your payment was authorized but could not be completed. Please try using a different card.', 'sbwc-reach-cc');
                    break;
                default:
                    $new_msg = $msg;
            }
            return $new_msg;
        }

        /**
         * Get the month in int from card expiry date (12 / 22)
         * @param $cardExpiryDate
         * @return string
         */
        public static function getMonthFromExpiryInput($cardExpiryDate) {
            $exploded = explode('/', $cardExpiryDate);
            if (!$exploded[0]) {
                return false;
            }
            return (int) trim($exploded[0]);
        }

        /**
         * Get the year in int from card expiry date (12 / 22)
         * @param $cardExpiryDate
         * @return string
         */
        public static function getYearFromExpiryInput($cardExpiryDate) {
            $exploded = explode('/', $cardExpiryDate);
            if (!$exploded[1]) {
                return false;
            }
            // if user enter 12 / 2030 for example return 2030
            if ((int)trim($exploded[1]) > 999) {
                return (int) trim($exploded[1]);
            }

            // if user enter 12 / 30 for example return 2030
            return 2000 + (int)trim($exploded[1]);
        }

        /**
         * The 6-digit IIN (Issuer Identification Number) of the card.
         * Previously known as the BIN, this is the first 6 digits of the card for which info should be fetched.
         * Only values between 100000 and 999999 are accepted.
         * @param $cardNumber
         * @return string
         */
        public static function getCardIIN($cardNumber) {

            $cardNumber = str_replace(' ', '', $cardNumber);
            $cardNumber = substr($cardNumber, 0, 6);

            return (int)$cardNumber;
        }

        /**
         * Our own custom float comparison to replace use of bccomp()
         *
         * This is used because some installations may be missing the libbcmath extension or have it disabled.
         *
         * @param $val
         * @param $val2
         * @return int
         */
        public static function compareFloats($val, $val2) {

            $val_expanded  = intval((float)$val * 100);
            $val2_expanded = intval((float)$val2 * 100);

            if ($val_expanded > $val2_expanded) {
                $compare = 1;
            } elseif ($val_expanded === $val2_expanded) {
                $compare = 0;
            } else {
                $compare = -1;
            }

            return $compare;
        }

        /**
         * Generate a UUID
         *
         * Copied from @see http://guid.us/GUID/PHP
         *
         * @return string
         */
        public static function getUUID() {

            $charid = strtoupper(md5(uniqid(random_int(PHP_INT_MIN, PHP_INT_MAX), true)));

            $hyphen = chr(45); // "-"

            $uuid = substr($charid, 0, 8) . $hyphen
                . substr($charid, 8, 4) . $hyphen
                . substr($charid, 12, 4) . $hyphen
                . substr($charid, 16, 4) . $hyphen
                . substr($charid, 20, 12);

            return $uuid;
        }

        /**
         * Only Credit Cards and Paypal support Delayed Capture (Capture: false)
         * All other Payment Methods (e.g. Sofort) Require Authorization and Capture to happen at the same time (Capture: true)
         * even if delayed capture is activated from the Reach Gateway Settings
         *
         * @param string $method
         * @return bool
         */
        public static function checkIfDelayedCaptureAllowed($method) {
            // Methods which support Delayed Capture: true
            $methodsCaptureFalseAllowed = ['CARD', 'PAYPAL'];
            if (in_array($method, $methodsCaptureFalseAllowed)) {
                return true;
            }
            return false;
        }

        /**
         * Get Currency Position Format (left or right position, or with spaces)
         *
         * @param string $currencyPosition
         * @return string
         */
        public static function getCurrencyPositionFormat($currencyPosition) {
            if (!$currencyPosition) {
                return '%1$s%2$s'; // default format
            }

            switch ($currencyPosition) {
                case 'left':
                    $format = '%1$s%2$s';
                    break;
                case 'right':
                    $format = '%2$s%1$s';
                    break;
                case 'left_space':
                    $format = '%1$s&nbsp;%2$s';
                    break;
                case 'right_space':
                    $format = '%2$s&nbsp;%1$s';
                    break;
                default:
                    $format = '%1$s%2$s';
                    break;
            }
            return $format;
        }

        /**
         * Get Currency Code (USD) based on Country Code (US)
         * @see https://docs.withreach.com/docs/currencies-and-countries
         * @param $countryCode
         * @return string
         */
        public static function getCurrencyBasedOnCountryCode($countryCode) {
            switch ($countryCode) {
                case "AE":
                    $currency = "AED";
                    break;
                case "AU":
                    $currency = "AUD";
                    break;
                case "BR":
                    $currency = "BRL";
                    break;
                case "CA":
                    $currency = "CAD";
                    break;
                case "CH":
                    $currency = "CHF";
                    break;
                case "CL":
                    $currency = "CLP";
                    break;
                case "CN":
                    $currency = "CNY";
                    break;
                case "CO":
                    $currency = "COP";
                    break;
                case "CZ":
                    $currency = "CZK";
                    break;
                case "DK":
                    $currency = "DKK";
                    break;
                case "EG":
                    $currency = "EGP";
                    break;
                case "AT":
                    $currency = "EUR";
                    break;
                case "BE":
                    $currency = "EUR";
                    break;
                case "BG":
                    $currency = "BGN";
                    break;
                case "DE":
                    $currency = "EUR";
                    break;
                case "EE":
                    $currency = "EUR";
                    break;
                case "IE":
                    $currency = "EUR";
                    break;
                case "ES":
                    $currency = "EUR";
                    break;
                case "FR":
                    $currency = "EUR";
                    break;
                case "GR":
                    $currency = "EUR";
                    break;
                case "HR":
                    $currency = "EUR";
                    break;
                case "IT":
                    $currency = "EUR";
                    break;
                case "CY":
                    $currency = "EUR";
                    break;
                case "LV":
                    $currency = "EUR";
                    break;
                case "LT":
                    $currency = "EUR";
                    break;
                case "LU":
                    $currency = "EUR";
                    break;
                case "HU":
                    $currency = "EUR";
                    break;
                case "MT":
                    $currency = "EUR";
                    break;
                case "NL":
                    $currency = "EUR";
                    break;
                case "PL":
                    $currency = "EUR";
                    break;
                case "PT":
                    $currency = "EUR";
                    break;
                case "SP":
                    $currency = "EUR";
                    break;
                case "RO":
                    $currency = "EUR";
                    break;
                case "SI":
                    $currency = "EUR";
                    break;
                case "SK":
                    $currency = "EUR";
                    break;
                case "FI":
                    $currency = "EUR";
                    break;
                case "UK":
                    $currency = "EUR";
                    break;
                case "GB":
                    $currency = "GBP";
                    break;
                case "HK":
                    $currency = "HKD";
                    break;
                case "HG":
                    $currency = "HUF";
                    break;
                case "ID":
                    $currency = "IDR";
                    break;
                case "IL":
                    $currency = "ILS";
                    break;
                case "IN":
                    $currency = "INR";
                    break;
                case "JP":
                    $currency = "JPY";
                    break;
                case "KR":
                    $currency = "KRW";
                    break;
                case "MX":
                    $currency = "MXN";
                    break;
                case "NO":
                    $currency = "NOK";
                    break;
                case "NZ":
                    $currency = "NZD";
                    break;
                case "PE":
                    $currency = "PEN";
                    break;
                case "PH":
                    $currency = "PHP";
                    break;
                case "QA":
                    $currency = "QAR";
                    break;
                case "RU":
                    $currency = "RUB";
                    break;
                case "SA":
                    $currency = "SAR";
                    break;
                case "SE":
                    $currency = "SEK";
                    break;
                case "SG":
                    $currency = "SGD";
                    break;
                case "TH":
                    $currency = "THB";
                    break;
                case "TW":
                    $currency = "TWD";
                    break;
                case "US":
                    $currency = "USD";
                    break;
                case "UY":
                    $currency = "UYU";
                    break;
                case "ZA": // south africa same as saudi arabia ?? ANSWER: NO
                    $currency = "ZAR";
                    break;

                default:
                    $currency = "";
            }

            return $currency;
        }

        /**
         * Strip the original tags added by the WC Converter Widget to get the number only
         *
         * @param string $html_str
         * @return bool|string
         */
        public static function stripWCConverterTags($html_str) {
            // $html_str format is like:
            // <span class="woocommerce-Price-amount amount"><span class="woocommerce-Price-currencySymbol">&#36;</span>160.00</span> <small class="woocommerce-Price-taxLabel tax_label">(ex. tax)</small>

            // Trim tax tags
            $number_str = preg_replace('/<small class="woocommerce-Price-taxLabel tax_label">.[A-Za-z .()]*<\/small>/', '', $html_str);

            // Trim currency tags
            //$number_str = preg_replace('/<span class="woocommerce-Price-currencySymbol">.[&#\d;]*<\/span>/', '', $number_str);

            // Trim <bdi> tag
            //$number_str = preg_replace('/<bdi>.[&#\d;]*<\/bdi>/', '', $number_str); // seems that not working
            $number_str = preg_replace('@<(\w+)\b.*?>.*?</\1>@si', '', $number_str); // Remove all HTML tags from PHP string with content!

            //var_dump($number_str); exit;

            // Trim html entities (ie. &#36; == $)
            $number_str = preg_replace('/&[#\da-zA-Z]*;/', '', $number_str);

            // Trim all other strings
            $number_str = preg_replace('/[^\d\.]+/', '', $number_str);

            // Trim remaining amount tag
            // e.g. <span class="woocommerce-Price-amount amount">160.00</span>
            $number_str = strip_tags($number_str);

            // Trim spaces
            $number_str = trim($number_str);

            return $number_str;
        }

        /**
         * Custom function to get formatted order total.
         *
         * Display for admin:   $166.95 ($1,039.45 HKD)
         * With refund:         <del>$166.95</del> <ins>$50.00</ins> (<del>$1,039.45</del> <ins>$300.00</ins> HKD)
         *
         * Display for user:    $1,039.45 HKD
         * With refund:         <del>$1,039.45</del> <ins>$300.00</ins> HKD
         *
         * Based directly from @see WC_Order::get_formatted_order_total()
         *
         * Used to filter the order total @see Hooks::filterFormattedOrderTotal
         *
         * @param WC_Order $order
         * @param string $tax_display Type of tax display.
         * @param bool $display_refunded If should include refunded value.
         *
         * @return string
         */
        public static function getFormattedOrderTotal(WC_Order $order, $tax_display = '', $display_refunded = true) {
            $currency_position = get_option('woocommerce_currency_pos');
            $price_format = self::getCurrencyPositionFormat($currency_position);

            // Base currency
            $currency                  = $order->get_currency();
            $currency_symbol           = get_woocommerce_currency_symbol($currency);
            $order_total               = $order->get_total();
            $formatted_total           = sprintf($price_format, $currency_symbol, self::numberFormat($order, $order_total));
            $total_refunded            = $order->get_total_refunded();
            $remaining_total           = $order_total - $total_refunded;
            $formatted_remaining_total = sprintf($price_format, $currency_symbol, self::numberFormat($order, $remaining_total));
            $tax_string                = '';

            // Target currency
            $user_selected_currency              = get_post_meta($order->get_id(), 'user_selected_currency', true);
            $user_selected_currency_symbol       = get_woocommerce_currency_symbol($user_selected_currency);
            $converted_total                     = self::getConvertedAmount($order, $order->get_total());
            $formatted_converted_total           = sprintf($price_format, $user_selected_currency_symbol, self::numberFormat($order, $converted_total));
            $converted_remaining_total           = self::getConvertedAmount($order, $remaining_total);
            $formatted_converted_remaining_total = sprintf($price_format, $user_selected_currency_symbol, self::numberFormat($order, $converted_remaining_total));


            // Tax for inclusive prices.
            if (wc_tax_enabled() && 'incl' === $tax_display) {

                $tax_string_array = array();
                $tax_totals       = $order->get_tax_totals();

                if ('itemized' === get_option('woocommerce_tax_total_display')) {
                    foreach ($tax_totals as $code => $tax) {
                        $tax_amount         = ($total_refunded && $display_refunded) ? wc_price(WC_Tax::round($tax->amount - $order->get_total_tax_refunded_by_rate_id($tax->rate_id)), array('currency' => $order->get_currency())) : $tax->formatted_amount;
                        $tax_string_array[] = sprintf('%s %s', $tax_amount, $tax->label);
                    }
                } elseif (!empty($tax_totals)) {
                    $tax_amount         = ($total_refunded && $display_refunded) ? $order->get_total_tax() - $order->get_total_tax_refunded() : $order->get_total_tax();
                    $tax_string_array[] = sprintf('%s %s', wc_price($tax_amount, array('currency' => $order->get_currency())), WC()->countries->tax_or_vat());
                }

                if (!empty($tax_string_array)) {
                    /* translators: %s: taxes */
                    $tax_string = ' <small class="includes_tax">' . sprintf(__('(includes %s)', 'woocommerce'), implode(', ', $tax_string_array)) . '</small>';
                }
            }

            if (get_post_type() === 'shop_order') {

                // For admin
                if ($total_refunded && $display_refunded) {
                    $modified_formatted_total = '<del>' . $formatted_total . '</del> <ins>' . $formatted_remaining_total . '</ins> (<del>' . $formatted_converted_total . '</del> <ins>' . $formatted_converted_remaining_total . '</ins> ' . ')' . $tax_string;
                } else {
                    $modified_formatted_total = $formatted_total . ' (' . $formatted_converted_total . ')' . $tax_string;
                }
            } else {

                // For user
                if ($total_refunded && $display_refunded) {
                    $modified_formatted_total = '<del>' . $formatted_converted_total . '</del> <ins>' . $formatted_converted_remaining_total . '</ins> ' . $tax_string;
                } else {
                    $modified_formatted_total = $formatted_converted_total . ' ' . $tax_string;
                }
            }

            return $modified_formatted_total;
        }


        /**
         * Format the currency string
         *
         * Use our own number format function that factors in the type of currency, ie. 0-decimal currency or not.
         *
         * @param WC_Abstract_Order $order
         * @param $number_str
         * @return string
         */
        public static function numberFormat(WC_Abstract_Order $order, $number_str) {
            $thousand_sep = get_option('woocommerce_price_thousand_sep');
            $decimal_sep  = get_option('woocommerce_price_decimal_sep');

            // Remove thousand seperator
            if (!empty($thousand_sep)) {
                $number_str = str_replace($thousand_sep, '', $number_str);
            }

            // Replace decimal seperator with period
            if (!empty($decimal_sep)) {
                $number_str = str_replace($decimal_sep, '.', $number_str);
            } else {
                // Defaults to period, so we're good.
            }

            $number_str = (float)$number_str;


            $user_selected_currency = get_post_meta($order->get_id(), 'user_selected_currency', true);

            if (in_array(strtoupper($user_selected_currency), Reach_API::ZERO_DECIMAL_CURRENCIES)) {
                $formatted_number = number_format($number_str);
            } else {
                $formatted_number = number_format($number_str, 2);
            }

            return $formatted_number;
        }
    }

endif;
