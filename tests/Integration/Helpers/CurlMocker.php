<?php
/**
 * Curl Mocker Helper
 *
 * Provides utilities for mocking curl requests/responses in integration tests.
 * Mocks curl_init, curl_setopt, curl_exec, curl_getinfo, curl_close.
 *
 * @package Payload_WooCommerce
 */

namespace PayloadWooCommerce\Tests\Integration\Helpers;

use Brain\Monkey;

class CurlMocker {

	private static $responses       = array();
	private static $current_request = array();
	private static $handle_counter  = 0;

	/**
	 * Reset all mocked responses
	 */
	public static function reset() {
		self::$responses       = array();
		self::$current_request = array();
		self::$handle_counter  = 0;
	}

	/**
	 * Mock a curl response for a specific request
	 *
	 * @param string            $method HTTP method (GET, POST, PUT, DELETE)
	 * @param string            $url Full URL or URL pattern
	 * @param int               $status_code HTTP status code
	 * @param array|string      $response_body Response body (array will be JSON encoded)
	 * @param array             $response_headers Response headers
	 * @param array|string|null $expected_request_body Expected request body to verify (optional)
	 */
	public static function mockResponse( $method, $url, $status_code, $response_body, $response_headers = array(), $expected_request_body = null ) {
		$key = strtoupper( $method ) . ':' . $url;

		if ( is_array( $response_body ) ) {
			$response_body = json_encode( $response_body );
		}

		if ( is_array( $expected_request_body ) ) {
			$expected_request_body = json_encode( $expected_request_body );
		}

		self::$responses[ $key ] = array(
			'status_code'           => $status_code,
			'body'                  => $response_body,
			'headers'               => $response_headers,
			'expected_request_body' => $expected_request_body,
		);
	}

	/**
	 * Set up curl function mocks
	 * Call this in test setUp after parent::setUp()
	 */
	public static function setUp() {
		self::reset();

		// Mock curl_init
		Monkey\Functions\when( 'curl_init' )->alias(
			function ( $url = null ) {
				self::$handle_counter++;
				$handle_id = 'curl_handle_' . self::$handle_counter;

				self::$current_request[ $handle_id ] = array(
					'url'     => $url,
					'method'  => 'GET',
					'options' => array(),
				);

				return $handle_id;
			}
		);

		// Mock curl_setopt
		Monkey\Functions\when( 'curl_setopt' )->alias(
			function ( $handle, $option, $value ) {
				if ( ! isset( self::$current_request[ $handle ] ) ) {
					return false;
				}

				self::$current_request[ $handle ]['options'][ $option ] = $value;

				// Extract method from CURLOPT_CUSTOMREQUEST
				if ( $option === CURLOPT_CUSTOMREQUEST ) {
					self::$current_request[ $handle ]['method'] = $value;
				}

				// Capture request body from CURLOPT_POSTFIELDS
				if ( $option === CURLOPT_POSTFIELDS ) {
					self::$current_request[ $handle ]['body'] = $value;
				}

				return true;
			}
		);

		// Mock curl_exec
		Monkey\Functions\when( 'curl_exec' )->alias(
			function ( $handle ) {
				if ( ! isset( self::$current_request[ $handle ] ) ) {
					return false;
				}

				$request     = self::$current_request[ $handle ];
				$method      = $request['method'];
				$url         = $request['url'];
				$actual_body = $request['body'] ?? null;

				// Find matching response
				$key = strtoupper( $method ) . ':' . $url;

				if ( isset( self::$responses[ $key ] ) ) {
					$response = self::$responses[ $key ];

					// Verify request body if expected body was specified
					if ( isset( $response['expected_request_body'] ) && $response['expected_request_body'] !== null ) {
						$expected_body = $response['expected_request_body'];

						// Normalize JSON for comparison (decode and re-encode to ignore formatting)
						$expected_normalized = json_decode( $expected_body, true );
						$actual_normalized   = json_decode( $actual_body, true );

						if ( $expected_normalized !== $actual_normalized ) {
							fwrite( STDERR, "CurlMocker: Request body mismatch for $method $url\n" );
							fwrite( STDERR, 'Expected: ' . json_encode( $expected_normalized, JSON_PRETTY_PRINT ) . "\n" );
							fwrite( STDERR, 'Actual: ' . json_encode( $actual_normalized, JSON_PRETTY_PRINT ) . "\n" );

							// Return error response
							return json_encode(
								array(
									'object'            => 'error',
									'error_type'        => 'BadRequest',
									'error_description' => 'Request body verification failed in test mock',
								)
							);
						}
					}

					self::$current_request[ $handle ]['response'] = $response;
					return $response['body'];
				}

				// No mock found - return error
				fwrite( STDERR, "CurlMocker: No mock found for $method $url\n" );
				return false;
			}
		);

		// Mock curl_getinfo
		Monkey\Functions\when( 'curl_getinfo' )->alias(
			function ( $handle, $option = null ) {
				if ( ! isset( self::$current_request[ $handle ] ) ) {
					return false;
				}

				$response = self::$current_request[ $handle ]['response'] ?? null;

				if ( $option === CURLINFO_HTTP_CODE ) {
					return $response['status_code'] ?? 0;
				}

				// If no option specified, return array of info
				if ( $option === null ) {
					return array(
						'http_code' => $response['status_code'] ?? 0,
						'url'       => self::$current_request[ $handle ]['url'] ?? '',
					);
				}

				return null;
			}
		);

		// Mock curl_close
		Monkey\Functions\when( 'curl_close' )->alias(
			function ( $handle ) {
				if ( isset( self::$current_request[ $handle ] ) ) {
					unset( self::$current_request[ $handle ] );
				}
				return true;
			}
		);
	}

