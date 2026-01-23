<?php
/**
 * Constants for testing
 *
 * Provides constants that need to exist before loading plugin code
 */

// Define ABSPATH constant if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

// Define Payload customer ID meta key constant
if (!defined('PAYLOAD_CUSTOMER_ID_META_KEY')) {
    define('PAYLOAD_CUSTOMER_ID_META_KEY', 'payload_customer_id');
}
