<?php
/**
 * Integration Tests for Payload Payment Flows
 *
 * Tests complete payment workflows including:
 * - Standard checkout payments
 * - Subscription payments and renewals
 * - Payment method changes
 * - Error handling and recovery
 *
 * @package Payload_WooCommerce
 */

use PayloadWooCommerce\Tests\Integration\IntegrationTestCase;
use PayloadWooCommerce\Tests\Integration\Helpers\CurlMocker;
use Brain\Monkey;
use Mockery as m;

class Test_Integration_Payment_Flows extends IntegrationTestCase {


	private $gateway;

	protected function setUp(): void {
		parent::setUp();

		// Integration tests use real Payload SDK classes (no mocks).
		// HTTP requests are intercepted by mocking curl functions (curl_init, curl_exec, etc.).
		// This allows testing with real SDK code without making actual API calls.

		$this->gateway = new WC_Payload_Gateway();
	}

	/**
	 * Test: Complete checkout flow with new payment method
	 */
	public function test_complete_checkout_flow_with_new_payment_method() {
		// Test data
		$order_id          = 123;
		$amount            = 100.00;
		$user_id           = 1;
		$customer_id       = 'cust_123';
		$payment_method_id = 'pm_new123';
		$card_brand        = 'visa';
		$last4             = '1111';
		$expiry            = '12/2025';

		// Mock HTTP responses for Payload API calls
		CurlMocker::mockPaymentMethodGet( $payment_method_id, $card_brand, $last4, $expiry, null );
		CurlMocker::mockTransactionCreate(
			$amount,
			$payment_method_id,
			null,
			array(
				'description'       => ' Order Item(s): ',
				'amount'            => $amount,
				'type'              => 'payment',
				'payment_method_id' => $payment_method_id,
				'order_number'      => strval( $order_id ),
			)
		);
		CurlMocker::mockUpdate(
			'payment_methods',
			$payment_method_id,
			array(),
			array( 'attrs' => array( '_wp_token_id' => 1 ) )
		);

		// Setup: Customer checking out with new card
		$_POST = array( 'payment_method_id' => $payment_method_id );

		$order = $this->create_mock_order( $order_id, $amount, $user_id );

		// Mock WordPress/WooCommerce functions
		Monkey\Functions\expect( 'get_user_meta' )
			->with( $user_id, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( $customer_id );

		Monkey\Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);
		Monkey\Functions\expect( 'wcs_is_subscription' )->with( $order_id )->andReturn( false );
		Monkey\Functions\expect( 'wc_get_order' )->with( $order_id )->andReturn( $order );

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( $order_id )
			->andReturn( false );

		// Execute
		$result = $this->gateway->process_payment( $order_id );

		// Assert
		$this->assertEquals( 'success', $result['result'] );
		$this->assertArrayHasKey( 'redirect', $result );
	}

	/**
	 * Test: Checkout with saved payment method token
	 */
	public function test_checkout_flow_with_saved_token() {
		// Test data
		$order_id          = 123;
		$amount            = 75.00;
		$user_id           = 1;
		$token_id          = 456;
		$payment_method_id = 'pm_saved123';
		$card_brand        = 'visa';
		$last4             = '4242';

		// Mock HTTP response for Transaction::create()
		CurlMocker::mockTransactionCreate(
			$amount,
			$payment_method_id,
			null,
			array(
				'description'       => ' Order Item(s): ',
				'amount'            => $amount,
				'type'              => 'payment',
				'payment_method_id' => $payment_method_id,
				'order_number'      => strval( $order_id ),
			)
		);

		$_POST = array( 'token' => $token_id );

		$order = $this->create_mock_order( $order_id, $amount, $user_id );

		// Create mock token
		$token = Mockery::mock( 'WC_Payment_Token_CC' );
		$token->shouldReceive( 'get_id' )->andReturn( $token_id );
		$token->shouldReceive( 'get_user_id' )->andReturn( $user_id );
		$token->shouldReceive( 'get_token' )->andReturn( $payment_method_id );
		$token->shouldReceive( 'get_card_type' )->andReturn( $card_brand );
		$token->shouldReceive( 'get_last4' )->andReturn( $last4 );

		// Mock WC_Payment_Tokens::get() to return our token
		\Patchwork\redefine(
			'WC_Payment_Tokens::get',
			function ( $id ) use ( $token, $token_id ) {
				if ( $id === strval( $token_id ) ) {
					return $token;
				}
				return null;
			}
		);

		// Mock WordPress/WooCommerce functions
		Monkey\Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);
		Monkey\Functions\expect( 'wcs_is_subscription' )->with( $order_id )->andReturn( false );
		Monkey\Functions\expect( 'wc_get_order' )->with( $order_id )->andReturn( $order );

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( $order_id )
			->andReturn( false );

