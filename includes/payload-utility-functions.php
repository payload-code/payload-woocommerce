<?php
/**
 * Payload Utility Functions
 *
 * General utility functions for the Payload WooCommerce integration.
 *
 * @package Payload_WooCommerce
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get an instance of the Payload gateway.
 *
 * Retrieves the Payload gateway instance from WooCommerce payment gateways,
 * or creates a new instance if necessary.
 *
 * @since 1.0.0
 * @return WC_Payload_Gateway|null Gateway instance or null if unavailable.
 */
function payload_get_gateway_instance() {
	if ( function_exists( 'WC' ) ) {
		$payment_gateways = WC()->payment_gateways();
		if ( $payment_gateways ) {
			$gateways = $payment_gateways->payment_gateways();
			if ( isset( $gateways['payload'] ) && $gateways['payload'] instanceof WC_Payload_Gateway ) {
				return $gateways['payload'];
			}
		}
	}

	if ( class_exists( 'WC_Payload_Gateway' ) ) {
		return new WC_Payload_Gateway();
	}

	return null;
}

/**
 * Auto-complete orders containing only virtual/downloadable products.
 *
 * Automatically marks orders as completed when payment is received and all
 * products in the order are virtual or downloadable (no physical shipping needed).
 *
 * @since 1.0.0
 * @param int $order_id Order ID that received payment.
 */
function payload_autocomplete_virtual_orders( $order_id ) {
	// Check if order contains only virtual/downloadable products
	if ( payload_order_is_virtual( $order_id ) ) {
		$order = wc_get_order( $order_id );
		$order->update_status( 'completed', 'Order auto-completed because it contains only virtual products.' );
	}
}
add_action( 'woocommerce_payment_complete', 'payload_autocomplete_virtual_orders' );

/**
 * Handle admin notice trigger from URL parameter.
 *
 * Checks for the my_notice=1 URL parameter and sets a transient to display
 * a flash notice on the next page load.
 *
 * @since 1.0.0
 */
function payload_handle_admin_notice_trigger() {
	// Example trigger: append ?my_notice=1 to any wp-admin URL
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$my_notice = isset( $_GET['my_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['my_notice'] ) ) : '';
	if ( $my_notice === '1' ) {
		set_transient(
			'my_admin_flash_notice_' . get_current_user_id(),
			array(
				'message' => '✅ Settings saved successfully.',
				'type'    => 'success', // success | warning | error | info
			),
			60
		); // seconds
	}
}
add_action( 'admin_init', 'payload_handle_admin_notice_trigger' );

/**
 * Display admin flash notices from transient storage.
 *
 * Checks for and displays flash notices stored in transients, then deletes
 * the transient after displaying.
 *
 * @since 1.0.0
 */
function payload_display_admin_flash_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$key  = 'my_admin_flash_notice_' . get_current_user_id();
	$data = get_transient( $key );

	if ( ! $data ) {
		return;
	}

	delete_transient( $key );

	$type    = isset( $data['type'] ) ? $data['type'] : 'info';
	$message = isset( $data['message'] ) ? $data['message'] : '';

	$allowed = array( 'success', 'warning', 'error', 'info' );
	if ( ! in_array( $type, $allowed, true ) ) {
		$type = 'info';
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $message )
	);
}
add_action( 'admin_notices', 'payload_display_admin_flash_notices' );
