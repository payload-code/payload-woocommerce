<?php
/**
 * Payload Order Functions
 *
 * Functions for managing WooCommerce orders, subscriptions, and payment retries.
 *
 * @package Payload_WooCommerce
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Display payment method for WooCommerce subscriptions.
 *
 * Gets the payment method title from the parent order for subscriptions.
 *
 * @since  1.0.0
 * @param  string          $label        Default payment method label.
 * @param  WC_Subscription $subscription Subscription object.
 * @param  string          $context      Display context.
 * @return string Payment method title from parent order or default message.
 */
function payload_subscription_payment_method_to_display( $label, $subscription, $context ) {

	$parent_order = wc_get_order( $subscription->get_parent_id() );
	if ( $parent_order ) {
		return $parent_order->get_payment_method_title();
	}
	return 'No Payment Method available at this time.';
}
add_filter( 'woocommerce_subscription_payment_method_to_display', 'payload_subscription_payment_method_to_display', 10, 3 );

/**
 * Check or set suppression status for card update retry on an order.
 *
 * Uses a static array to track which orders have already been processed during
 * card update retry to prevent infinite loops or duplicate processing.
 *
 * @since  1.0.0
 * @param  int|null  $order_id Order ID to check/set suppression for.
 * @param  bool|null $status   If provided, sets suppression status (true=suppress, false=unsuppress).
 * @return bool True if order retry is suppressed, false otherwise.
 */
function payload_card_update_retry_suppressed( $order_id = null, $status = null ) {
	static $suppressed_orders = array();

	$order_id = $order_id ? (int) $order_id : 0;

	// If no order context, default to NOT suppressed.
	if ( ! $order_id ) {
		return false;
	}

	if ( null !== $status ) {
		if ( $status ) {
			$suppressed_orders[ $order_id ] = true;
		} else {
			unset( $suppressed_orders[ $order_id ] );
		}
	}

	return ! empty( $suppressed_orders[ $order_id ] );
}

/**
 * Check if an order contains only virtual or downloadable products.
 *
 * Determines whether an order can be auto-completed without physical shipping.
 *
 * @since  1.4.0
 * @param  int $order_id Order ID to check.
 * @return bool True if all products are virtual or downloadable, false otherwise.
 */
function payload_order_is_virtual( $order_id ) {

	$order = wc_get_order( $order_id );
	if ( empty( $order ) ) {
		return false;
	}
	$items = $order->get_items();

	foreach ( $items as $item ) {
		$product = $item->get_product();
		if ( ! $product || ( ! $product->is_virtual() && ! $product->is_downloadable() ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Get product names from an order.
 *
 * @since  1.4.0
 * @param  int $order_id Order ID.
 * @return string Comma-separated product names.
 */
function payload_get_order_product_names( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! empty( $order ) ) {
		$items         = $order->get_items();
		$product_names = array();

		foreach ( $items as $item ) {
			$product_names[] = $item->get_name();
		}

		return implode( ', ', $product_names );
	}
	return '';
}

/**
 * Get the customer ID from an order object.
 *
 * Compatible with multiple WooCommerce versions.
 *
 * @since  1.4.0
 * @param  WC_Order $order The order object.
 * @return int Customer/user ID.
 */
function payload_get_order_user_id( $order ) {
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
