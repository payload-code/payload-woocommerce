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

    if ( ! empty( $_POST['billing_company'] ) ) {
        $company = sanitize_text_field( $_POST['billing_company'] );

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
            $customer = Payload\Customer::filter_by( array('id'=>$payload_customer_id) )->update(
                array(
                    'email' => $user->user_email,
                    'name'  => $user->display_name,
                    'attrs' => array(
                        '_wp_user_id' => $user_id,
                        'billing_company' => get_user_meta( $user_id, 'billing_company', true
                    )
                )
                )
                    );
           
         
        }
    

}, 10, 2 );


add_action( 'plugins_loaded', 'woocommerce_payload', 0 );
function woocommerce_payload() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // if the WC payment gateway class
	}

	include plugin_dir_path( __FILE__ ) . 'includes/class-wc-payload-gateway.php';
}


add_filter( 'woocommerce_payment_gateways', 'add_payload_gateway' );
function add_payload_gateway( $gateways ) {
	$gateways[] = 'WC_Payload_Gateway';
	return $gateways;
}

/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
add_action( 'before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility' );
function declare_cart_checkout_blocks_compatibility() {
	// Check if the required class exists
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		// Declare compatibility for 'cart_checkout_blocks'
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}

// Hook to the 'woocommerce_blocks_loaded' action to register payment method type
add_action( 'woocommerce_blocks_loaded', 'payload_register_order_approval_payment_method_type' );
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

        if ( ! $product->is_virtual() && ! $product->is_downloadable() ) {
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

	if (isset($_GET['my_notice']) && $_GET['my_notice'] === '1') {
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
    // print_r($_POST);
    // die();
}, 10, 3);


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
     $logger->info('Script started Payload Customer ID checking '.print_r($user, true), $context);


		if(!$payload_customer_id && !empty($user) && $user->user_email){
        $logger->info('$User variable is not empty here is the email:'.$user->user_email, $context);
			$customer = Payload\Customer::filter_by(
				array("email"=>$user->user_email )
			)->all();
			
				if(is_array($customer) && !empty($customer)){
					$payload_customer_id = $customer[0]->id ;
					update_user_meta( $user->ID, 'payload_customer_id', $payload_customer_id );
                     $logger->info('Payload Customer ID found for user email:'.$user->user_email, $context);
					return $payload_customer_id;
				}
		}
        
		if ( ! $payload_customer_id && !empty($user) && $user->user_email && $user->user_nicename ) {


							// Create new Payload customer
						$customer = Payload\Customer::create(
							array(
								'email' => $user->user_email,
								'name'  => $user->user_nicename,
								'attrs' => array(
									'_wp_user_id' => $user->ID,
									'billing_company'=>get_user_meta( $user->ID, 'billing_company', true ),
								),
							)
						);
                         $logger->info('Payload Customer ID Created'.print_r($customer, true), $context);
					}
                     

				if(!empty($customer))
				{
				$payload_customer_id = $customer->id;

				update_user_meta( $user->ID, 'payload_customer_id', $payload_customer_id );
				}
		
	

	return $payload_customer_id ?: null;
}

function get_intent( $data ) {
	setup_payload_api();

	$payload_customer_id = get_payload_customer_id();

	if ( $_GET['type'] == 'payment_method' ) {
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

	$clientToken = Payload\ClientToken::create(
		array( 'intent' => $intent ),
	);

	return array( 'client_token' => $clientToken->id );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'wc/v3',
			'payload_client_token',
			array(
				'methods'  => 'GET',
				'callback' => 'get_intent',
			)
		);
	}
);

function setup_payload_api() {
	$settings = get_option( 'woocommerce_payload_settings', array() );
	pl::$api_key = $settings['api_key'];
	if ( getenv( 'PAYLOAD_API_URL' ) ) {
		pl::$api_url = getenv( 'PAYLOAD_API_URL' );
	}
}

add_filter( 'woocommerce_subscription_payment_method_to_display', 'payload_subscription_payment_method_to_display', 10, 3 );

function payload_subscription_payment_method_to_display( $label, $subscription, $context ) {

	$parent_order = wc_get_order( $subscription->get_parent_id() );
	if($parent_order){
	return $parent_order->get_payment_method_title();
	}
	return "No Payment Method available at this time.";
}

add_action( 'woocommerce_new_payment_token', 'payload_retry_orders_after_card_update', 10, 2 );
add_action( 'woocommerce_payment_token_set_default', 'payload_retry_orders_after_card_update', 10, 2 );

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
		'limit'          => -1,
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
