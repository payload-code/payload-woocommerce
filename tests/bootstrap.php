<?php

// Mock Payload classes FIRST before autoloader
require_once __DIR__ . '/mocks/payload-mocks.php';

// Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Brain Monkey for WordPress function mocking
\Brain\Monkey\setUp();

// Load WordPress test environment if available
if (getenv('WP_TESTS_DIR')) {
    require_once getenv('WP_TESTS_DIR') . '/includes/functions.php';
    require_once getenv('WP_TESTS_DIR') . '/includes/bootstrap.php';
} else {
    // Mock WordPress functions when WP test environment isn't available
    require_once __DIR__ . '/mocks/wordpress-mocks.php';
}
// 2. Load our stubs so classes/methods exist before plugin code runs
require_once __DIR__ . '/stubs/wc-and-payload-stubs.php';


// Mock WooCommerce classes
require_once __DIR__ . '/mocks/woocommerce-mocks.php';

// Load plugin files
require_once dirname(__DIR__) . '/payload-woocommerce.php';
require_once dirname(__DIR__) . '/includes/class-wc-payload-gateway.php';
require_once dirname(__DIR__) . '/includes/class-wc-payload-blocks.php';