<?php
/**
 * Payload Payment Gateway Class
 *
 * Handles payment processing for Payload payment gateway.
 *
 * @package Payload_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * TransactionDeclined Exception
 *
 * Thrown when a payment transaction is declined.
 */
class TransactionDeclined extends Exception {

	public $error_description;

	public function __construct( $message = '', $code = 0, Exception $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->error_description = $message;
	}
}

class WC_Payload_Gateway extends WC_Payment_Gateway {


	/**
	 * Constructor - Initialize payment gateway.
	 *
	 * @since 1.0.0
	 */
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

	/**
	 * Initialize gateway form fields.
	 *
	 * @since 1.0.0
	 */
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
				'type'  => 'secret',
			),
		);
	}

	/**
	 * Render a write-only secret field. The saved value is never sent to the browser.
	 *
	 * @since  1.5.0
	 * @param  string $key  Field key.
	 * @param  array  $data Field data.
	 * @return string HTML output.
	 */
	public function generate_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'class'       => '',
			'css'         => '',
			'placeholder' => '',
			'desc_tip'    => false,
			'description' => '',
		);
		$data      = wp_parse_args( $data, $defaults );

		$saved_value = $this->get_option( $key );
		$has_value   = (bool) $saved_value;
		$description = $has_value
			? __( 'An API key is saved. Enter a new value to replace it.', 'payload' )
			: $data['description'];
		$placeholder = $has_value
			? '••••••••' . substr( $saved_value, -6 )
			: $data['placeholder'];

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>">
					<?php echo wp_kses_post( $data['title'] ); ?>
				</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input
						class="input-text regular-input <?php echo esc_attr( $data['class'] ); ?>"
						type="password"
						name="<?php echo esc_attr( $field_key ); ?>"
						id="<?php echo esc_attr( $field_key ); ?>"
						style="<?php echo esc_attr( $data['css'] ); ?>"
						value=""
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						autocomplete="new-password"
					/>
					<?php if ( ! empty( $description ) ) : ?>
						<p class="description"><?php echo wp_kses_post( $description ); ?></p>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Validate the secret field. Keeps the existing value if the field is left empty.
	 *
	 * @since  1.5.0
	 * @param  string $key   Field key.
	 * @param  string $value Posted value.
	 * @return string Validated value.
	 */
	public function validate_secret_field( $key, $value ) {
		$value = is_null( $value ) ? '' : trim( $value );

		if ( '' === $value ) {
			return $this->get_option( $key );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Enqueue payment scripts and styles.
	 *
	 * @since 1.0.0
	 */
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

	/**
	 * Display payment fields on checkout page.
	 *
	 * @since 1.0.0
	 */
	public function payment_fields() {
		?>
		<div id="payload-add-payment-method"></div>
		<script>if(window.plMountPaymentMethodForm) window.plMountPaymentMethodForm()</script>
		<?php
	}

	/**
	 * Process the payment for an order.
	 *
	 * @since  1.0.0
	 * @param  int $order_id The order ID to process payment for.
	 * @return array Payment result with redirect URL.
	 * @throws Exception If payment processing fails.
	 */
	public function process_payment( $order_id ) {
		try {
			setup_payload_api();

			if ( payload_card_update_retry_suppressed( $order_id ) ) {
				throw new Exception( __( 'Payment already processing for order', 'payload' ) );
			}

			payload_card_update_retry_suppressed( $order_id, true );

			$logger  = wc_get_logger();
			$context = array( 'source' => 'payload-gateway.php' );
			$logger->info( 'Payment Process started for Order ID: ' . $order_id, $context );

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				throw new Exception( __( 'Invalid order', 'payload' ) );
			}

			$user_id_from_order = payload_get_order_user_id( $order );

			$post_payment_method_id = isset( $_POST['payment_method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method_id'] ) ) : '';
			$post_token             = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

			// Handle subscription payment method updates
			if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) {
				return $this->process_subscription_payment_method_update( $order, $post_payment_method_id, $user_id_from_order );
			}

			// Handle zero-amount orders (e.g., 100% discount coupons, free trials)
			if ( $order->get_total() == 0 ) {
				$logger->info( 'Zero-amount order detected for Order ID: ' . $order_id . ', completing without payment processing', $context );
				$order->payment_complete();
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			// Process payment using token or payment method
			if ( ! empty( $post_token ) || ! empty( $post_payment_method_id ) ) {
				$payment = $this->process_token_payment( $order, $post_token, $post_payment_method_id, $user_id_from_order );
			} else {
				$transaction_id = isset( $_POST['transactionid'] ) ? sanitize_text_field( wp_unslash( $_POST['transactionid'] ) ) : '';
				if ( empty( $transaction_id ) ) {
					throw new Exception( __( 'Missing payment details', 'payload' ) );
				}

				// Confirm payment processed on client side
				$payment = $this->process_client_side_payment( $transaction_id, $order, $user_id_from_order );
			}

			$this->handle_order_payment( $order, $payment );

			$logger->info( 'Payment Process ENDED for Order ID: ' . $order_id, $context );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		} finally {
			payload_card_update_retry_suppressed( $order_id, false );
		}
	}

	/**
	 * Add a new payment method to customer account.
	 *
	 * @since  1.0.0
	 * @return array Result with redirect URL.
	 * @throws Exception If payment method details are missing.
	 */
	public function add_payment_method() {
		setup_payload_api();

		$payment_method_id = isset( $_POST['payment_method_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method_id'] ) ) : '';
		if ( empty( $payment_method_id ) ) {
			throw new Exception( 'Missing payment method details' );
		}

		$token = $this->create_token_from_payment_method_id( $payment_method_id, null );

		return array(
			'result'   => 'success',
			'redirect' => wc_get_endpoint_url( 'payment-methods' ),
		);
	}

	/**
	 * Process scheduled subscription renewal payment.
	 *
	 * @since 1.0.0
	 * @param float    $amount         Amount to charge.
	 * @param WC_Order $renewal_order  Renewal order object.
	 * @param bool     $retry          Whether this is a retry attempt.
	 * @param mixed    $previous_error Previous error if retrying.
	 */
	public function scheduled_subscription_payment( $amount, $renewal_order, $retry = true, $previous_error = false ) {
		setup_payload_api();

		$status = $renewal_order->get_status();

		if ( $status !== 'pending' ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_order( $renewal_order->get_id(), array( 'order_type' => 'any' ) );

		if ( empty( $subscriptions ) ) {
			$logger = wc_get_logger();
			$logger->error(
				'No subscriptions found for renewal order ' . $renewal_order->get_id(),
				array( 'source' => 'payload-gateway' )
			);
			$renewal_order->update_status(
				'failed',
				__( 'Automatic subscription payment failed: No subscription found for renewal order.', 'payload' )
			);
			return;
		}

		$payment_method_id = null;
		foreach ( $subscriptions as $subscription_id => $subscription ) {
			$parent_order = wc_get_order( $subscription->get_parent_id() );
			$token_id     = $parent_order->get_payment_method();

			$token = WC_Payment_Tokens::get( $token_id );

			if ( is_null( $token ) ) {
				$logger = wc_get_logger();
				$logger->error( 'No available payment method for order ' . $parent_order->get_id(), array( 'source' => 'payload' ) );

				$note = __( 'Automatic subscription payment failed: No payment method on file for this account.', 'payload' );
				$renewal_order->add_order_note( $note );
				$renewal_order->save();
				set_transient(
					'my_admin_flash_notice_' . get_current_user_id(),
					array(
						'message' => 'Automatic subscription payment failed: No payment method on file for this account.',
						'type'    => 'error',
					),
					60
				);

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

			$payment_method_id = $token->get_token();
			break;
		}

		try {
			$payment_method = Payload\PaymentMethod::get( $payment_method_id );
		} catch ( Exception $e ) {
			$logger = wc_get_logger();
			$logger->error(
				'Failed to retrieve payment method for scheduled subscription payment: ' . $e->getMessage(),
				array( 'source' => 'payload-gateway' )
			);
			$renewal_order->add_order_note(
				__( 'Automatic subscription payment failed: Unable to retrieve payment method.', 'payload' )
			);
			return;
		}

		$this->create_payment_for_order( $renewal_order, $amount, $token );

		$renewal_order->save();
	}

	/**
	 * Process subscription payment method update.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order              The subscription order object.
	 * @param  string   $payment_method_id  The new payment method ID.
	 * @param  int      $user_id_from_order The user ID from the order.
	 * @return array Payment result with redirect URL.
	 * @throws Exception If payment method is missing.
	 */
	protected function process_subscription_payment_method_update( $order, $payment_method_id, $user_id_from_order ) {
		if ( empty( $payment_method_id ) ) {
			throw new Exception( __( 'Missing payment method details', 'payload' ) );
		}

		$token = $this->create_token_from_payment_method_id( $payment_method_id, $user_id_from_order );

		$parent_order = wc_get_order( $order->get_parent_id() );
		$this->update_order_payment_method_token( $parent_order, $token );

		// Update all subscriptions if requested
		$update_all = isset( $_POST['update_all_subscriptions_payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['update_all_subscriptions_payment_method'] ) ) : '';
		if ( $update_all === '1' ) {
			$subscriptions = wcs_get_users_subscriptions( $user_id_from_order );
			$errors        = array();

			foreach ( $subscriptions as $subscription ) {
				try {
					$subscription_parent_order = wc_get_order( $subscription->get_parent_id() );
					$this->update_order_payment_method_token( $subscription_parent_order, $token );
				} catch ( Exception $e ) {
					$errors[] = sprintf(
						'Failed to update subscription %s: %s',
						$subscription->get_id(),
						$e->getMessage()
					);
				}
			}

			// If any updates failed, throw an exception with all errors
			if ( ! empty( $errors ) ) {
				throw new Exception( implode( '; ', $errors ) );
			}
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Process payment using a saved token or new payment method.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order                  The order object.
	 * @param  string   $post_token             The saved token ID from POST.
	 * @param  string   $post_payment_method_id The payment method ID from POST.
	 * @param  int      $user_id_from_order     The user ID from the order.
	 * @return object Payment transaction object.
	 * @throws TransactionDeclined If payment is declined.
	 */
	protected function process_token_payment( $order, $post_token, $post_payment_method_id, $user_id_from_order ) {
		if ( ! empty( $post_token ) ) {
			$token = WC_Payment_Tokens::get( $post_token );
		} else {
			$token = $this->create_token_from_payment_method_id( $post_payment_method_id, $user_id_from_order );
		}

		try {
			return $this->create_payment_for_order( $order, $order->get_total(), $token );
		} catch ( TransactionDeclined $e ) {
			wc_add_notice( __( 'Payment error:', 'payload' ) . ' ' . esc_html( $e->error_description ), 'error' );
			throw $e;
		}
	}

	/**
	 * Process payment that was completed on the client side.
	 *
	 * @since  1.0.0
	 * @param  WC_Order $order              The order object.
	 * @param  int      $user_id_from_order The user ID from the order.
	 * @return object Payment transaction object.
	 * @throws Exception If transaction ID is missing or amount mismatches.
	 */
	protected function process_client_side_payment( $transaction_id, $order, $user_id_from_order ) {

		try {
			$payment = Payload\Transaction::get( $transaction_id );
		} catch ( Exception $e ) {
			$logger = wc_get_logger();
			$logger->error(
				'Failed to retrieve transaction: ' . $e->getMessage(),
				array( 'source' => 'payload-gateway' )
			);
			throw new Exception( __( 'Unable to verify payment. Please contact support.', 'payload' ) );
		}

		// Validate payment amount
		$amt         = (float) $order->get_total();
		$payment_amt = (float) $payment->amount;
		if ( abs( $amt - $payment_amt ) > 0.01 ) {
			throw new Exception( __( 'Mismatched Amount', 'payload' ) );
		}

		// Associate customer with payment if not already set
		if ( ! $payment->customer_id ) {
			$this->associate_customer_with_payment( $payment, $user_id_from_order );
		}

		// Create and set token if subscription, or if the payment method should be kept active for a known user
		$has_subscription = class_exists( 'WC_Subscriptions_Order' ) && WC_Subscriptions_Order::order_contains_subscription( $order->get_id() );
		$should_tokenize  = $has_subscription || ( ! empty( $payment->payment_method['keep_active'] ) && $user_id_from_order );

		if ( $should_tokenize ) {
			$token = $this->create_token( $payment->payment_method, $user_id_from_order );
			$this->update_order_payment_method_token( $order, $token );
		} else {
			$this->update_order_payment_method( $order, $payment->payment_method['description'], $payment->payment_method_id );
		}

		return $payment;
	}

	/**
	 * Create a payment transaction for an order.
	 *
	 * @since  1.0.0
	 * @param  WC_Order         $order  The order object.
	 * @param  float            $amount Amount to charge.
	 * @param  WC_Payment_Token $token  Payment token to use.
	 * @return object Payment transaction object.
	 * @throws TransactionDeclined If payment creation fails.
	 */
	public function create_payment_for_order( $order, $amount, $token ) {
		$this->update_order_payment_method_token( $order, $token );

		$payment_method_id = $token->get_token();

		try {
			$order_id = $order->get_id();
			$payment  = Payload\Transaction::create(
				array(
					'description'       => ' Order Item(s): ' . payload_get_order_product_names( $order_id ),
					'amount'            => $amount,
					'type'              => 'payment',
					'payment_method_id' => $payment_method_id,
					'order_number'      => strval( $order_id ),
				)
			);
			$this->handle_order_payment( $order, $payment );
		} catch ( Payload\Exceptions\BadRequest $e ) {
			throw new TransactionDeclined( 'Transaction creation failed: ' . $e->getMessage() );
		} catch ( Payload\Exceptions\InvalidAttributes $e ) {
			throw new TransactionDeclined( 'Transaction creation failed: ' . $e->getMessage() );
		} catch ( Payload\Exceptions\TransactionDeclined $e ) {
			throw new TransactionDeclined( 'Transaction creation failed: ' . $e->getMessage() );
		}
		return $payment;
	}

	/**
	 * Handle order payment processing after transaction is created.
	 *
	 * @since 1.0.0
	 * @param WC_Order $order   The order object.
	 * @param object   $payment The payment transaction object.
	 */
	public function handle_order_payment( $order, $payment ) {
		$order->set_transaction_id( $payment->ref_number );
		$order_id = $order->get_id();

		// Non virtual goods will be processed manaully after admin review
		if ( $payment->status === 'authorized' && payload_order_is_virtual( $order_id ) ) {
			try {
				$payment->update(
					array(
						'order_number' => strval( $order_id ),
						'status'       => 'processed',
						'description'  => ' Order Item(s): ' . payload_get_order_product_names( $order_id ),
					)
				);
			} catch ( Exception $e ) {
				$logger = wc_get_logger();
				$logger->error(
					'Failed to update payment details for order ' . $order_id . ': ' . $e->getMessage(),
					array( 'source' => 'payload-gateway' )
				);
				// Continue processing - this is a non-critical update
			}
		}

		// Set completed automatically if transaction is fully processed
		if ( $payment->status === 'processed' ) {
			$order->payment_complete();

		}

		$order->save();
	}

	/**
	 * Associate a Payload customer with a payment.
	 *
	 * @since 1.0.0
	 * @param object $payment The payment transaction object.
	 * @param int    $user_id The WordPress user ID.
	 */
	protected function associate_customer_with_payment( $payment, $user_id ) {
		$payload_customer_id = get_payload_customer_id( $user_id );
		if ( $payload_customer_id ) {
			try {
				$payment->update( array( 'customer_id' => $payload_customer_id ) );

				$payment_method = Payload\PaymentMethod::get( $payment->payment_method_id );
				$payment_method->update( array( 'account_id' => $payload_customer_id ) );
			} catch ( Exception $e ) {
				$logger = wc_get_logger();
				$logger->error(
					'Failed to associate customer with payment: ' . $e->getMessage(),
					array( 'source' => 'payload-gateway' )
				);
				// Don't throw - this is a non-critical operation
			}
		}
	}

	/**
	 * Create a payment token from a Payload payment method ID.
	 *
	 * @since  1.0.0
	 * @param  string $payment_method_id The Payload payment method ID.
	 * @param  int    $user_id           The WordPress user ID.
	 * @return WC_Payment_Token_CC The created payment token.
	 * @throws Exception If payment method cannot be retrieved.
	 */
	public function create_token_from_payment_method_id( $payment_method_id, $user_id ) {

		try {
			$payment_method = Payload\PaymentMethod::get( $payment_method_id );
		} catch ( Exception $e ) {
			$logger = wc_get_logger();
			$logger->error(
				'Failed to retrieve payment method for subscription update: ' . $e->getMessage(),
				array( 'source' => 'payload-gateway' )
			);
			throw new Exception( __( 'Unable to retrieve payment method. Please try again.', 'payload' ) );
		}

		return $this->create_token( $payment_method->data(), $user_id );
	}

	/**
	 * Create a WooCommerce payment token from payment method data.
	 *
	 * @since  1.0.0
	 * @param  array    $payment_method Payment method data array.
	 * @param  int|null $user_id        WordPress user ID (optional).
	 * @return WC_Payment_Token_CC The created or existing payment token.
	 */
	public function create_token( $payment_method, $user_id = null ) {
		$token = new WC_Payment_Token_CC();
		$token->set_token( $payment_method['id'] );
		$token->set_gateway_id( $this->id );
		$token->set_card_type( $payment_method['card']['card_brand'] );
		$token->set_last4( substr( $payment_method['card']['card_number'], -4 ) );
		$token->set_expiry_month( substr( $payment_method['card']['expiry'], 0, 2 ) );
		$token->set_expiry_year( substr( $payment_method['card']['expiry'], -4 ) );

		if ( $user_id ) {
			// We create this flag just incase Admin is changing payment method for a user
			$token->set_user_id( $user_id );
		} else {
			$token->set_user_id( get_current_user_id() );
		}

		$existing_token = $this->check_if_card_exist( $token );
		if ( ! $existing_token ) {
			$token->save();
		} else {
			$token = $existing_token;
		}

		$pm = new Payload\PaymentMethod( array( 'id' => $payment_method['id'] ) );
		try {
			$pm->update( array( 'attrs' => array( '_wp_token_id' => $token->get_id() ) ) );
		} catch ( Exception $e ) {
			$logger = wc_get_logger();
			$logger->error(
				'Failed to update payment method attributes: ' . $e->getMessage(),
				array( 'source' => 'payload-gateway' )
			);
			// Continue - this is a non-critical metadata update
		}

		return $token;
	}

	/**
	 * Check if a card already exists for a customer.
	 *
	 * @since  1.0.0
	 * @param  WC_Payment_Token_CC $token The token to check.
	 * @return WC_Payment_Token_CC|false Existing token if found, false otherwise.
	 */
	public function check_if_card_exist( $token ) {
		// Check if card exist
		$chk_tokens = WC_Payment_Tokens::get_customer_tokens( $token->get_user_id(), $this->id );
		foreach ( $chk_tokens as $chk_token ) {
			if ( $chk_token->get_last4() === $token->get_last4()
				&& $chk_token->get_expiry_month() === $token->get_expiry_month()
				&& $chk_token->get_expiry_year() === $token->get_expiry_year()
			) {
				return $chk_token;
			}
		}
		return false;
	}

	/**
	 * Update order with new payment method token.
	 *
	 * @since 1.0.0
	 * @param WC_Order         $order The order object.
	 * @param WC_Payment_Token $token The payment token.
	 */
	protected function update_order_payment_method_token( $order, $token ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $token instanceof WC_Payment_Token ) {
			return;
		}

		if ( $order->get_status() === 'on-hold'
			&& strval( $order->get_payment_method() ) !== strval( $token->get_id() )
		) {
			$order->set_status( 'pending' );

			$this->notify_payment_retry( $order, $token, $note );
		}

		if ( method_exists( $token, 'get_card_type' )
			&& method_exists( $token, 'get_last4' ) && $token->get_last4()
		) {
			$method_title = sprintf(
				__( '%1$s x-%2$s', 'payload' ),
				strtoupper( $token->get_card_type() ),
				$token->get_last4()
			);
		} else {
			$method_title = '';
		}

		$order->add_payment_token( $token );
		$this->update_order_payment_method( $order, $method_title, $token->get_id() );
	}

	/**
	 * Update order payment method details.
	 *
	 * @since 1.0.0
	 * @param WC_Order $order        The order object.
	 * @param string   $method_title The payment method title.
	 * @param string   $method_id    The payment method ID.
	 */
	protected function update_order_payment_method( $order, $method_title, $method_id ) {
		$order->set_payment_method_title( $method_title );
		$order->set_payment_method( $method_id );
		$order->save();
	}

	/**
	 * Notify relevant parties about payment retry after card update.
	 *
	 * @since 1.0.0
	 * @param WC_Order         $order The order object.
	 * @param WC_Payment_Token $token The new payment token.
	 * @param string           $note  The order note.
	 */
	protected function notify_payment_retry( $order, $token, $note ) {
		if ( ! $order instanceof WC_Order || ! $token instanceof WC_Payment_Token ) {
			return;
		}

		$note = sprintf(
			__( 'Payment method updated to card ending in %s after a previous card issue. Order moved to pending for retry.', 'payload' ),
			$token->get_last4()
		);

		$order->add_order_note( $note );
		$order->save();

		$order_number = method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : $order->get_id();

		$subject = sprintf(
			__( 'Order #%s payment retry notice', 'payload' ),
			$order_number
		);

		$message_body = sprintf(
			__( 'Order #%1$s:  %3$s', 'payload' ),
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
}
