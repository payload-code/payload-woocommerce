<?php
/**
 * Payload API Functions
 *
 * Functions for interacting with the Payload API and REST endpoints.
 *
 * @package Payload_WooCommerce
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Initialize and configure Payload API settings.
 *
 * Sets up API key and URL from WooCommerce settings and environment variables.
 *
 * @since 1.0.0
 */
function setup_payload_api() {
	$settings = get_option( 'woocommerce_payload_settings', array() );

	if ( ! empty( $settings['api_key'] ) ) {
		Payload\API::$api_key = $settings['api_key'];
	} else {
		// Log error if API key is missing
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->error( 'Payload API key is not configured', array( 'source' => 'payload-woocommerce' ) );
		}
	}

	if ( getenv( 'PAYLOAD_API_URL' ) ) {
		Payload\API::$api_url = getenv( 'PAYLOAD_API_URL' );
	}
}

/**
 * Generate Payload client token for payment or payment method forms.
 *
 * REST API callback that creates a client token for payment processing.
 *
 * @since  1.0.0
 * @param  array $data Request data from REST API.
 * @return array Array containing client_token ID.
 */
function get_intent( $data ) {
	setup_payload_api();

	$payload_customer_id = get_payload_customer_id();

	$request_type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
	if ( $request_type === 'payment_method' ) {
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

	try {
		$clientToken = Payload\ClientToken::create(
			array( 'intent' => $intent ),
		);

		return array( 'client_token' => $clientToken->id );
	} catch ( Exception $e ) {
		$logger = wc_get_logger();
		$logger->error(
			'Failed to create Payload client token: ' . $e->getMessage(),
			array( 'source' => 'payload-woocommerce' )
		);
		return new WP_Error(
			'payload_token_error',
			__( 'Unable to initialize payment form. Please try again later.', 'payload' ),
			array( 'status' => 500 )
		);
	}
}

/**
 * Register REST API endpoint for Payload client token generation.
 *
 * Registers the /wc/v3/payload_client_token endpoint for retrieving client tokens
 * used in payment forms.
 *
 * @since 1.0.0
 */
function payload_register_rest_api_routes() {
	register_rest_route(
		'wc/v3',
		'payload_client_token',
		array(
			'methods'             => 'GET',
			'callback'            => 'get_intent',
			'permission_callback' => 'payload_rest_permission_check',
		)
	);
}
add_action( 'rest_api_init', 'payload_register_rest_api_routes' );

/**
 * Permission callback for Payload REST API endpoints.
 *
 * Checks if the current user is logged in before allowing access to
 * Payload REST API endpoints.
 *
 * @since  1.0.0
 * @return bool True if user is logged in, false otherwise.
 */
function payload_rest_permission_check() {
	return is_user_logged_in();
}
