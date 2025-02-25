<?php

class WC_Payload_Gateway extends WC_Payment_Gateway {

	// Constructor method
	public function __construct() {
		$this->id                 = 'payload';
		$this->method_title       = __( 'Payload', 'payload' );
		$this->method_description = __( 'Accept payments through Payload.com', 'payload' );
		$this->title              = __( 'Credit card / debit card', 'payload' );

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
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

	// Process the payment
  public function process_payment( $order_id ) {
    setup_payload_api();

		$order    = wc_get_order( $order_id );

		$pm       = $order->get_payment_method();
		$pm_title = $order->get_payment_method_title();
		$txid     = $order->get_transaction_id();

		$payment = Payload\Transaction::get( $_POST['transactionid'] );

		$amt = $order->get_total();

		if ( $amt != $payment->amount ) {
			throw new Exception( 'Mismatched Amount' );
		}

		$payment->update( array( 'status' => 'processed' ) );

		$order->set_transaction_id( $payment->ref_number );
		$order->save();

		// Mark the order as processed
		$order->payment_complete();

		// Redirect to the thank you page
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