	/**
	 * Helper: Mock a Payload Transaction::get() response
	 */
	public static function mockTransactionGet( $txn_id, $amount, $status, $pm_id, $customer_id = null ) {
		self::mockResponse(
			'GET',
			'https://api.payload.com/transactions/' . $txn_id,
			200,
			array(
				'object'            => 'transaction',
				'id'                => $txn_id,
				'type'              => 'payment',
				'status'            => $status,
				'amount'            => $amount,
				'ref_number'        => 'REF_' . $txn_id,
				'customer_id'       => $customer_id,
				'payment_method_id' => $pm_id,
				'payment_method'    => array(
					'id'          => $pm_id,
					'description' => 'Visa x-1111',
					'card'        => array(
						'card_brand'  => 'visa',
						'card_number' => '4111111111111111',
						'expiry'      => '12/2025',
					),
				),
			)
		);
	}

	/**
	 * Helper: Mock a Payload PaymentMethod::get() response
	 */
	public static function mockPaymentMethodGet( $pm_id, $card_brand, $last4, $expiry, $account_id = null ) {
		self::mockResponse(
			'GET',
			'https://api.payload.com/payment_methods/' . $pm_id,
			200,
			array(
				'object'      => 'payment_method',
				'id'          => $pm_id,
				'type'        => 'card',
				'card_type'   => $card_brand,
				'description' => ucfirst( $card_brand ) . ' ending in ' . $last4,
				'card'        => array(
					'card_brand'  => $card_brand,
					'card_number' => str_repeat( '*', 12 ) . $last4,
					'expiry'      => $expiry,
				),
				'account_id'  => $account_id,
			)
		);
	}

	/**
	 * Helper: Mock a Payload Transaction::create() response
	 *
	 * @param float       $amount Expected transaction amount
	 * @param string      $pm_id Expected payment method ID
	 * @param string|null $customer_id Expected customer ID
	 * @param array|null  $expected_request_body Full expected request body (optional, for verification)
	 * @return string The generated transaction ID
	 */
	public static function mockTransactionCreate( $amount, $pm_id, $customer_id = null, $expected_request_body = null ) {
		$txn_id = 'txn_' . bin2hex( random_bytes( 8 ) );

		self::mockResponse(
			'POST',
			'https://api.payload.com/transactions',
			200,
			array(
				'object'            => 'transaction',
				'id'                => $txn_id,
				'type'              => 'payment',
				'status'            => 'processed',
				'amount'            => $amount,
				'ref_number'        => 'REF_' . $txn_id,
				'customer_id'       => $customer_id,
				'payment_method_id' => $pm_id,
				'payment_method'    => array(
					'id'          => $pm_id,
					'description' => 'Visa ending in 1111',
					'card'        => array(
						'card_brand'  => 'visa',
						'card_number' => '4111111111111111',
						'expiry'      => '12/2025',
					),
				),
			),
			array(),
			$expected_request_body
		);

		return $txn_id;
	}

	/**
	 * Helper: Mock a Payload Transaction/PaymentMethod update response
	 *
	 * @param string      $object_type Object type (transactions, payment_methods, etc.)
	 * @param string|null $object_id Object ID
	 * @param array       $updated_fields Fields in the response
	 * @param array|null  $expected_request_body Expected request body for verification (optional)
	 */
	public static function mockUpdate( $object_type, $object_id, $updated_fields, $expected_request_body = null ) {
		$url = 'https://api.payload.com/' . $object_type;

		if ( $object_id ) {
			$url .= '/' . $object_id;
		}

		self::mockResponse(
			'PUT',
			$url,
			200,
			array_merge(
				array(
					'object' => rtrim( $object_type, 's' ), // transactions -> transaction
					'id'     => $object_id,
				),
				$updated_fields
			),
			array(),
			$expected_request_body
		);
	}

	/**
	 * Helper: Mock a Payload API error response
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE)
	 * @param string $url Full URL
	 * @param int    $status_code HTTP status code (e.g., 400, 422)
	 * @param string $error_type Error type (e.g., InvalidAttributes, BadRequest)
	 * @param string $error_description Error description
	 * @param array  $details Optional field-level validation errors (e.g., ['payment_method_id' => 'Required'])
	 */
	public static function mockError( $method, $url, $status_code, $error_type, $error_description, $details = array() ) {
		$error_response = array(
			'object'            => 'error',
			'error_type'        => $error_type,
			'error_description' => $error_description,
		);

		if ( ! empty( $details ) ) {
			$error_response['details'] = $details;
		}

		self::mockResponse(
			$method,
			$url,
			$status_code,
			$error_response
		);
	}
}
