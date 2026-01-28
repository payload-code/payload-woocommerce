<?php
/**
 * Integration tests for Payload API functions
 *
 * @package Payload_WooCommerce
 */

namespace PayloadWooCommerce\Tests\Integration\API;

use PayloadWooCommerce\Tests\Integration\IntegrationTestCase;
use PayloadWooCommerce\Tests\Integration\Helpers\CurlMocker;
use Brain\Monkey\Functions;
use Mockery;

class PayloadAPIFunctions_Test extends IntegrationTestCase {


	protected function setUp(): void {
		parent::setUp();

		// Set up Payload API credentials directly
		\Payload\API::$api_key = 'test_key';
		\Payload\API::$api_url = 'https://api.payload.com';

		// Mock WordPress functions that are used by all tests
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// Mock setup_payload_api (it will be called by get_intent but doesn't need to do anything)
		Functions\when( 'setup_payload_api' )->justReturn( null );
	}

	/**
	 * Test get_intent creates client token for payment form
	 */
	public function test_get_intent_creates_client_token_for_payment_form() {
		$customer_id     = 'cust_test123';
		$client_token_id = 'ct_' . bin2hex( random_bytes( 8 ) );

		// Mock get_payload_customer_id
		Functions\when( 'get_payload_customer_id' )->justReturn( $customer_id );

		// Mock the ClientToken::create API call
		CurlMocker::mockResponse(
			'POST',
			'https://api.payload.com/access_tokens',
			200,
			array(
				'object' => 'client_token',
				'id'     => $client_token_id,
			),
			array(),
			array(
				'intent' => array(
					'payment_form' => array(
						'payment' => array(
							'customer_id' => $customer_id,
						),
					),
				),
				'type'   => 'client',
			)
		);

		// Don't set $_GET['type'], so it defaults to payment form
		$_GET = array();

		// Call the function
		$result = get_intent( array() );

		// Verify result
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'client_token', $result );
		$this->assertEquals( $client_token_id, $result['client_token'] );
	}

	/**
	 * Test get_intent creates client token for payment_method form
	 */
	public function test_get_intent_creates_client_token_for_payment_method_form() {
		$customer_id     = 'cust_test456';
		$client_token_id = 'ct_' . bin2hex( random_bytes( 8 ) );

		// Mock get_payload_customer_id
		Functions\when( 'get_payload_customer_id' )->justReturn( $customer_id );

		// Mock the ClientToken::create API call
		CurlMocker::mockResponse(
			'POST',
			'https://api.payload.com/access_tokens',
			200,
			array(
				'object' => 'client_token',
				'id'     => $client_token_id,
			),
			array(),
			array(
				'intent' => array(
					'payment_method_form' => array(
						'payment_method' => array(
							'customer_id' => $customer_id,
						),
					),
				),
				'type'   => 'client',
			)
		);

		// Set $_GET['type'] to payment_method
		$_GET = array( 'type' => 'payment_method' );

		// Call the function
		$result = get_intent( array() );

		// Verify result
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'client_token', $result );
		$this->assertEquals( $client_token_id, $result['client_token'] );
	}

	/**
	 * Test get_intent handles API errors gracefully
	 */
	public function test_get_intent_handles_api_error() {
		$customer_id = 'cust_test789';

		// Mock get_payload_customer_id
		Functions\when( 'get_payload_customer_id' )->justReturn( $customer_id );

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'error' )
			->once()
			->with(
				Mockery::pattern( '/Failed to create Payload client token:/' ),
				array( 'source' => 'payload-woocommerce' )
			);

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the ClientToken::create API call to return error
		CurlMocker::mockError(
			'POST',
			'https://api.payload.com/client_tokens',
			400,
			'InvalidAttributes',
			'Invalid intent structure'
		);

		// Don't set $_GET['type']
		$_GET = array();

		// Call the function
		$result = get_intent( array() );

		// Verify result is WP_Error
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'payload_token_error', $result->get_error_code() );
		$this->assertEquals( 'Unable to initialize payment form. Please try again later.', $result->get_error_message() );
		$this->assertEquals( array( 'status' => 500 ), $result->get_error_data() );
	}
}
