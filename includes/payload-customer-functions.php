<?php
/**
 * Payload Customer Management Functions
 *
 * Functions for managing Payload customer records and syncing with WordPress users.
 *
 * @package Payload_WooCommerce
 * @since   1.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the Payload customer ID meta key value for a user.
 *
 * Helper function to retrieve the Payload customer ID from user meta without
 * creating a new customer if one doesn't exist. For customer creation, use
 * get_payload_customer_id() instead.
 *
 * @since 1.4.0
 * @param int $user_id WordPress user ID.
 * @return string|false Payload customer ID or false if not found.
 */
function payload_get_customer_id_meta( $user_id ) {
	return get_user_meta( $user_id, PAYLOAD_CUSTOMER_ID_META_KEY, true );
}

/**
 * Update the Payload customer ID for a user.
 *
 * Helper function to store a Payload customer ID in WordPress user meta.
 *
 * @since 1.4.0
 * @param int    $user_id              WordPress user ID.
 * @param string $payload_customer_id  Payload customer ID to store.
 * @return int|bool Meta ID on success, false on failure.
 */
function payload_update_customer_id_meta( $user_id, $payload_customer_id ) {
	return update_user_meta( $user_id, PAYLOAD_CUSTOMER_ID_META_KEY, $payload_customer_id );
}

/**
 * Get or create a Payload customer ID for a WordPress user.
 *
 * Retrieves the Payload customer ID from user meta, or creates a new Payload
 * customer if one doesn't exist. Searches by email first, then creates if needed.
 *
 * @since 1.0.0
 * @param int|null $user_id WordPress user ID. If null, uses current user.
 * @return string|null Payload customer ID or null if unable to create.
 */
function get_payload_customer_id( $user_id = null ) {
	$user                = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
	$payload_customer_id = ! empty( $user ) ? payload_get_customer_id_meta( $user->ID ) : null;

	$logger  = wc_get_logger();
	$context = array( 'source' => 'payload-woocommerce.php' );
	$logger->info( 'Script started Payload Customer ID checking for user ID: ' . ( $user ? $user->ID : 'none' ), $context );

	if ( $payload_customer_id ) {
		return $payload_customer_id;
	}

	if ( empty( $user ) || ! $user->user_email ) {
		return;
	}

	// Try and lookup customer by email
	try {
		$customer = Payload\Customer::filter_by(
			array( 'email' => $user->user_email )
		)->all();

		if ( is_array( $customer ) && ! empty( $customer ) ) {
			$payload_customer_id = $customer[0]->id;
			payload_update_customer_id_meta( $user->ID, $payload_customer_id );
			$logger->info( 'Payload Customer ID found for user email:' . $user->user_email, $context );
			return $payload_customer_id;
		}
	} catch ( Exception $e ) {
		$logger->error(
			'Failed to retrieve Payload customer by email for user ID ' . $user->ID . ': ' . $e->getMessage(),
			$context
		);
		// Continue to creation attempt
	}

	// Create customer if doesn't exist
	try {
		$customer_data = payload_build_customer_data( $user );
		$customer      = Payload\Customer::create(
			$customer_data
		);
		$logger->info( 'Payload Customer ID Created: ' . ( isset( $customer->id ) ? $customer->id : 'unknown' ), $context );

		$payload_customer_id = $customer->id;
		payload_update_customer_id_meta( $user->ID, $payload_customer_id );
		return $payload_customer_id;
	} catch ( Exception $e ) {
		$logger->error(
			'Failed to create Payload customer for user ID ' . $user->ID . ': ' . $e->getMessage(),
			$context
		);
		return null;
	}
}

/**
 * Display billing company field on checkout form.
 *
 * Adds a company name field to the WooCommerce billing section during checkout.
 *
 * @since 1.0.0
 * @param WC_Checkout $checkout WooCommerce checkout object.
 */
function payload_display_billing_company_field( $checkout ) {

	echo '<div class="form-row form-row-wide" id="billing_company_custom_wrapper">';

	woocommerce_form_field(
		'billing_company',
		array(
			'type'     => 'text',
			'class'    => array( 'form-row-wide' ),
			'label'    => __( 'Company Name', 'woocommerce' ),
			'required' => false,
			'priority' => 8,
		),
		$checkout->get_value( 'billing_company' )
	);

	echo '</div>';
}
add_action( 'woocommerce_after_checkout_billing_form', 'payload_display_billing_company_field', 10, 2 );

