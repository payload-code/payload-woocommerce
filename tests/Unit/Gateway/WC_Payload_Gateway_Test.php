<?php

use PayloadWooCommerce\Tests\Unit\UnitTestCase;
use Brain\Monkey;
use Mockery as m;
use Payload\API as pl;

class Test_WC_Payload_Gateway extends UnitTestCase {


	private $gateway;

	protected function setUp(): void {
		parent::setUp();

		$this->gateway = new WC_Payload_Gateway();
	}

	protected function tearDown(): void {
		\Payload\Transaction::$payment_method_override = null;
		parent::tearDown();
	}

	public function test_constructor_sets_correct_properties() {
		$this->assertEquals( 'payload', $this->gateway->id );
		$this->assertTrue( $this->gateway->has_fields );
		$this->assertContains( 'products', $this->gateway->supports );
		$this->assertContains( 'tokenization', $this->gateway->supports );
		$this->assertContains( 'subscriptions', $this->gateway->supports );
	}

	public function test_init_form_fields_creates_required_fields() {
		$this->gateway->init_form_fields();

		$this->assertArrayHasKey( 'enabled', $this->gateway->form_fields );
		$this->assertArrayHasKey( 'api_key', $this->gateway->form_fields );
		$this->assertEquals( 'checkbox', $this->gateway->form_fields['enabled']['type'] );
		$this->assertEquals( 'secret', $this->gateway->form_fields['api_key']['type'] );
	}

	public function test_payment_scripts_skips_admin() {
		Monkey\Functions\expect( 'is_admin' )
			->once()
			->andReturn( true );

		Monkey\Functions\expect( 'wp_enqueue_style' )->never();
		Monkey\Functions\expect( 'wp_enqueue_script' )->never();

		$this->gateway->payment_scripts();

		// Assert that the function completes without enqueueing scripts
		$this->assertTrue( true );
	}

	public function test_payment_scripts_enqueues_frontend_assets() {
		Monkey\Functions\expect( 'is_admin' )
			->once()
			->andReturn( false );

		Monkey\Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'payload-blocks-css', Mockery::type( 'string' ), array(), '' );

		Monkey\Functions\expect( 'wp_enqueue_script' )
			->once()
			->with( 'payload-blocks-integration', Mockery::type( 'string' ), Mockery::type( 'array' ), '', true );

		Monkey\Functions\expect( 'function_exists' )
			->with( 'wp_set_script_translations' )
			->andReturn( true );

		Monkey\Functions\expect( 'wp_set_script_translations' )
			->once()
			->with( 'payload-blocks-integration' );

		$this->gateway->payment_scripts();

