<?php
/**
 * Status WP-CLI Command class for FreemKit.
 *
 * @package WebberZone\FreemKit\CLI
 * @since 1.0.0
 */

namespace WebberZone\FreemKit\CLI;

use WebberZone\FreemKit\Audit_Log;
use WebberZone\FreemKit\Database;
use WebberZone\FreemKit\Kit\Kit_Settings;
use WebberZone\FreemKit\Options_API;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Show an overview of FreemKit's runtime state.
 *
 * @since 1.0.0
 */
class Status_Command extends Base_Command {

	/**
	 * Show comprehensive status of the FreemKit installation.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv). Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp freemkit status
	 *     wp freemkit status --format=json
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		try {
			$this->validate_environment();

			$format = $this->get_format( $assoc_args );
			$status = $this->get_status_data();

			if ( 'table' === $format ) {
				$table_data = array();
				foreach ( $status as $key => $value ) {
					$table_data[] = array(
						'Setting' => $key,
						'Value'   => $value,
					);
				}
				$this->format_output( $table_data, $format );
			} else {
				$this->format_output( $status, $format );
			}
		} catch ( \Exception $e ) {
			$this->fatal_error( $e );
		}
	}

	/**
	 * Build the flattened status array.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>
	 */
	private function get_status_data(): array {
		global $wpdb;

		$database  = new Database();
		$kit       = new Kit_Settings();
		$counts    = $database->get_subscriber_counts();
		$queue     = $this->count_transients( 'freemkit_webhook_queue_' );
		$seen      = $this->count_transients( 'freemkit_webhook_seen_' );
		$audit_log = Audit_Log::all();

		$status = array(
			'FreemKit Version'      => defined( 'FREEMKIT_VERSION' ) ? FREEMKIT_VERSION : 'unknown',
			'WordPress Version'     => get_bloginfo( 'version' ),
			'PHP Version'           => PHP_VERSION,
			'Subscribers Table'     => $database->is_table_installed( $database->get_table_name() ) ? 'Present' : 'Missing',
			'Events Table'          => $database->is_table_installed( $database->get_events_table_name() ) ? 'Present' : 'Missing',
			'DB Update Required'    => $database->needs_update() ? 'Yes' : 'No',
			'Kit Access Token'      => $kit->has_access_token() ? 'Set' : 'Not set',
			'Kit Refresh Token'     => $kit->has_refresh_token() ? 'Set' : 'Not set',
			'Kit Credential Source' => $kit->using_convertkit_credentials() ? 'Kit plugin' : 'FreemKit',
			'Webhook Queue Depth'   => $queue,
			'Webhook Seen Cache'    => $seen,
			'Audit Log Enabled'     => Options_API::get_option( 'enable_audit_log', 1 ) ? 'Yes' : 'No',
			'Audit Log Entries'     => count( $audit_log ),
			'Process Hook Cron'     => wp_next_scheduled( 'freemkit_process_webhook_event' ) ? 'Scheduled' : 'Idle',
			'Refresh Token Cron'    => wp_next_scheduled( Kit_Settings::CRON_REFRESH_HOOK ) ? 'Scheduled' : 'Idle',
		);

		foreach ( is_array( $counts ) ? $counts : array() as $status_name => $count ) {
			$status[ 'Subscribers (' . $status_name . ')' ] = $count;
		}

		return $status;
	}

	/**
	 * Count transients matching a given key prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Transient key prefix (without the `_transient_` wrapper).
	 * @return int
	 */
	private function count_transients( string $prefix ): int {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
