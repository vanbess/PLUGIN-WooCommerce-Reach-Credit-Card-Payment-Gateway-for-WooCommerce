<?php

/**
 * Plugin Name:       SBWC Reach Credit Card Payment Gateway
 * Description:       Stripped down/revised/improved/debugged version of Reach payment gateway for WooCommerce
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            WC Bessinger
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sbwc-reach-cc
 */

defined('ABSPATH') || exit();

add_action('plugins_loaded', function () {

    // constants
    define('SBWC_REACH_PATH', plugin_dir_path(__FILE__));
    define('SBWC_REACH_URI', plugin_dir_url(__FILE__));

    // composer/guzzle http
    require_once SBWC_REACH_PATH . 'vendor/autoload.php';

    // classes
    require_once SBWC_REACH_PATH . 'inc/back/class_reach_api.php';
    require_once SBWC_REACH_PATH . 'inc/back/class_reach_helpers.php';
    require_once SBWC_REACH_PATH . 'inc/back/class_reach_request.php';
    require_once SBWC_REACH_PATH . 'inc/back/class_reach_response.php';
    require_once SBWC_REACH_PATH . 'inc/back/class_reach_log.php';
    require_once SBWC_REACH_PATH . 'inc/back/class_reach_cc.php';
    
    // notifications
    require_once SBWC_REACH_PATH . 'inc/back/class_reach_notifications.php';

    // register payment gateway
    add_filter('woocommerce_payment_gateways', function($gateways){
        $gateways[] = 'Reach_CC';
        return $gateways;
    });

    // init gateway class
    new Reach_CC;

    // hook notifications to wp_loaded
    add_action('wp_loaded', ['Reach_Notifications', 'handleNotifications']);
    add_action('wp_loaded', ['Reach_Notifications', 'handleReturnUrl']);


});
