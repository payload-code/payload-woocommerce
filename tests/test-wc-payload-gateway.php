<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery as m;
use Payload\API as pl;

class Test_WC_Payload_Gateway extends TestCase {

    private $gateway;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        
        // Mock global state
        $_POST = array();
        $_GET = array();
        
        $this->gateway = new WC_Payload_Gateway();

    }


    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_constructor_sets_correct_properties() {
        $this->assertEquals('payload', $this->gateway->id);
        $this->assertTrue($this->gateway->has_fields);
        $this->assertContains('products', $this->gateway->supports);
        $this->assertContains('tokenization', $this->gateway->supports);
        $this->assertContains('subscriptions', $this->gateway->supports);
    }

    public function test_init_form_fields_creates_required_fields() {
        $this->gateway->init_form_fields();
        
        $this->assertArrayHasKey('enabled', $this->gateway->form_fields);
        $this->assertArrayHasKey('api_key', $this->gateway->form_fields);
        $this->assertEquals('checkbox', $this->gateway->form_fields['enabled']['type']);
        $this->assertEquals('password', $this->gateway->form_fields['api_key']['type']);
    }

    public function test_payment_scripts_skips_admin() {
        Monkey\Functions\expect('is_admin')
            ->once()
            ->andReturn(true);
        
        Monkey\Functions\expect('wp_enqueue_style')->never();
        Monkey\Functions\expect('wp_enqueue_script')->never();
        
        $this->gateway->payment_scripts();
        
        // Assert that the function completes without enqueueing scripts
        $this->assertTrue(true);
    }

    public function test_payment_scripts_enqueues_frontend_assets() {
        Monkey\Functions\expect('is_admin')
            ->once()
            ->andReturn(false);
            
        Monkey\Functions\expect('wp_enqueue_style')
            ->once()
            ->with('payload-blocks-css', Mockery::type('string'), array(), '');
            
        Monkey\Functions\expect('wp_enqueue_script')
            ->once()
            ->with('payload-blocks-integration', Mockery::type('string'), Mockery::type('array'), '', true);
            
        Monkey\Functions\expect('function_exists')
            ->with('wp_set_script_translations')
            ->andReturn(true);
            
        Monkey\Functions\expect('wp_set_script_translations')
            ->once()
            ->with('payload-blocks-integration');
        
        $this->gateway->payment_scripts();
        
        // Assert that the function completes and enqueues frontend assets
        $this->assertTrue(true);
    }

    public function test_process_payment_with_missing_details_throws_exception() {
        // Set empty values for the keys that the code checks
        $_POST = array(
            'token' => '',
            'payment_method_id' => '',
            'transactionid' => ''
        );
        
        $order = new WC_Order(123);
        
        Monkey\Functions\expect('wcs_is_subscription')
            ->with(123)
            ->andReturn(false);
            
        Monkey\Functions\expect('wc_get_order')
            ->with(123)
            ->andReturn($order);
            
        Monkey\Functions\expect('setup_payload_api')->once();
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing payment details');
        
        $this->gateway->process_payment(123);
    }

    public function test_process_payment_with_transaction_id_success() {
        $_POST = array(
            'transactionid' => 'txn_123',
            'token' => '',
            'payment_method_id' => ''
        );
        
        $order = new WC_Order(123);
        
        // Mock PaymentMethod for update first
        $payment_method_mock = Mockery::mock();
        $payment_method_mock->shouldReceive('update')->andReturn(true);
        
        // Mock Payload Transaction
        $payment_mock = Mockery::mock();
        $payment_mock->amount = 100.00;
        $payment_mock->ref_number = 'REF123';
        $payment_mock->customer_id = 'cust_existing';
        $payment_mock->payment_method_id = 'pm_123';
        $payment_mock->payment_method = array(
            'id' => 'pm_123',
            'card' => array(
                'card_brand' => 'visa',
                'card_number' => '4111111111111111',
                'expiry' => '12/2025'
            )
        );
        $payment_mock->shouldReceive('update')->andReturn(true);
        
        Monkey\Functions\expect('wcs_is_subscription')
            ->with(123)
            ->andReturn(false);
            
        Monkey\Functions\expect('wc_get_order')
            ->with(123)
            ->andReturn($order);
            
        Monkey\Functions\expect('setup_payload_api')->once();
        
        // Transaction mock is handled by the mock class
            
        // No need to mock get_payload_customer_id since customer_id is already set
            
        // Skip mocking PaymentMethod::get since it conflicts with other tests
        // The test will focus on the main flow
            
        $subscription_order_mock = Mockery::mock('alias:WC_Subscriptions_Order');
        $subscription_order_mock->shouldReceive('order_contains_subscription')
            ->with(123)
            ->andReturn(false);
        
        $result = $this->gateway->process_payment(123);
        
        $this->assertEquals('success', $result['result']);
        $this->assertArrayHasKey('redirect', $result);
    }

    public function test_create_token_sets_correct_properties() {
        $payment_method_data = array(
            'id' => 'pm_test123',
            'card' => array(
                'card_brand' => 'visa',
                'card_number' => '4111111111111111',
                'expiry' => '12/'.date('Y', strtotime('+1 year'))
            )
        );
       
        Monkey\Functions\expect('get_current_user_id')
            ->andReturn(1);
        
        // Mock the Payload\PaymentMethod construction and update call
        // We'll use a more direct approach without conflicting aliases
        $token = $this->gateway->create_token($payment_method_data);
        
        $this->assertInstanceOf(WC_Payment_Token_CC::class, $token);
        $this->assertEquals('pm_test123', $token->get_token());
    }

    public function test_add_payment_method_success() {
        $_POST = array('payment_method_id' => 'pm_123');
        
        $payment_method_mock = Mockery::mock();
        $payment_method_mock->shouldReceive('data')->andReturn(array(
            'id' => 'pm_123',
            'card' => array(
                'card_brand' => 'visa',
                'card_number' => '4111111111111111',
                'expiry' => '12/'.date('Y', strtotime('+1 year'))
            )
        ));
        
        Monkey\Functions\expect('setup_payload_api')->once();
        
        // PaymentMethod mock is handled by the mock class
            
        Monkey\Functions\expect('wc_get_endpoint_url')
            ->with('payment-methods')
            ->andReturn('http://example.com/my-account/payment-methods/');
        
        $result = $this->gateway->add_payment_method();
        
        $this->assertEquals('success', $result['result']);
        $this->assertEquals('http://example.com/my-account/payment-methods/', $result['redirect']);
    }

    public function test_add_payment_method_missing_payment_method_id_throws_exception() {
        $_POST = array('payment_method_id' => '');
        
        Monkey\Functions\expect('setup_payload_api')->once();
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing payment method details');
        
        $this->gateway->add_payment_method();
    }

    public function test_create_payment_for_order() {
        $order = new WC_Order(123);
        $amount = 50.00;
        $payment_method_id = 'pm_123';
        
        $payment_mock = Mockery::mock();
        $payment_mock->ref_number = 'REF456';
        
        // Transaction mock is handled by the mock class
        
        $payment = $this->gateway->create_payment_for_order($order, $amount, $payment_method_id);
        
        $this->assertInstanceOf('Payload\Transaction', $payment);
        $this->assertEquals('REF456', $payment->ref_number);
    }

    public function test_update_subscription_order_payment_method() {
        $order = new WC_Order(123);
        $token = new WC_Payment_Token_CC();
        $token->set_token('pm_123');
        
        $payment_method_mock = Mockery::mock();
        $payment_method_mock->description = 'Visa ending in 1111';
        
        $this->gateway->update_subscription_order_payment_method($order, $token, $payment_method_mock);
        
        $this->assertEquals($token->get_id(), $order->get_payment_method());
        $this->assertEquals('Visa ending in 1111', $order->get_payment_method_title());
    }
}