<?php

defined('ABSPATH') ?: exit();

if (!class_exists('Reach_Request')) :

    class Reach_Request {

        /**
         * Process the request.
         * Called mainly from Api class
         *
         * @param string $method
         * @param string $url
         * @param array $merchant_secret_key Required for post requests.
         * @param null|array $post_body Required for post requests.
         * @return array
         * @throws \GuzzleHttp\Exception\GuzzleException
         */
        public static function process($method, $url, $merchant_secret_key = null, $post_body = null) {

            $parse_success = false;
            $err_msg       = null;
            $data          = null;

            try {
                $client = new \GuzzleHttp\Client();

                if (strtolower($method) == 'get') {
                    $response = $client->request($method, $url);
                } elseif (strtolower($method) == 'post_stash') {
                    $response = $client->request('post', $url, $post_body);
                } elseif (strtolower($method) == 'post') {
                    $response = $client->request('post', $url, $post_body);
                } else {
                    die('Invalid request method.');
                }

                $response_status_code = $response->getStatusCode();
                $response_body        = (string) $response->getBody();

                Reach_Log::log($response_status_code, 'info', 'response status code: ');
                Reach_Log::log($response_body, 'info', 'response body: ');

                if ($response_status_code == 200) {

                    if (strtolower($method) == 'get') {
                        $parse_success = true;
                        $data          = json_decode($response_body, true);
                    } elseif (strtolower($method) == 'post') {
                        //var_dump($response_body); exit;
                        list($parse_success, $data) = Reach_Response::parse($response_body, $merchant_secret_key);

                        if ($parse_success) {
                            $parse_success = true;
                        } else {
                            $err_msg = 'Invalid response';
                        }
                    } else {

                        $parse_success = true;
                        $data = json_decode($response_body, true);
                    }
                } else {
                    $err_msg = 'Response error - status ' . $response_status_code;
                }
            } catch (\GuzzleHttp\Exception\TransferException $e) {

                $err_msg = $e->getMessage();
                
                if ($e instanceof \GuzzleHttp\Exception\ClientException) {

                    // Handle 502 errors specifically to catch the un-styled 'Proxy E' error
                    // From testing, it happens when calling stash sandbox.
                    // From logs:
                    //   Server error: `POST https://stash-sandbox.gointerpay.net/5fd973f3-ce1b-45e4-8aeb-30a3dcc2c547/C2EE4D85-BD60-8E20-76AD-3095D1179FA8` resulted in a `502 Proxy Error`
                    if ($e->getResponse()->getStatusCode() == 502) {
                        $err_msg = 'Network error, please try again later.';
                    } else {
                        $err_msg = $e->getResponse()->getBody();
                    }
                }
                Reach_Log::log($err_msg, 'error', 'REQUEST TRY_CATCH err_msg: ');
            }

            return [$parse_success, $err_msg, $data];
        }
    }

endif;
