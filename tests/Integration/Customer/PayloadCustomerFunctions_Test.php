<?php
/**
 * Integration tests for Payload Customer functions
 *
 * @package Payload_WooCommerce
 */

namespace PayloadWooCommerce\Tests\Integration\Customer;

use PayloadWooCommerce\Tests\Integration\IntegrationTestCase;
use PayloadWooCommerce\Tests\Integration\Helpers\CurlMocker;
use Brain\Monkey\Functions;
use Mockery;

class PayloadCustomerFunctions_Test extends IntegrationTestCase {


	protected function setUp(): void {
		parent::setUp();

		// Set up Payload API credentials for all tests
		\Payload\API::$api_key = 'test_key';
		\Payload\API::$api_url = 'https://api.payload.com';
	}

	/**
	 * Test get_payload_customer_id returns existing customer ID from meta
	 */
	public function test_get_payload_customer_id_returns_existing_from_meta() {
		$user_id     = 123;
		$customer_id = 'cust_existing123';

		// Create mock user
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $user_id;
		$user->user_email = 'test@example.com';

		// Mock WordPress functions
		Functions\expect( 'get_user_by' )
			->with( 'id', $user_id )
			->once()
			->andReturn( $user );

		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( $customer_id );

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'info' )
			->with(
				'Script started Payload Customer ID checking for user ID: ' . $user_id,
				array( 'source' => 'payload-woocommerce.php' )
			);
		$logger->shouldNotReceive( 'error' );

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Call the function
		$result = get_payload_customer_id( $user_id );

