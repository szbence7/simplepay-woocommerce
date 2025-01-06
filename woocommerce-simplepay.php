<?php
/**
 * Plugin Name: WooCommerce SimplePay Gateway
 * Plugin URI: https://example.com/wc-simplepay
 * Description: SimplePay payment gateway integration for WooCommerce
 * Version: 1.0.0
 * Author: Bence Szorgalmatos
 * Author URI: https://bencecodes.co.uk
 * Text Domain: wc-simplepay
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

defined('ABSPATH') || exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Define plugin constants
define('WC_SIMPLEPAY_VERSION', '1.0.0');
define('WC_SIMPLEPAY_PATH', plugin_dir_path(__FILE__));

// Include main gateway class
require_once WC_SIMPLEPAY_PATH . 'includes/class-wc-simplepay-gateway.php';

// Add the gateway to WooCommerce
add_filter('woocommerce_payment_gateways', 'add_simplepay_gateway');
function add_simplepay_gateway($gateways) {
    $gateways[] = 'WC_SimplePay_Gateway';
    return $gateways;
}

// Add HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Add logger
function wc_simplepay_log($message, $level = 'info') {
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $context = array('source' => 'wc-simplepay');
        $logger->log($level, $message, $context);
    }
} 