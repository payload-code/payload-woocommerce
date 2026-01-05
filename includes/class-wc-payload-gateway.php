<?php

class WC_Payload_Gateway extends WC_Payment_Gateway {

	// Constructor method
	public function __construct() {
		$this->id                 = 'payload';
		$this->method_title       = __( 'Payload', 'payload' );
		$this->method_description = __( 'Accept payments through Payload.com', 'payload' );
		$this->has_fields         = true;
		$this->title              = __( 'Credit card / debit card', 'payload' );
		$this->supports           = array(
			'products',
			'tokenization',
			'add_payment_method',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
		);

		$this->init_form_fields();
		$this->init_settings();


		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'payload' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Payload', 'payload' ),
				'default' => 'yes',
			),
			'api_key' => array(
				'title' => __( 'API Key', 'payload' ),
				'type'  => 'password',
				'label' => __( 'API Key', 'payload' ),
			),
		);
	}

	public function payment_scripts() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style( 'payload-blocks-css', plugin_dir_url( __FILE__ ) . '../build/style-main.css', array(), '' );

		wp_enqueue_script(
			'payload-blocks-integration',
			plugin_dir_url( __FILE__ ) . '../build/main.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			'',
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'payload-blocks-integration' );
		}
	}

	public function payment_fields() {
		?>
		<div id="payload-add-payment-method"></div>
		<script>if(window.plMountPaymentMethodForm) window.plMountPaymentMethodForm()</script>
		<?php
	}

	// Process the payment
	public function process_payment( $order_id ) {
		payload_card_update_retry_suppressed( true );

		try {
			setup_payload_api();

			$order = wc_get_order( $order_id );

			$user_id_from_order = $this->get_order_customer_id($order);	

			// Update subscription payment method if wc subscription exists
			if (function_exists('wcs_is_subscription') && wcs_is_subscription( $order_id ) ) {

				if ( ! $_POST['payment_method_id'] ) {
					throw new Exception( 'Missing payment method details' );
				}
				

				$payment_method = Payload\PaymentMethod::get( $_POST['payment_method_id'] );
				$token = $this->create_token( $payment_method->data() , $user_id_from_order  );

				$parent_order = wc_get_order( $order->get_parent_id() );

				$this->update_subscription_order_payment_method( $parent_order, $token, $payment_method );
			
				if ( $_POST['update_all_subscriptions_payment_method'] == '1' ) {
					// Update all subscriptions to the new payment method if selected
					$subscriptions = wcs_get_users_subscriptions( $user_id_from_order );
					foreach ( $subscriptions as $subscription ) {
						$subscription_parent_order = wc_get_order( $subscription->get_parent_id() );
						$this->update_subscription_order_payment_method( $subscription_parent_order, $token, $payment_method );
					}
				}

				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			// Process payment using token
			if ( $_POST['token'] || $_POST['payment_method_id'] ) {
				if ( $_POST['token'] ) {
					$token = WC_Payment_Tokens::get( $_POST['token'] );
				} else {
					$payment_method = Payload\PaymentMethod::get( $_POST['payment_method_id'] );
					$token          = $this->create_token( $payment_method->data()  , $user_id_from_order);

					// Create and set token if subscription
					if ( wcs_order_contains_subscription( $order_id ) ) {
						$this->update_subscription_order_payment_method( $order, $token, $payment_method );
					}
				}

				$this->maybe_retry_on_hold_order( $order, $token );

				try {
					$payment = $this->create_payment_for_order( $order, $order->get_total(), $token->get_token() );
				} catch ( TransactionDeclined $e ) {
					wc_add_notice( __( 'Payment error:', 'woothemes' ) . $e->error_description, 'error' );
					return array(
						'result' => 'failure',
					);
				}
			}

			// Confirm payment processed on client side
			else {

				if ( ! $_POST['transactionid'] ) {
					throw new Exception( 'Missing payment details' );
				}

				$payment = Payload\Transaction::get( $_POST['transactionid'] );

				$amt = $order->get_total();

				if ( $amt != $payment->amount ) {
					throw new Exception( 'Mismatched Amount' );
				}

				
				

				if ( ! $payment->customer_id ) {
					$payload_customer_id = get_payload_customer_id();
					if ( $payload_customer_id ) {
						
						$payment->update( array( 'customer_id' => $payload_customer_id ) );
					
						$payment_method = Payload\PaymentMethod::get( $payment->payment_method_id );
						$payment_method->update( array( 'account_id' => $payload_customer_id ) );
					}
				}

				$order->set_transaction_id( $payment->ref_number );

				// Create and set token if subscription
				if ( function_exists("WC_Subscriptions_Order") && WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {
					$token = $this->create_token( $payment->payment_method , $this->set_customer_id_by_order($order)  );
					$order->add_payment_token( $token );
				}
			}

		
				$payment = $this->handle_order_payment( $order, $payment );


			// Redirect to the thank you page
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} finally {
			payload_card_update_retry_suppressed( false );
		}
	}

	public function add_payment_method() {
		setup_payload_api();

		if ( ! $_POST['payment_method_id'] ) {
			throw new Exception( 'Missing payment method details' );
		}

		$payment_method = Payload\PaymentMethod::get( $_POST['payment_method_id'] );

		$token = $this->create_token( $payment_method->data() );

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}

	public function update_subscription_order_payment_method( $order, $token, $payment_method ) {
		$order->set_payment_method( $token->get_id() );
		$order->set_payment_method_title( $payment_method->description );
		$order->save();
		$this->maybe_retry_on_hold_order( $order, $token );
	}

	public function scheduled_subscription_payment( $amount, $renewal_order, $retry = true, $previous_error = false ) {
		setup_payload_api();

		$status = $renewal_order->get_status();

		if ( $status != 'pending' ) {
			return;
		}

		$subscriptions     = wcs_get_subscriptions_for_order( $renewal_order->get_id(), array( 'order_type' => 'any' ) );
		$payment_method_id = null;
		foreach ( $subscriptions as $subscription_id => $subscription ) {
			$parent_order = wc_get_order( $subscription->get_parent_id() );
			$token_id     = $parent_order->get_payment_method();

			$token = WC_Payment_Tokens::get( $token_id );

            if ( is_null( $token ) ) {
                // Legacy support from version <=1.0.0
                $log = wc_get_logger();
                $tokens = $parent_order->get_payment_tokens();

                if ( count( $tokens ) == 0 ) {
                    $log->error( 'No available payment method for order ' . $parent_order->get_id(), array( 'source' => 'payload' ) );
                    
                    $note = __( 'Automatic subscription payment failed: No payment method on file for this account.', 'payload' );
                    $renewal_order->add_order_note( $note );
                    $renewal_order->save();
                    set_transient('my_admin_flash_notice_' . get_current_user_id(), [
  'message' => 'Automatic subscription payment failed: No payment method on file for this account.',
  'type' => 'error',
], 60);

                    $admin_email = get_option( 'admin_email' );
                    if ( $admin_email ) {
                        wp_mail(
                            $admin_email,
                            sprintf( __( 'Subscription Payment Failed - Order #%s', 'payload' ), $renewal_order->get_id() ),
                            sprintf( __( "Automatic subscription payment could not be processed for order #%s.\n\nReason: No payment method on file.\n\nPlease contact the customer to update their payment information.", 'payload' ), $renewal_order->get_id() )
                        );
                    }
                    
                    return;
                }

                $token = WC_Payment_Tokens::get( $tokens[0] );

                if ( is_null( $token ) ) {
                    $log->error( 'Invalid payment token for order ' . $parent_order->get_id(), array( 'source' => 'payload' ) );
                    
                    $note = __( 'Automatic subscription payment failed: Invalid payment method on file.', 'payload' );
                    $renewal_order->add_order_note( $note );
                    $renewal_order->save();
                    
                    $admin_email = get_option( 'admin_email' );
                    if ( $admin_email ) {
                        wp_mail(
                            $admin_email,
                            sprintf( __( 'Subscription Payment Failed - Order #%s', 'payload' ), $renewal_order->get_id() ),
                            sprintf( __( "Automatic subscription payment could not be processed for order #%s.\n\nReason: Invalid payment method on file.", 'payload' ), $renewal_order->get_id() )
                        );
                    }
                    
                    return;
                }
            }

			$payment_method_id = $token->get_token();
			break;
		}

		$this->create_payment_for_order( $renewal_order, $amount, $payment_method_id );

		$renewal_order->set_payment_method( $token->get_id() );
		$renewal_order->set_payment_method_title( $payment_method->description );

		$renewal_order->save();
	}

	public function create_payment_for_order( $order, $amount, $payment_method_id ) {
		$order_id =  $order->get_id();
		$payment_array = array(
				'description'       =>  " Order Item(s): ".$this->get_order_product_name($order_id),
				'amount'            => $amount,
				'type'              => 'payment',
				'payment_method_id' => $payment_method_id,
				'order_number'      => strval( $order_id),
			);
		$payment = Payload\Transaction::create(
			$payment_array
		);

		$payment = $this->handle_order_payment( $order, $payment );
		return $payment;
	}

	public function handle_order_payment( $order, $payment ) {
    $order->set_transaction_id( $payment->ref_number );
    $order_id =  $order->get_id();
    if($payment->status  == 'authorized' ){
        $payment->update( array('order_number'=>strval( $order_id),  'status' => 'processed', "description"=> " Order Item(s): ".$this->get_order_product_name($order_id) ) );
        $get_user_company = get_user_meta( $order->get_user_id(), 'billing_company', true );
        if(!empty($get_user_company)){
            $payment->update( array('attrs' => array( 'Company Name' => $get_user_company ) ) );
        }
    }
    if ($payment->status  == 'processed' && $this->is_virtual($order_id)){
			$order->payment_complete();
			
    }
	$order->save();

	return $payment;
}

    public function find_user_by_payload_customer_id( $payload_customer_id ) {
        $users = get_users( array(
            'meta_key'   => 'payload_customer_id',
            'meta_value' => $payload_customer_id,
            'number'     => 1,
            'fields'     => 'ID',
        ) );    
        if ( ! empty( $users ) ) {
            return $users[0];
        }

    }

	public function create_token( $payment_method,$set_current_user=null ) {
		$token = new WC_Payment_Token_CC();
		$token->set_token( $payment_method['id'] );
		$token->set_gateway_id( $this->id );
		$token->set_card_type( $payment_method['card']['card_brand'] );
		$token->set_last4( substr( $payment_method['card']['card_number'], -4 ) );
		$token->set_expiry_month( substr( $payment_method['card']['expiry'], 0, 2 ) );
		$token->set_expiry_year( substr( $payment_method['card']['expiry'], -4 ) );
        $set_current_user = $this->find_user_by_payload_customer_id( $set_current_user );   
		if($set_current_user){
			//We create this flag just incase Admin is changing payment method for a user
			$token->set_user_id( $set_current_user );
		}else{
			$token->set_user_id( get_current_user_id() );
		}


		if(!$this->check_if_card_exist( $token )){
			$token->save();
		}else{
			$token = $this->check_if_card_exist( $token );
		}


		$pm = new Payload\PaymentMethod( array( 'id' => $payment_method['id'] ) );
		$pm->update( array( 'attrs' => array( '_wp_token_id' => $token->get_id() ) ) );

		return $token;
	}

	protected function maybe_retry_on_hold_order( $order, $token ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $token instanceof WC_Payment_Token ) {
			return;
		}

		if ( 'on-hold' !== $order->get_status() ) {
			return;
		}

		$current_token_id = $order->get_payment_method();

		if ( strval( $current_token_id ) === strval( $token->get_id() ) ) {
			return;
		}

		$order->set_payment_method( $token->get_id() );

		if ( method_exists( $token, 'get_card_type' ) && method_exists( $token, 'get_last4' ) && $token->get_last4() ) {
			$method_title = sprintf(
				__( '%1$s ending in %2$s', 'payload' ),
				strtoupper( $token->get_card_type() ),
				$token->get_last4()
			);
			$order->set_payment_method_title( $method_title );
		}

		$order->set_status( 'pending' );

		$note = sprintf(
			__( 'Payment method updated to card ending in %s after a previous card issue. Order moved to pending for retry.', 'payload' ),
			$token->get_last4()
		);

		$order->add_order_note( $note );
		$order->save();

		$this->notify_payment_retry_parties( $order, $token, $note );
	}

	protected function notify_payment_retry_parties( $order, $token, $note ) {
		if ( ! $order instanceof WC_Order || ! $token instanceof WC_Payment_Token ) {
			return;
		}

		$order_number = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id();

		$subject = sprintf(
			__( 'Order #%s payment retry notice', 'payload' ),
			$order_number
		);

		$message_body = sprintf(
			__( "Order #%1\$s experienced a payment issue. The payment method has been updated to card ending in %2\$s and the order is now pending for a new attempt.\n\nNote: %3\$s", 'payload' ),
			$order_number,
			$token->get_last4(),
			$note
		);

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			wp_mail( $admin_email, $subject, $message_body );
		}

		$customer_email = $order->get_billing_email();
		if ( $customer_email && is_email( $customer_email ) ) {
			wp_mail( $customer_email, $subject, $message_body );
		}
	}

	public function is_virtual($order_id){
		
		$order = wc_get_order( $order_id );
		if(empty($order)){
			return false;
		}
		$items = $order->get_items();

		foreach ( $items as $item ) {
			if ( !$item->get_product()->is_virtual() ) {
				return false;
			}
		}
		return true;
	}

	public function get_order_product_name($order_id){
		$order = wc_get_order( $order_id );
		if(!empty($order)){
				$items = $order->get_items();
				$product_names = array();

				foreach ( $items as $item ) {
					$product_names[] = $item->get_name();
				}

				return implode( ', ', $product_names );
			}
	return "";
	}



	public function set_customer_id_by_order($order){
			$payload_customer_id = $order->get_meta( 'payload_customer_id', true );

			if ( ! $payload_customer_id ) {
				$parent_token_id = $order->get_payment_method();
				if ( $parent_token_id ) {
					$parent_token = WC_Payment_Tokens::get( $parent_token_id );
					if ( $parent_token ) {
						$parent_pm_id = $parent_token->get_token();
						if ( $parent_pm_id ) {
							try {
								$parent_payment_method = Payload\PaymentMethod::get( $parent_pm_id );
								if ( ! empty( $parent_payment_method->account_id ) ) {
									$payload_customer_id = $parent_payment_method->account_id;
								}else{
                                    $payload_customer_id = get_payload_customer_id();
                                }
							} catch ( Exception $e ) {
								// ignore if Payload lookup fails
							}
						}
					}
				}
			}

			if ( $payload_customer_id ) {
				$order->update_meta_data( 'payload_customer_id', $payload_customer_id );
				$order->save();
				}
			
			return $payload_customer_id ;
	}

	    protected function get_order_customer_id( $order ) {
        if ( is_callable( array( $order, 'get_customer_id' ) ) ) {
            // Newer WooCommerce (3+)
            return (int) $order->get_customer_id();
        }

        if ( is_callable( array( $order, 'get_user_id' ) ) ) {
            // Older WooCommerce
            return (int) $order->get_user_id();
        }

        // Fallback for really old style or weird mocks
        if ( isset( $order->customer_user ) ) {
            return (int) $order->customer_user;
        }

        return 0;
    }

	public function check_if_card_exist( $token ) {
			//Check if card exist
		$chk_tokens = WC_Payment_Tokens::get_customer_tokens( $token->get_user_id(), $this->id );
		foreach ( $chk_tokens as $chk_token ) {
			if ( $chk_token->get_last4() == $token->get_last4() &&
				 $chk_token->get_expiry_month() == $token->get_expiry_month() &&
				 $chk_token->get_expiry_year() == $token->get_expiry_year() ) {
				return $chk_token;	
			}
		}
		return false;
	}

}