		// Verify result - should return existing ID without making API calls
		$this->assertEquals( $customer_id, $result );
	}

	/**
	 * Test get_payload_customer_id finds existing customer by email
	 */
	public function test_get_payload_customer_id_finds_customer_by_email() {
		$user_id     = 456;
		$customer_id = 'cust_found456';
		$email       = 'existing@example.com';

		// Create mock user
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $user_id;
		$user->user_email = $email;

		// Mock WordPress functions
		Functions\expect( 'get_user_by' )
			->with( 'id', $user_id )
			->once()
			->andReturn( $user );

		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( null );

		Functions\expect( 'payload_update_customer_id_meta' )
			->with( $user_id, $customer_id )
			->once();

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'info' )
			->with(
				'Script started Payload Customer ID checking for user ID: ' . $user_id,
				array( 'source' => 'payload-woocommerce.php' )
			);
		$logger->shouldReceive( 'info' )
			->with(
				'Payload Customer ID found for user email:' . $email,
				array( 'source' => 'payload-woocommerce.php' )
			);
		$logger->shouldNotReceive( 'error' );

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the Customer::filter_by API call
		CurlMocker::mockResponse(
			'GET',
			'https://api.payload.com/customers?email=' . urlencode( $email ),
			200,
			array(
				'object' => 'list',
				'values' => array(
					array(
						'object' => 'customer',
						'id'     => $customer_id,
						'email'  => $email,
					),
				),
			)
		);

		// Call the function
		$result = get_payload_customer_id( $user_id );

		// Verify result
		$this->assertEquals( $customer_id, $result );
	}

	/**
	 * Test get_payload_customer_id creates new customer when not found
	 */
	public function test_get_payload_customer_id_creates_new_customer() {
		$user_id         = 789;
		$email           = 'newuser@example.com';
		$new_customer_id = 'cust_new789';

		// Create mock user
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $user_id;
		$user->user_email = $email;

		// Mock WordPress functions
		Functions\expect( 'get_user_by' )
			->with( 'id', $user_id )
			->once()
			->andReturn( $user );

		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( null );

		$customer_data = array(
			'email'      => $email,
			'first_name' => 'Test',
			'last_name'  => 'User',
		);

		Functions\expect( 'payload_build_customer_data' )
			->with( $user )
			->once()
			->andReturn( $customer_data );

		Functions\expect( 'payload_update_customer_id_meta' )
			->with( $user_id, $new_customer_id )
			->once();

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'info' )->andReturn( true );
		$logger->shouldReceive( 'error' )->andReturnUsing(
			function ( $message ) {
				fwrite( STDERR, "Logger error: $message\n" );
				return true;
			}
		);

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the Customer::filter_by API call (returns empty - customer not found)
		CurlMocker::mockResponse(
			'GET',
			'https://api.payload.com/customers?email=' . urlencode( $email ),
			200,
			array(
				'object' => 'list',
				'values' => array(),
			)
		);

		// Mock the Customer::create API call
		CurlMocker::mockResponse(
			'POST',
			'https://api.payload.com/customers',
			200,
			array(
				'object' => 'customer',
				'id'     => $new_customer_id,
				'email'  => $email,
			),
			array(),
			$customer_data
		);

		// Call the function
		$result = get_payload_customer_id( $user_id );

		// Verify result
		$this->assertEquals( $new_customer_id, $result );
	}

	/**
	 * Test get_payload_customer_id returns null when user has no email
	 */
	public function test_get_payload_customer_id_returns_null_for_user_without_email() {
		$user_id = 999;

		// Create mock user without email
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $user_id;
		$user->user_email = '';

		// Mock WordPress functions
		Functions\expect( 'get_user_by' )
			->with( 'id', $user_id )
			->once()
			->andReturn( $user );

		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( null );

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'info' )
			->with(
				'Script started Payload Customer ID checking for user ID: ' . $user_id,
				array( 'source' => 'payload-woocommerce.php' )
			);

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Call the function
		$result = get_payload_customer_id( $user_id );

		// Verify result
		$this->assertNull( $result );
	}

	/**
	 * Test get_payload_customer_id handles customer lookup error and attempts creation
	 */
	public function test_get_payload_customer_id_handles_lookup_error_and_creates_customer() {
		$user_id         = 111;
		$email           = 'errortest@example.com';
		$new_customer_id = 'cust_recovery111';

		// Create mock user
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $user_id;
		$user->user_email = $email;

		// Mock WordPress functions
		Functions\expect( 'get_user_by' )
			->with( 'id', $user_id )
			->once()
			->andReturn( $user );

		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( null );

		$customer_data = array(
			'email'      => $email,
			'first_name' => 'Error',
			'last_name'  => 'Test',
		);

		Functions\expect( 'payload_build_customer_data' )
			->with( $user )
			->once()
			->andReturn( $customer_data );

		Functions\expect( 'payload_update_customer_id_meta' )
			->with( $user_id, $new_customer_id )
			->once();

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'info' )->andReturn( true );
		$logger->shouldReceive( 'error' )->andReturn( true );

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the Customer::filter_by API call to return error
		CurlMocker::mockError(
			'GET',
			'https://api.payload.com/customers?email=' . urlencode( $email ),
			500,
			'InternalError',
			'Database connection failed'
		);

		// Mock the Customer::create API call
		CurlMocker::mockResponse(
			'POST',
			'https://api.payload.com/customers',
			200,
			array(
				'object' => 'customer',
				'id'     => $new_customer_id,
				'email'  => $email,
			),
			array(),
			$customer_data
		);

		// Call the function
		$result = get_payload_customer_id( $user_id );

		// Verify result
		$this->assertEquals( $new_customer_id, $result );
	}

	/**
	 * Test get_payload_customer_id returns null when both lookup and creation fail
	 */
	public function test_get_payload_customer_id_returns_null_when_creation_fails() {
		$user_id = 222;
		$email   = 'failedcreation@example.com';

		// Create mock user
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $user_id;
		$user->user_email = $email;

		// Mock WordPress functions
		Functions\expect( 'get_user_by' )
			->with( 'id', $user_id )
			->once()
			->andReturn( $user );

		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( null );

		$customer_data = array(
			'email'      => $email,
			'first_name' => 'Failed',
			'last_name'  => 'Creation',
		);

		Functions\expect( 'payload_build_customer_data' )
			->with( $user )
			->once()
			->andReturn( $customer_data );

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'info' )
			->with(
				'Script started Payload Customer ID checking for user ID: ' . $user_id,
				array( 'source' => 'payload-woocommerce.php' )
			);
		$logger->shouldReceive( 'error' )
			->with(
				Mockery::pattern( '/Failed to create Payload customer/' ),
				array( 'source' => 'payload-woocommerce.php' )
			);

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the Customer::filter_by API call (returns empty - customer not found)
		CurlMocker::mockResponse(
			'GET',
			'https://api.payload.com/customers?email=' . urlencode( $email ),
			200,
			array(
				'object' => 'list',
				'values' => array(),
			)
		);

		// Mock the Customer::create API call to return error
		CurlMocker::mockError(
			'POST',
			'https://api.payload.com/customers',
			400,
			'InvalidAttributes',
			'Email address is invalid'
		);

		// Call the function
		$result = get_payload_customer_id( $user_id );

		// Verify result
		$this->assertNull( $result );
	}

	/**
	 * Test get_payload_customer_id uses current user when no user_id provided
	 */
	public function test_get_payload_customer_id_uses_current_user() {
		$user_id     = 333;
		$customer_id = 'cust_current333';

		// Create mock current user
		$user             = Mockery::mock( 'WP_User' );
		$user->ID         = $user_id;
		$user->user_email = 'current@example.com';

		// Mock WordPress functions - should use wp_get_current_user instead of get_user_by
		Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( $user );

		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( $customer_id );

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldReceive( 'info' )
			->with(
				'Script started Payload Customer ID checking for user ID: ' . $user_id,
				array( 'source' => 'payload-woocommerce.php' )
			);

		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Call the function without user_id parameter
		$result = get_payload_customer_id();

		// Verify result
		$this->assertEquals( $customer_id, $result );
	}

	/**
	 * Test payload_sync_customer_on_profile_update successfully updates customer
	 */
	public function test_payload_sync_customer_on_profile_update_successfully_updates_customer() {
		$user_id     = 555;
		$customer_id = 'cust_sync555';
		$email       = 'updated@example.com';
		$company     = 'Updated Company';

		// Create mock user with updated data
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $user_id;
		$user->user_email   = $email;
		$user->display_name = 'Updated User';

		// Mock old user (not used in function but required by profile_update hook)
		$old_user = Mockery::mock( 'WP_User' );

		// Mock WordPress functions
		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( $customer_id );

		Functions\expect( 'get_userdata' )
			->with( $user_id )
			->once()
			->andReturn( $user );

		Functions\when( 'get_user_meta' )
		->justReturn( $company );

		$expected_customer_data = array(
			'email' => $email,
			'name'  => $company,
			'attrs' => array(
				'_wp_user_id' => $user_id,
			),
		);

		Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);

		// Mock logger - should not log errors on success
		$logger = Mockery::mock();
		$logger->shouldNotReceive( 'error' );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the Customer::filter_by()->update() API call
		CurlMocker::mockResponse(
			'PUT',
			'https://api.payload.com/customers?id=' . urlencode( $customer_id ) . '&mode=query',
			200,
			array(
				'object' => 'customer',
				'id'     => $customer_id,
				'email'  => $email,
				'name'   => $company,
			),
			array(),
			$expected_customer_data
		);

		// Call the function
		payload_sync_customer_on_profile_update( $user_id, $old_user );

		// Verification happens through Mockery expectations
		$this->assertTrue( true );
	}

	/**
	 * Test payload_sync_customer_on_profile_update does nothing when no customer ID exists
	 */
	public function test_payload_sync_customer_on_profile_update_skips_when_no_customer_id() {
		$user_id = 666;

		// Mock old user
		$old_user = Mockery::mock( 'WP_User' );

		// Mock WordPress functions
		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( null );

		Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);

		// Should not call get_userdata or make API calls
		Functions\expect( 'get_userdata' )->never();
		Functions\expect( 'payload_build_customer_data' )->never();

		// Call the function
		payload_sync_customer_on_profile_update( $user_id, $old_user );

		// Verification happens through Mockery expectations
		$this->assertTrue( true );
	}

	/**
	 * Test payload_sync_customer_on_profile_update handles API errors gracefully
	 */
	public function test_payload_sync_customer_on_profile_update_handles_api_error() {
		$user_id     = 777;
		$customer_id = 'cust_error777';
		$email       = 'error@example.com';

		// Create mock user
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $user_id;
		$user->user_email   = $email;
		$user->display_name = 'Error User';

		// Mock old user
		$old_user = Mockery::mock( 'WP_User' );

		// Mock WordPress functions
		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( $customer_id );

		Functions\expect( 'get_userdata' )
			->with( $user_id )
			->once()
			->andReturn( $user );

		Functions\when( 'get_user_meta' )
		->justReturn( '' );

		$expected_customer_data = array(
			'email' => $email,
			'name'  => 'Error User',
			'attrs' => array(
				'_wp_user_id' => $user_id,
			),
		);

		Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);

		// Mock logger - should log error on failure
		$logger = Mockery::mock();
		$logger->shouldReceive( 'error' )
			->once()
			->with(
				Mockery::pattern( '/Failed to update Payload customer on profile update/' ),
				array( 'source' => 'payload-woocommerce' )
			);
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the Customer::filter_by()->update() API call to return error
		CurlMocker::mockError(
			'PUT',
			'https://api.payload.com/customers?id=' . urlencode( $customer_id ) . '&mode=query',
			400,
			'InvalidAttributes',
			'Email format is invalid'
		);

		// Call the function - should not throw exception (fails silently)
		payload_sync_customer_on_profile_update( $user_id, $old_user );

		// Verification happens through Mockery expectations
		$this->assertTrue( true );
	}

	/**
	 * Test payload_sync_customer_on_profile_update uses company name when available
	 */
	public function test_payload_sync_customer_on_profile_update_uses_company_name() {
		$user_id     = 888;
		$customer_id = 'cust_company888';
		$email       = 'company@example.com';
		$company     = 'ACME Corporation';

		// Create mock user
		$user               = Mockery::mock( 'WP_User' );
		$user->ID           = $user_id;
		$user->user_email   = $email;
		$user->display_name = 'John Doe';

		// Mock old user
		$old_user = Mockery::mock( 'WP_User' );

		// Mock WordPress functions
		Functions\expect( 'payload_get_customer_id_meta' )
			->with( $user_id )
			->once()
			->andReturn( $customer_id );

		Functions\expect( 'get_userdata' )
			->with( $user_id )
			->once()
			->andReturn( $user );

		Functions\when( 'get_user_meta' )
		->justReturn( $company );

		$expected_customer_data = array(
			'email' => $email,
			'name'  => $company, // Should use company, not display_name
			'attrs' => array(
				'_wp_user_id' => $user_id,
			),
		);

		Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);

		// Mock logger
		$logger = Mockery::mock();
		$logger->shouldNotReceive( 'error' );
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		// Mock the Customer::filter_by()->update() API call
		CurlMocker::mockResponse(
			'PUT',
			'https://api.payload.com/customers?id=' . urlencode( $customer_id ) . '&mode=query',
			200,
			array(
				'object' => 'customer',
				'id'     => $customer_id,
				'email'  => $email,
				'name'   => $company,
			),
			array(),
			$expected_customer_data
		);

		// Call the function
		payload_sync_customer_on_profile_update( $user_id, $old_user );

		// Verification happens through Mockery expectations
		$this->assertTrue( true );
	}
}
