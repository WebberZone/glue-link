<?php
/**
 * Database WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Database;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Manage FreemKit's custom database tables.
 *
 * @since 1.0.0
 */
class DB_Command extends Base_Command {

	/**
	 * Show the status of FreemKit's custom tables.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit db status
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$database = new Database();

		$data = array(
			array(
				'table'     => $database->get_table_name(),
				'installed' => $database->is_table_installed( $database->get_table_name() ) ? 'Yes' : 'No',
			),
			array(
				'table'     => $database->get_events_table_name(),
				'installed' => $database->is_table_installed( $database->get_events_table_name() ) ? 'Yes' : 'No',
			),
		);

		$this->format_output( $data, $this->get_format( $assoc_args ) );
		\WP_CLI::line( sprintf( 'DB version: %s (update required: %s)', $database->db_version, $database->needs_update() ? 'yes' : 'no' ) );
	}

	/**
	 * Create (or update) FreemKit's custom tables via dbDelta.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Run even if the tables already exist and are up to date.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit db create
	 *     wp freemkit db create --force
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function create( array $args, array $assoc_args ): void {
		$database = new Database();

		if ( ! $this->is_force( $assoc_args )
			&& $database->is_table_installed( $database->get_table_name() )
			&& $database->is_table_installed( $database->get_events_table_name() )
			&& ! $database->needs_update() ) {
			\WP_CLI::success( 'Tables already exist and are up to date. Use --force to run anyway.' );
			return;
		}

		$result = $database->create_table();

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( 'FreemKit tables created/updated successfully.' );
	}
}
