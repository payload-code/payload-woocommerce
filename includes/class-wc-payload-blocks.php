<?php

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
		wp_enqueue_style( 'payload-blocks-css', plugin_dir_url( __FILE__ ) . '../build/style-main.css', array(), '' );

		wp_register_script(
			'payload-blocks-integration',
			plugin_dir_url( __FILE__ ) . '../build/main.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			),
			null,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'payload-blocks-integration' );

		}

		return array( 'payload-blocks-integration' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => $this->gateway->title,
			'description' => $this->gateway->description,
		);
	}
}
