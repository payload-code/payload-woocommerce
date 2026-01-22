<?php
/**
 * Plugin Name: Payload WooCommerce
 * Plugin URI: https://github.com/payload-code/payload-woocommerce
 * Description: Accept WooCommerce payments through Payload.com.
 * Version: 1.4.0
 * Author: Payload
 * Author URI: https://payload.com
 * Requires Plugins: woocommerce
 * License: MIT
 * License URI: https://mit-license.org/
 * Text Domain: payload
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

require_once 'vendor/autoload.php';
use Payload\API as pl;
// Force a Company field to show on the billing section
add_action( 'woocommerce_after_checkout_billing_form', function( $checkout ) {

    echo '<div class="form-row form-row-wide" id="billing_company_custom_wrapper">';

    woocommerce_form_field(
        'billing_company',
        [
            'type'        => 'text',
            'class'       => [ 'form-row-wide' ],
            'label'       => __( 'Company Name', 'woocommerce' ),
            'required'    => false,
            'priority'    => 8,
        ],
        $checkout->get_value( 'billing_company' )
    );

    echo '</div>';
}, 10, 2 );
// Save billing_company to the order and user meta
add_action( 'woocommerce_checkout_create_order', function( $order, $data ) {

    if ( isset( $_POST['billing_company'] ) && ! empty( $_POST['billing_company'] ) ) {
        $company = sanitize_text_field( wp_unslash( $_POST['billing_company'] ) );

        // Set on the order
        $order->set_billing_company( $company );

        // Also persist on the user, if logged in
        if ( $order->get_customer_id() ) {
            update_user_meta( $order->get_customer_id(), 'billing_company', $company );
        }
    }

}, 10, 2 );

add_action( 'profile_update', function( $user_id, $old_user ) {
    setup_payload_api();
    $user = get_userdata( $user_id );

    $payload_customer_id = get_user_meta( $user_id, 'payload_customer_id', true );


        if( !empty($payload_customer_id)){
            $company_name = get_user_meta( $user_id, 'billing_company', true );
            try {
                $customer = Payload\Customer::filter_by( array('id'=>$payload_customer_id) )->update(
                    array(
                        'email' => $user->user_email,
                        'name'  => $company_name ? $company_name : $user->display_name,
                        'attrs' => array(
                            '_wp_user_id' => $user_id,
                            'Billing Company' => $company_name
                        )
                    )
                    )
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


}, 10, 2 );


add_action( 'plugins_loaded', 'woocommerce_payload', 0 );
/**
 * Initialize WooCommerce Payload integration.
 *
 * Loads the Payload payment gateway class if WooCommerce is available.
 *
 * @since 1.0.0
 */
function woocommerce_payload() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // if the WC payment gateway class
	}

	include plugin_dir_path( __FILE__ ) . 'includes/class-wc-payload-gateway.php';
}


add_filter( 'woocommerce_payment_gateways', 'add_payload_gateway' );
/**
 * Add Payload gateway to WooCommerce payment gateways.
 *
 * @since 1.0.0
 * @param array $gateways Array of WooCommerce payment gateway classes.
 * @return array Modified array of payment gateways including Payload.
 */
function add_payload_gateway( $gateways ) {
	$gateways[] = 'WC_Payload_Gateway';
	return $gateways;
}

/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
add_action( 'before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility' );
/**
 * Declare compatibility with WooCommerce cart and checkout blocks.
 *
 * @since 1.0.0
 */
function declare_cart_checkout_blocks_compatibility() {
	// Check if the required class exists
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		// Declare compatibility for 'cart_checkout_blocks'
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}

// Hook to the 'woocommerce_blocks_loaded' action to register payment method type
add_action( 'woocommerce_blocks_loaded', 'payload_register_order_approval_payment_method_type' );
/**
 * Register Payload payment method type for WooCommerce Blocks.
 *
 * @since 1.0.0
 */
function payload_register_order_approval_payment_method_type() {
	// Check if the required class exists
	if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}

	// Include Blocks Checkout class
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-payload-blocks.php';

	// Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
			// Register an instance of WC_Payload_Blocks
			$payment_method_registry->register( new WC_Payload_Blocks() );
		}
	);
}
// Auto-complete orders with only virtual/downloadable products
add_action( 'woocommerce_payment_complete', function( $order_id ) {

    $order = wc_get_order( $order_id );

    // Check if ALL items are virtual or downloadable
    $virtual_order = true;

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();

        if ( ! $product || ( ! $product->is_virtual() && ! $product->is_downloadable() ) ) {
            $virtual_order = false;
            break;
        }
    }

    // Auto-complete the order
    if ( $virtual_order ) {
        $order->update_status( 'completed', 'Order auto-completed because it contains only virtual products.' );
    }

});

