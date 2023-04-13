<?php

defined('ABSPATH') ?: exit();

if (!class_exists('Reach_Response')) :

    class Reach_Response {

        /**
         * Parse the responses from the Reach API
         *
         * All POST response entities will be an x-www-form-urlencoded string with
         * response and signature fields. Response field will be JSON data.
         * Response data is verified by the the included signature.
         *
         * @param $response_body
         * @param $merchant_secret_key
         * @return array
         */
        public static function parse($response_body, $merchant_secret_key) {
            $response_json = null;
            $signature     = null;

            parse_str($response_body, $parsedVars);   // $parsedVars['response'], $parsedVars['signature']

            $response_json = urldecode($parsedVars['response']);  // {"OrderId": "981bbdaa-861f-4c1c-900b-0d4721dcedfb", "Error":{"Code":"PaymentAuthorizationFailed"} }
            $signature     = $parsedVars['signature'];            // 1AHdc3SJYMWDxtCgV4vGy83L  // TODO urldecode replace plus sign in signature with space!

            // Default return values
            $parse_success = false;
            $data          = null;

            // Verify response by signature
            $calculated_signature = Reach_Helpers::getSignature($response_json, $merchant_secret_key);

            Reach_Log::log($signature, 'info', 'parsed signature: ');
            Reach_Log::log($calculated_signature, 'info', 'generated signature: ');

            if ($signature === $calculated_signature) {
                $parse_success = true;
                $data          = json_decode($response_json, true);
            }

            Reach_Log::log(($parse_success ? 'true' : 'false'), 'info', 'response success: ');
            Reach_Log::log($response_json, 'info', 'response data: ');

            return [$parse_success, $data];
        }

        /**
         * Parse the notifications from the Reach API
         *
         * Response field will be JSON data.
         * Response data is verified by the the included signature.
         *
         * @param array $post_data
         * @param $merchant_secret_key
         * @return array
         */
        public static function parseNotification($post_data, $merchant_secret_key) {
            // For some reason the json is escaped with backslashes
            $response_json = stripslashes($post_data['request']);
            $signature     = $post_data['signature'];

            // Default return values
            $parse_success = false;
            $data          = null;

            // Verify response by signature
            $calculated_signature = Reach_Helpers::getSignature($response_json, $merchant_secret_key);

            Reach_Log::log($signature, 'info', 'parsed signature: ');
            Reach_Log::log($calculated_signature, 'info', 'generated signature: ');

            if ($signature === $calculated_signature) {
                $parse_success = true;
                $data          = json_decode($response_json, true);
            }

            Reach_Log::log(($parse_success ? 'true' : 'false'), 'info', 'Notification response success: ');
            Reach_Log::log($response_json, 'info', 'Notification response data: ');

            return [$parse_success, $data];
        }

        /**
         * Parse the query string from the return URLs
         *
         * Response data is verified by the the included signature.
         *
         * @param array $getData
         * @param $merchant_secret_key
         * @return array
         */
        public static function parseReturnUrlQueryString($getData, $merchant_secret_key) {
            // For some reason the json is escaped with backslashes
            $response_json = stripslashes($getData['response']);
            $signature     = $getData['signature'];

            // Default return values
            $parse_success = false;
            $data          = null;

            // Verify response by signature
            $calculated_signature = Reach_Helpers::getSignature($response_json, $merchant_secret_key);


            Reach_Log::log($signature, 'info', 'parsed signature: ');
            Reach_Log::log($calculated_signature, 'info', 'generated signature: ');

            if ($signature === $calculated_signature) {
                $parse_success = true;
                $data          = json_decode($response_json, true);
            }

            Reach_Log::log(($parse_success ? 'true' : 'false'), 'info', 'Return Url Notification response success: ');
            Reach_Log::log($response_json, 'info', 'Return Url Notification response data: ');

            return [$parse_success, $data];
        }
    }

endif;
