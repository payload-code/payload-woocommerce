<?php
/**
 * Plugin Name: Payload WooCommerce
 * Plugin URI: https://github.com/payload-code/payload-woocommerce
 * Description: Accept WooCommerce payments through Payload.com.
 * Version: 1.2.0
 * Author: Payload
 * Author URI: https://payload.com
 * Requires Plugins: woocommerce
 * License: MIT
 * License URI: https://mit-license.org/
 * Text Domain: payload
 * Domain Path: /languages
 */

require_once 'vendor/autoload.php';
use Payload\API as pl;


add_action( 'plugins_loaded', 'woocommerce_payload', 0 );
function woocommerce_payload() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // if the WC payment gateway class
	}

	include plugin_dir_path( __FILE__ ) . 'includes/class-wc-payload-gateway.php';
}


add_filter( 'woocommerce_payment_gateways', 'add_payload_gateway' );
function add_payload_gateway( $gateways ) {
	$gateways[] = 'WC_Payload_Gateway';
	return $gateways;
}

/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
add_action( 'before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility' );
function declare_cart_checkout_blocks_compatibility() {
	// Check if the required class exists
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		// Declare compatibility for 'cart_checkout_blocks'
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}

// Hook to the 'woocommerce_blocks_loaded' action to register payment method type
add_action( 'woocommerce_blocks_loaded', 'payload_register_order_approval_payment_method_type' );
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
		function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			// Register an instance of WC_Payload_Blocks
			$payment_method_registry->register( new WC_Payload_Blocks() );
		}
	);
}

function get_payload_customer_id() {

	$user = wp_get_current_user();
	if ( $user ) {
		$payload_customer_id = get_user_meta( $user->get_id(), 'payload_customer_id' );

		if ( ! $payload_customer_id && $user->user_email && $user->user_nicename ) {
			$customer = Payload\Customer::create(
				array(
					'email' => $user->user_email,
					'name'  => $user->user_nicename,
				)
			);

			$payload_customer_id = $customer->id;

			update_user_meta( $user->get_id(), 'payload_customer_id', $payload_customer_id );

		}
	}

	return $payload_customer_id ?: null;
}

function get_intent( $data ) {
	setup_payload_api();

	$payload_customer_id = get_payload_customer_id();

	if ( $_GET['type'] == 'payment_method' ) {
		$intent = array(
			'payment_method_form' => array(
				'payment_method' => array(
					'customer_id' => $payload_customer_id,
				),
			),
		);
	} else {
		$intent = array(
			'payment_form' => array(
				'payment' => array(
					'customer_id' => $payload_customer_id,
				),
			),
		);
	}

	$clientToken = Payload\ClientToken::create(
		array( 'intent' => $intent ),
	);

	return array( 'client_token' => $clientToken->id );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wc/v3',
			'payload_client_token',
			array(
				'methods'  => 'GET',
				'callback' => 'get_intent',
			)
		);
	}
);

function setup_payload_api() {
	$settings = get_option( 'woocommerce_payload_settings', array() );

	pl::$api_key = $settings['api_key'];
	if ( getenv( 'PAYLOAD_API_URL' ) ) {
		pl::$api_url = getenv( 'PAYLOAD_API_URL' );
	}
}

add_filter( 'woocommerce_subscription_payment_method_to_display', 'payload_subscription_payment_method_to_display', 10, 3 );

function payload_subscription_payment_method_to_display( $label, $subscription, $context ) {
	$parent_order = wc_get_order( $subscription->get_parent_id() );
	return $parent_order->get_payment_method_title();
}