/**
 * Plugin Name: Admin Flash Notice Example
 */

add_action('admin_init', function () {
	// Example trigger: append ?my_notice=1 to any wp-admin URL
	if (!current_user_can('manage_options')) return;

	$my_notice = isset( $_GET['my_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['my_notice'] ) ) : '';
	if ( $my_notice === '1' ) {
		set_transient('my_admin_flash_notice_' . get_current_user_id(), [
			'message' => '✅ Settings saved successfully.',
			'type'    => 'success', // success | warning | error | info
		], 60); // seconds
	}
});

add_action('admin_notices', function () {
	if (!current_user_can('manage_options')) return;

	$key  = 'my_admin_flash_notice_' . get_current_user_id();
	$data = get_transient($key);

	if (!$data) return;

	delete_transient($key);

	$type    = isset($data['type']) ? $data['type'] : 'info';
	$message = isset($data['message']) ? $data['message'] : '';

	$allowed = ['success', 'warning', 'error', 'info'];
	if (!in_array($type, $allowed, true)) $type = 'info';

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr($type),
		esc_html($message)
	);
});
add_action('woocommerce_created_customer', function ($customer_id) {
       if( ! $customer_id ) {
        return;
    }
    if ( get_user_meta($customer_id, 'payload_customer_id', true) ) {
    return;
    }
    setup_payload_api();
    // User NOW exists
      get_payload_customer_id($customer_id);
});

add_action('woocommerce_checkout_order_processed',function ($order_id, $posted_data, $order) {
    // When new user is created it wasnt getting Payload Customer ID only existing users get customer id
    // User NOW exists

    $customer_id = $order->get_customer_id();
    if( ! $customer_id ) {
        return;
    }
    if ( get_user_meta($customer_id, 'payload_customer_id', true) ) {
    return;
    }

    setup_payload_api();
    get_payload_customer_id($customer_id);
}, 10, 3);


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
function get_payload_customer_id($user_id=null) {
	$payload_customer_id = null;
    if($user_id){
        $user = get_user_by( 'id', $user_id );
    } else {
        	$user = wp_get_current_user();
    }

    $payload_customer_id = !empty($user) ? get_user_meta( $user->ID, 'payload_customer_id', true ) : null;

    $logger = wc_get_logger();
    $context = [ 'source' => 'payload-woocommerce.php' ]; // shows up as the log "Source"
     $logger->info('Script started Payload Customer ID checking for user ID: ' . ( $user ? $user->ID : 'none' ), $context);


		if(!$payload_customer_id && !empty($user) && $user->user_email){
        $logger->info('$User variable is not empty here is the email:'.$user->user_email, $context);
			try {
				$customer = Payload\Customer::filter_by(
					array("email"=>$user->user_email )
				)->all();

					if(is_array($customer) && !empty($customer)){
						$payload_customer_id = $customer[0]->id ;
						update_user_meta( $user->ID, 'payload_customer_id', $payload_customer_id );
	                     $logger->info('Payload Customer ID found for user email:'.$user->user_email, $context);
						return $payload_customer_id;
					}
			} catch ( Exception $e ) {
				$logger->error(
					'Failed to retrieve Payload customer by email for user ID ' . $user->ID . ': ' . $e->getMessage(),
					$context
				);
				// Continue to creation attempt
			}
		}

		if ( ! $payload_customer_id && !empty($user) && $user->user_email && $user->user_nicename ) {

            $company_name = get_user_meta( $user->ID, 'billing_company', true );
			try {
				// Create new Payload customer
				$customer = Payload\Customer::create(

					array(
						'email' => $user->user_email,
						'name'  => $company_name ? $company_name : $user->display_name,
						'attrs' => array(
							'_wp_user_id' => $user->ID,
							'Billing Company'=>$company_name,
						),
					)
				);
	                     $logger->info('Payload Customer ID Created: ' . ( isset( $customer->id ) ? $customer->id : 'unknown' ), $context);
			} catch ( Exception $e ) {
				$logger->error(
					'Failed to create Payload customer for user ID ' . $user->ID . ': ' . $e->getMessage(),
					$context
				);
				return null;
			}
					}


				if(!empty($customer))
				{
				$payload_customer_id = $customer->id;

				update_user_meta( $user->ID, 'payload_customer_id', $payload_customer_id );
				}
		
	

	return $payload_customer_id ?: null;
}

/**
 * Generate Payload client token for payment or payment method forms.
 *
 * REST API callback that creates a client token for payment processing.
 *
 * @since 1.0.0
 * @param array $data Request data from REST API.
 * @return array Array containing client_token ID.
 */
function get_intent( $data ) {
	setup_payload_api();

	$payload_customer_id = get_payload_customer_id();

	$request_type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
	if ( $request_type === 'payment_method' ) {
		$intent = array(
			'payment_method_form' => array(
				'payment_method' => array(
					'customer_id' => $payload_customer_id,
				),
			),
		);
	} else {
		$intent = array(
			'payment_form' => array(
				'payment' => array(
					'customer_id' => $payload_customer_id,
				),
			),
		);
	}

	try {
		$clientToken = Payload\ClientToken::create(
			array( 'intent' => $intent ),
		);

		return array( 'client_token' => $clientToken->id );
	} catch ( Exception $e ) {
		$logger = wc_get_logger();
		$logger->error(
			'Failed to create Payload client token: ' . $e->getMessage(),
			array( 'source' => 'payload-woocommerce' )
		);
		return new WP_Error(
			'payload_token_error',
			__( 'Unable to initialize payment form. Please try again later.', 'payload' ),
			array( 'status' => 500 )
		);
	}
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wc/v3',
			'payload_client_token',
			array(
				'methods'             => 'GET',
				'callback'            => 'get_intent',
				'permission_callback' => function() {
					return is_user_logged_in();
				},
			)
		);
	}
);

