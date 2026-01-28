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
 * Retry pending/on-hold orders after customer updates payment card.
 *
 * Automatically attempts to process failed orders when a new payment method is added
 * or set as default. Includes order-scoped suppression to prevent duplicate retries.
 *
 * @since 1.0.0
 * @param int                   $token_id Payment token ID.
 * @param WC_Payment_Token|null $token    Payment token object.
 */
function payload_retry_orders_after_card_update( $token_id, $token = null ) {

	if ( ! $token instanceof WC_Payment_Token ) {
		$token = WC_Payment_Tokens::get( $token_id );
	}

	if ( ! $token instanceof WC_Payment_Token ) {
		return;
	}

	if ( 'payload' !== $token->get_gateway_id() ) {
		return;
	}

	$user_id = $token->get_user_id();
	if ( ! $user_id ) {
		return;
	}

	setup_payload_api();

	$args   = array(
		'customer_id'    => $user_id,
		'status'         => array( 'wc-on-hold', 'wc-pending' ),
		'payment_method' => 'payload',
		'type'           => array( 'shop_order', 'shop_subscription' ),
		'limit'          => 20, // Limit to 20 orders to prevent memory exhaustion
		'orderby'        => 'date',
		'order'          => 'ASC',
	);
	$orders = wc_get_orders( $args );

	if ( empty( $orders ) ) {
		return;
	}

	$gateway = payload_get_gateway_instance();

	if ( ! $gateway ) {
		return;
	}

	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$order_id = (int) $order->get_id();
		// Order-scoped suppression check
		if ( payload_card_update_retry_suppressed( $order_id ) ) {
			continue;
		}
		payload_card_update_retry_suppressed( $order_id, true );

		try {
			if ( strval( $order->get_payment_method() ) !== strval( $token->get_id() ) ) {
				$order->set_payment_method( $token->get_id() );

				if ( method_exists( $token, 'get_card_type' ) && method_exists( $token, 'get_last4' ) && $token->get_last4() ) {
					$method_title = sprintf(
						__( '%1$s ending in %2$s', 'payload' ),
						strtoupper( $token->get_card_type() ),
						$token->get_last4()
					);
						$order->set_payment_method_title( $method_title );
				}

				$order->save();
			}

			$gateway->create_payment_for_order( $order, $order->get_total(), $token->get_token() );
			$order->add_order_note( __( 'Automatically retried payment after customer updated saved card.', 'payload' ) );
		} catch ( Exception $e ) {
			$order->add_order_note(
				sprintf(
					__( 'Automatic retry after card update failed: %s', 'payload' ),
					$e->getMessage()
				)
			);
		} finally {
			// Remove order-scoped suppression
			payload_card_update_retry_suppressed( $order_id, false );
		}
	}
}
add_action( 'woocommerce_new_payment_token', 'payload_retry_orders_after_card_update', 10, 2 );
add_action( 'woocommerce_payment_token_set_default', 'payload_retry_orders_after_card_update', 10, 2 );

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
