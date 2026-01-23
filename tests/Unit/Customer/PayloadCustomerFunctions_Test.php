<?php
/**
 * Unit tests for Payload Customer Functions
 *
 * Tests functions from includes/payload-customer-functions.php
 *
 * @package Payload_WooCommerce
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery as m;

class PayloadCustomerFunctions_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Reset global state
		$_POST = array();
		$_GET  = array();
		// Default: assume no existing customer unless test says otherwise
		if ( class_exists( 'Customer' ) ) {
			Customer::$shouldFindExisting = true;
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_get_payload_customer_id_creates_new_customer() {

		if ( class_exists( 'Payload\\Customer' ) ) {
			\Payload\Customer::$shouldFindExisting = false;
		}
		$user_mock = (object) array(
			'ID'           => 123,
			'user_email'   => 'test@example.com',
			'display_name' => 'testuser',
		);

		// Customer mock is handled by the mock class

		Monkey\Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( $user_mock );

		Monkey\Functions\expect( 'get_user_meta' )
			->once()
			->with( 123, 'payload_customer_id', true )
			->andReturn( '' );

		Monkey\Functions\expect( 'update_user_meta' )
			->once()
			->with( 123, 'payload_customer_id', 'cust_123' );

		$result = get_payload_customer_id();

		$this->assertEquals( 'cust_123', $result );
	}

	public function test_get_payload_customer_id_returns_existing_customer() {
		$user_mock = (object) array(
			'ID'            => 123,
			'user_email'    => 'test@example.com',
			'user_nicename' => 'testuser',
		);

		Monkey\Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( $user_mock );

		Monkey\Functions\expect( 'get_user_meta' )
			->once()
			->with( 123, 'payload_customer_id', true )
			->andReturn( 'cust_existing' );

		$result = get_payload_customer_id();

		$this->assertEquals( 'cust_existing', $result );
	}

	public function test_get_payload_customer_id_returns_null_for_no_user() {
		Monkey\Functions\expect( 'wp_get_current_user' )
			->once()
			->andReturn( false );

		$result = get_payload_customer_id();

		$this->assertNull( $result );
	}

	public function test_payload_get_customer_id_meta_returns_customer_id() {
		Monkey\Functions\expect( 'get_user_meta' )
			->once()
			->with( 123, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( 'cust_123' );

		$result = payload_get_customer_id_meta( 123 );

		$this->assertEquals( 'cust_123', $result );
	}

	public function test_payload_update_customer_id_meta_stores_customer_id() {
		Monkey\Functions\expect( 'update_user_meta' )
			->once()
			->with( 123, PAYLOAD_CUSTOMER_ID_META_KEY, 'cust_456' )
			->andReturn( true );

		$result = payload_update_customer_id_meta( 123, 'cust_456' );

		$this->assertTrue( $result );
	}

	public function test_payload_sync_customer_on_profile_update_with_existing_customer() {
		$user_mock = (object) array(
			'ID'            => 123,
			'user_email'    => 'updated@example.com',
			'user_nicename' => 'updateduser',
			'first_name'    => 'Updated',
			'last_name'     => 'User',
		);

		$customer_mock = Mockery::mock();
		$customer_mock->shouldReceive( 'update' )
			->with( Mockery::type( 'array' ) )
			->andReturn( true );

		Monkey\Functions\expect( 'get_user_meta' )
			->once()
			->with( 123, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( 'cust_existing' );

		Monkey\Functions\expect( 'get_user_meta' )
			->with( 123, 'billing_company', true )
			->andReturn( 'Test Company' );

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'error' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		payload_sync_customer_on_profile_update( 123, $user_mock );

		$this->assertTrue( true );
	}

	public function test_payload_create_customer_on_registration_success() {

		$user_mock = (object) array(
			'ID'           => 123,
			'user_email'   => 'newuser@example.com',
			'display_name' => 'newuser',
		);

		$customer_mock     = Mockery::mock();
		$customer_mock->id = 'cust_123';

		Monkey\Functions\expect( 'get_user_by' )
			->once()
			->with( 'id', 123 )
			->andReturn( $user_mock );

		Monkey\Functions\expect( 'get_user_meta' )
			->twice()
			->with( 123, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( '' );

		Monkey\Functions\expect( 'update_user_meta' )
			->once()
			->with( 123, PAYLOAD_CUSTOMER_ID_META_KEY, 'cust_123' );

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		$logger_mock->shouldReceive( 'error' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		payload_create_customer_on_registration( 123 );

		$this->assertTrue( true );
	}

	public function test_payload_ensure_customer_after_checkout_creates_customer() {

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_user_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_customer_id' )->andReturn( 'cust_123' );
		$order_mock->shouldReceive( 'get_payment_method' )->andReturn( 'payload' );

		$user_mock = (object) array(
			'ID'           => 123,
			'user_email'   => 'checkout@example.com',
			'display_name' => 'checkoutuser',
		);

		$customer_mock     = Mockery::mock();
		$customer_mock->id = 'cust_checkout123';

		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'info' );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order_mock );

		Monkey\Functions\expect( 'get_user_by' )
			->with( 'id', 123 )
			->andReturn( $user_mock );

		Monkey\Functions\expect( 'get_user_meta' )
			->with( 123, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( '' );

		Monkey\Functions\expect( 'update_user_meta' )
			->with( 123, PAYLOAD_CUSTOMER_ID_META_KEY, 'cust_123' );

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		payload_ensure_customer_after_checkout( 123, null, $order_mock );

		$this->assertTrue( true );
	}
}
