<?php
/**
 * Payload Blocks Payment Method
 *
 * Handles Payload payment method for WooCommerce Blocks checkout.
 *
 * @package Payload_WooCommerce
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Payload payment method type for WooCommerce Blocks.
 */
final class WC_Payload_Blocks extends AbstractPaymentMethodType {


	/**
	 * Payload gateway instance.
	 *
	 * @var WC_Payload_Gateway
	 */
	private $gateway;

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = 'payload';

	/**
	 * Initialize the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_payload_settings', array() );
		$this->gateway  = new WC_Payload_Gateway();
	}

	/**
	 * Whether the payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Script handles for the block-based checkout integration.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$this->gateway->payment_scripts();

		return array( 'payload-blocks-integration' );
	}

	/**
	 * Data passed to the block-based checkout client.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway->title,
			'description' => $this->gateway->method_description,
		);
	}
}
