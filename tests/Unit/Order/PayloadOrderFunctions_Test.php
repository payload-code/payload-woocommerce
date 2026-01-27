<?php
/**
 * Unit tests for Payload Order Functions
 *
 * Tests functions from includes/payload-order-functions.php
 *
 * @package Payload_WooCommerce
 */

use PayloadWooCommerce\Tests\Unit\UnitTestCase;
use Brain\Monkey;
use Mockery as m;

class PayloadOrderFunctions_Test extends UnitTestCase {

	public function test_payload_subscription_payment_method_to_display() {
		$subscription_mock = Mockery::mock();
		$subscription_mock->shouldReceive( 'get_parent_id' )->andReturn( 456 );

		$parent_order_mock = Mockery::mock();
		$parent_order_mock->shouldReceive( 'get_payment_method_title' )->andReturn( 'Payload Credit Card' );

		Monkey\Functions\expect( 'wc_get_order' )
			->once()
			->with( 456 )
			->andReturn( $parent_order_mock );

		$result = payload_subscription_payment_method_to_display( 'Original Label', $subscription_mock, 'context' );

		$this->assertEquals( 'Payload Credit Card', $result );
	}

	public function test_payload_autocomplete_virtual_orders_completes_virtual_order() {
		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order_mock->shouldReceive( 'get_payment_method' )->andReturn( 'payload' );
		$order_mock->shouldReceive( 'update_status' )
			->with( 'completed', Mockery::type( 'string' ) );

		$product_mock = Mockery::mock();
		$product_mock->shouldReceive( 'is_virtual' )->andReturn( true );
		$product_mock->shouldReceive( 'is_downloadable' )->andReturn( false );

		$item_mock = Mockery::mock();
		$item_mock->shouldReceive( 'get_product' )->andReturn( $product_mock );

		$order_mock->shouldReceive( 'get_items' )->andReturn( array( $item_mock ) );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order_mock );

		payload_autocomplete_virtual_orders( 123 );

		$this->assertTrue( true );
	}

	public function test_payload_autocomplete_virtual_orders_skips_non_virtual() {
		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_id' )->andReturn( 123 );
		$order_mock->shouldReceive( 'get_status' )->andReturn( 'processing' );
		$order_mock->shouldReceive( 'get_payment_method' )->andReturn( 'payload' );

		$product_mock = Mockery::mock();
		$product_mock->shouldReceive( 'is_virtual' )->andReturn( false );
		$product_mock->shouldReceive( 'is_downloadable' )->andReturn( false );

		$item_mock = Mockery::mock();
		$item_mock->shouldReceive( 'get_product' )->andReturn( $product_mock );

		$order_mock->shouldReceive( 'get_items' )->andReturn( array( $item_mock ) );
		$order_mock->shouldReceive( 'update_status' )->never();

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order_mock );

		payload_autocomplete_virtual_orders( 123 );

		$this->assertTrue( true );
	}

	public function test_payload_order_is_virtual_returns_true_for_all_virtual_products() {
		$product_mock = Mockery::mock();
		$product_mock->shouldReceive( 'is_virtual' )->andReturn( true );

		$item_mock = Mockery::mock();
		$item_mock->shouldReceive( 'get_product' )->andReturn( $product_mock );

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_items' )->andReturn( array( $item_mock ) );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order_mock );

		$result = payload_order_is_virtual( 123 );

		$this->assertTrue( $result );
	}

	public function test_payload_order_is_virtual_returns_false_for_non_virtual_products() {
		$product_mock = Mockery::mock();
		$product_mock->shouldReceive( 'is_virtual' )->andReturn( false );
		$product_mock->shouldReceive( 'is_downloadable' )->andReturn( false );

		$item_mock = Mockery::mock();
		$item_mock->shouldReceive( 'get_product' )->andReturn( $product_mock );

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_items' )->andReturn( array( $item_mock ) );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order_mock );

		$result = payload_order_is_virtual( 123 );

		$this->assertFalse( $result );
	}

	public function test_payload_order_is_virtual_handles_null_product() {
		$item_mock = Mockery::mock();
		$item_mock->shouldReceive( 'get_product' )->andReturn( null );

		$order_mock = Mockery::mock( 'WC_Order' );
		$order_mock->shouldReceive( 'get_items' )->andReturn( array( $item_mock ) );

		Monkey\Functions\expect( 'wc_get_order' )
			->with( 123 )
			->andReturn( $order_mock );

		$result = payload_order_is_virtual( 123 );

		$this->assertFalse( $result );
	}
}
