<?php

defined('ABSPATH') ?: exit();

use WC_Order;

if (!class_exists('Reach_Log')) :

    class Reach_Log {

        /**
         * WooCommerce debug error logging
         * Log API calls, responses and payloads
         *
         * @param $error_msg
         * @param string $logType (debug, info, notice, error, critical, ...)
         * @param string $prependText
         */
        public static function log($error_msg, $logType = 'info', $prependText = '') {
            $logger = wc_get_logger();
            $logger->log($logType, $prependText . $error_msg, ['source' => 'gateway-reach-log']);
        }

        /**
         * Log Order Details to be able to debug API calls and errors easier
         *
         * @param WC_Order $order
         * @param string $logType
         */
        public static function logOrder(WC_Order $order, $logType = 'info') {

            // Calculate fee total
            $fee_total            = round($order->get_total() - $order->get_subtotal() - $order->get_shipping_total() - $order->get_total_tax() + $order->get_total_discount(), 2);
            $userSelectedCurrency = get_post_meta($order->get_id(), '_order_currency', true);
            $baseCurrency         = get_woocommerce_currency(); 

            self::log('=========================================================');
            self::log('OrderId:             ' . $order->get_id());
            self::log('Order Currency:      ' . $userSelectedCurrency);
            self::log('Base Currency:       ' . $baseCurrency);
            self::log('Subtotal:            ' . $order->get_subtotal());
            self::log('Shipping Total:      ' . $order->get_shipping_total());
            self::log('Fee Total:           ' . $fee_total);
            self::log('Discount Total:      ' . $order->get_total_discount());
            self::log('Tax Total:           ' . $order->get_total_tax());
            self::log('Grand Total:         ' . $order->get_total());

            self::log('-------------------------Items----------------------------');
            $items = $order->get_items();
            foreach ($items as $item_id => $item) {
                self::log(json_encode([
                    'item_id'             => $item_id,
                    'product_id'          => $item->get_product_id(),
                    'name'                => $item->get_name(),
                    'price'               => Reach_Helpers::getConvertedAmount($order, $item->get_total()) . $userSelectedCurrency,
                    'price_base_currency' => $item->get_total() . $baseCurrency,
                ]));
            }
            self::log('-------------------------Fees----------------------------');
            $fees = $order->get_fees();
            self::log(json_encode($fees));
            self::log('-------------------------Coupons--------------------------');
            $discounts = $order->get_coupon_codes();
            self::log(json_encode($discounts));
        }

        /**
         * Log Refund to be able to debug API calls and errors easier
         *
         * @param array $data
         * @param string $logType
         */
        public static function logRefund(array $data, $logType = 'info') {
            self::log('======================== REFUND =================================');
            self::log('Amount:                        ' . $data['amount']);
            self::log('Amount after refund:           ' . $data['amountAfterRefund']);
            if (!($data['amountAfterRefund'] > 0)) {
                self::log('Remaining Reach refund amount: ' . $data['reachRemainingRefundAmount']);
                self::log('Reach amount:                  ' . $data['reachRefundAmount']);
                self::log('Reach amount after refund:     ' . $data['reachAmountAfterRefund']);
            }
            self::log('Amount to refund:              ' . $data['finalAmount']);
            self::log('---------------------------------------------------------------');
        }

    }

endif;
