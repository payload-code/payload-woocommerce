<?php

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery as m;

class Test_WC_Payload_Blocks extends TestCase {

    private $blocks;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        
        $this->blocks = new WC_Payload_Blocks();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        Mockery::close();
        parent::tearDown();
    }

    public function test_constructor_sets_correct_name() {
        $reflection = new ReflectionClass($this->blocks);
        $nameProperty = $reflection->getProperty('name');
        $nameProperty->setAccessible(true);
        
        $this->assertEquals('payload', $nameProperty->getValue($this->blocks));
    }

    public function test_initialize_sets_settings_and_gateway() {
        Monkey\Functions\expect('get_option')
            ->once()
            ->with('woocommerce_payload_settings', array())
            ->andReturn(array('api_key' => 'test_key'));
        
        $this->blocks->initialize();
        
        $reflection = new ReflectionClass($this->blocks);
        $settingsProperty = $reflection->getProperty('settings');
        $settingsProperty->setAccessible(true);
        
        $settings = $settingsProperty->getValue($this->blocks);
        $this->assertEquals(array('api_key' => 'test_key'), $settings);
        
        $gatewayProperty = $reflection->getProperty('gateway');
        $gatewayProperty->setAccessible(true);
        
        $gateway = $gatewayProperty->getValue($this->blocks);
        $this->assertInstanceOf(WC_Payload_Gateway::class, $gateway);
    }

    public function test_is_active_returns_gateway_availability() {
        $this->blocks->initialize();
        
        $result = $this->blocks->is_active();
        $this->assertTrue($result);
    }

    public function test_get_payment_method_script_handles_calls_payment_scripts() {
        $this->blocks->initialize();
        
        $handles = $this->blocks->get_payment_method_script_handles();
        
        $this->assertEquals(array('payload-blocks-integration'), $handles);
    }

    public function test_get_payment_method_data_returns_gateway_data() {
        $this->blocks->initialize();
        
        $data = $this->blocks->get_payment_method_data();
        
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('description', $data);
    }

    public function test_blocks_extends_abstract_payment_method_type() {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            $this->assertInstanceOf('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType', $this->blocks);
        } else {
            // If the class doesn't exist, just verify our blocks object exists
            $this->assertNotNull($this->blocks);
            $this->assertTrue(method_exists($this->blocks, 'initialize'));
            $this->assertTrue(method_exists($this->blocks, 'is_active'));
            $this->assertTrue(method_exists($this->blocks, 'get_payment_method_script_handles'));
            $this->assertTrue(method_exists($this->blocks, 'get_payment_method_data'));
        }
    }
}