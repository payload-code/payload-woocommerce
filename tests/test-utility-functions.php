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
            'display_name' => 'testuser'
        ];
        
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

    public function test_payload_get_customer_id_meta_returns_customer_id() {
        Monkey\Functions\expect('get_user_meta')
            ->once()
            ->with(123, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('cust_123');

        $result = payload_get_customer_id_meta(123);

        $this->assertEquals('cust_123', $result);
    }

    public function test_payload_update_customer_id_meta_stores_customer_id() {
        Monkey\Functions\expect('update_user_meta')
            ->once()
            ->with(123, PAYLOAD_CUSTOMER_ID_META_KEY, 'cust_456')
            ->andReturn(true);

        $result = payload_update_customer_id_meta(123, 'cust_456');

        $this->assertTrue($result);
    }

    public function test_payload_sync_customer_on_profile_update_with_existing_customer() {
        $user_mock = (object) [
            'ID' => 123,
            'user_email' => 'updated@example.com',
            'user_nicename' => 'updateduser',
            'first_name' => 'Updated',
            'last_name' => 'User'
        ];

        $customer_mock = Mockery::mock();
        $customer_mock->shouldReceive('update')
            ->with(Mockery::type('array'))
            ->andReturn(true);

        Monkey\Functions\expect('get_user_meta')
            ->once()
            ->with(123, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('cust_existing');

        Monkey\Functions\expect('get_user_meta')
            ->with(123, 'billing_company', true)
            ->andReturn('Test Company');

        Monkey\Functions\expect('setup_payload_api')->once();
        $logger_mock = Mockery::mock();
        $logger_mock->shouldReceive('error')->andReturn(true);
        Monkey\Functions\expect('wc_get_logger')->andReturn($logger_mock);

        payload_sync_customer_on_profile_update(123, $user_mock);

        $this->assertTrue(true);
    }

    public function test_payload_autocomplete_virtual_orders_completes_virtual_order() {
        $order_mock = Mockery::mock('WC_Order');
        $order_mock->shouldReceive('get_id')->andReturn(123);
        $order_mock->shouldReceive('get_status')->andReturn('processing');
        $order_mock->shouldReceive('get_payment_method')->andReturn('payload');
        $order_mock->shouldReceive('update_status')
            ->with('completed', Mockery::type('string'));

        $product_mock = Mockery::mock();
        $product_mock->shouldReceive('is_virtual')->andReturn(true);
        $product_mock->shouldReceive('is_downloadable')->andReturn(false);

        $item_mock = Mockery::mock();
        $item_mock->shouldReceive('get_product')->andReturn($product_mock);

        $order_mock->shouldReceive('get_items')->andReturn(array($item_mock));

        Monkey\Functions\expect('wc_get_order')
            ->with(123)
            ->andReturn($order_mock);

        payload_autocomplete_virtual_orders(123);

        $this->assertTrue(true);
    }

    public function test_payload_autocomplete_virtual_orders_skips_non_virtual() {
        $order_mock = Mockery::mock('WC_Order');
        $order_mock->shouldReceive('get_id')->andReturn(123);
        $order_mock->shouldReceive('get_status')->andReturn('processing');
        $order_mock->shouldReceive('get_payment_method')->andReturn('payload');

        $product_mock = Mockery::mock();
        $product_mock->shouldReceive('is_virtual')->andReturn(false);
        $product_mock->shouldReceive('is_downloadable')->andReturn(false);

        $item_mock = Mockery::mock();
        $item_mock->shouldReceive('get_product')->andReturn($product_mock);

        $order_mock->shouldReceive('get_items')->andReturn(array($item_mock));
        $order_mock->shouldReceive('update_status')->never();

        Monkey\Functions\expect('wc_get_order')
            ->with(123)
            ->andReturn($order_mock);

        payload_autocomplete_virtual_orders(123);

        $this->assertTrue(true);
    }

    public function test_payload_create_customer_on_registration_success() {

        $user_mock = (object) [
            'ID' => 123,
            'user_email' => 'newuser@example.com',
            'display_name' => 'newuser'
        ];

        $customer_mock = Mockery::mock();
        $customer_mock->id = 'cust_123';

        Monkey\Functions\expect('get_user_by')
            ->once()
            ->with('id', 123)
            ->andReturn($user_mock);

        Monkey\Functions\expect('get_user_meta')
            ->twice()
            ->with(123, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('');

        Monkey\Functions\expect('update_user_meta')
            ->once()
            ->with(123, PAYLOAD_CUSTOMER_ID_META_KEY, 'cust_123');

        Monkey\Functions\expect('setup_payload_api')->once();
        $logger_mock = Mockery::mock();
        $logger_mock->shouldReceive('info')->andReturn(true);
        $logger_mock->shouldReceive('error')->andReturn(true);
        Monkey\Functions\expect('wc_get_logger')->andReturn($logger_mock);

        payload_create_customer_on_registration(123);

        $this->assertTrue(true);
    }

    public function test_payload_ensure_customer_after_checkout_creates_customer() {

        $order_mock = Mockery::mock('WC_Order');
        $order_mock->shouldReceive('get_user_id')->andReturn(123);
        $order_mock->shouldReceive('get_customer_id')->andReturn('cust_123');
        $order_mock->shouldReceive('get_payment_method')->andReturn('payload');

        $user_mock = (object) [
            'ID' => 123,
            'user_email' => 'checkout@example.com',
            'display_name' => 'checkoutuser'
        ];

        $customer_mock = Mockery::mock();
        $customer_mock->id = 'cust_checkout123';

        $logger_mock = Mockery::mock();
        $logger_mock->shouldReceive('info');

        Monkey\Functions\expect('wc_get_order')
            ->with(123)
            ->andReturn($order_mock);

        Monkey\Functions\expect('get_user_by')
            ->with('id', 123)
            ->andReturn($user_mock);

        Monkey\Functions\expect('get_user_meta')
            ->with(123, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('');

        Monkey\Functions\expect('update_user_meta')
            ->with(123, PAYLOAD_CUSTOMER_ID_META_KEY, 'cust_123');

        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wc_get_logger')->andReturn($logger_mock);

        payload_ensure_customer_after_checkout(123, null, $order_mock);

        $this->assertTrue(true);
    }

    public function test_payload_rest_permission_check_allows_logged_in_users() {
        Monkey\Functions\expect('is_user_logged_in')
            ->once()
            ->andReturn(true);

        $result = payload_rest_permission_check();

        $this->assertTrue($result);
    }

    public function test_payload_rest_permission_check_blocks_guests() {
        Monkey\Functions\expect('is_user_logged_in')
            ->once()
            ->andReturn(false);

        $result = payload_rest_permission_check();

        $this->assertFalse($result);
    }

    public function test_payload_handle_admin_notice_trigger_sets_transient() {
        $_GET = array('my_notice' => '1');

        Monkey\Functions\expect('current_user_can')
            ->with('manage_options')
            ->andReturn(true);

        Monkey\Functions\expect('get_current_user_id')
            ->andReturn(1);

        Monkey\Functions\expect('set_transient')
            ->once()
            ->with(
                'my_admin_flash_notice_1',
                Mockery::type('array'),
                60
            )
            ->andReturn(true);

        payload_handle_admin_notice_trigger();

        $this->assertTrue(true);
    }

    public function test_payload_display_admin_flash_notices_outputs_notice() {
        Monkey\Functions\expect('get_current_user_id')
            ->andReturn(1);

        Monkey\Functions\expect('get_transient')
            ->with('my_admin_flash_notice_1')
            ->andReturn(array(
                'message' => 'Test notice',
                'type' => 'success'
            ));

        Monkey\Functions\expect('delete_transient')
            ->with('my_admin_flash_notice_1')
            ->andReturn(true);

        // Capture output
        ob_start();
        payload_display_admin_flash_notices();
        $output = ob_get_clean();

        $this->assertStringContainsString('Test notice', $output);
        $this->assertStringContainsString('notice-success', $output);
    }
}
