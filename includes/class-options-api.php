<?php
/**
 * Glue Link Options API.
 *
 * @since 1.0.0
 *
 * @package WebberZone\Glue_Link
 */

namespace WebberZone\Glue_Link;

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Options API Class.
 *
 * @since 1.0.0
 */
class Options_API {

	/**
	 * Settings option name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SETTINGS_OPTION = 'glue_link_settings';

	/**
	 * Filter prefix.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const FILTER_PREFIX = 'glue_link';

	/**
	 * Settings array.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private static $settings;

	/**
	 * Get Settings.
	 *
	 * Retrieves all plugin settings
	 *
	 * @since 1.0.0
	 * @return array Glue Link settings
	 */
	public static function get_settings() {
		$settings = get_option( self::SETTINGS_OPTION );

		/**
		 * Settings array
		 *
		 * Retrieves all plugin settings
		 *
		 * @since 1.0.0
		 * @param array $settings Settings array
		 */
		return apply_filters( self::FILTER_PREFIX . '_get_settings', $settings );
	}

	/**
	 * Get an option
	 *
	 * Looks to see if the specified setting exists, returns default if not
	 *
	 * @since 1.0.0
	 *
	 * @param string $key           Option to fetch.
	 * @param mixed  $default_value Default option.
	 * @return mixed
	 */
	public static function get_option( $key = '', $default_value = null ) {
		if ( empty( self::$settings ) ) {
			self::$settings = self::get_settings();
		}

		$value = isset( self::$settings[ $key ] ) ? self::$settings[ $key ] : null;

		if ( is_null( $value ) ) {
			if ( is_null( $default_value ) ) {
				$default_value = self::get_default_option( $key );
			}
			$value = $default_value;
		}

		/**
		 * Filter the value for the option being fetched.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $value         Value of the option.
		 * @param mixed $key           Name of the option.
		 * @param mixed $default_value Default value.
		 */
		$value = apply_filters( self::FILTER_PREFIX . '_get_option', $value, $key, $default_value );

		/**
		 * Key specific filter for the value of the option being fetched.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed $value         Value of the option.
		 * @param mixed $key           Name of the option.
		 * @param mixed $default_value Default value.
		 */
		return apply_filters( self::FILTER_PREFIX . "_get_option_{$key}", $value, $key, $default_value );
	}

	/**
	 * Update an option
	 *
	 * Updates a setting value in both the db and the global variable.
	 * Warning: Passing in an empty, false or null string value will remove
	 *        the key from the settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param  string          $key   The Key to update.
	 * @param  string|bool|int $value The value to set the key to.
	 * @return boolean True if updated, false if not.
	 */
	public static function update_option( $key = '', $value = false ) {
		// If no key, exit.
		if ( empty( $key ) ) {
			return false;
		}

		// If no value, delete.
		if ( empty( $value ) ) {
			return self::delete_option( $key );
		}

		// First let's grab the current settings.
		$options = self::get_settings();

		// Let's let devs alter that value coming in.
		$value = apply_filters( self::FILTER_PREFIX . '_update_option', $value, $key );

		// Next let's try to update the value.
		$options[ $key ] = $value;
		$did_update      = update_option( self::SETTINGS_OPTION, $options );

		// If it updated, let's update the static variable.
		if ( $did_update ) {
			self::$settings[ $key ] = $value;
		}

		return $did_update;
	}

	/**
	 * Remove an option
	 *
	 * Removes a Glue Link setting value in both the db and the static variable.
	 *
	 * @since 1.0.0
	 *
	 * @param  string $key The Key to delete.
	 * @return boolean True if updated, false if not.
	 */
	public static function delete_option( $key = '' ) {
		// If no key, exit.
		if ( empty( $key ) ) {
			return false;
		}

		// First let's grab the current settings.
		$options = self::get_settings();

		// Next let's try to update the value.
		if ( isset( $options[ $key ] ) ) {
			unset( $options[ $key ] );
		}

		$did_update = update_option( self::SETTINGS_OPTION, $options );

		// If it updated, let's update the static variable.
		if ( $did_update ) {
			self::$settings = $options;
		}

		return $did_update;
	}

	/**
	 * Default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Default settings
	 */
	public static function get_settings_defaults() {
		return Admin\Settings::settings_defaults();
	}

	/**
	 * Get the default option for a specific key
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key of the option to fetch.
	 * @return mixed
	 */
	public static function get_default_option( $key = '' ) {
		$default_settings = self::get_settings_defaults();

		if ( array_key_exists( $key, $default_settings ) ) {
			return $default_settings[ $key ];
		}

		return false;
	}

