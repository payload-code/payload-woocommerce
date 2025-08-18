<?php

namespace Automattic\WooCommerce\Blocks\Payments\Integrations {
    class AbstractPaymentMethodType {
        protected $name;
        protected $settings;
        
        public function initialize() {}
        public function is_active() { return true; }
        public function get_payment_method_script_handles() { return array(); }
        public function get_payment_method_data() { return array(); }
    }
}