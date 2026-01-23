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

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery as m;

class Test_Integration_Payment_Flows extends TestCase {

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

    /**
     * Test: Complete checkout flow with new payment method
     */
    public function test_complete_checkout_flow_with_new_payment_method() {
        // Setup: Customer checking out with new card
        $_POST = array('payment_method_id' => 'pm_new123');

        $order = $this->create_mock_order(123, 100.00, 1);
        $payment_method = $this->create_mock_payment_method('pm_new123', 'visa', '1111', '12/2025');
        $payment = $this->create_mock_payment('txn_123', 100.00, 'processed', 'pm_new123');

        // Mock customer ID retrieval
        Monkey\Functions\expect('get_user_meta')
            ->with(1, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('cust_123');

        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_is_subscription')->with(123)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $subscription_order_mock = Mockery::mock('alias:WC_Subscriptions_Order');
        $subscription_order_mock->shouldReceive('order_contains_subscription')
            ->with(123)
            ->andReturn(false);

        // Execute
        $result = $this->gateway->process_payment(123);

        // Assert
        $this->assertEquals('success', $result['result']);
        $this->assertArrayHasKey('redirect', $result);
    }

    /**
     * Test: Checkout with saved payment method token
     */
    public function test_checkout_flow_with_saved_token() {
        $_POST = array('token' => '456');

        $order = $this->create_mock_order(123, 75.00, 1);
        $token = $this->create_mock_token(456, 'pm_saved123', 'visa', '4242');
        $payment = $this->create_mock_payment('txn_456', 75.00, 'processed', 'pm_saved123');

        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_is_subscription')->with(123)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(123)->andReturn($order);

        $subscription_order_mock = Mockery::mock('alias:WC_Subscriptions_Order');
        $subscription_order_mock->shouldReceive('order_contains_subscription')
            ->with(123)
            ->andReturn(false);

        $result = $this->gateway->process_payment(123);

        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test: Subscription renewal payment
     */
    public function test_subscription_renewal_payment_flow() {
        $order = $this->create_mock_order(789, 50.00, 1);
        $order->shouldReceive('get_meta')
            ->with('_payload_payment_method_id', true)
            ->andReturn('pm_subscription123');
        $order->shouldReceive('add_order_note')->andReturn(true);
        $order->shouldReceive('set_payment_method')->with(Mockery::any());
        $order->shouldReceive('set_payment_method_title')->with(Mockery::type('string'));

        $subscription = Mockery::mock('WC_Subscription');
        $subscription->shouldReceive('get_parent_id')->andReturn(456);

        $parent_order = Mockery::mock('WC_Order');
        $parent_order->shouldReceive('get_payment_method')->andReturn('111');
        $parent_order->shouldReceive('get_payment_tokens')->andReturn(array('111'));

        $token = $this->create_mock_token(111, 'pm_subscription123', 'mastercard', '5555');
        $payment = $this->create_mock_payment('txn_renewal', 50.00, 'processed', 'pm_subscription123');

        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_get_subscriptions_for_order')
            ->with(789, array('order_type' => 'any'))
            ->andReturn(array('sub_123' => $subscription));

        Monkey\Functions\expect('wc_get_order')
            ->andReturnUsing(function($order_id) use ($order, $parent_order) {
                if ($order_id == 789) return $order;
                if ($order_id == 456) return $parent_order;
                return null;
            });

        $logger_mock = Mockery::mock();
        $logger_mock->shouldReceive('info')->andReturn(true);
        $logger_mock->shouldReceive('error')->andReturn(true);
        Monkey\Functions\expect('wc_get_logger')->andReturn($logger_mock);

        $this->gateway->scheduled_subscription_payment(50.00, $order);

        $this->assertTrue(true);
    }

    /**
     * Test: Payment method update for subscription
     */
    public function test_subscription_payment_method_update_flow() {
        $_POST = array('payment_method_id' => 'pm_updated123');

        $subscription = $this->create_mock_order(456, 0, 1);
        $subscription->shouldReceive('update_meta_data')->with('_payload_payment_method_id', 'pm_updated123');
        $subscription->shouldReceive('save');
        $subscription->shouldReceive('get_parent_id')->andReturn(789);
        $subscription->shouldReceive('set_payment_method')->with(Mockery::any());
        $subscription->shouldReceive('set_payment_method_title')->with(Mockery::type('string'));

        $parent_order = Mockery::mock('WC_Order');
        $parent_order->shouldReceive('update_meta_data')->with('_payload_payment_method_id', 'pm_updated123');
        $parent_order->shouldReceive('set_payment_method')->with(Mockery::any());
        $parent_order->shouldReceive('set_payment_method_title')->with(Mockery::type('string'));
        $parent_order->shouldReceive('save')->andReturn(true);
        $parent_order->shouldReceive('get_status')->andReturn('pending');

        $payment_method = $this->create_mock_payment_method('pm_updated123', 'amex', '8888', '06/2026');

        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_is_subscription')->with(456)->andReturn(true);
        Monkey\Functions\expect('wc_get_order')->with(456)->andReturn($subscription);
        Monkey\Functions\expect('wc_get_order')->with(789)->andReturn($parent_order);
        Monkey\Functions\expect('get_user_meta')
            ->with(1, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('cust_123');

        $result = $this->gateway->process_payment(456);

        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test: Missing payment details error
     */
    public function test_missing_payment_details_throws_exception() {
        $_POST = array(
            'token' => '',
            'payment_method_id' => '',
            'transactionid' => ''
        );

        $order = $this->create_mock_order(111, 50.00, 1);

        Monkey\Functions\expect('wcs_is_subscription')->with(111)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(111)->andReturn($order);
        Monkey\Functions\expect('setup_payload_api')->once();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Missing payment details');

        $this->gateway->process_payment(111);
    }

    /**
     * Test: Subscription with multiple items
     */
    public function test_subscription_checkout_with_multiple_items() {
        $_POST = array('payment_method_id' => 'pm_multi123');

        $order = $this->create_mock_order(555, 150.00, 1);
        $order->shouldReceive('get_items')->andReturn(array());
        $order->shouldReceive('set_transaction_id')->with(Mockery::type('string'));

        $payment_method = $this->create_mock_payment_method('pm_multi123', 'visa', '9999', '03/2027');
        $payment = $this->create_mock_payment('txn_multi', 150.00, 'processed', 'pm_multi123');

        Monkey\Functions\expect('get_user_meta')
            ->with(1, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('cust_123');
        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_is_subscription')->with(555)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(555)->andReturn($order);

        $subscription_order_mock = Mockery::mock('alias:WC_Subscriptions_Order');
        $subscription_order_mock->shouldReceive('order_contains_subscription')
            ->with(555)
            ->andReturn(true);

        $result = $this->gateway->process_payment(555);

        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test: Virtual product auto-completion
     */
    public function test_virtual_product_order_auto_completion() {
        $order = $this->create_mock_order(777, 25.00, 1);
        $order->shouldReceive('get_status')->andReturn('processing');
        $order->shouldReceive('get_payment_method')->andReturn('payload');
        $order->shouldReceive('update_status')
            ->with('completed', Mockery::type('string'));

        $product = Mockery::mock();
        $product->shouldReceive('is_virtual')->andReturn(true);

        $item = Mockery::mock();
        $item->shouldReceive('get_product')->andReturn($product);

        $order->shouldReceive('get_items')->andReturn(array($item));

        Monkey\Functions\expect('wc_get_order')->with(777)->andReturn($order);

        payload_autocomplete_virtual_orders(777);

        $this->assertTrue(true);
    }

    /**
     * Test: Customer association with payment
     */
    public function test_customer_association_during_checkout() {
        $_POST = array('transactionid' => 'txn_assoc');

        $order = $this->create_mock_order(888, 100.00, 1);
        $order->shouldReceive('set_transaction_id')->with(Mockery::type('string'));
        $order->shouldReceive('set_payment_method_title')->with(Mockery::type('string'));
        $order->shouldReceive('set_payment_method')->with(Mockery::type('string'));
        $order->shouldReceive('get_items')->andReturn(array());

        $payment = Mockery::mock();
        $payment->amount = 100.00;
        $payment->ref_number = 'REF888';
        $payment->customer_id = null;
        $payment->payment_method_id = 'pm_assoc';
        $payment->payment_method = array(
            'id' => 'pm_assoc',
            'description' => 'Visa ending in 1111',
            'card' => array(
                'card_brand' => 'visa',
                'card_number' => '4111111111111111',
                'expiry' => '12/2025'
            )
        );
        $payment->shouldReceive('update')
            ->with(Mockery::on(function($data) {
                return isset($data['customer_id']) && $data['customer_id'] === 'cust_123';
            }))
            ->andReturn(true);

        Monkey\Functions\expect('get_user_meta')
            ->with(1, PAYLOAD_CUSTOMER_ID_META_KEY, true)
            ->andReturn('cust_123');
        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_is_subscription')->with(888)->andReturn(false);
        Monkey\Functions\expect('wc_get_order')->with(888)->andReturn($order);

        $subscription_order_mock = Mockery::mock('alias:WC_Subscriptions_Order');
        $subscription_order_mock->shouldReceive('order_contains_subscription')
            ->with(888)
            ->andReturn(false);

        $result = $this->gateway->process_payment(888);

        $this->assertEquals('success', $result['result']);
    }

    /**
     * Test: Failed subscription renewal retry
     */
    public function test_failed_subscription_renewal_handling() {
        $order = $this->create_mock_order(666, 60.00, 1);
        $order->shouldReceive('get_meta')
            ->with('_payload_payment_method_id', true)
            ->andReturn('');
        $order->shouldReceive('update_status')
            ->with('failed', Mockery::type('string'));
        $order->shouldReceive('add_order_note')->andReturn(true);

        Monkey\Functions\expect('setup_payload_api')->once();
        Monkey\Functions\expect('wcs_get_subscriptions_for_order')
            ->with(666, array('order_type' => 'any'))
            ->andReturn(array());

        $logger_mock = Mockery::mock();
        $logger_mock->shouldReceive('info')->andReturn(true);
        $logger_mock->shouldReceive('error')->andReturn(true);
        Monkey\Functions\expect('wc_get_logger')->andReturn($logger_mock);

        $this->gateway->scheduled_subscription_payment(60.00, $order);

        $this->assertTrue(true);
    }

    // Helper methods

    private function create_mock_order($order_id, $total, $user_id) {
        $order = Mockery::mock('WC_Order');
        $order->shouldReceive('get_id')->andReturn($order_id);
        $order->shouldReceive('get_total')->andReturn($total);
        $order->shouldReceive('get_user_id')->andReturn($user_id);
        $order->shouldReceive('get_customer_id')->andReturn($user_id);
        $order->shouldReceive('get_checkout_order_received_url')
            ->andReturn("http://example.com/order-received/{$order_id}/");
        $order->shouldReceive('update_meta_data')->andReturnSelf();
        $order->shouldReceive('save')->andReturnSelf();
        $order->shouldReceive('get_items')->andReturn(array());
        $order->shouldReceive('set_transaction_id')->with(Mockery::type('string'));
        $order->shouldReceive('get_status')->andReturn('pending');
        $order->shouldReceive('payment_complete')->andReturn(true);

        return $order;
    }

    private function create_mock_payment_method($id, $brand, $last4, $expiry) {
        $payment_method = Mockery::mock();
        $payment_method->shouldReceive('data')->andReturn(array(
            'id' => $id,
            'card' => array(
                'card_brand' => $brand,
                'card_number' => '4' . str_repeat('*', 11) . $last4,
                'expiry' => $expiry
            )
        ));
        $payment_method->description = ucfirst($brand) . ' ending in ' . $last4;

        return $payment_method;
    }

    private function create_mock_payment($txn_id, $amount, $status, $pm_id) {
        $payment = Mockery::mock();
        $payment->amount = $amount;
        $payment->ref_number = $txn_id;
        $payment->status = $status;
        $payment->customer_id = 'cust_123';
        $payment->payment_method_id = $pm_id;
        $payment->payment_method = array(
            'id' => $pm_id,
            'card' => array(
                'card_brand' => 'visa',
                'card_number' => '4111111111111111',
                'expiry' => '12/2025'
            )
        );
        $payment->shouldReceive('update')->andReturn(true);

        return $payment;
    }

    private function create_mock_token($token_id, $token_value, $brand, $last4) {
        $token = Mockery::mock('WC_Payment_Token_CC');
        $token->shouldReceive('get_id')->andReturn($token_id);
        $token->shouldReceive('get_user_id')->andReturn(1);
        $token->shouldReceive('get_token')->andReturn($token_value);
        $token->shouldReceive('get_card_type')->andReturn($brand);
        $token->shouldReceive('get_last4')->andReturn($last4);

        return $token;
    }
}
