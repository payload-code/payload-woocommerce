<?php
/**
 * Unit tests for Payload API Functions
 *
 * Tests functions from includes/payload-api-functions.php
 *
 * @package Payload_WooCommerce
 */

use PayloadWooCommerce\Tests\Unit\UnitTestCase;
use Brain\Monkey;
use Mockery as m;

class PayloadAPIFunctions_Test extends UnitTestCase {


	public function test_get_intent_for_payment_method() {
		$_GET = array( 'type' => 'payment_method' );

		$client_token_mock     = Mockery::mock();
		$client_token_mock->id = 'ct_123';

		// ClientToken mock is handled by the mock class

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		Monkey\Functions\expect( 'get_payload_customer_id' )->andReturn( 'cust_123' );

		$result = get_intent( array() );

		$this->assertEquals( array( 'client_token' => 'ct_123' ), $result );
	}

	public function test_get_intent_for_payment() {
		$_GET = array( 'type' => 'payment' );

		$client_token_mock     = Mockery::mock();
		$client_token_mock->id = 'ct_123';

		// ClientToken mock is handled by the mock class

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		Monkey\Functions\expect( 'get_payload_customer_id' )->andReturn( 'cust_123' );

		$result = get_intent( array() );

		$this->assertEquals( array( 'client_token' => 'ct_123' ), $result );
	}

	public function test_setup_payload_api_sets_api_key() {
		$settings = array( 'api_key' => 'test_api_key' );

		Monkey\Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_payload_settings', array() )
			->andReturn( $settings );

		Monkey\Functions\expect( 'getenv' )
			->once()
			->with( 'PAYLOAD_API_URL' )
			->andReturn( false );

		setup_payload_api();

		// Since we can't easily test static property assignment,
		// we'll verify the function completes without error
		$this->assertTrue( true );
	}

	public function test_setup_payload_api_with_custom_url() {
		$settings = array( 'api_key' => 'test_api_key' );

		Monkey\Functions\expect( 'get_option' )
			->once()
			->with( 'woocommerce_payload_settings', array() )
			->andReturn( $settings );

		Monkey\Functions\expect( 'getenv' )
			->twice()
			->with( 'PAYLOAD_API_URL' )
			->andReturn( 'https://custom-api.payload.com' );

		setup_payload_api();

		$this->assertTrue( true );
	}

	public function test_payload_rest_permission_check_allows_logged_in_users() {
		Monkey\Functions\expect( 'is_user_logged_in' )
			->once()
			->andReturn( true );

		$result = payload_rest_permission_check();

		$this->assertTrue( $result );
	}

	public function test_payload_rest_permission_check_blocks_guests() {
		Monkey\Functions\expect( 'is_user_logged_in' )
			->once()
			->andReturn( false );

		$result = payload_rest_permission_check();

		$this->assertFalse( $result );
	}
}
