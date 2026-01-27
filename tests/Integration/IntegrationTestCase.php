<?php
/**
 * Base test case for integration tests
 *
 * Integration tests use real Payload SDK with HTTP request/response mocking via curl mocks.
 * These tests verify that different components work together correctly.
 *
 * @package Payload_WooCommerce
 */

namespace PayloadWooCommerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery;
use PayloadWooCommerce\Tests\Integration\Helpers\CurlMocker;

abstract class IntegrationTestCase extends TestCase {

	/**
	 * Set up test environment before each test
	 */
	protected function setUp(): void {
		parent::setUp();

		// Set up Brain Monkey for WordPress function mocking
		Monkey\setUp();

		// Set up curl mocking for Payload API HTTP requests
		CurlMocker::setUp();

		// Reset superglobals
		$_POST = array();
		$_GET  = array();
	}

	/**
	 * Clean up test environment after each test
	 */
	protected function tearDown(): void {
		CurlMocker::reset();
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}
}
