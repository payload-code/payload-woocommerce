<?php

class WC_Payment_Gateway {
    public $id;
    public $method_title;
    public $method_description;
    public $has_fields;
    public $title;
    public $supports = array();
    public $form_fields = array();
    public $settings = array();
    
    public function __construct() {
        $this->method_description = 'Test payment gateway description';
        $this->title = 'Test Gateway';
    }

    public function init_form_fields() {}
    public function init_settings() {}
    public function process_admin_options() {}
    public function is_available() { return true; }
    public function get_return_url($order) { return 'http://example.com/thank-you/'; }
}

class WC_Payment_Token_CC {
    private $data = array();
    public function set_data($data) { $this->data = $data; }
    public function set_token($token) { $this->data['token'] = $token; }
    public function set_gateway_id($id) { $this->data['gateway_id'] = $id; }
    public function set_card_type($type) { $this->data['card_type'] = $type; }
    public function set_last4($last4) { $this->data['last4'] = $last4; }
    public function set_expiry_month($month) { $this->data['expiry_month'] = $month; }
    public function set_expiry_year($year) { $this->data['expiry_year'] = $year; }
    public function set_user_id($user_id) { $this->data['user_id'] = $user_id; }
    
    public function get_token() { return $this->data['token'] ?? ''; }
    public function get_id() { return $this->data['id'] ?? 1; }
    public function get_user_id() { return $this->data['user_id'] ?? 0; }
    public function get_last4() { return $this->data['last4'] ?? ''; }
    public function get_expiry_month() { return $this->data['expiry_month'] ?? ''; }
    public function get_expiry_year() { return $this->data['expiry_year'] ?? ''; }
    
    public function save() { $this->data['id'] = 1; return true; }
}

class WC_Payment_Tokens {
    public static function get($token_id) {
        $token = new WC_Payment_Token_CC();
        $token->set_token('pm_test_token');
        return $token;
    }
    public static function get_customer_tokens($user_id, $gateway_id) {
        $token = new WC_Payment_Token_CC();
        $token->set_token('pm_test_token');
        return array($token);
    }
}

if (!function_exists('wc_get_order')) {
    function wc_get_order($order_id) {
        return new WC_Order($order_id);
    }
}

class WC_Order {
    private $id;
    private $data = array();
    
    public function __construct($id = 1) {
        $this->id = $id;
        $this->data = array(
            'total' => 100.00,
            'status' => 'pending',
            'transaction_id' => '',
            'payment_method' => 'payload',
            'payment_method_title' => 'Payload'
        );
    }
    
    public function get_id() { return $this->id; }
    public function get_total() { return $this->data['total']; }
    public function get_status() { return $this->data['status']; }
    public function get_parent_id() { return 0; }
    public function get_payment_method() { return $this->data['payment_method']; }
    public function get_payment_method_title() { return $this->data['payment_method_title']; }
    public function get_payment_tokens() { return array(1); }
    public function get_items() { return array(); }
    
    public function set_transaction_id($id) { $this->data['transaction_id'] = $id; }
    public function set_payment_method($method) { $this->data['payment_method'] = $method; }
    public function set_payment_method_title($title) { $this->data['payment_method_title'] = $title; }
    
    public function payment_complete() { $this->data['status'] = 'completed'; }
    public function save() { return true; }
    public function add_payment_token($token) { return true; }
}

if (!function_exists('wcs_is_subscription')) {
    function wcs_is_subscription($order_id) {
        return false;
    }
}

if (!function_exists('wcs_order_contains_subscription')) {
    function wcs_order_contains_subscription($order_id) {
        return false;
    }
}

if (!function_exists('wcs_get_subscriptions_for_order')) {
    function wcs_get_subscriptions_for_order($order_id, $args = array()) {
        return array();
    }
}

if (!function_exists('wcs_get_users_subscriptions')) {
    function wcs_get_users_subscriptions($user_id) {
        return array();
    }
}


if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
    require_once __DIR__ . '/woocommerce-blocks-mock.php';
}
