<?php

defined('ABSPATH') ?: exit();

if (!class_exists('Reach_Notifications')) :

    class Reach_Notifications {

        /**
         * Notification events are sent to merchants via asynchronous HTTP POST
         * requests in order to synchronize the merchant's payment information with Reach's information.
         * /woocommerce-gateway-reach-notifications/
         */
        public static function handleNotifications() {
            $request_path = substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?'));

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['REQUEST_URI'], '/woocommerce-gateway-reach-notifications') === 0) {

                Reach_Log::log('=======================Notification==============================');
                Reach_Log::log($_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . $request_path, 'debug');
                Reach_Log::log(json_encode($_POST), 'debug', '$_POST');
                Reach_Log::log(json_encode($_SERVER), 'debug', '$_SERVER');

                $err_msg = false;

                // Get Reach Gateway Class
                $gateway = Reach_Helpers::getReachGateway();
                if (!$gateway->settings) {
                    return false;
                }

                // Get the settings and the secret key depending the environment
                $gatewaySettings = $gateway->settings;
                $merchantSecretKey = $gatewaySettings['test_environment'] == 'yes' ? $gatewaySettings['test_merchant_secret_key'] : $gatewaySettings['live_merchant_secret_key'];

                list($parse_success, $data) = Reach_Response::parseNotification($_POST, $merchantSecretKey);

                if ($parse_success) {

                    Reach_Log::log(json_encode($data), 'debug', 'Notification Data');

                    if (array_key_exists('OrderId', $data)) {

                        $transactional_order_id = $data['OrderId']; // '65566f9d-6a8b-42c7-88be-ed0c2e6072f0'
                        $order_id = Reach_Helpers::getOrderIdByOrderTransactionalId($transactional_order_id);

                        if (!empty($order_id)) {

                            $order = wc_get_order($order_id);

                            if (array_key_exists('OrderId', $data) && !empty($data['OrderId'])) {

                                $pre_note = 'Notification: ';

                                $note = Reach_Helpers::getNoteFromOrderState($data['OrderState'], $pre_note);

                                // Parse Order States
                                switch ($data['OrderState']) {
                                    case 'Processing':
                                        $order->add_order_note(__($note, 'sbwc-reach-cc'));
                                        break;
                                    case 'PaymentAuthorized':
                                        $order->add_order_note(__($note, 'sbwc-reach-cc'));
                                        break;
                                    case 'Processed':
                                        $order->update_status('processing', __($note, 'sbwc-reach-cc'));
                                        break;
                                    case 'ProcessingFailed':
                                        $order->add_order_note(__($note, 'sbwc-reach-cc'));
                                        break;
                                    case 'Cancelled':
                                        $order->update_status('cancelled', __($note, 'sbwc-reach-cc'));
                                        break;
                                    case 'Declined':
                                        if ($data['UnderReview'] == false) {

                                            // Only process if the order has a 'under_review' meta value set
                                            $post_meta_under_review = get_post_custom_values('under_review', $order->get_id());
                                            if (!empty($post_meta_under_review) && $post_meta_under_review[0] == 'yes') {
                                                $order->add_order_note(__($note, 'sbwc-reach-cc'));
                                                delete_post_meta($order->get_id(), 'under_review');
                                            }
                                        }
                                        break;
                                }

                                // Parse Refunds
                                if (!empty($data['Refunds']) && array_key_exists('State', $data['Refunds'])) {
                                    switch ($data['Refunds']['State']) {
                                        case 'Succeeded':
                                            $order->add_order_note(__($pre_note . 'Refund succeeded.', 'sbwc-reach-cc'));
                                            break;
                                        case 'Failed':
                                            $order->add_order_note(__($pre_note . 'Refund failed.', 'sbwc-reach-cc'));
                                            break;
                                    }
                                }

                                // Parse Reviews
                                if ($data['UnderReview'] == true) {
                                    $order->add_order_note(__($pre_note . 'A fraud review has been opened for this order.', 'sbwc-reach-cc'));
                                    add_post_meta($order->get_id(), 'under_review', 'yes', true);
                                } elseif ($data['UnderReview'] == false) {

                                    // Only process if the order has a 'under_review' meta value set
                                    $post_meta_under_review = get_post_custom_values('under_review', $order->get_id());
                                    if (!empty($post_meta_under_review) && $post_meta_under_review[0] == 'yes') {
                                        $order->add_order_note(__($pre_note . 'The payment attempt has been approved and is no longer under review. Fulfillment may continue.', 'sbwc-reach-cc'));
                                        delete_post_meta($order->get_id(), 'under_review');
                                    }
                                }
                            } else {
                                $err_msg = 'Invalid OrderId';
                            }
                        } else {
                            $err_msg = 'Order not found from transaction id (' . $transactional_order_id . ')';

                            // We will send a 200 OK back to Reach to stop sending this notification
                            // since the order is deleted for failed /checkout calls.
                            Reach_Log::log($err_msg, 'error', 'We will send a 200 OK back to Reach to stop sending this notification since the order is deleted for failed /checkout calls');

                            die(200);
                        }
                    } else {
                        $err_msg = 'Missing OrderId';
                    }
                } else {
                    $err_msg = 'Parse failed';
                }

                if ($err_msg) {
                    Reach_Log::log($err_msg, 'error', 'Notification Processing Error');

                    wp_send_json([
                        'error_msg' => $err_msg
                    ], 400);
                } else {
                    die(200);
                }

                //wp_die($err_msg, 200);
            }
        }


        /**
         * Handle payment return page for online/offline payment processing
         * /woocommerce-gateway-reach-return-url
         */
        public static function handleReturnUrl() {
            $request_path = substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?'));

            if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos($_SERVER['REQUEST_URI'], '/woocommerce-gateway-reach-return-url') === 0) {

                Reach_Log::log('=======================Return URL Notification==============================');
                Reach_Log::log($_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . $request_path, 'debug');
                Reach_Log::log(json_encode($_GET), 'debug', '$_GET');
                Reach_Log::log(json_encode($_SERVER), 'debug', '$_SERVER');

                // Get Reach Gateway Class
                $gateway = Reach_Helpers::getReachGateway();

                if (!$gateway->settings) {
                    return false;
                }

                // Get the settings and the secret key depending the environment
                $gatewaySettings = $gateway->settings;
                $merchantSecretKey = $gatewaySettings['test_environment'] == 'yes' ? $gatewaySettings['test_merchant_secret_key'] : $gatewaySettings['live_merchant_secret_key'];

                // Parse Data From Return Url String
                list($parse_success, $data) = Reach_Response::parseReturnUrlQueryString($_GET, $merchantSecretKey);

                if ($parse_success) {

                    if (array_key_exists('OrderId', $data)) {

                        if (array_key_exists('Error', $data)) {
                            $err_msg = 'Error processing return url';
                            if (array_key_exists('Code', $data['Error'])) {
                                $err_msg = $data['Error']['Code'];
                            }

                            // Redirect back to checkout page
                            wp_redirect(site_url() . '?page_id=' . get_option('woocommerce_checkout_page_id') . '&Error=' . $err_msg);
                            exit;
                        } else {

                            $transactional_order_id = $data['OrderId'];
                            $order_id = Reach_Helpers::getOrderIdByOrderTransactionalId($transactional_order_id);
                            $order = wc_get_order($order_id);

                            if (array_key_exists('Captured', $data) && $data['Captured'] == true) {
                                $order->add_order_note(__('Payment captured.', 'sbwc-reach-cc'));
                                $order->payment_complete();
                            } else {

                                // Add note from OrderState
                                $note = Reach_Helpers::getNoteFromOrderState($data['OrderState'], 'Payment Return Page: ');
                                $order->add_order_note($note, 'sbwc-reach-cc');

                                // Add note for fraud review
                                if (array_key_exists('UnderReview', $data) && $data['UnderReview'] == true) {
                                    $order->add_order_note(__('Payment is under review. Fulfillment should not continue until the payment has been approved.', 'sbwc-reach-cc'));
                                }
                            }

                            // Remove cart
                            WC()->cart->empty_cart();

                            // Redirect
                            $order_received_url = $gateway->get_return_url($order);
                            wp_redirect($order_received_url);
                            exit;
                        }
                    } else {
                        $err_msg = 'Invalid OrderId';
                    }
                } else {
                    $err_msg = 'Parse failed';
                }

                if ($err_msg) {
                    Reach_Log::log($err_msg, 'error', 'Return Url Notification Error');
                }

                wp_die($err_msg, 200);
            }
        }
    }

endif;