		// Assert that the function completes and enqueues frontend assets
		$this->assertTrue( true );
	}

	public function test_process_payment_with_missing_details_throws_exception() {
		// Set empty values for the keys that the code checks
		$_POST = array(
			'token'             => '',
			'payment_method_id' => '',
			'transactionid'     => '',
		);

		$order = new WC_Order( 123 );

		Monkey\Functions\expect( 'wcs_is_subscription' )
			->with( 123 )
			->andReturn( false );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order );

		Monkey\Functions\expect( 'setup_payload_api' )->once();

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Missing payment details' );

		$this->gateway->process_payment( 123 );
	}

	public function test_process_payment_with_zero_amount_completes_without_processing() {
		$_POST = array(
			'token'             => '',
			'payment_method_id' => '',
			'transactionid'     => '',
		);

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_customer_id' )->andReturn( 1 );
		$order_mock->shouldReceive( 'get_total' )->andReturn( 0 );
		$order_mock->shouldReceive( 'payment_complete' )->once();
		$order_mock->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'http://example.com/order-received/123/' );

		Monkey\Functions\expect( 'wcs_is_subscription' )
			->with( 123 )
			->andReturn( false );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order_mock );

		Monkey\Functions\expect( 'setup_payload_api' )->once();

		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'info' )
			->with(
				Mockery::on(
					function ( $message ) {
						return strpos( $message, 'Zero-amount order detected' ) !== false ||
								strpos( $message, 'Payment Process' ) !== false;
					}
				),
				Mockery::type( 'array' )
			)
		->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$result = $this->gateway->process_payment( 123 );

		$this->assertEquals( 'success', $result['result'] );
		$this->assertArrayHasKey( 'redirect', $result );
	}

	public function test_process_payment_with_null_order_throws_exception() {
		Monkey\Functions\expect( 'wcs_is_subscription' )
			->with( 999 )
			->andReturn( false );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 999 )
			->andReturn( null );

		Monkey\Functions\expect( 'setup_payload_api' )->once();

		$this->expectException( Exception::class );

		$this->gateway->process_payment( 999 );
	}

	public function test_process_payment_with_transaction_id_success() {
		$_POST = array(
			'transactionid'     => 'txn_123',
			'token'             => '',
			'payment_method_id' => '',
		);

		$order = new WC_Order( 123 );

		// Mock PaymentMethod for update first
		$payment_method_mock = Mockery::mock();
		$payment_method_mock->shouldReceive( 'update' )->andReturn( true );

		// Mock Payload Transaction
		$payment_mock                    = Mockery::mock();
		$payment_mock->amount            = 100.00;
		$payment_mock->ref_number        = 'REF123';
		$payment_mock->customer_id       = 'cust_existing';
		$payment_mock->payment_method_id = 'pm_123';
		$payment_mock->payment_method    = array(
			'id'   => 'pm_123',
			'card' => array(
				'card_brand'  => 'visa',
				'card_number' => '4111111111111111',
				'expiry'      => '12/2025',
			),
		);
		$payment_mock->shouldReceive( 'update' )->andReturn( true );

		Monkey\Functions\expect( 'wcs_is_subscription' )
			->with( 123 )
			->andReturn( false );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order );

		Monkey\Functions\expect( 'setup_payload_api' )->once();

		// Transaction mock is handled by the mock class

		// No need to mock get_payload_customer_id since customer_id is already set

		// Skip mocking PaymentMethod::get since it conflicts with other tests
		// The test will focus on the main flow

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( 123 )
			->andReturn( false );

		$result = $this->gateway->process_payment( 123 );

		$this->assertEquals( 'success', $result['result'] );
		$this->assertArrayHasKey( 'redirect', $result );
	}

	public function test_create_token_sets_correct_properties() {
		$payment_method_data = array(
			'id'   => 'pm_test123',
			'card' => array(
				'card_brand'  => 'visa',
				'card_number' => '4111111111111111',
				'expiry'      => '12/' . date( 'Y', strtotime( '+1 year' ) ),
			),
		);

		Monkey\Functions\expect( 'get_current_user_id' )
		->andReturn( 1 );

		// Mock the Payload\PaymentMethod construction and update call
		// We'll use a more direct approach without conflicting aliases
		$token = $this->gateway->create_token( $payment_method_data );

		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertEquals( 'pm_test123', $token->get_token() );
	}

	public function test_add_payment_method_success() {
		$_POST = array( 'payment_method_id' => 'pm_123' );

		$payment_method_mock = Mockery::mock();
		$payment_method_mock->shouldReceive( 'data' )->andReturn(
			array(
				'id'   => 'pm_123',
				'card' => array(
					'card_brand'  => 'visa',
					'card_number' => '4111111111111111',
					'expiry'      => '12/' . date( 'Y', strtotime( '+1 year' ) ),
				),
			)
		);

		Monkey\Functions\expect( 'setup_payload_api' )->once();

		// PaymentMethod mock is handled by the mock class

		Monkey\Functions\expect( 'wc_get_endpoint_url' )
			->with( 'payment-methods' )
			->andReturn( 'http://example.com/my-account/payment-methods/' );

		$result = $this->gateway->add_payment_method();

		$this->assertEquals( 'success', $result['result'] );
		$this->assertEquals( 'http://example.com/my-account/payment-methods/', $result['redirect'] );
	}

	public function test_add_payment_method_missing_payment_method_id_throws_exception() {
		$_POST = array( 'payment_method_id' => '' );

		Monkey\Functions\expect( 'setup_payload_api' )->once();

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Missing payment method details' );

		$this->gateway->add_payment_method();
	}

	public function test_create_payment_for_order() {
		$order  = new WC_Order( 123 );
		$amount = 50.00;

		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_123' );
		$token->set_gateway_id( 'payload' );

		$payment_mock             = Mockery::mock();
		$payment_mock->ref_number = 'REF456';

		// Transaction mock is handled by the mock class

		$payment = $this->gateway->create_payment_for_order( $order, $amount, $token );

		$this->assertInstanceOf( 'Payload\Transaction', $payment );
		$this->assertEquals( 'REF456', $payment->ref_number );
	}


	public function test_process_subscription_payment_method_update_success() {
		$_POST = array( 'payment_method_id' => 'pm_123' );

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'update_meta_data' )->with( '_payload_payment_method_id', 'pm_123' );
		$order_mock->shouldReceive( 'save' );
		$order_mock->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'http://example.com/order-received/123/' );
		$order_mock->shouldReceive( 'get_parent_id' )->andReturn( 456 );

		$parent_order_mock = Mockery::mock( 'WC_Order' );
		$parent_order_mock->shouldReceive( 'update_meta_data' )->with( '_payload_payment_method_id', 'pm_123' );
		$parent_order_mock->shouldReceive( 'set_payment_method' )->with( Mockery::any() );
		$parent_order_mock->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );
		$parent_order_mock->shouldReceive( 'save' )->andReturn( true );
		$parent_order_mock->shouldReceive( 'get_status' )->andReturn( 'on-hold' );

		Monkey\Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Monkey\Functions\expect( 'wc_get_order' )->with( 456 )->andReturn( $parent_order_mock );

		// Test the protected method via reflection
		// The existing Payload\PaymentMethod mock will handle PaymentMethod::get() call
		$reflection = new ReflectionClass( $this->gateway );
		$method     = $reflection->getMethod( 'process_subscription_payment_method_update' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->gateway, $order_mock, 'pm_123', 1 );

		$this->assertEquals( 'success', $result['result'] );
	}

	public function test_process_token_payment_with_saved_token() {
		$_POST = array( 'token' => '1' );

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_total' )->andReturn( 100.00 );
		$order_mock->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'http://example.com/order-received/123/' );
		$order_mock->shouldReceive( 'update_meta_data' )->andReturnSelf();
		$order_mock->shouldReceive( 'set_transaction_id' )->with( Mockery::type( 'string' ) )->andReturnSelf();
		$order_mock->shouldReceive( 'payment_complete' )->andReturn( true );
		$order_mock->shouldReceive( 'save' )->andReturn( true );

		$token_mock = Mockery::mock( 'WC_Payment_Token_CC' );
		$token_mock->shouldReceive( 'get_id' )->andReturn( 1 );
		$token_mock->shouldReceive( 'get_user_id' )->andReturn( 1 );
		$token_mock->shouldReceive( 'get_token' )->andReturn( 'pm_123' );

		Monkey\Functions\expect( 'get_user_meta' )
			->with( 1, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( 'cust_123' );

		// WC_Payment_Tokens::get() is already mocked in woocommerce-mocks.php
		// and will return a token mock

		// Test the protected method via reflection
		$reflection = new ReflectionClass( $this->gateway );
		$method     = $reflection->getMethod( 'process_token_payment' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->gateway, $order_mock, '1', '', 1 );

		// This method returns a transaction object, not an array
		$this->assertInstanceOf( 'Payload\Transaction', $result );
		$this->assertNotEmpty( $result->ref_number );
	}

	public function test_process_client_side_payment_validates_transaction_id() {

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_total' )->andReturn( 100.00 );
		$order_mock->shouldReceive( 'get_user_id' )->andReturn( 1 );
		$order_mock->shouldReceive( 'get_customer_id' )->andReturn( 1 );
		$order_mock->shouldReceive( 'get_checkout_order_received_url' )->andReturn( 'http://example.com/order-received/123/' );
		$order_mock->shouldReceive( 'update_meta_data' );
		$order_mock->shouldReceive( 'save' );
		$order_mock->shouldReceive( 'set_transaction_id' )->with( Mockery::type( 'string' ) );
		$order_mock->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );
		$order_mock->shouldReceive( 'set_payment_method' )->with( Mockery::type( 'string' ) );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( 123 )
			->andReturn( false );

		// Test the protected method via reflection
		$reflection = new ReflectionClass( $this->gateway );
		$method     = $reflection->getMethod( 'process_client_side_payment' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->gateway, 'txn_123', $order_mock, 1 );

		$this->assertEquals( 'processed', $result->status );
	}

	public function test_process_client_side_payment_tokenizes_when_keep_active_and_user_id() {
		\Payload\Transaction::$payment_method_override = array(
			'id'          => 'pm_123',
			'description' => 'Visa ending in 1111',
			'keep_active' => true,
			'card'        => array(
				'card_brand'  => 'visa',
				'card_number' => '4111111111111111',
				'expiry'      => '12/2025',
			),
		);

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_total' )->andReturn( 100.00 );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( 123 )
			->andReturn( false );

		$token_mock = Mockery::mock( 'WC_Payment_Token_CC' );

		$gateway = Mockery::mock( 'WC_Payload_Gateway' )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$gateway->shouldReceive( 'create_token' )
			->once()
			->with( Mockery::type( 'array' ), 1 )
			->andReturn( $token_mock );
		$gateway->shouldReceive( 'update_order_payment_method_token' )
			->once()
			->with( $order_mock, $token_mock );
		$gateway->shouldNotReceive( 'update_order_payment_method' );

		$reflection = new ReflectionClass( 'WC_Payload_Gateway' );
		$method     = $reflection->getMethod( 'process_client_side_payment' );
		$method->setAccessible( true );

		$result = $method->invoke( $gateway, 'txn_123', $order_mock, 1 );

		$this->assertEquals( 'processed', $result->status );
	}

	public function test_process_client_side_payment_does_not_tokenize_for_guest_even_when_keep_active() {
		\Payload\Transaction::$payment_method_override = array(
			'id'          => 'pm_123',
			'description' => 'Visa ending in 1111',
			'keep_active' => true,
			'card'        => array(
				'card_brand'  => 'visa',
				'card_number' => '4111111111111111',
				'expiry'      => '12/2025',
			),
		);

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_total' )->andReturn( 100.00 );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( 123 )
			->andReturn( false );

		$gateway = Mockery::mock( 'WC_Payload_Gateway' )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$gateway->shouldNotReceive( 'create_token' );
		$gateway->shouldNotReceive( 'update_order_payment_method_token' );
		$gateway->shouldReceive( 'update_order_payment_method' )
			->once()
			->with( $order_mock, 'Visa ending in 1111', 'pm_123' );

		$reflection = new ReflectionClass( 'WC_Payload_Gateway' );
		$method     = $reflection->getMethod( 'process_client_side_payment' );
		$method->setAccessible( true );

		$result = $method->invoke( $gateway, 'txn_123', $order_mock, 0 );

		$this->assertEquals( 'processed', $result->status );
	}

	public function test_process_client_side_payment_does_not_tokenize_when_keep_active_false() {
		\Payload\Transaction::$payment_method_override = array(
			'id'          => 'pm_123',
			'description' => 'Visa ending in 1111',
			'keep_active' => false,
			'card'        => array(
				'card_brand'  => 'visa',
				'card_number' => '4111111111111111',
				'expiry'      => '12/2025',
			),
		);

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_total' )->andReturn( 100.00 );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( 123 )
			->andReturn( false );

		$gateway = Mockery::mock( 'WC_Payload_Gateway' )
			->makePartial()
			->shouldAllowMockingProtectedMethods();
		$gateway->shouldNotReceive( 'create_token' );
		$gateway->shouldNotReceive( 'update_order_payment_method_token' );
		$gateway->shouldReceive( 'update_order_payment_method' )
			->once()
			->with( $order_mock, 'Visa ending in 1111', 'pm_123' );

		$reflection = new ReflectionClass( 'WC_Payload_Gateway' );
		$method     = $reflection->getMethod( 'process_client_side_payment' );
		$method->setAccessible( true );

		$result = $method->invoke( $gateway, 'txn_123', $order_mock, 1 );

		$this->assertEquals( 'processed', $result->status );
	}

	public function test_associate_customer_with_payment_success() {
		$payment_mock                    = Mockery::mock();
		$payment_mock->customer_id       = null;
		$payment_mock->payment_method_id = 'pm_test';
		$payment_mock->shouldReceive( 'update' )
			->with( array( 'customer_id' => 'cust_123' ) )
			->andReturnUsing(
				function ( $data ) use ( $payment_mock ) {
						$payment_mock->customer_id = $data['customer_id'];
						return true;
				}
			);

		Monkey\Functions\expect( 'get_user_meta' )
			->with( 1, 'payload_customer_id', true )
			->andReturn( 'cust_123' );

		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		$logger_mock->shouldReceive( 'error' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		// Test the protected method via reflection
		$reflection = new ReflectionClass( $this->gateway );
		$method     = $reflection->getMethod( 'associate_customer_with_payment' );
		$method->setAccessible( true );

		$method->invoke( $this->gateway, $payment_mock, 1 );

		$this->assertEquals( 'cust_123', $payment_mock->customer_id );
	}

	public function test_scheduled_subscription_payment_with_valid_token() {
		$product_mock = Mockery::mock();
		$product_mock->shouldReceive( 'is_virtual' )->andReturn( true );

		$item_mock = Mockery::mock();
		$item_mock->shouldReceive( 'get_product' )->andReturn( $product_mock );
		$item_mock->shouldReceive( 'get_name' )->andReturn( 'test' );

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_total' )->andReturn( 50.00 );
		$order_mock->shouldReceive( 'get_user_id' )->andReturn( 1 );
		$order_mock->shouldReceive( 'get_customer_id' )->andReturn( 1 );
		$order_mock->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$order_mock->shouldReceive( 'get_meta' )
			->with( '_payload_payment_method_id', true )
			->andReturn( 'pm_123' );
		$order_mock->shouldReceive( 'payment_complete' )->once();
		$order_mock->shouldReceive( 'set_transaction_id' )->with( Mockery::type( 'string' ) );
		$order_mock->shouldReceive( 'save' )->andReturn( true );
		$order_mock->shouldReceive( 'set_payment_method' )->with( Mockery::any() );
		$order_mock->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );
		$order_mock->shouldReceive( 'add_order_note' )->andReturn( true );
		$order_mock->shouldReceive( 'get_items' )->andReturn( array( $item_mock ) );

		$token_mock = Mockery::mock( 'WC_Payment_Token_CC' );
		$token_mock->shouldReceive( 'get_token' )->andReturn( 'pm_123' );
		$token_mock->shouldReceive( 'get_id' )->andReturn( 111 );

		$subscription_mock = Mockery::mock( 'WC_Subscription' );
		$subscription_mock->shouldReceive( 'get_parent_id' )->andReturn( 456 );

		$parent_order_mock = Mockery::mock( 'WC_Order' );
		$parent_order_mock->shouldReceive( 'get_payment_method' )->andReturn( '111' );
		$parent_order_mock->shouldReceive( 'get_payment_tokens' )->andReturn( array( '111' ) );

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		Monkey\Functions\expect( 'wcs_get_subscriptions_for_order' )
			->with( 123, array( 'order_type' => 'any' ) )
			->andReturn( array( 'sub_123' => $subscription_mock ) );

		Monkey\Functions\expect( 'wc_get_order' )
		->andReturnUsing(
			function ( $order_id ) use ( $order_mock, $parent_order_mock ) {
				if ( $order_id == 123 ) {
						return $order_mock;
				}
				if ( $order_id == 456 ) {
					return $parent_order_mock;
				}
				return null;
			}
		);

		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		$logger_mock->shouldReceive( 'error' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		// WC_Payment_Tokens::get_customer_tokens() is already mocked in woocommerce-mocks.php
		// and will return array with a token

		$this->gateway->scheduled_subscription_payment( 50.00, $order_mock );

		$this->assertTrue( true );
	}

	public function test_scheduled_subscription_payment_fails_when_no_subscriptions_found() {
		// Test data
		$order_id = 666;
		$amount   = 60.00;

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( $order_id );
		$order_mock->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$order_mock->shouldReceive( 'update_status' )
			->once()
			->with( 'failed', Mockery::type( 'string' ) );

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		Monkey\Functions\expect( 'wcs_get_subscriptions_for_order' )
			->with( $order_id, array( 'order_type' => 'any' ) )
			->andReturn( array() );

		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'error' )
			->once()
			->with(
				'No subscriptions found for renewal order ' . $order_id,
				array( 'source' => 'payload-gateway' )
			)
			->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$this->gateway->scheduled_subscription_payment( $amount, $order_mock );

		$this->assertTrue( true );
	}
}
