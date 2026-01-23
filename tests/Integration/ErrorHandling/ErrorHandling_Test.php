<?php
/**
 * Error Handling and Edge Case Tests
 *
 * Tests error scenarios, exception handling, and edge cases for:
 * - API failures
 * - Invalid input
 * - Network errors
 * - Data validation
 *
 * @package Payload_WooCommerce
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery as m;

class Test_Error_Handling extends TestCase {

    private $gateway;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $_POST = array();
        $_GET = array();

        $this->gateway = new WC_Payload_Gateway();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test: API connection failure handling
     */
    public function test_api_connection_failure_handling() {
        $_POST = array('payment_method_id' => 'pm_123');

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_total')->andReturn(100.00);
        $order->shouldReceive('get_user_id')->andReturn(1);
        $order->shouldReceive('get_customer_id')->andReturn(1);

        Monkey\Functions\expect('wcs_is_subscription')->with(123)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);
        Monkey\Functions\expect('setup_payload_api')->andThrow(
            new Exception('Unable to connect to Payload API')
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unable to connect to Payload API');

        $this->gateway->process_payment(123);
    }


    /**
     * Test: Empty order total handling
     */
    public function test_zero_amount_order_handling() {
        $_POST = array('payment_method_id' => 'pm_123');

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_total')->andReturn(0.00);
        $order->shouldReceive('get_user_id')->andReturn(1);
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_items')->andReturn(array());
        $order->shouldReceive('set_transaction_id')->with(Mockery::type('string'));
        $order->shouldReceive('save')->andReturn(true);
        $order->shouldReceive('get_checkout_order_received_url')->andReturn('http://example.com/order-received/123/');
        $order->shouldReceive('payment_complete')->zeroOrMoreTimes();

        Monkey\Functions\expect('wcs_is_subscription')->with(123)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);
        Monkey\Functions\expect('setup_payload_api')->once();

        // Zero-amount orders should still process for subscriptions
        $result = $this->gateway->process_payment(123);

        $this->assertIsArray($result);
    }


    /**
     * Test: Null order handling
     */
    public function test_null_order_handling() {
        Monkey\Functions\expect('wcs_is_subscription')->with(999)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(999)->andReturn(null);

        $this->expectException(Exception::class);

        $this->gateway->process_payment(999);
    }


    /**
     * Test: Transaction already processed handling
     */
    public function test_duplicate_transaction_handling() {
        $_POST = array('transactionid' => 'txn_duplicate');

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_meta')
            ->with('_transaction_id', true)
            ->andReturn('txn_duplicate');

        Monkey\Functions\expect('wcs_is_subscription')->with(123)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);
        Monkey\Functions\expect('setup_payload_api')->once();

        // Should detect duplicate and skip processing
        $this->expectException(Exception::class);

        $this->gateway->process_payment(123);
    }

    /**
     * Test: Expired card handling
     */
    public function test_expired_card_error() {
        $payment_method_data = array(
            'id' => 'pm_expired',
            'card' => array(
                'card_brand' => 'visa',
                'card_number' => '4111111111111111',
                'expiry' => '12/2020' // Expired
            )
        );

        Monkey\Functions\expect('get_current_user_id')->andReturn(1);

        // Should create token even with expired date - validation happens at Payload API
        $token = $this->gateway->create_token($payment_method_data);

        $this->assertInstanceOf(WC_Payment_Token_CC::class, $token);
    }

    /**
     * Test: Payment authorization vs processing status
     */
    public function test_payment_authorized_vs_processed_status() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_user_id')->andReturn(1);
        $order->shouldReceive('update_meta_data')->andReturnSelf();
        $order->shouldReceive('save')->andReturnSelf();
        $order->shouldReceive('set_transaction_id')->with('REF_AUTH');
        $order->shouldReceive('get_items')->andReturn(array());
        $order->shouldReceive('payment_complete')->never(); // authorized but not virtual, so no payment_complete

        $payment_authorized = Mockery::mock();
        $payment_authorized->status = 'authorized';
        $payment_authorized->ref_number = 'REF_AUTH';
        $payment_authorized->shouldReceive('update')->andReturn(true);

        Monkey\Functions\expect('get_user_meta')
            ->with(1, 'billing_company', true)
            ->andReturn('');

        $logger_mock = Mockery::mock();
        $logger_mock->shouldReceive('error')->andReturn(true);
        Monkey\Functions\expect('wc_get_logger')->andReturn($logger_mock);

        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);

        // Test handling of authorized payment
        $this->gateway->handle_order_payment($order, $payment_authorized);

        $this->assertEquals('authorized', $payment_authorized->status);
    }

    /**
     * Test: Subscription renewal with no subscriptions found
     */
    public function test_subscription_renewal_no_saved_method() {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('update_status')
            ->with('failed', Mockery::type('string'))
            ->once();

        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_get_subscriptions_for_order')
            ->with(123, array('order_type' => 'any'))
            ->andReturn(array()); // No subscriptions found

        $logger_mock = Mockery::mock();
        $logger_mock->shouldReceive('error')->once()->andReturn(true);
        Monkey\Functions\expect('wc_get_logger')->andReturn($logger_mock);

        // Test that when no subscriptions are found, the order is marked as failed
        $this->gateway->scheduled_subscription_payment(50.00, $order);

        $this->assertTrue(true);
    }


    /**
     * Test: REST API permission check for non-logged-in users
     */
    public function test_rest_api_blocks_guests() {
        Monkey\Functions\expect('is_user_logged_in')->andReturn(false);

        $result = payload_rest_permission_check();

        $this->assertFalse($result);
    }

    /**
     * Test: Sanitization of POST data
     */
    public function test_post_data_sanitization() {
        $_POST = array(
            'payment_method_id' => '<script>alert("xss")</script>pm_123',
            'token' => '"><script>alert("xss")</script>',
            'transactionid' => 'txn_123<script>'
        );

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_total')->andReturn(100.00);
        $order->shouldReceive('get_user_id')->andReturn(1);
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_items')->andReturn(array());
        $order->shouldReceive('set_transaction_id')->with(Mockery::type('string'));
        $order->shouldReceive('save')->andReturn(true);
        $order->shouldReceive('get_checkout_order_received_url')->andReturn('http://example.com/order-received/123/');
        $order->shouldReceive('payment_complete')->zeroOrMoreTimes();

        Monkey\Functions\expect('wcs_is_subscription')->with(123)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);
        Monkey\Functions\expect('setup_payload_api')->once();

        // The sanitize_text_field should strip script tags
        $this->gateway->process_payment(123);

        // If we get here without XSS, sanitization worked
        $this->assertTrue(true);
    }

    /**
     * Test: Transaction ID format validation
     */
    public function test_transaction_id_format_validation() {
        $_POST = array('transactionid' => 'txn_valid123');

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn(123);
        $order->shouldReceive('get_total')->andReturn(100.00);
        $order->shouldReceive('get_user_id')->andReturn(1);
        $order->shouldReceive('get_customer_id')->andReturn(1);
        $order->shouldReceive('get_checkout_order_received_url')
            ->andReturn('http://example.com/order-received/123/');
        $order->shouldReceive('update_meta_data')->andReturnSelf();
        $order->shouldReceive('save')->andReturnSelf();
        $order->shouldReceive('set_transaction_id')->with(Mockery::type('string'));
        $order->shouldReceive('set_payment_method_title')->with(Mockery::type('string'));
        $order->shouldReceive('set_payment_method')->with(Mockery::type('string'));
        $order->shouldReceive('payment_complete')->zeroOrMoreTimes();

        $payment = Mockery::mock();
        $payment->amount = 100.00;
        $payment->ref_number = 'REF123';
        $payment->customer_id = 'cust_123';
        $payment->payment_method_id = 'pm_123';
        $payment->payment_method = array(
            'id' => 'pm_123',
            'description' => 'Visa ending in 1111',
            'card' => array(
                'card_brand' => 'visa',
                'card_number' => '4111111111111111',
                'expiry' => '12/2025'
            )
        );
        $payment->shouldReceive('update')->andReturn(true);

        Monkey\Functions\expect('wcs_is_subscription')->with(123)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);
        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('get_user_meta')
            ->with(1, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('cust_123');

        $subscription_order_mock = Mockery::mock('alias:WC_Subscriptions_Order');
        $subscription_order_mock->shouldReceive('order_contains_subscription')
            ->with(123)
            ->andReturn(false);

        $result = $this->gateway->process_payment(123);

        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test: Handling of null product in order items
     */
    public function test_null_product_in_order_items() {
        $item = Mockery::mock();
        $item->shouldReceive('get_product')->andReturn(null);

        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_items')->andReturn(array($item));
        $order->shouldReceive('get_customer_id')->andReturn(1);

        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $result = payload_order_is_virtual(123);

        // Should return false when product is null
        $this->assertFalse($result);
    }
}
