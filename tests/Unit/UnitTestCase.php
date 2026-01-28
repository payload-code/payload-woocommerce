<?php
/**
 * Base test case for unit tests
 *
 * Unit tests use mocked Payload classes and isolated WordPress functions.
 *
 * @package Payload_WooCommerce
 */

namespace PayloadWooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery;

abstract class UnitTestCase extends TestCase {


	/**
	 * Set up test environment before each test
	 */
	protected function setUp(): void {
		parent::setUp();

		// Set up Brain Monkey for WordPress function mocking
		Monkey\setUp();

		// Reset superglobals
		$_POST = array();
		$_GET  = array();
	}

	/**
	 * Clean up test environment after each test
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}
}
