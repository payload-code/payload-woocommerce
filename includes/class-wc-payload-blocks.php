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

final class WC_Payload_Blocks extends AbstractPaymentMethodType {


	private $gateway;
	protected $name = 'payload';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_payload_settings', array() );
		$this->gateway  = new WC_Payload_Gateway();
	}

	public function is_active() {
		return $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		$this->gateway->payment_scripts();

		return array( 'payload-blocks-integration' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway->title,
			'description' => $this->gateway->method_description,
		);
	}
}