		$result = $this->gateway->process_payment( $order_id );

		$this->assertEquals( 'success', $result['result'] );
	}

	/**
	 * Test: Payment method update for subscription
	 */
	public function test_subscription_payment_method_update_flow() {
		// Test data
		$subscription_id   = 456;
		$parent_order_id   = 789;
		$user_id           = 1;
		$customer_id       = 'cust_123';
		$payment_method_id = 'pm_updated123';
		$card_brand        = 'amex';
		$last4             = '8888';
		$expiry            = '06/2026';

		// Mock HTTP response for PaymentMethod::get()
		CurlMocker::mockPaymentMethodGet( $payment_method_id, $card_brand, $last4, $expiry, null );

		$_POST = array( 'payment_method_id' => $payment_method_id );

		$subscription = $this->create_mock_order( $subscription_id, 0, $user_id );
		$subscription->shouldReceive( 'update_meta_data' )->with( '_payload_payment_method_id', $payment_method_id );
		$subscription->shouldReceive( 'save' );
		$subscription->shouldReceive( 'get_parent_id' )->andReturn( $parent_order_id );
		$subscription->shouldReceive( 'set_payment_method' )->with( Mockery::any() );
		$subscription->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );

		$parent_order = Mockery::mock( 'WC_Order' );
		$parent_order->shouldReceive( 'update_meta_data' )->with( '_payload_payment_method_id', $payment_method_id );
		$parent_order->shouldReceive( 'set_payment_method' )->with( Mockery::any() );
		$parent_order->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );
		$parent_order->shouldReceive( 'save' )->andReturn( true );
		$parent_order->shouldReceive( 'get_status' )->andReturn( 'pending' );

		// Mock the payment method update
		CurlMocker::mockUpdate(
			'payment_methods',
			$payment_method_id,
			array(),
			array( 'attrs' => array( '_wp_token_id' => 1 ) )
		);

		// Mock WordPress/WooCommerce functions
		Monkey\Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);
		Monkey\Functions\expect( 'wcs_is_subscription' )->with( $subscription_id )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_order' )->with( $subscription_id )->andReturn( $subscription );
		Monkey\Functions\expect( 'wc_get_order' )->with( $parent_order_id )->andReturn( $parent_order );
		Monkey\Functions\expect( 'get_user_meta' )
			->with( $user_id, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( $customer_id );

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$result = $this->gateway->process_payment( $subscription_id );

		$this->assertEquals( 'success', $result['result'] );
	}

	/**
	 * Test: Customer association with payment
	 */
	public function test_client_side_payment_initiation() {
		// Test data
		$order_id          = 888;
		$amount            = 100.00;
		$user_id           = 1;
		$customer_id       = 'cust_123';
		$transaction_id    = 'txn_assoc';
		$payment_method_id = 'pm_assoc';
		$ref_number        = 'REF888';
		$card_brand        = 'visa';
		$last4             = '1111';
		$card_number       = '4111111111111111';
		$expiry            = '12/2025';
		$description       = 'Visa ending in 1111';

		// Mock HTTP responses for Payload API calls
		$payment_method_data = array(
			'id'          => $payment_method_id,
			'description' => $description,
			'card'        => array(
				'card_brand'  => $card_brand,
				'card_number' => $card_number,
				'expiry'      => $expiry,
			),
		);

		// Transaction response structure (reused for GET and UPDATE)
		$transaction_data = array(
			'object'            => 'transaction',
			'id'                => $transaction_id,
			'type'              => 'payment',
			'status'            => 'processed',
			'amount'            => $amount,
			'ref_number'        => $ref_number,
			'payment_method_id' => $payment_method_id,
			'payment_method'    => $payment_method_data,
		);

		// First, Transaction::get() returns payment with no customer_id
		CurlMocker::mockResponse(
			'GET',
			'https://api.payload.com/transactions/' . $transaction_id,
			200,
			array_merge( $transaction_data, array( 'customer_id' => null ) )
		);

		// Then, payment.update() is called to associate customer
		// IMPORTANT: Must include all fields because SDK caches objects by ID
		CurlMocker::mockUpdate(
			'transactions',
			$transaction_id,
			array_merge( $transaction_data, array( 'customer_id' => $customer_id ) ),
			array( 'customer_id' => $customer_id )
		);

		// Also need to mock PaymentMethod::get() for associate_customer_with_payment
		CurlMocker::mockPaymentMethodGet( $payment_method_id, $card_brand, $last4, $expiry, null );
		// And mock the payment method update
		CurlMocker::mockUpdate(
			'payment_methods',
			$payment_method_id,
			$payment_method_data,
			array( 'account_id' => $customer_id )
		);

		$_POST = array( 'transactionid' => $transaction_id );

		$order = $this->create_mock_order( $order_id, $amount, $user_id );
		$order->shouldReceive( 'set_transaction_id' )->with( Mockery::type( 'string' ) );
		$order->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );
		$order->shouldReceive( 'set_payment_method' )->with( Mockery::type( 'string' ) );
		$order->shouldReceive( 'get_items' )->andReturn( array() );

		// Mock WordPress/WooCommerce functions
		Monkey\Functions\expect( 'get_user_meta' )
			->with( $user_id, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( $customer_id );
		Monkey\Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);
		Monkey\Functions\expect( 'wcs_is_subscription' )->with( $order_id )->andReturn( false );
		Monkey\Functions\expect( 'wc_get_order' )->with( $order_id )->andReturn( $order );

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( $order_id )
			->andReturn( false );

		$result = $this->gateway->process_payment( $order_id );

		$this->assertEquals( 'success', $result['result'] );
	}

	/**
	 * Test: Decline checkout flow with new payment method
	 */
	public function test_decline_checkout_flow_with_new_payment_method() {
		// Test data
		$order_id          = 123;
		$amount            = 100.00;
		$user_id           = 1;
		$customer_id       = 'cust_123';
		$payment_method_id = 'pm_new123';
		$card_brand        = 'visa';
		$last4             = '1111';
		$expiry            = '12/2025';

		// Mock HTTP responses for Payload API calls
		CurlMocker::mockPaymentMethodGet( $payment_method_id, $card_brand, $last4, $expiry, null );
		CurlMocker::mockUpdate(
			'payment_methods',
			$payment_method_id,
			array(),
			array( 'attrs' => array( '_wp_token_id' => 1 ) )
		);
		CurlMocker::mockError(
			'POST',
			'https://api.payload.com/transactions',
			400,
			'TransactionDeclined',
			'This transaction was declined'
		);

		// Setup: Customer checking out with new card
		$_POST = array( 'payment_method_id' => $payment_method_id );

		$order = $this->create_mock_order( $order_id, $amount, $user_id );

		// Mock WordPress/WooCommerce functions
		Monkey\Functions\expect( 'get_user_meta' )
			->with( $user_id, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( $customer_id );

		Monkey\Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);
		Monkey\Functions\expect( 'wcs_is_subscription' )->with( $order_id )->andReturn( false );
		Monkey\Functions\expect( 'wc_get_order' )->with( $order_id )->andReturn( $order );

		// Mock logger - should log info but not errors (exception will be thrown)
		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		$logger_mock->shouldNotReceive( 'error' );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( $order_id )
			->andReturn( false );

		// Expect TransactionDeclined exception
		$this->expectException( \TransactionDeclined::class );
		$this->expectExceptionMessage( 'Transaction creation failed: This transaction was declined' );

		// Execute
		$this->gateway->process_payment( $order_id );
	}

	/**
	 * Test: Subscription renewal payment
	 */
	public function test_subscription_renewal_payment_flow() {
		// Test data
		$order_id          = 789;
		$amount            = 50.00;
		$user_id           = 1;
		$payment_method_id = 'pm_subscription123';
		$parent_order_id   = 456;
		$token_id          = 111;
		$card_brand        = 'mastercard';
		$last4             = '5555';
		$expiry            = '12/2025';
		$subscription_id   = 'sub_123';

		$order = $this->create_mock_order( $order_id, $amount, $user_id );
		$order->shouldReceive( 'get_meta' )
			->with( '_payload_payment_method_id', true )
			->andReturn( $payment_method_id );
		$order->shouldReceive( 'add_order_note' )->andReturn( true );
		$order->shouldReceive( 'set_payment_method' )->with( Mockery::any() );
		$order->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );

		$subscription = Mockery::mock( 'WC_Subscription' );
		$subscription->shouldReceive( 'get_parent_id' )->andReturn( $parent_order_id );

		$parent_order = Mockery::mock( 'WC_Order' );
		$parent_order->shouldReceive( 'get_payment_method' )->andReturn( strval( $token_id ) );
		$parent_order->shouldReceive( 'get_payment_tokens' )->andReturn( array( strval( $token_id ) ) );

		// Create mock token
		$token = Mockery::mock( 'WC_Payment_Token_CC' );
		$token->shouldReceive( 'get_id' )->andReturn( $token_id );
		$token->shouldReceive( 'get_user_id' )->andReturn( $user_id );
		$token->shouldReceive( 'get_token' )->andReturn( $payment_method_id );
		$token->shouldReceive( 'get_card_type' )->andReturn( $card_brand );
		$token->shouldReceive( 'get_last4' )->andReturn( $last4 );

		// Mock WC_Payment_Tokens::get() to return our token
		\Patchwork\redefine(
			'WC_Payment_Tokens::get',
			function ( $id ) use ( $token, $token_id ) {
				if ( $id === strval( $token_id ) ) {
					return $token;
				}
				return null;
			}
		);

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		Monkey\Functions\expect( 'wcs_get_subscriptions_for_order' )
			->with( $order_id, array( 'order_type' => 'any' ) )
			->andReturn( array( $subscription_id => $subscription ) );

		Monkey\Functions\expect( 'wc_get_order' )
		->andReturnUsing(
			function ( $id ) use ( $order_id, $parent_order_id, $order, $parent_order ) {
				if ( $id == $order_id ) {
						return $order;
				}
				if ( $id == $parent_order_id ) {
					return $parent_order;
				}
				return null;
			}
		);

		// Mock HTTP response for Payload API calls
		CurlMocker::mockPaymentMethodGet( $payment_method_id, $card_brand, $last4, $expiry, null );

		CurlMocker::mockTransactionCreate(
			$amount,
			$payment_method_id,
			null,
			array(
				'description'       => ' Order Item(s): ',
				'amount'            => $amount,
				'type'              => 'payment',
				'payment_method_id' => $payment_method_id,
				'order_number'      => strval( $order_id ),
			)
		);

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$this->gateway->scheduled_subscription_payment( $amount, $order );

		$this->assertTrue( true );
	}

	/**
	 * Test: Subscription renewal payment decline
	 */
	public function test_subscription_renewal_payment_decline_flow() {
		// Test data
		$order_id          = 789;
		$amount            = 50.00;
		$user_id           = 1;
		$payment_method_id = 'pm_subscription123';
		$parent_order_id   = 456;
		$token_id          = 111;
		$card_brand        = 'mastercard';
		$last4             = '5555';
		$expiry            = '12/2025';
		$subscription_id   = 'sub_123';

		$order = $this->create_mock_order( $order_id, $amount, $user_id );
		$order->shouldReceive( 'get_meta' )
			->with( '_payload_payment_method_id', true )
			->andReturn( $payment_method_id );
		$order->shouldReceive( 'add_order_note' )->andReturn( true );
		$order->shouldReceive( 'set_payment_method' )->with( Mockery::any() );
		$order->shouldReceive( 'set_payment_method_title' )->with( Mockery::type( 'string' ) );

		$subscription = Mockery::mock( 'WC_Subscription' );
		$subscription->shouldReceive( 'get_parent_id' )->andReturn( $parent_order_id );

		$parent_order = Mockery::mock( 'WC_Order' );
		$parent_order->shouldReceive( 'get_payment_method' )->andReturn( strval( $token_id ) );
		$parent_order->shouldReceive( 'get_payment_tokens' )->andReturn( array( strval( $token_id ) ) );

		// Create mock token
		$token = Mockery::mock( 'WC_Payment_Token_CC' );
		$token->shouldReceive( 'get_id' )->andReturn( $token_id );
		$token->shouldReceive( 'get_user_id' )->andReturn( $user_id );
		$token->shouldReceive( 'get_token' )->andReturn( $payment_method_id );
		$token->shouldReceive( 'get_card_type' )->andReturn( $card_brand );
		$token->shouldReceive( 'get_last4' )->andReturn( $last4 );

		// Mock WC_Payment_Tokens::get() to return our token
		\Patchwork\redefine(
			'WC_Payment_Tokens::get',
			function ( $id ) use ( $token, $token_id ) {
				if ( $id === strval( $token_id ) ) {
					return $token;
				}
				return null;
			}
		);

		Monkey\Functions\expect( 'setup_payload_api' )->once();
		Monkey\Functions\expect( 'wcs_get_subscriptions_for_order' )
			->with( $order_id, array( 'order_type' => 'any' ) )
			->andReturn( array( $subscription_id => $subscription ) );

		Monkey\Functions\expect( 'wc_get_order' )
		->andReturnUsing(
			function ( $id ) use ( $order_id, $parent_order_id, $order, $parent_order ) {
				if ( $id == $order_id ) {
						return $order;
				}
				if ( $id == $parent_order_id ) {
					return $parent_order;
				}
				return null;
			}
		);

		// Mock HTTP response for Payload API calls
		CurlMocker::mockPaymentMethodGet( $payment_method_id, $card_brand, $last4, $expiry, null );

		CurlMocker::mockError(
			'POST',
			'https://api.payload.com/transactions',
			400,
			'TransactionDeclined',
			'This transaction was declined'
		);

		// Mock logger
		$logger_mock = Mockery::mock();
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		// Expect TransactionDeclined exception
		$this->expectException( \TransactionDeclined::class );
		$this->expectExceptionMessage( 'Transaction creation failed: This transaction was declined' );

		$this->gateway->scheduled_subscription_payment( $amount, $order );
	}

	/**
	 * Test: Payment authorization vs processing status
	 */
	public function test_payment_authorized_updated_on_virtual_orders() {
		$payment_id = 'txn_authorized';
		$ref_number = 'REF_AUTH';

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_customer_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_user_id' )->andReturn( 1 );
		$order->shouldReceive( 'update_meta_data' )->andReturnSelf();
		$order->shouldReceive( 'save' )->andReturnSelf();
		$order->shouldReceive( 'set_transaction_id' )->with( $ref_number );
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'payment_complete' )->once(); // authorized but not virtual, so no payment_complete

		$payment_authorized = new Payload\Transaction(
			array(
				'id'         => $payment_id,
				'status'     => 'authorized',
				'ref_number' => $ref_number,
			)
		);

		Monkey\Functions\expect( 'get_user_meta' )
			->with( 1, 'billing_company', true )
			->andReturn( '' );

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		// Mock the payment method update
		CurlMocker::mockUpdate(
			'transactions',
			$payment_id,
			array(
				'id'         => $payment_id,
				'status'     => 'processed',
				'ref_number' => $ref_number,
			),
			array(
				'order_number' => '123',
				'status'       => 'processed',
				'description'  => ' Order Item(s): ',
			)
		);

		Monkey\Functions\expect( 'wc_get_order' )->with( 123 )->andReturn( $order );

		// Test handling of authorized payment
		$this->gateway->handle_order_payment( $order, $payment_authorized );

		$this->assertEquals( 'processed', $payment_authorized->status );
	}

	/**
	 * Test: Payment authorization vs processing status
	 */
	public function test_payment_authorized_not_updated_for_physical_orders() {
		$payment_id = 'txn_authorized';
		$ref_number = 'REF_AUTH';

		$product_mock = Mockery::mock();
		$product_mock->shouldReceive( 'is_virtual' )->andReturn( false );
		$product_mock->shouldReceive( 'is_downloadable' )->andReturn( false );

		$item_mock = Mockery::mock();
		$item_mock->shouldReceive( 'get_product' )->andReturn( $product_mock );

		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( 123 );
		$order->shouldReceive( 'get_customer_id' )->andReturn( 1 );
		$order->shouldReceive( 'get_user_id' )->andReturn( 1 );
		$order->shouldReceive( 'update_meta_data' )->andReturnSelf();
		$order->shouldReceive( 'save' )->andReturnSelf();
		$order->shouldReceive( 'set_transaction_id' )->with( $ref_number );
		$order->shouldReceive( 'get_items' )->andReturn( array( $item_mock ) );
		$order->shouldReceive( 'payment_complete' )->never(); // authorized but not virtual, so no payment_complete

		$payment_authorized = new Payload\Transaction(
			array(
				'id'         => $payment_id,
				'status'     => 'authorized',
				'ref_number' => $ref_number,
			)
		);

		Monkey\Functions\expect( 'get_user_meta' )
			->with( 1, 'billing_company', true )
			->andReturn( '' );

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		Monkey\Functions\expect( 'wc_get_order' )->with( 123 )->andReturn( $order );

		// Test handling of authorized payment
		$this->gateway->handle_order_payment( $order, $payment_authorized );

		$this->assertEquals( 'authorized', $payment_authorized->status );
	}

	/**
	 * Test: Sanitization of POST data with XSS attempts
	 */
	public function test_checkout_with_malicious_input_sanitization() {

		// Test data
		$order_id          = 123;
		$amount            = 100.00;
		$user_id           = 1;
		$customer_id       = 'cust_123';
		$payment_method_id = 'pm_123';
		$card_brand        = 'visa';
		$last4             = '1111';
		$expiry            = '12/2025';

		// Mock HTTP responses for Payload API calls
		CurlMocker::mockPaymentMethodGet( $payment_method_id, $card_brand, $last4, $expiry, null );
		CurlMocker::mockTransactionCreate(
			$amount,
			$payment_method_id,
			null,
			array(
				'description'       => ' Order Item(s): ',
				'amount'            => $amount,
				'type'              => 'payment',
				'payment_method_id' => $payment_method_id,
				'order_number'      => strval( $order_id ),
			)
		);
		CurlMocker::mockUpdate(
			'payment_methods',
			$payment_method_id,
			array(),
			array( 'attrs' => array( '_wp_token_id' => 1 ) )
		);

		// Setup: Customer checking out with new card
		$_POST = array(
			'payment_method_id' => '<script></script>pm_123',
		);

		$order = $this->create_mock_order( $order_id, $amount, $user_id );

		// Mock WordPress/WooCommerce functions
		Monkey\Functions\expect( 'get_user_meta' )
			->with( $user_id, PAYLOAD_CUSTOMER_ID_META_KEY, true )
			->andReturn( $customer_id );

		Monkey\Functions\expect( 'setup_payload_api' )->once()->andReturnUsing(
			function () {
				\Payload\API::$api_key = 'test_key';
				\Payload\API::$api_url = 'https://api.payload.com';
			}
		);
		Monkey\Functions\expect( 'wcs_is_subscription' )->with( $order_id )->andReturn( false );
		Monkey\Functions\expect( 'wc_get_order' )->with( $order_id )->andReturn( $order );

		// Mock logger - successful checkout should NOT log any errors
		$logger_mock = Mockery::mock();
		$logger_mock->shouldNotReceive( 'error' );
		$logger_mock->shouldReceive( 'info' )->andReturn( true );
		Monkey\Functions\expect( 'wc_get_logger' )->andReturn( $logger_mock );

		$subscription_order_mock = Mockery::mock( 'alias:WC_Subscriptions_Order' );
		$subscription_order_mock->shouldReceive( 'order_contains_subscription' )
			->with( $order_id )
			->andReturn( false );

		// Execute
		$result = $this->gateway->process_payment( $order_id );

		// Assert
		$this->assertEquals( 'success', $result['result'] );
		$this->assertArrayHasKey( 'redirect', $result );
	}

	// Helper methods

	private function create_mock_order( $order_id, $total, $user_id ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_id' )->andReturn( $order_id );
		$order->shouldReceive( 'get_total' )->andReturn( $total );
		$order->shouldReceive( 'get_user_id' )->andReturn( $user_id );
		$order->shouldReceive( 'get_customer_id' )->andReturn( $user_id );
		$order->shouldReceive( 'get_checkout_order_received_url' )
			->andReturn( "http://example.com/order-received/{$order_id}/" );
		$order->shouldReceive( 'update_meta_data' )->andReturnSelf();
		$order->shouldReceive( 'save' )->andReturnSelf();
		$order->shouldReceive( 'get_items' )->andReturn( array() );
		$order->shouldReceive( 'set_transaction_id' )->with( Mockery::type( 'string' ) );
		$order->shouldReceive( 'get_status' )->andReturn( 'pending' );
		$order->shouldReceive( 'payment_complete' )->andReturn( true );

		return $order;
	}
}
