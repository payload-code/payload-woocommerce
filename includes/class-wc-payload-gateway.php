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

	public function payment_fields() {
		?>
		<div id="payload-add-payment-method"></div>
		<?php
	}

	// Process the payment
	public function process_payment( $order_id ) {
		setup_payload_api();

		$order = wc_get_order( $order_id );

		// Update subscription payment method
		if ( wcs_is_subscription( $order_id ) ) {

			if ( ! $_POST['payment_method_id'] ) {
				throw new Exception( 'Missing payment method details' );
			}

			$payment_method = Payload\PaymentMethod::get( $_POST['payment_method_id'] );

			$token = $this->create_token( $payment_method->data() );

			$parent_order = wc_get_order( $order->get_parent_id() );
			$parent_order->add_payment_token( $token->get_id() );
			// $parent_order->set_payment_method_title($payment_method->description);
			$parent_order->save();

			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// Process payment using Token
		if ( $_POST['token'] ) {
			$token   = WC_Payment_Tokens::get( $_POST['token'] );
			$payment = $this->create_payment_for_order( $order, $order->get_total(), $token->get_token() );
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

			$payment->update( array( 'status' => 'processed' ) );

			$order->set_transaction_id( $payment->ref_number );
		}

		// Create and set token if subscription
		if ( WC_Subscriptions_Order::order_contains_subscription( $order_id ) ) {
			$token = $this->create_token( $payment->payment_method );
			$order->add_payment_token( $token );
		}

		// Mark the order as processed
		$order->payment_complete();
		$order->save();

		// Redirect to the thank you page
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
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
			$tokens       = $parent_order->get_payment_tokens();

			if ( count( $tokens ) == 0 ) {
				throw new Exception( 'No available payment method' );
			}

			$token = WC_Payment_Tokens::get( $tokens[0] );

			if ( is_null( $token ) ) {
				throw new Exception( 'No available payment method' );
			}

			$payment_method_id = $token->get_token();
			break;
		}

		$this->create_payment_for_order( $renewal_order, $amount, $payment_method_id );

		$renewal_order->save();
	}

	public function create_payment_for_order( $order, $amount, $payment_method_id ) {
		$payment = Payload\Transaction::create(
			array(
				'amount'            => $amount,
				'type'              => 'payment',
				'payment_method_id' => $payment_method_id,
				'order_number'      => strval( $order->get_id() ),
			)
		);

		$order->set_transaction_id( $payment->ref_number );
		$order->payment_complete();

		return $payment;
	}

	public function create_token( $payment_method ) {
		$token = new WC_Payment_Token_CC();
		$token->set_token( $payment_method['id'] );
		$token->set_gateway_id( $this->id );
		$token->set_card_type( $payment_method['card']['card_brand'] );
		$token->set_last4( substr( $payment_method['card']['card_number'], -4 ) );
		$token->set_expiry_month( substr( $payment_method['card']['expiry'], 0, 2 ) );
		$token->set_expiry_year( substr( $payment_method['card']['expiry'], -4 ) );
		$token->set_user_id( get_current_user_id() );
		$token->save();

		return $token;
	}
}