/**
 * Save billing company to order and user meta during checkout.
 *
 * Captures the billing company field and saves it to both the order
 * and the user meta (if logged in).
 *
 * @since 1.0.0
 * @param WC_Order $order Order object being created.
 * @param array    $data  Posted checkout data.
 */
function payload_save_billing_company_to_order( $order, $data ) {

	if ( isset( $_POST['billing_company'] ) && ! empty( $_POST['billing_company'] ) ) {
		$company = sanitize_text_field( wp_unslash( $_POST['billing_company'] ) );

		// Set on the order
		$order->set_billing_company( $company );

		$user_id = payload_get_order_customer_id( $order );

		// Also persist on the user, if logged in
		if ( $user_id ) {
			update_user_meta( $user_id, 'billing_company', $company );
		}
	}
}
add_action( 'woocommerce_checkout_create_order', 'payload_save_billing_company_to_order', 10, 2 );

/**
 * Update Payload customer when WordPress user profile is updated.
 *
 * Syncs user profile changes (email, display name, company) to the corresponding
 * Payload customer record.
 *
 * @since 1.0.0
 * @param int     $user_id  User ID being updated.
 * @param WP_User $old_user Previous user data object.
 */
function payload_sync_customer_on_profile_update( $user_id, $old_user ) {
	setup_payload_api();

	$payload_customer_id = payload_get_customer_id_meta( $user_id );

	if ( ! empty( $payload_customer_id ) ) {
		$user          = get_userdata( $user_id );
		$customer_data = payload_build_customer_data( $user );
		try {
			$customer = Payload\Customer::filter_by( array( 'id' => $payload_customer_id ) )->update(
				$customer_data
			);
		} catch ( Exception $e ) {
			$logger = wc_get_logger();
			$logger->error(
				'Failed to update Payload customer on profile update for user ID ' . $user_id . ': ' . $e->getMessage(),
				array( 'source' => 'payload-woocommerce' )
			);
			// Fail silently - don't block profile updates
		}
	}
}
add_action( 'profile_update', 'payload_sync_customer_on_profile_update', 10, 2 );

/**
 * Create Payload customer record when WooCommerce customer is created.
 *
 * Automatically creates a corresponding Payload customer when a new WooCommerce
 * customer account is created, if one doesn't already exist.
 *
 * @since 1.0.0
 * @param int $customer_id WooCommerce customer ID.
 */
function payload_create_customer_on_registration( $customer_id ) {
	if ( ! $customer_id ) {
		return;
	}

	if ( payload_get_customer_id_meta( $customer_id ) ) {
		return;
	}

	setup_payload_api();
	get_payload_customer_id( $customer_id );
}
add_action( 'woocommerce_created_customer', 'payload_create_customer_on_registration' );

/**
 * Ensure Payload customer record exists after checkout order is processed.
 *
 * Creates a Payload customer record for new users during checkout if one doesn't
 * already exist. This handles cases where the customer was created during the
 * checkout process.
 *
 * @since 1.0.0
 * @param int      $order_id     Order ID being processed.
 * @param array    $posted_data  Posted checkout data.
 * @param WC_Order $order        Order object.
 */
function payload_ensure_customer_after_checkout( $order_id, $posted_data, $order ) {
	$customer_id = $order->get_customer_id();
	if ( ! $customer_id ) {
		return;
	}
	if ( payload_get_customer_id_meta( $customer_id ) ) {
		return;
	}

	setup_payload_api();
	get_payload_customer_id( $customer_id );
}
add_action( 'woocommerce_checkout_order_processed', 'payload_ensure_customer_after_checkout', 10, 3 );

/**
 * Find a WordPress user by their Payload customer ID.
 *
 * @since 1.4.0
 * @param string $payload_customer_id Payload customer ID to search for.
 * @return int|null User ID if found, null otherwise.
 */
function payload_find_user_by_customer_id( $payload_customer_id ) {
	$users = get_users(
		array(
			'meta_key'   => PAYLOAD_CUSTOMER_ID_META_KEY,
			'meta_value' => $payload_customer_id,
			'number'     => 1,
			'fields'     => 'ID',
		)
	);
	if ( ! empty( $users ) ) {
		return $users[0];
	}
	return null;
}

function payload_build_customer_data( $user ) {
	$company_name = get_user_meta( $user->ID, 'billing_company', true );
	return array(
		'email' => $user->user_email,
		'name'  => $company_name ? $company_name : $user->display_name,
		'attrs' => array(
			'_wp_user_id' => $user->ID,
		),
	);
}