	/**
	 * Reset settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset_settings() {
		delete_option( self::SETTINGS_OPTION );
	}


	/**
	 * Encrypts an API key using either OpenSSL or Sodium, if available.
	 *
	 * @param string $key The API key to encrypt.
	 * @return string The encrypted API key, or the plain text key if no secure method is available.
	 */
	public static function encrypt_api_key( $key ) {
		if ( empty( $key ) ) {
			return '';
		}

		// Use OpenSSL if available.
		if ( extension_loaded( 'openssl' ) ) {
			$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv        = openssl_random_pseudo_bytes( $iv_length );
			$encrypted = openssl_encrypt( $key, 'aes-256-cbc', AUTH_SALT, 0, $iv );

			// Store IV + ciphertext in hex format.
			return 'enc:' . bin2hex( $iv . $encrypted );
		}

		// Use Sodium (libsodium) if OpenSSL is unavailable.
		if ( extension_loaded( 'sodium' ) ) {
			$sodium_key = substr( hash( 'sha256', AUTH_SALT, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$encrypted  = sodium_crypto_secretbox( $key, $nonce, $sodium_key );

			return 'enc:' . sodium_bin2hex( $nonce . $encrypted );
		}

		// Log error and return plain text (not ideal, but prevents breaking functionality).
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( self::FILTER_PREFIX . ': No secure encryption method available.' );
		}

		return $key;
	}

	/**
	 * Decrypts an API key using either OpenSSL or Sodium, if available.
	 *
	 * @param string $encrypted_key The encrypted API key to decrypt.
	 * @return string The decrypted API key, or the encrypted key if no secure method is available.
	 */
	public static function decrypt_api_key( $encrypted_key ) {
		if ( empty( $encrypted_key ) ) {
			return '';
		}

		// If the key doesn't start with 'enc:', it's not encrypted.
		if ( strpos( $encrypted_key, 'enc:' ) !== 0 ) {
			return $encrypted_key;
		}

		// Remove the 'enc:' prefix.
		$encrypted_key = substr( $encrypted_key, 4 );

		// Try OpenSSL decryption.
		if ( extension_loaded( 'openssl' ) ) {
			$data = hex2bin( $encrypted_key );
			if ( false === $data ) {
				return '';
			}

			$iv_length  = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv         = mb_substr( $data, 0, $iv_length, '8bit' );
			$ciphertext = mb_substr( $data, $iv_length, null, '8bit' );

			$decrypted = openssl_decrypt( $ciphertext, 'aes-256-cbc', AUTH_SALT, 0, $iv );
			return false === $decrypted ? '' : $decrypted;
		}

		// Try Sodium (libsodium) decryption.
		if ( extension_loaded( 'sodium' ) ) {
			$sodium_key = substr( hash( 'sha256', AUTH_SALT, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$decoded    = sodium_hex2bin( $encrypted_key );

			if ( ! $decoded ) {
				return '';
			}

			$nonce      = mb_substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit' );
			$ciphertext = mb_substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit' );
			$decrypted  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $sodium_key );

			return false === $decrypted ? '' : $decrypted;
		}

		// Log error and return encrypted key.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( self::FILTER_PREFIX . ': No secure decryption method available.' );
		}

		return $encrypted_key;
	}


	/**
	 * Function to add an action to search for tags using Ajax.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function tags_search() {
		if ( ! isset( $_REQUEST['tax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die();
		}

		$taxonomy = sanitize_key( $_REQUEST['tax'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tax      = get_taxonomy( $taxonomy );
		if ( ! empty( $taxonomy ) ) {
			$tax = get_taxonomy( $taxonomy );
			if ( ! $tax ) {
				wp_die();
			}

			if ( ! current_user_can( $tax->cap->assign_terms ) ) {
				wp_die();
			}
		}

		$s = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$comma = _x( ',', 'tag delimiter' );
		if ( ',' !== $comma ) {
			$s = str_replace( $comma, ',', $s );
		}
		if ( false !== strpos( $s, ',' ) ) {
			$s = explode( ',', $s );
			$s = $s[ count( $s ) - 1 ];
		}
		$s = trim( $s );

		/** This filter has been defined in /wp-admin/includes/ajax-actions.php */
		$term_search_min_chars = (int) apply_filters( 'term_search_min_chars', 2, $tax, $s );

		/*
		 * Require $term_search_min_chars chars for matching (default: 2)
		 * ensure it's a non-negative, non-zero integer.
		 */
		if ( ( 0 === $term_search_min_chars ) || ( strlen( $s ) < $term_search_min_chars ) ) {
			wp_die();
		}

		$results = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'name__like' => $s,
				'fields'     => 'names',
				'hide_empty' => false,
			)
		);

		echo wp_json_encode( $results );
		wp_die();
	}
}

// Add the tags search AJAX action.
add_action( 'wp_ajax_' . Options_API::FILTER_PREFIX . '_tags_search', array( Options_API::class, 'tags_search' ) );