/**
 * Initialize and configure Payload API settings.
 *
 * Sets up API key and URL from WooCommerce settings and environment variables.
 *
 * @since 1.0.0
 */
function setup_payload_api() {
	$settings = get_option( 'woocommerce_payload_settings', array() );

	if ( ! empty( $settings['api_key'] ) ) {
		pl::$api_key = $settings['api_key'];
	} else {
		// Log error if API key is missing
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->error( 'Payload API key is not configured', array( 'source' => 'payload-woocommerce' ) );
		}
	}

	if ( getenv( 'PAYLOAD_API_URL' ) ) {
		pl::$api_url = getenv( 'PAYLOAD_API_URL' );
	}
}

add_filter( 'woocommerce_subscription_payment_method_to_display', 'payload_subscription_payment_method_to_display', 10, 3 );
/**
 * Display payment method for WooCommerce subscriptions.
 *
 * Gets the payment method title from the parent order for subscriptions.
 *
 * @since 1.0.0
 * @param string           $label        Default payment method label.
 * @param WC_Subscription  $subscription Subscription object.
 * @param string           $context      Display context.
 * @return string Payment method title from parent order or default message.
 */
function payload_subscription_payment_method_to_display( $label, $subscription, $context ) {

	$parent_order = wc_get_order( $subscription->get_parent_id() );
	if($parent_order){
	return $parent_order->get_payment_method_title();
	}
	return "No Payment Method available at this time.";
}

add_action( 'woocommerce_new_payment_token', 'payload_retry_orders_after_card_update', 10, 2 );
add_action( 'woocommerce_payment_token_set_default', 'payload_retry_orders_after_card_update', 10, 2 );
/**
 * Retry pending/on-hold orders after customer updates payment card.
 *
 * Automatically attempts to process failed orders when a new payment method is added
 * or set as default. Includes order-scoped suppression to prevent duplicate retries.
 *
 * @since 1.0.0
 * @param int                      $token_id Payment token ID.
 * @param WC_Payment_Token|null    $token    Payment token object.
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
 * Check or set suppression status for card update retry on an order.
 *
 * Uses a static array to track which orders have already been processed during
 * card update retry to prevent infinite loops or duplicate processing.
 *
 * @since 1.0.0
 * @param int|null  $order_id Order ID to check/set suppression for.
 * @param bool|null $status   If provided, sets suppression status (true=suppress, false=unsuppress).
 * @return bool True if order retry is suppressed, false otherwise.
 */
function payload_card_update_retry_suppressed( $order_id = null, $status = null ) {
	static $suppressed_orders = [];

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
