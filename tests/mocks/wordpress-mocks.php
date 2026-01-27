<?php

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/payload-woocommerce/';
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) array(
			'ID'            => 1,
			'user_email'    => 'test@example.com',
			'user_nicename' => 'testuser',
		);
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $single ? '' : array();
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $meta_key, $meta_value, $prev_value = '' ) {
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array() ) {
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		return true;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		return true;
	}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = null ) {
		return true;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $notice_type = 'success' ) {
		return true;
	}
}

if ( ! function_exists( 'wc_get_endpoint_url' ) ) {
	function wc_get_endpoint_url( $endpoint, $value = '', $permalink = '' ) {
		return 'http://example.com/my-account/' . $endpoint . '/';
	}
}

if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		static $logger = null;
		if ( $logger === null ) {
			$logger = new class() {
				public function error( $message, $context = array() ) {
					return true; }
				public function warning( $message, $context = array() ) {
					return true; }
				public function info( $message, $context = array() ) {
					return true; }
				public function debug( $message, $context = array() ) {
					return true; }
			};
		}
		return $logger;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return strip_tags( $str );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		return (object) array(
			'ID'            => $value,
			'user_email'    => 'test@example.com',
			'user_nicename' => 'testuser',
			'first_name'    => 'Test',
			'last_name'     => 'User',
		);
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		return (object) array(
			'ID'            => $user_id,
			'user_email'    => 'test@example.com',
			'user_nicename' => 'testuser',
			'first_name'    => 'Test',
			'last_name'     => 'User',
			'data'          => (object) array(
				'user_email' => 'test@example.com',
			),
		);
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		return true;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

// WP_Error class mock
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $errors     = array();
		private $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = $this->get_error_codes();
			return $codes ? $codes[0] : '';
		}

		public function get_error_codes() {
			return array_keys( $this->errors );
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return $this->errors[ $code ][0] ?? '';
		}

		public function get_error_data( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return $this->error_data[ $code ] ?? '';
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
			if ( ! empty( $data ) ) {
				$this->error_data[ $code ] = $data;
			}
		}
	}
}
