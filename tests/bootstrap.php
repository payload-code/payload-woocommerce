<?php

// Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Load test base classes
require_once __DIR__ . '/Unit/UnitTestCase.php';
require_once __DIR__ . '/Integration/IntegrationTestCase.php';
require_once __DIR__ . '/Integration/Helpers/CurlMocker.php';

// Detect which test suite is running
$argv              = $_SERVER['argv'] ?? array();
$isUnitTest        = false;
$isIntegrationTest = false;

foreach ( $argv as $i => $arg ) {
	// Check for --testsuite argument (could be --testsuite=value or --testsuite value)
	if ( strpos( $arg, '--testsuite' ) !== false ) {
		// Format: --testsuite=value
		if ( stripos( $arg, 'Unit' ) !== false ) {
			$isUnitTest = true;
		}
		if ( stripos( $arg, 'Integration' ) !== false ) {
			$isIntegrationTest = true;
		}
	} elseif ( $i > 0 && $argv[ $i - 1 ] === '--testsuite' ) {
		// Format: --testsuite value (two separate args)
		if ( stripos( $arg, 'Unit' ) !== false ) {
			$isUnitTest = true;
		}
		if ( stripos( $arg, 'Integration' ) !== false ) {
			$isIntegrationTest = true;
		}
	}
	// Check for direct path arguments
	if ( strpos( $arg, 'tests/Unit' ) !== false ) {
		$isUnitTest = true;
	}
	if ( strpos( $arg, 'tests/Integration' ) !== false ) {
		$isIntegrationTest = true;
	}
}

// Error if no specific suite specified or both are specified
if ( ( ! $isUnitTest && ! $isIntegrationTest ) || ( $isUnitTest && $isIntegrationTest ) ) {
	fwrite( STDERR, "\n" );
	fwrite( STDERR, "ERROR: You must specify exactly one test suite.\n" );
	fwrite( STDERR, "\n" );
	fwrite( STDERR, "Usage:\n" );
	fwrite( STDERR, "  vendor/bin/phpunit --testsuite \"Unit Tests\"\n" );
	fwrite( STDERR, "  vendor/bin/phpunit --testsuite \"Integration Tests\"\n" );
	fwrite( STDERR, "\n" );
	fwrite( STDERR, "Reason: Unit tests use mocked Payload classes, while integration tests use real Payload SDK.\n" );
	fwrite( STDERR, "        Running both together would cause conflicts.\n" );
	fwrite( STDERR, "\n" );
	exit( 1 );
}

// Only load Payload mocks for unit tests
if ( $isUnitTest ) {
	require_once __DIR__ . '/mocks/payload-mocks.php';
}

// Integration tests will use real Payload SDK (no mocks loaded)

// Brain Monkey for WordPress function mocking
\Brain\Monkey\setUp();

// Load WordPress test environment if available
if ( getenv( 'WP_TESTS_DIR' ) ) {
	require_once getenv( 'WP_TESTS_DIR' ) . '/includes/functions.php';
	require_once getenv( 'WP_TESTS_DIR' ) . '/includes/bootstrap.php';
} else {
	// Mock WordPress functions when WP test environment isn't available
	require_once __DIR__ . '/mocks/wordpress-mocks.php';
}
// 2. Load our stubs so classes/methods exist before plugin code runs
require_once __DIR__ . '/stubs/wc-and-payload-stubs.php';


// Mock WooCommerce classes
require_once __DIR__ . '/mocks/woocommerce-mocks.php';

// Load plugin files
require_once dirname( __DIR__ ) . '/payload-woocommerce.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-payload-gateway.php';
require_once dirname( __DIR__ ) . '/includes/class-wc-payload-blocks.php';

// Load function files (normally loaded via plugins_loaded hook)
require_once dirname( __DIR__ ) . '/includes/payload-api-functions.php';
require_once dirname( __DIR__ ) . '/includes/payload-customer-functions.php';
require_once dirname( __DIR__ ) . '/includes/payload-order-functions.php';
require_once dirname( __DIR__ ) . '/includes/payload-utility-functions.php';
