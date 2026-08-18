<?php
/**
 * Kit connection WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Kit\Kit_API;
use WebberZone\FreemKit\Kit\Kit_Settings;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Inspect and test the Kit (ConvertKit) API connection.
 *
 * @since 1.0.0
 */
class Kit_Command extends Base_Command {

	/**
	 * Show the current Kit credential status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit kit status
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$kit = new Kit_Settings();

		$expiry = $kit->get_token_expiry();

		$data = array(
			array(
				'Credential Source' => $kit->using_convertkit_credentials() ? 'Kit plugin' : 'FreemKit',
				'Access Token'      => $kit->has_access_token() ? 'Set' : 'Not set',
				'Refresh Token'     => $kit->has_refresh_token() ? 'Set' : 'Not set',
				'Token Expires'     => $expiry ? gmdate( 'Y-m-d H:i:s', $expiry ) . ' UTC' : 'Unknown',
			),
		);

		$this->format_output( $data, $this->get_format( $assoc_args ) );
	}

	/**
	 * Test the Kit API connection by fetching the account.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit kit test
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function test( array $args, array $assoc_args ): void {
		$kit = new Kit_Settings();

		if ( ! $kit->has_access_and_refresh_token() ) {
			\WP_CLI::error( 'Kit is not connected. No access/refresh token found.' );
		}

		$api     = new Kit_API( $kit->get_access_token(), $kit->get_refresh_token() );
		$account = $api->get_account();

		if ( is_wp_error( $account ) ) {
			\WP_CLI::error( sprintf( 'Kit API connection failed: %s', $account->get_error_message() ) );
		}

		\WP_CLI::success( 'Kit API connection successful.' );

		if ( is_array( $account ) || is_object( $account ) ) {
			\WP_CLI::line( wp_json_encode( $account, JSON_PRETTY_PRINT ) );
		}
	}
}
