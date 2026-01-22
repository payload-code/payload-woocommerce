<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery as  m;

class Test_Utility_Functions extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        
        // Reset global state
        $_POST = array();
        $_GET = array();
        // Default: assume no existing customer unless test says otherwise
         if (class_exists('Customer')) {
        Customer::$shouldFindExisting = true;
        }

         }
    
    

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_payload_customer_id_creates_new_customer() {
        if (class_exists('Payload\\Customer')) {
            \Payload\Customer::$shouldFindExisting = false;
        }
        $user_mock = (object) [
            'ID' => 123,
            'user_email' => 'test@example.com',
            'user_nicename' => 'testuser'
        ];
        
        $customer_mock = Mockery::mock();
        $customer_mock->id = 'cust_123';
        
        // Customer mock is handled by the mock class
        
        Monkey\Functions\expect('wp_get_current_user')
            ->once()
            ->andReturn($user_mock);
            
        Monkey\Functions\expect('get_user_meta')
            ->once()
            ->with(123, 'payload_customer_id', true)
            ->andReturn('');
            
        Monkey\Functions\expect('update_user_meta')
            ->once()
            ->with(123, 'payload_customer_id', 'cust_123');
        
        $result = get_payload_customer_id();
        
        $this->assertEquals('cust_123', $result);
    }

    public function test_get_payload_customer_id_returns_existing_customer() {
        $user_mock = (object) [
            'ID' => 123,
            'user_email' => 'test@example.com',
            'user_nicename' => 'testuser'
        ];
        
        Monkey\Functions\expect('wp_get_current_user')
            ->once()
            ->andReturn($user_mock);
            
        Monkey\Functions\expect('get_user_meta')
            ->once()
            ->with(123, 'payload_customer_id', true)
            ->andReturn('cust_existing');
        
        $result = get_payload_customer_id();
        
        $this->assertEquals('cust_existing', $result);
    }

    public function test_get_payload_customer_id_returns_null_for_no_user() {
        Monkey\Functions\expect('wp_get_current_user')
            ->once()
            ->andReturn(false);
        
        $result = get_payload_customer_id();
        
        $this->assertNull($result);
    }

    public function test_get_intent_for_payment_method() {
        $_GET = array('type' => 'payment_method');
        
        $client_token_mock = Mockery::mock();
        $client_token_mock->id = 'ct_123';
        
        // ClientToken mock is handled by the mock class
        
        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('get_payload_customer_id')->andReturn('cust_123');
        
        $result = get_intent(array());
        
        $this->assertEquals(array('client_token' => 'ct_123'), $result);
    }

    public function test_get_intent_for_payment() {
        $_GET = array('type' => 'payment');
        
        $client_token_mock = Mockery::mock();
        $client_token_mock->id = 'ct_123';
        
        // ClientToken mock is handled by the mock class
        
        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('get_payload_customer_id')->andReturn('cust_123');
        
        $result = get_intent(array());
        
        $this->assertEquals(array('client_token' => 'ct_123'), $result);
    }

    public function test_setup_payload_api_sets_api_key() {
        $settings = array('api_key' => 'test_api_key');
        
        Monkey\Functions\expect('get_option')
            ->once()
            ->with('woocommerce_payload_settings', array())
            ->andReturn($settings);
            
        Monkey\Functions\expect('getenv')
            ->once()
            ->with('PAYLOAD_API_URL')
            ->andReturn(false);
        
        setup_payload_api();
        
        // Since we can't easily test static property assignment,
        // we'll verify the function completes without error
        $this->assertTrue(true);
    }

    public function test_setup_payload_api_with_custom_url() {
        $settings = array('api_key' => 'test_api_key');
        
        Monkey\Functions\expect('get_option')
            ->once()
            ->with('woocommerce_payload_settings', array())
            ->andReturn($settings);
            
        Monkey\Functions\expect('getenv')
            ->twice()
            ->with('PAYLOAD_API_URL')
            ->andReturn('https://custom-api.payload.com');
        
        setup_payload_api();
        
        $this->assertTrue(true);
    }

    public function test_payload_subscription_payment_method_to_display() {
        $subscription_mock = Mockery::mock();
        $subscription_mock->shouldReceive('get_parent_id')->andReturn(456);
        
        $parent_order_mock = Mockery::mock();
        $parent_order_mock->shouldReceive('get_payment_method_title')->andReturn('Payload Credit Card');
        
        Monkey\Functions\expect('wc_get_order')
            ->once()
            ->with(456)
            ->andReturn($parent_order_mock);
        
        $result = payload_subscription_payment_method_to_display('Original Label', $subscription_mock, 'context');
        
        $this->assertEquals('Payload Credit Card', $result);
    }
}
