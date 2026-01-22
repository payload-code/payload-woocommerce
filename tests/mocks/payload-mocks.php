<?php

namespace Payload {
    class PaymentMethod {
        public function __construct($data = array()) {
            // Mock constructor
        }
        
        public function update($data = array()) {
            return true;
        }
        
        public static function get($id) {
            $mock = new self();
            $mock->id = $id;
            return $mock;
        }
        
        public function data() {
            return array(
                'id' => $this->id ?? 'pm_test',
                'card' => array(
                    'card_brand' => 'visa',
                    'card_number' => '4111111111111111',
                    'expiry' => '12/2025'
                )
            );
        }
    }
    
    class Transaction {
        public $amount;
        public $ref_number;
        public $customer_id;
        public $payment_method_id;
        public $payment_method;
        public $status; 
      
        
        public static function get($id) {
            $mock = new self();
            $mock->amount = 100.00;
            $mock->ref_number = 'REF123';
            $mock->customer_id = 'cust_existing';
            $mock->payment_method_id = 'pm_123';
            $mock->payment_method = array(
                'id' => 'pm_123',
                'card' => array(
                    'card_brand' => 'visa',
                    'card_number' => '4111111111111111',
                    'expiry' => '12/2025'
                )
            );
            return $mock;
        }
        
        public static function create($data = array()) {
            $mock = new self();
            $mock->ref_number = 'REF456';
            return $mock;
        }
        
        public function update($data = array()) {
            return true;
        }
    }
    
    class Customer {
        public $id;
        public $customer;
         public static $shouldFindExisting = true;

        public static function create($data = array()) {
            $mock = new self();
            $mock->id = 'cust_123';
            return $mock;
        }
        public static function filter_by($data = array()) {
            $mock = new self();
            if (!self::$shouldFindExisting) {
                $mock->id = null;
                return $mock;
            }

            $mock->id = 'cust_123';
            return $mock;
        }
       public function first(){
         if ($this->id === null) {
            return null;
        }

        return (object) array( 'id' => $this->id );
        
       }
          public function all(){
         if ($this->id === null) {
            return array();
        }

        return array( (object) array( 'id' => $this->id ) );
        
       }
 
    }
    
    class ClientToken {
        public $id;
        
        public static function create($data = array()) {
            $mock = new self();
            $mock->id = 'ct_123';
            return $mock;
        }
    }
}
