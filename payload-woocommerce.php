<?php
/**
 * Plugin Name: Payload WooCommerce
 * Plugin URI: https://github.com/payload-code/payload-woocommerce
 * Description: Accept WooCommerce payments through Payload.com.
 * Version: 1.4.0
 * Author: Payload
 * Author URI: https://payload.com
 * Requires Plugins: woocommerce
 * License: MIT
 * License URI: https://mit-license.org/
 * Text Domain: payload
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

require_once 'vendor/autoload.php';
use Payload\API as pl;

/**
 * Meta key constant for storing Payload customer ID in WordPress user meta.
 *
 * @since 1.4.0
 */
define( 'PAYLOAD_CUSTOMER_ID_META_KEY', 'payload_customer_id' );

/**
 * Initialize WooCommerce Payload integration.
 *
 * Loads the Payload payment gateway class if WooCommerce is available.
 *
 * @since 1.0.0
 */
function woocommerce_payload() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // if the WC payment gateway class is not available
	}

	// Load core gateway class
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-payload-gateway.php';

	// Load function files
	require_once plugin_dir_path( __FILE__ ) . 'includes/payload-api-functions.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/payload-customer-functions.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/payload-order-functions.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/payload-utility-functions.php';
}
add_action( 'plugins_loaded', 'woocommerce_payload', 0 );

/**
 * Add Payload gateway to WooCommerce payment gateways.
 *
 * @since 1.0.0
 * @param array $gateways Array of WooCommerce payment gateway classes.
 * @return array Modified array of payment gateways including Payload.
 */
function add_payload_gateway( $gateways ) {
	$gateways[] = 'WC_Payload_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'add_payload_gateway' );

/**
 * Declare compatibility with WooCommerce cart and checkout blocks.
 *
 * @since 1.0.0
 */
function declare_cart_checkout_blocks_compatibility() {
	// Check if the required class exists
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		// Declare compatibility for 'cart_checkout_blocks'
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility' );

/**
 * Register Payload payment method type for WooCommerce Blocks.
 *
 * @since 1.0.0
 */
function payload_register_order_approval_payment_method_type() {
	// Check if the required class exists
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	// Include Blocks Checkout class
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-payload-blocks.php';

	// Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		'payload_register_blocks_payment_method'
	);
}
add_action( 'woocommerce_blocks_loaded', 'payload_register_order_approval_payment_method_type' );

/**
 * Register Payload Blocks payment method with WooCommerce payment registry.
 *
 * Callback function that registers the WC_Payload_Blocks instance with
 * WooCommerce Blocks payment method registry.
 *
 * @since 1.0.0
 * @param Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry Payment method registry instance.
 */
function payload_register_blocks_payment_method( $payment_method_registry ) {
	// Register an instance of WC_Payload_Blocks
	$payment_method_registry->register( new WC_Payload_Blocks() );
}
