<?php
/**
 * Unit tests for Payload Utility Functions
 *
 * Tests functions from includes/payload-utility-functions.php
 *
 * @package Payload_WooCommerce
 */

use PayloadWooCommerce\Tests\Unit\UnitTestCase;
use Brain\Monkey;
use Mockery as m;

class PayloadUtilityFunctions_Test extends UnitTestCase {


	public function test_payload_handle_admin_notice_trigger_sets_transient() {
		$_GET = array( 'my_notice' => '1' );

		Monkey\Functions\expect( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );

		Monkey\Functions\expect( 'get_current_user_id' )
		->andReturn( 1 );

		Monkey\Functions\expect( 'set_transient' )
			->once()
			->with(
				'my_admin_flash_notice_1',
				Mockery::type( 'array' ),
				60
			)
			->andReturn( true );

		payload_handle_admin_notice_trigger();

		$this->assertTrue( true );
	}

	public function test_payload_display_admin_flash_notices_outputs_notice() {
		Monkey\Functions\expect( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );

		Monkey\Functions\expect( 'get_current_user_id' )
		->andReturn( 1 );

		Monkey\Functions\expect( 'get_transient' )
			->with( 'my_admin_flash_notice_1' )
			->andReturn(
				array(
					'message' => 'Test notice',
					'type'    => 'success',
				)
			);

		Monkey\Functions\expect( 'delete_transient' )
			->with( 'my_admin_flash_notice_1' )
			->andReturn( true );

		// Capture output
		ob_start();
		payload_display_admin_flash_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test notice', $output );
		$this->assertStringContainsString( 'notice-success', $output );
	}
}
